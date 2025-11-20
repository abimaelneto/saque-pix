<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\AccountWithdrawPix;

class AccountWithdrawPixRepository
{
    public function create(array $data): AccountWithdrawPix
    {
        return AccountWithdrawPix::create($data);
    }

    public function findByWithdrawId(string $withdrawId): ?AccountWithdrawPix
    {
        return AccountWithdrawPix::find($withdrawId);
    }
}

