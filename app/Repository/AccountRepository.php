<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Account;
use Hyperf\DbConnection\Db;

class AccountRepository
{
    public function findById(string $id): ?Account
    {
        return Account::find($id);
    }

    public function updateBalance(string $accountId, string $amount): bool
    {
        return Account::where('id', $accountId)
            ->decrement('balance', $amount) > 0;
    }

    public function hasSufficientBalance(string $accountId, string $amount): bool
    {
        $account = $this->findById($accountId);
        
        if (!$account) {
            return false;
        }

        return (float) $account->balance >= (float) $amount;
    }
}

