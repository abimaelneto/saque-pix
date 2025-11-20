<?php

declare(strict_types=1);

namespace App\Event;

use App\Model\AccountWithdraw;

/**
 * Evento disparado quando um saque é criado
 */
class WithdrawCreated
{
    public function __construct(
        public readonly AccountWithdraw $withdraw,
        public readonly bool $isScheduled,
    ) {
    }
}

