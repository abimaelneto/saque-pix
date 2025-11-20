<?php

declare(strict_types=1);

namespace App\Strategy;

use App\DTO\WithdrawRequestDTO;
use App\Model\AccountWithdraw;

/**
 * Strategy Pattern para métodos de saque
 * 
 * Permite extensibilidade fácil para novos métodos (PIX, TED, DOC, etc.)
 */
interface WithdrawMethodStrategy
{
    /**
     * Valida os dados específicos do método de saque
     */
    public function validate(WithdrawRequestDTO $dto): void;

    /**
     * Processa o saque usando o método específico
     * 
     * @return bool True se processado com sucesso, false caso contrário
     */
    public function process(AccountWithdraw $withdraw): bool;

    /**
     * Retorna o nome do método (ex: "PIX", "TED", "DOC")
     */
    public function getMethodName(): string;
}

