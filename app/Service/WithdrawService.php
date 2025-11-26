<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\WithdrawRequestDTO;
use App\Event\WithdrawCreated;
use App\Event\WithdrawFailed;
use App\Event\WithdrawProcessed;
use App\Factory\WithdrawMethodStrategyFactory;
use App\Model\Account;
use App\Model\AccountWithdraw;
use App\Model\AccountWithdrawPix;
use App\Repository\AccountRepository;
use App\Repository\AccountWithdrawRepository;
use App\Helper\LogMasker;
use App\Service\AuditService;
use App\Service\DistributedLockService;
use App\Service\EventDispatcherService;
use App\Service\FraudDetectionService;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class WithdrawService
{
    public function __construct(
        private AccountRepository $accountRepository,
        private AccountWithdrawRepository $withdrawRepository,
        private EmailService $emailService,
        private LoggerInterface $logger,
        private DistributedLockService $lockService,
        private MetricsService $metricsService,
        private FraudDetectionService $fraudDetectionService,
        private AuditService $auditService,
        private WithdrawMethodStrategyFactory $strategyFactory,
        private EventDispatcherService $eventDispatcher,
    ) {
    }

    public function createWithdraw(WithdrawRequestDTO $dto, ?string $userId = null, ?string $idempotencyKey = null): AccountWithdraw
    {
        $startTime = microtime(true);
        
        // Verificar idempotência se key fornecida
        if ($idempotencyKey) {
            $existing = $this->withdrawRepository->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY);
                $this->logger->info('Idempotent request: returning existing withdraw', 
                    LogMasker::mask([
                        'correlation_id' => $correlationId,
                        'idempotency_key' => $idempotencyKey,
                        'withdraw_id' => $existing->id,
                    ])
                );
                return $existing;
            }
        }
        
        return Db::transaction(function () use ($dto, $startTime, $userId, $idempotencyKey) {
            // Validar conta existe
            $account = $this->accountRepository->findById($dto->accountId);
            if (!$account) {
                $this->metricsService->incrementCounter('withdraws_created_total', [
                    'status' => 'error',
                    'error_type' => 'account_not_found',
                ]);
                throw new \InvalidArgumentException('Account not found');
            }

            // Validar saldo suficiente (se não for agendado)
            // Usar lock distribuído por conta para prevenir race conditions
            if (!$dto->isScheduled()) {
                $accountLockKey = "account:withdraw:{$dto->accountId}";
                
                $result = $this->lockService->executeWithLock(
                    $accountLockKey,
                    function () use ($dto) {
                        // Verificar saldo com lock pessimista dentro da transação
                        if (!$this->accountRepository->hasSufficientBalanceWithLock($dto->accountId, $dto->amount)) {
                            $this->metricsService->recordInsufficientBalance('withdraw_creation');
                            $this->metricsService->incrementCounter('withdraws_created_total', [
                                'status' => 'error',
                                'error_type' => 'insufficient_balance',
                            ]);
                            throw new \InvalidArgumentException('Insufficient balance');
                        }
                        return true;
                    },
                    10 // 10 segundos de lock (suficiente para criar saque)
                );
                
                if ($result === null) {
                    // Lock não adquirido - outra operação está em andamento
                    $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY);
                    $this->logger->warning('Could not acquire lock for account withdraw', [
                        'correlation_id' => $correlationId,
                        'account_id' => $dto->accountId,
                    ]);
                    throw new \RuntimeException('Account is being processed by another request. Please try again.');
                }
            }

            // Validar data de agendamento
            if ($dto->isScheduled()) {
                $scheduledDate = $dto->getScheduledDateTime();
                if (!$scheduledDate) {
                    throw new \InvalidArgumentException('Invalid schedule date format');
                }

                if ($scheduledDate < new \DateTime()) {
                    throw new \InvalidArgumentException('Cannot schedule withdraw for past date');
                }
            }

            // Obter strategy para o método de saque
            $strategy = $this->strategyFactory->create($dto->method);
            
            // Validar usando a strategy (validações específicas do método)
            $strategy->validate($dto);

            // Detecção de fraude
            $fraudCheck = $this->fraudDetectionService->checkFraud(
                $dto->accountId,
                (float) $dto->amount,
                $dto->pixKey
            );

            if ($fraudCheck->isFraud) {
                $severity = $fraudCheck->getSeverity();
                
                // Bloquear se for crítico ou alto
                if ($severity === 'critical' || $severity === 'high') {
                    $this->auditService->log(
                        'withdraw_blocked_fraud',
                        'withdraw',
                        'pending',
                        $userId,
                        $dto->accountId,
                        [
                            'amount' => $dto->amount,
                            'fraud_checks' => $fraudCheck->checks,
                            'severity' => $severity,
                        ]
                    );
                    
                    throw new \InvalidArgumentException('Transaction blocked due to security policy');
                }
                
                // Apenas logar se for médio (pode ser falso positivo)
                $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY);
                $this->logger->warning('Fraud check triggered but allowing transaction', [
                    'correlation_id' => $correlationId,
                    'account_id' => $dto->accountId,
                    'checks' => $fraudCheck->checks,
                    'severity' => $severity,
                ]);
            }

            // Obter correlation_id do contexto (adicionado pelo CorrelationIdMiddleware)
            $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY);

            // Criar saque
            $withdrawId = Uuid::uuid4()->toString();
            $withdraw = $this->withdrawRepository->create([
                'id' => $withdrawId,
                'idempotency_key' => $idempotencyKey, // Garante idempotência
                'correlation_id' => $correlationId, // Rastreamento distribuído
                'account_id' => $dto->accountId,
                'method' => $dto->method,
                'amount' => $dto->amount,
                'scheduled' => $dto->isScheduled(),
                'scheduled_for' => $dto->getScheduledDateTime(),
                'done' => false,
                'error' => false,
            ]);

            // Criar dados específicos do método (PIX, TED, etc.)
            // Por enquanto, apenas PIX é suportado
            if ($dto->method === 'PIX') {
                AccountWithdrawPix::create([
                    'account_withdraw_id' => $withdrawId,
                    'type' => $dto->pixType,
                    'key' => $dto->pixKey,
                ]);
            }

            // Registrar métricas de criação
            $this->metricsService->recordWithdrawCreated($dto->isScheduled(), $dto->pixType);
            $this->metricsService->recordWithdrawAmount((float) $dto->amount, $dto->isScheduled());

            // Registrar no sistema de detecção de fraude
            $this->fraudDetectionService->recordWithdrawal($dto->accountId, (float) $dto->amount);

            // Recarregar withdraw com relacionamentos
            $withdraw = $this->withdrawRepository->findById($withdrawId);

            // Disparar evento de criação
            $this->eventDispatcher->dispatch(new WithdrawCreated($withdraw, $dto->isScheduled()));

            // Enviar notificação de agendamento se for saque agendado
            if ($dto->isScheduled()) {
                try {
                    $this->emailService->sendScheduledWithdrawNotification($withdraw);
                } catch (\Exception $e) {
                    // Log erro mas não falha a criação do saque
                    $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY);
                    $this->logger->warning('Failed to send scheduled withdraw notification', [
                        'correlation_id' => $correlationId,
                        'withdraw_id' => $withdrawId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Processar imediatamente se não for agendado
            // Usar lock distribuído por conta para prevenir processamento duplicado
            if (!$dto->isScheduled()) {
                $accountLockKey = "account:withdraw:{$dto->accountId}";
                
                $this->lockService->executeWithLock(
                    $accountLockKey,
                    function () use ($withdrawId, &$processDuration, &$processed) {
                        $processStartTime = microtime(true);
                        $processed = $this->processWithdraw($withdrawId);
                        $processDuration = microtime(true) - $processStartTime;
                        return $processed;
                    },
                    30 // 30 segundos de lock (suficiente para processar)
                );
                
                if (isset($processed)) {
                    $this->metricsService->recordWithdrawProcessingTime($processDuration, false);
                    $this->metricsService->recordWithdrawProcessed($processed);
                }
            }

            $totalDuration = microtime(true) - $startTime;
            $this->metricsService->recordHistogram('withdraw_creation_time_seconds', $totalDuration, [
                'type' => $dto->isScheduled() ? 'scheduled' : 'immediate',
            ]);

            return $withdraw;
        });
    }

    /**
     * Cancela um saque agendado
     * Apenas saques agendados e não processados podem ser cancelados
     */
    public function cancelScheduledWithdraw(string $withdrawId, ?string $userId = null): bool
    {
        return Db::transaction(function () use ($withdrawId, $userId) {
            // Buscar saque com lock pessimista
            $withdraw = $this->withdrawRepository->findByIdWithLock($withdrawId);
            
            if (!$withdraw) {
                throw new \InvalidArgumentException('Withdraw not found');
            }

            // Validar que é saque agendado
            if (!$withdraw->scheduled) {
                throw new \InvalidArgumentException('Only scheduled withdraws can be cancelled');
            }

            // Validar que não foi processado
            if ($withdraw->done) {
                throw new \InvalidArgumentException('Cannot cancel already processed withdraw');
            }

            // Validar que não está com erro
            if ($withdraw->error) {
                throw new \InvalidArgumentException('Cannot cancel withdraw with error status');
            }

            // Marcar como cancelado (usando error=true e error_reason para indicar cancelamento)
            // Nota: Não marcamos done=true para cancelamentos, apenas error=true
            $this->withdrawRepository->markAsCancelled($withdrawId, 'Cancelled by user');

            // Recarregar withdraw com relacionamentos
            $withdraw = $this->withdrawRepository->findById($withdrawId);

            // Enviar email de cancelamento
            try {
                $this->emailService->sendWithdrawCancellationNotification($withdraw);
            } catch (\Exception $e) {
                // Log erro mas não falha o cancelamento
                $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id;
                $this->logger->warning('Failed to send cancellation notification', [
                    'correlation_id' => $correlationId,
                    'withdraw_id' => $withdrawId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Registrar métricas
            $this->metricsService->incrementCounter('withdraws_cancelled_total', [
                'account_id' => $withdraw->account_id,
            ]);

            // Auditoria
            $this->auditService->logWithdrawCancelled(
                $withdrawId,
                $withdraw->account_id,
                $userId
            );

            $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id;
            $this->logger->info('Scheduled withdraw cancelled', [
                'correlation_id' => $correlationId,
                'withdraw_id' => $withdrawId,
                'account_id' => $withdraw->account_id,
                'user_id' => $userId,
            ]);

            return true;
        });
    }

    public function processWithdraw(string $withdrawId): bool
    {
        $startTime = microtime(true);
        
        // Usar distributed lock por saque para prevenir processamento duplicado
        // em sistemas distribuídos (múltiplas instâncias)
        $withdrawLockKey = "withdraw:process:{$withdrawId}";
        
        return $this->lockService->executeWithLock(
            $withdrawLockKey,
            function () use ($withdrawId, $startTime) {
                return Db::transaction(function () use ($withdrawId, $startTime) {
                    // Buscar saque com lock pessimista para prevenir race conditions
                    $withdraw = $this->withdrawRepository->findByIdWithLock($withdrawId);
                    
                    if (!$withdraw) {
                        $this->metricsService->recordWithdrawProcessed(false, 'withdraw_not_found');
                        throw new \InvalidArgumentException('Withdraw not found');
                    }

                    // Verificar se já foi processado (double-check após lock)
                    if ($withdraw->done) {
                        $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id;
                        $this->logger->warning('Withdraw already processed', [
                            'correlation_id' => $correlationId,
                            'withdraw_id' => $withdrawId,
                        ]);
                        $this->metricsService->recordWithdrawProcessed(false, 'already_processed');
                        return false;
                    }

                    // Verificar e deduzir saldo de forma atômica
                    // Isso previne race conditions entre verificação e dedução
                    if (!$this->accountRepository->decrementBalanceIfSufficient($withdraw->account_id, $withdraw->amount)) {
                        $this->withdrawRepository->markAsError(
                            $withdrawId,
                            'Insufficient balance at processing time'
                        );

                            $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id;
                            $this->logger->warning('Insufficient balance for scheduled withdraw', [
                                'correlation_id' => $correlationId,
                                'withdraw_id' => $withdrawId,
                                'account_id' => $withdraw->account_id,
                            ]);

                        $this->metricsService->recordInsufficientBalance('withdraw_processing');
                        $this->metricsService->recordWithdrawProcessed(false, 'Insufficient balance at processing time');
                        
                        // Recarregar withdraw e disparar evento de falha
                        $withdraw = $this->withdrawRepository->findById($withdrawId);
                        $this->eventDispatcher->dispatch(new WithdrawFailed(
                            $withdraw,
                            'Insufficient balance at processing time'
                        ));
                        
                        return false;
                    }

                    // Saldo foi deduzido com sucesso de forma atômica
                    // Obter strategy para processar o saque
                    $strategy = $this->strategyFactory->create($withdraw->method);
                    
                    // Processar usando a strategy (integração com provedor PIX, TED, etc.)
                    $processed = $strategy->process($withdraw);
                    
                    if (!$processed) {
                        // Marcar como erro se a strategy falhou
                        $this->withdrawRepository->markAsError(
                            $withdrawId,
                            'Failed to process withdraw using method strategy'
                        );
                        
                        $duration = microtime(true) - $startTime;
                        $this->metricsService->recordWithdrawProcessed(false, 'strategy_processing_failed');
                        
                        // Recarregar withdraw e disparar evento de falha
                        $withdraw = $this->withdrawRepository->findById($withdrawId);
                        $this->eventDispatcher->dispatch(new WithdrawFailed(
                            $withdraw,
                            'Failed to process withdraw using method strategy'
                        ));
                        
                        return false;
                    }

                    // Marcar como processado
                    $this->withdrawRepository->markAsDone($withdrawId);

                    // Recarregar withdraw com relacionamentos
                    $withdraw = $this->withdrawRepository->findById($withdrawId);

                    // Enviar email
                    $emailSent = $this->emailService->sendWithdrawNotification($withdraw);
                    $this->metricsService->recordEmailSent($emailSent);

                    $duration = microtime(true) - $startTime;
                    $this->metricsService->recordWithdrawProcessingTime($duration, $withdraw->scheduled);
                    $this->metricsService->recordWithdrawProcessed(true);

                    // Disparar evento de processamento bem-sucedido
                    $this->eventDispatcher->dispatch(new WithdrawProcessed($withdraw, $duration));

                    // Auditoria de processamento
                    $this->auditService->logWithdrawProcessed(
                        $withdrawId,
                        $withdraw->account_id,
                        true,
                        null,
                        null // userId será obtido do contexto se disponível
                    );

                            $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id;
                            $this->logger->info('Withdraw processed successfully', [
                                'correlation_id' => $correlationId,
                                'withdraw_id' => $withdrawId,
                                'account_id' => $withdraw->account_id,
                                'amount' => $withdraw->amount,
                                'duration_seconds' => $duration,
                            ]);

                    return true;
                });
            },
            60 // 60 segundos de lock (suficiente para processar saque)
        ) ?? false;
    }

    public function processScheduledWithdraws(?int $maxConcurrency = null): int
    {
        $startTime = microtime(true);
        $requestedConcurrency = $maxConcurrency ?? (int) env('SCHEDULED_WITHDRAW_CONCURRENCY', 1);

        if (
            $requestedConcurrency > 1
            && \extension_loaded('swoole')
            && ! Coroutine::inCoroutine()
            && function_exists('\Swoole\Coroutine\run')
        ) {
            $result = 0;
            \Swoole\Coroutine\run(function () use (&$result, $requestedConcurrency) {
                $result = $this->processScheduledWithdraws($requestedConcurrency);
            });

            return $result;
        }
        
        // Usar distributed lock para evitar processamento duplicado
        // em ambientes com múltiplas instâncias
        $lockKey = 'process_scheduled_withdraws';
        
        $result = $this->lockService->executeWithLock(
            $lockKey,
            function () use ($startTime, $requestedConcurrency) {
                $pending = $this->withdrawRepository->findPendingScheduled();
                $processed = 0;
                $successCount = 0;
                $errorCount = 0;

                if ($pending->isEmpty()) {
                    return 0;
                }

                $concurrency = $requestedConcurrency;
                $canParallel = $concurrency > 1
                    && class_exists(Parallel::class)
                    && extension_loaded('swoole')
                    && Coroutine::inCoroutine();

                if ($canParallel) {
                    $parallel = new Parallel($concurrency);
                    foreach ($pending as $withdraw) {
                        $parallel->add(function () use ($withdraw) {
                            try {
                                return $this->processWithdraw($withdraw->id);
                            } catch (\Throwable $e) {
                                $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
                                $this->logger->error('Error processing scheduled withdraw (parallel)', [
                                    'correlation_id' => $correlationId,
                                    'withdraw_id' => $withdraw->id,
                                    'error' => $e->getMessage(),
                                ]);

                                return $e;
                            }
                        });
                    }

                    $results = $parallel->wait(false);
                    foreach ($results as $result) {
                        if ($result instanceof \Throwable) {
                            $errorCount++;
                            continue;
                        }

                        if ($result === true) {
                            $processed++;
                            $successCount++;
                        } else {
                            $errorCount++;
                        }
                    }
                } else {
                    foreach ($pending as $withdraw) {
                        try {
                            if ($this->processWithdraw($withdraw->id)) {
                                $processed++;
                                $successCount++;
                            } else {
                                $errorCount++;
                            }
                        } catch (\Exception $e) {
                            $errorCount++;
                            $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
                            $this->logger->error('Error processing scheduled withdraw', [
                                'correlation_id' => $correlationId,
                                'withdraw_id' => $withdraw->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                $duration = microtime(true) - $startTime;
                $this->metricsService->recordScheduledWithdrawsProcessed($processed, $successCount, $errorCount);
                $this->metricsService->recordHistogram('scheduled_withdraws_batch_processing_time_seconds', $duration, [
                    'count' => (string) $processed,
                ]);

                return $processed;
            },
            300 // 5 minutos de lock (suficiente para processar)
        ) ?? 0; // Retorna 0 se não conseguiu adquirir o lock
        
        if ($result === 0) {
            $this->metricsService->incrementCounter('scheduled_withdraws_batch_skipped_total', [
                'reason' => 'lock_not_acquired',
            ]);
        }
        
        return $result;
    }

    /**
     * Busca saque por ID (método auxiliar para controllers)
     */
    public function getWithdrawById(string $withdrawId): ?AccountWithdraw
    {
        return $this->withdrawRepository->findById($withdrawId);
    }
}

