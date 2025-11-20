<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\AccountWithdraw;
use Hyperf\DbConnection\Db;

class AccountWithdrawRepository
{
    public function create(array $data): AccountWithdraw
    {
        return AccountWithdraw::create($data);
    }

    public function findById(string $id): ?AccountWithdraw
    {
        return AccountWithdraw::with(['account', 'pix'])->find($id);
    }

    public function findPendingScheduled(): \Hyperf\Database\Model\Collection
    {
        return AccountWithdraw::where('scheduled', true)
            ->where('done', false)
            ->where('error', false)
            ->where('scheduled_for', '<=', new \DateTime())
            ->with(['account', 'pix'])
            ->get();
    }

    public function markAsDone(string $id, ?\DateTime $processedAt = null): bool
    {
        $data = [
            'done' => true,
            'processed_at' => $processedAt ?? new \DateTime(),
        ];

        return AccountWithdraw::where('id', $id)->update($data) > 0;
    }

    public function markAsError(string $id, string $reason, ?\DateTime $processedAt = null): bool
    {
        $data = [
            'done' => true,
            'error' => true,
            'error_reason' => $reason,
            'processed_at' => $processedAt ?? new \DateTime(),
        ];

        return AccountWithdraw::where('id', $id)->update($data) > 0;
    }

    /**
     * Encontra saques recentes de uma conta
     */
    public function findRecentByAccount(string $accountId, \DateTime $since): array
    {
        return AccountWithdraw::where('account_id', $accountId)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }
}
