<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\WithdrawRequestDTO;
use App\Model\Account;
use App\Model\AccountWithdraw;
use App\Model\AccountWithdrawPix;
use App\Repository\AccountRepository;
use App\Repository\AccountWithdrawRepository;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class WithdrawService
{
    #[Inject]
    private AccountRepository $accountRepository;

    #[Inject]
    private AccountWithdrawRepository $withdrawRepository;

    #[Inject]
    private EmailService $emailService;

    #[Inject]
    private LoggerInterface $logger;

    public function createWithdraw(WithdrawRequestDTO $dto): AccountWithdraw
    {
        return Db::transaction(function () use ($dto) {
            // Validar conta existe
            $account = $this->accountRepository->findById($dto->accountId);
            if (!$account) {
                throw new \InvalidArgumentException('Account not found');
            }

            // Validar saldo suficiente (se não for agendado)
            if (!$dto->isScheduled()) {
                if (!$this->accountRepository->hasSufficientBalance($dto->accountId, $dto->amount)) {
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

            // Criar dados PIX
            AccountWithdrawPix::create([
                'account_withdraw_id' => $withdrawId,
                'type' => $dto->pixType,
                'key' => $dto->pixKey,
            ]);

            // Processar imediatamente se não for agendado
            if (!$dto->isScheduled()) {
                $this->processWithdraw($withdrawId);
            }

            return $this->withdrawRepository->findById($withdrawId);
        });
    }

    public function processWithdraw(string $withdrawId): bool
    {
        return Db::transaction(function () use ($withdrawId) {
            $withdraw = $this->withdrawRepository->findById($withdrawId);
            
            if (!$withdraw) {
                throw new \InvalidArgumentException('Withdraw not found');
            }

            if ($withdraw->done) {
                $this->logger->warning('Withdraw already processed', [
                    'withdraw_id' => $withdrawId,
                ]);
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

                return false;
            }

            // Deduzir saldo
            $this->accountRepository->updateBalance($withdraw->account_id, $withdraw->amount);

            // Marcar como processado
            $this->withdrawRepository->markAsDone($withdrawId);

            // Recarregar withdraw com relacionamentos
            $withdraw = $this->withdrawRepository->findById($withdrawId);

            // Enviar email
            $this->emailService->sendWithdrawNotification($withdraw);

            $this->logger->info('Withdraw processed successfully', [
                'withdraw_id' => $withdrawId,
                'account_id' => $withdraw->account_id,
                'amount' => $withdraw->amount,
            ]);

            return true;
        });
    }

    public function processScheduledWithdraws(): int
    {
        $pending = $this->withdrawRepository->findPendingScheduled();
        $processed = 0;

        foreach ($pending as $withdraw) {
            try {
                if ($this->processWithdraw($withdraw->id)) {
                    $processed++;
                }
            } catch (\Exception $e) {
                $this->logger->error('Error processing scheduled withdraw', [
                    'withdraw_id' => $withdraw->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }
}

