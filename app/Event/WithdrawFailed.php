<?php

declare(strict_types=1);

namespace App\Event;

use App\Model\AccountWithdraw;

/**
 * Evento disparado quando um saque falha no processamento
 */
class WithdrawFailed
{
    public function __construct(
        public readonly AccountWithdraw $withdraw,
        public readonly string $reason,
        public readonly ?\Throwable $exception = null,
    ) {
    }
}

