<?php

declare(strict_types=1);

namespace App\Listener;

use App\Event\WithdrawCreated;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener para evento de saque criado
 * 
 * Pode ser usado para notificações, webhooks, etc.
 */
#[Listener]
class WithdrawCreatedListener implements ListenerInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function listen(): array
    {
        return [
            WithdrawCreated::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof WithdrawCreated) {
            $this->handle($event);
        }
    }

    private function handle(WithdrawCreated $event): void
    {
        $withdraw = $event->withdraw;
        
        $this->logger->info('Withdraw created event received', [
            'withdraw_id' => $withdraw->id,
            'account_id' => $withdraw->account_id,
            'amount' => $withdraw->amount,
            'is_scheduled' => $event->isScheduled,
            'method' => $withdraw->method,
        ]);

        // Aqui poderia adicionar:
        // - Notificação push
        // - Webhook para sistemas externos
        // - Atualização de cache
        // - etc.
    }
}

