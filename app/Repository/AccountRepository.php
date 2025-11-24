<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Account;
use Hyperf\DbConnection\Db;

class AccountRepository
{
    /**
     * Busca conta por ID
     */
    public function findById(string $id): ?Account
    {
        return Account::find($id);
    }

    /**
     * Busca conta com lock pessimista (SELECT FOR UPDATE)
     * Previne race conditions em sistemas distribuídos
     */
    public function findByIdWithLock(string $id): ?Account
    {
        return Account::where('id', $id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Deduz saldo de forma atômica
     * Retorna true se saldo foi suficiente e deduzido, false caso contrário
     */
    public function decrementBalanceIfSufficient(string $accountId, string $amount): bool
    {
        // Usar SQL atômico para verificar e deduzir em uma única operação
        // Isso previne race conditions entre verificação e dedução
        // Usar parâmetros posicionais para evitar problemas com parâmetros duplicados
        $result = Db::update("
            UPDATE account 
            SET balance = balance - ?,
                updated_at = NOW()
            WHERE id = ? 
            AND balance >= ?
        ", [
            $amount,
            $accountId,
            $amount,
        ]);

        return $result > 0;
    }

    /**
     * Deduz saldo (método legado - mantido para compatibilidade)
     * @deprecated Use decrementBalanceIfSufficient para operações críticas
     */
    public function updateBalance(string $accountId, string $amount): bool
    {
        return Account::where('id', $accountId)
            ->decrement('balance', $amount) > 0;
    }

    /**
     * Verifica se conta tem saldo suficiente
     * ATENÇÃO: Este método não previne race conditions!
     * Use decrementBalanceIfSufficient para operações críticas
     */
    public function hasSufficientBalance(string $accountId, string $amount): bool
    {
        $account = $this->findById($accountId);
        
        if (!$account) {
            return false;
        }

        return (float) $account->balance >= (float) $amount;
    }

    /**
     * Verifica saldo com lock pessimista (previne race conditions)
     */
    public function hasSufficientBalanceWithLock(string $accountId, string $amount): bool
    {
        $account = $this->findByIdWithLock($accountId);
        
        if (!$account) {
            return false;
        }

        return (float) $account->balance >= (float) $amount;
    }
}

