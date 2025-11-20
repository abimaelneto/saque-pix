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
use App\Service\AuditService;
use App\Service\DistributedLockService;
use App\Service\EventDispatcherService;
use App\Service\FraudDetectionService;
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

    public function createWithdraw(WithdrawRequestDTO $dto, ?string $userId = null): AccountWithdraw
    {
        $startTime = microtime(true);
        
        return Db::transaction(function () use ($dto, $startTime, $userId) {
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
            if (!$dto->isScheduled()) {
                if (!$this->accountRepository->hasSufficientBalance($dto->accountId, $dto->amount)) {
                    $this->metricsService->recordInsufficientBalance('withdraw_creation');
                    $this->metricsService->incrementCounter('withdraws_created_total', [
                        'status' => 'error',
                        'error_type' => 'insufficient_balance',
                    ]);
                    throw new \InvalidArgumentException('Insufficient balance');
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
                $this->logger->warning('Fraud check triggered but allowing transaction', [
                    'account_id' => $dto->accountId,
                    'checks' => $fraudCheck->checks,
                    'severity' => $severity,
                ]);
            }

            // Criar saque
            $withdrawId = Uuid::uuid4()->toString();
            $withdraw = $this->withdrawRepository->create([
                'id' => $withdrawId,
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

            // Processar imediatamente se não for agendado
            if (!$dto->isScheduled()) {
                $processStartTime = microtime(true);
                $processed = $this->processWithdraw($withdrawId);
                $processDuration = microtime(true) - $processStartTime;
                
                $this->metricsService->recordWithdrawProcessingTime($processDuration, false);
                $this->metricsService->recordWithdrawProcessed($processed);
            }

            $totalDuration = microtime(true) - $startTime;
            $this->metricsService->recordHistogram('withdraw_creation_time_seconds', $totalDuration, [
                'type' => $dto->isScheduled() ? 'scheduled' : 'immediate',
            ]);

            return $withdraw;
        });
    }

    public function processWithdraw(string $withdrawId): bool
    {
        $startTime = microtime(true);
        
        return Db::transaction(function () use ($withdrawId, $startTime) {
            $withdraw = $this->withdrawRepository->findById($withdrawId);
            
            if (!$withdraw) {
                $this->metricsService->recordWithdrawProcessed(false, 'withdraw_not_found');
                throw new \InvalidArgumentException('Withdraw not found');
            }

            if ($withdraw->done) {
                $this->logger->warning('Withdraw already processed', [
                    'withdraw_id' => $withdrawId,
                ]);
                $this->metricsService->recordWithdrawProcessed(false, 'already_processed');
                return false;
            }

            // Verificar saldo
            if (!$this->accountRepository->hasSufficientBalance($withdraw->account_id, $withdraw->amount)) {
                $this->withdrawRepository->markAsError(
                    $withdrawId,
                    'Insufficient balance at processing time'
                );

                $this->logger->warning('Insufficient balance for scheduled withdraw', [
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

            // Deduzir saldo
            $this->accountRepository->updateBalance($withdraw->account_id, $withdraw->amount);

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

            $this->logger->info('Withdraw processed successfully', [
                'withdraw_id' => $withdrawId,
                'account_id' => $withdraw->account_id,
                'amount' => $withdraw->amount,
                'duration_seconds' => $duration,
            ]);

            return true;
        });
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
        // em ambientes com múltiplas instâncias ou serverless
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
                                $this->logger->error('Error processing scheduled withdraw (parallel)', [
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
                            $this->logger->error('Error processing scheduled withdraw', [
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
}

