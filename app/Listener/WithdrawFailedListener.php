<?php

declare(strict_types=1);

namespace App\Listener;

use App\Event\WithdrawFailed;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener para evento de saque falhado
 */
#[Listener]
class WithdrawFailedListener implements ListenerInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function listen(): array
    {
        return [
            WithdrawFailed::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof WithdrawFailed) {
            $this->handle($event);
        }
    }

    private function handle(WithdrawFailed $event): void
    {
        $withdraw = $event->withdraw;
        
        $this->logger->error('Withdraw failed event received', [
            'withdraw_id' => $withdraw->id,
            'account_id' => $withdraw->account_id,
            'amount' => $withdraw->amount,
            'reason' => $event->reason,
            'exception' => $event->exception?->getMessage(),
        ]);

        // Aqui poderia adicionar:
        // - Notificação de erro ao usuário
        // - Alertas para equipe de suporte
        // - Retry logic
        // - etc.
    }
}

