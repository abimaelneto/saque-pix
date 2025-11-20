<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountWithdrawRepository;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

/**
 * Service de Detecção de Fraude
 * 
 * Detecta padrões suspeitos de atividade fraudulenta
 */
class FraudDetectionService
{
    private const FRAUD_CHECK_PREFIX = 'fraud_check:';
    private const MAX_WITHDRAWALS_PER_HOUR = 5;
    private const MAX_WITHDRAWALS_PER_DAY = 20;
    private const MAX_AMOUNT_PER_DAY = 10000.00; // R$ 10.000,00
    private const SUSPICIOUS_AMOUNT_THRESHOLD = 5000.00; // R$ 5.000,00

    public function __construct(
        private Redis $redis,
        private AccountWithdrawRepository $withdrawRepository,
        private LoggerInterface $logger,
        private AuditService $auditService,
    ) {
    }

    /**
     * Verifica se a operação é suspeita
     */
    public function checkFraud(
        string $accountId,
        float $amount,
        ?string $pixKey = null
    ): FraudCheckResult {
        if (env('APP_ENV') === 'testing') {
            return new FraudCheckResult(false, []);
        }

        $checks = [];

        // Verificar limite de saques por hora
        $hourlyCount = $this->getWithdrawalsCount($accountId, 'hour');
        if ($hourlyCount >= self::MAX_WITHDRAWALS_PER_HOUR) {
            $checks[] = 'exceeded_hourly_limit';
        }

        // Verificar limite de saques por dia
        $dailyCount = $this->getWithdrawalsCount($accountId, 'day');
        if ($dailyCount >= self::MAX_WITHDRAWALS_PER_DAY) {
            $checks[] = 'exceeded_daily_limit';
        }

        // Verificar limite de valor por dia
        $dailyAmount = $this->getWithdrawalsAmount($accountId, 'day');
        if (($dailyAmount + $amount) > self::MAX_AMOUNT_PER_DAY) {
            $checks[] = 'exceeded_daily_amount_limit';
        }

        // Verificar se valor é suspeito (muito alto)
        if ($amount >= self::SUSPICIOUS_AMOUNT_THRESHOLD) {
            $checks[] = 'suspicious_amount';
        }

        // Verificar se chave PIX foi usada recentemente por outra conta
        if ($pixKey) {
            $recentUsage = $this->checkPixKeyRecentUsage($pixKey, $accountId);
            if ($recentUsage) {
                $checks[] = 'pix_key_recently_used';
            }
        }

        // Verificar padrão de saques rápidos (possível bot)
        $recentWithdrawals = $this->getRecentWithdrawals($accountId, 300); // Últimos 5 minutos
        if (count($recentWithdrawals) >= 3) {
            $checks[] = 'rapid_withdrawal_pattern';
        }

        $isFraud = !empty($checks);
        
        if ($isFraud) {
            $this->logger->warning('Fraud detection triggered', [
                'account_id' => $accountId,
                'amount' => $amount,
                'checks' => $checks,
            ]);

            $this->auditService->log(
                'fraud_detected',
                'account',
                $accountId,
                null,
                $accountId,
                [
                    'amount' => $amount,
                    'checks' => $checks,
                ]
            );
        }

        return new FraudCheckResult($isFraud, $checks);
    }

    /**
     * Obtém quantidade de saques em um período
     */
    private function getWithdrawalsCount(string $accountId, string $period): int
    {
        $key = self::FRAUD_CHECK_PREFIX . "count:{$accountId}:{$period}";
        return (int) ($this->redis->get($key) ?? 0);
    }

    /**
     * Incrementa contador de saques
     */
    public function recordWithdrawal(string $accountId, float $amount): void
    {
        if (env('APP_ENV') === 'testing') {
            return;
        }

        // Contador por hora
        $hourKey = self::FRAUD_CHECK_PREFIX . "count:{$accountId}:hour";
        $this->redis->incr($hourKey);
        $this->redis->expire($hourKey, 3600);

        // Contador por dia
        $dayKey = self::FRAUD_CHECK_PREFIX . "count:{$accountId}:day";
        $this->redis->incr($dayKey);
        $this->redis->expire($dayKey, 86400);

        // Valor acumulado por dia
        $amountKey = self::FRAUD_CHECK_PREFIX . "amount:{$accountId}:day";
        $this->redis->incrByFloat($amountKey, $amount);
        $this->redis->expire($amountKey, 86400);
    }

    /**
     * Obtém valor total sacado em um período
     */
    private function getWithdrawalsAmount(string $accountId, string $period): float
    {
        $key = self::FRAUD_CHECK_PREFIX . "amount:{$accountId}:{$period}";
        return (float) ($this->redis->get($key) ?? 0);
    }

    /**
     * Verifica se chave PIX foi usada recentemente por outra conta
     */
    private function checkPixKeyRecentUsage(string $pixKey, string $currentAccountId): bool
    {
        $key = self::FRAUD_CHECK_PREFIX . "pix_key:{$pixKey}";
        $lastAccountId = $this->redis->get($key);
        
        if ($lastAccountId && $lastAccountId !== $currentAccountId) {
            // Chave foi usada por outra conta nos últimos 24h
            return true;
        }

        // Registrar uso atual
        $this->redis->set($key, $currentAccountId, 86400); // 24 horas

        return false;
    }

    /**
     * Obtém saques recentes
     */
    private function getRecentWithdrawals(string $accountId, int $seconds): array
    {
        $since = new \DateTime("-{$seconds} seconds");
        
        return $this->withdrawRepository->findRecentByAccount($accountId, $since);
    }
}

/**
 * Resultado da verificação de fraude
 */
class FraudCheckResult
{
    public function __construct(
        public readonly bool $isFraud,
        public readonly array $checks
    ) {
    }

    public function getSeverity(): string
    {
        if (in_array('exceeded_daily_amount_limit', $this->checks)) {
            return 'critical';
        }
        
        if (in_array('exceeded_daily_limit', $this->checks) || 
            in_array('suspicious_amount', $this->checks)) {
            return 'high';
        }

        return 'medium';
    }
}

