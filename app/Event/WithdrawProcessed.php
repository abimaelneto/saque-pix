<?php

declare(strict_types=1);

namespace App\Event;

use App\Model\AccountWithdraw;

/**
 * Evento disparado quando um saque é processado com sucesso
 */
class WithdrawProcessed
{
    public function __construct(
        public readonly AccountWithdraw $withdraw,
        public readonly float $processingTimeSeconds,
    ) {
    }
}

