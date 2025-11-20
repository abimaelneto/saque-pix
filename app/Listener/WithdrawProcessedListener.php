<?php

declare(strict_types=1);

namespace App\Listener;

use App\Event\WithdrawProcessed;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener para evento de saque processado
 */
#[Listener]
class WithdrawProcessedListener implements ListenerInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function listen(): array
    {
        return [
            WithdrawProcessed::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof WithdrawProcessed) {
            $this->handle($event);
        }
    }

    private function handle(WithdrawProcessed $event): void
    {
        $withdraw = $event->withdraw;
        
        $this->logger->info('Withdraw processed event received', [
            'withdraw_id' => $withdraw->id,
            'account_id' => $withdraw->account_id,
            'amount' => $withdraw->amount,
            'processing_time_seconds' => $event->processingTimeSeconds,
        ]);

        // Aqui poderia adicionar:
        // - Notificação de sucesso ao usuário
        // - Webhook para sistemas externos
        // - Atualização de cache
        // - etc.
    }
}

