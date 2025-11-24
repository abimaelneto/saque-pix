<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Context\Context;
use Psr\Log\LoggerInterface;

/**
 * Service de Auditoria
 * 
 * Registra todas as operações críticas para compliance e investigação
 * Essencial para sistemas financeiros (LGPD, PCI-DSS, etc.)
 */
class AuditService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Registra operação de auditoria
     */
    public function log(
        string $action,
        string $entityType,
        string $entityId,
        ?string $userId = null,
        ?string $accountId = null,
        array $metadata = []
    ): void {
        // Obter correlation_id do contexto
        $correlationId = Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY);
        
        $auditData = [
            'timestamp' => date('c'),
            'correlation_id' => $correlationId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'account_id' => $accountId,
            'ip_address' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'metadata' => $metadata,
        ];

        // Log estruturado para fácil parsing
        $this->logger->info('AUDIT', $auditData);

        // Em produção, também salvar em tabela de auditoria para compliance
        // $this->saveToAuditTable($auditData);
    }

    /**
     * Registra criação de saque
     */
    public function logWithdrawCreated(
        string $withdrawId,
        string $accountId,
        string $amount,
        bool $scheduled,
        ?string $userId = null
    ): void {
        $this->log(
            'withdraw_created',
            'withdraw',
            $withdrawId,
            $userId,
            $accountId,
            [
                'amount' => $amount,
                'scheduled' => $scheduled,
            ]
        );
    }

    /**
     * Registra processamento de saque
     */
    public function logWithdrawProcessed(
        string $withdrawId,
        string $accountId,
        bool $success,
        ?string $errorReason = null,
        ?string $userId = null
    ): void {
        $this->log(
            'withdraw_processed',
            'withdraw',
            $withdrawId,
            $userId,
            $accountId,
            [
                'success' => $success,
                'error_reason' => $errorReason,
            ]
        );
    }

    /**
     * Registra cancelamento de saque agendado
     */
    public function logWithdrawCancelled(
        string $withdrawId,
        string $accountId,
        ?string $userId = null
    ): void {
        $this->log(
            'withdraw_cancelled',
            'withdraw',
            $withdrawId,
            $userId,
            $accountId,
            []
        );
    }

    /**
     * Registra tentativa de acesso não autorizado
     */
    public function logUnauthorizedAccess(
        string $resource,
        ?string $userId = null,
        ?string $accountId = null
    ): void {
        $this->log(
            'unauthorized_access',
            'security',
            $resource,
            $userId,
            $accountId,
            [
                'severity' => 'high',
            ]
        );
    }

    /**
     * Registra violação de rate limit
     */
    public function logRateLimitExceeded(
        string $identifier,
        string $endpoint
    ): void {
        $this->log(
            'rate_limit_exceeded',
            'security',
            $identifier,
            null,
            null,
            [
                'endpoint' => $endpoint,
                'severity' => 'medium',
            ]
        );
    }

    private function getClientIp(): ?string
    {
        $headers = [
            'X-Forwarded-For',
            'X-Real-Ip',
            'CF-Connecting-Ip',
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER['HTTP_' . str_replace('-', '_', strtoupper($header))])) {
                $ip = $_SERVER['HTTP_' . str_replace('-', '_', strtoupper($header))];
                if (!empty($ip)) {
                    $ips = explode(',', $ip);
                    return trim($ips[0]);
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}

