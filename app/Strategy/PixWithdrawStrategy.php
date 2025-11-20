<?php

declare(strict_types=1);

namespace App\Strategy;

use App\DTO\WithdrawRequestDTO;
use App\Model\AccountWithdraw;
use App\Model\AccountWithdrawPix;
use App\Repository\AccountWithdrawPixRepository;
use Psr\Log\LoggerInterface;

/**
 * Implementação do Strategy para saques PIX
 */
class PixWithdrawStrategy implements WithdrawMethodStrategy
{
    public function __construct(
        private AccountWithdrawPixRepository $pixRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function validate(WithdrawRequestDTO $dto): void
    {
        // Validar tipo de chave PIX
        if (!in_array($dto->pixType, ['email', 'cpf', 'phone', 'random'])) {
            throw new \InvalidArgumentException("Invalid PIX type: {$dto->pixType}");
        }

        // Validar formato da chave PIX baseado no tipo
        $this->validatePixKey($dto->pixType, $dto->pixKey);
    }

    public function process(AccountWithdraw $withdraw): bool
    {
        try {
            // Verificar se dados PIX já existem
            $pix = $withdraw->pix;
            
            if (!$pix) {
                $this->logger->error('PIX data not found for withdraw', [
                    'withdraw_id' => $withdraw->id,
                ]);
                return false;
            }

            // Em um sistema real, aqui faria a integração com o provedor PIX
            // Por enquanto, apenas logamos que o PIX foi processado
            $this->logger->info('PIX withdraw processed', [
                'withdraw_id' => $withdraw->id,
                'pix_type' => $pix->type,
                'pix_key' => $pix->key,
                'amount' => $withdraw->amount,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error processing PIX withdraw', [
                'withdraw_id' => $withdraw->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getMethodName(): string
    {
        return 'PIX';
    }

    /**
     * Valida o formato da chave PIX baseado no tipo
     */
    private function validatePixKey(string $type, string $key): void
    {
        switch ($type) {
            case 'email':
                if (!filter_var($key, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException("Invalid email format for PIX key: {$key}");
                }
                break;

            case 'cpf':
                // Remover formatação
                $cpf = preg_replace('/[^0-9]/', '', $key);
                if (strlen($cpf) !== 11 || !$this->isValidCpf($cpf)) {
                    throw new \InvalidArgumentException("Invalid CPF format for PIX key: {$key}");
                }
                break;

            case 'phone':
                // Remover formatação
                $phone = preg_replace('/[^0-9]/', '', $key);
                if (strlen($phone) < 10 || strlen($phone) > 11) {
                    throw new \InvalidArgumentException("Invalid phone format for PIX key: {$key}");
                }
                break;

            case 'random':
                // Chave aleatória UUID
                if (!\Ramsey\Uuid\Uuid::isValid($key)) {
                    throw new \InvalidArgumentException("Invalid random key format (must be UUID): {$key}");
                }
                break;

            default:
                throw new \InvalidArgumentException("Unknown PIX type: {$type}");
        }
    }

    /**
     * Valida CPF usando algoritmo de validação
     */
    private function isValidCpf(string $cpf): bool
    {
        // Verificar se todos os dígitos são iguais
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        // Validar dígitos verificadores
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += (int) $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ((int) $cpf[$c] !== $d) {
                return false;
            }
        }

        return true;
    }
}

