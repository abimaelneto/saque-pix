<?php

declare(strict_types=1);

namespace App\Service;

use App\Job\ProcessScheduledWithdrawsJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Psr\Log\LoggerInterface;

/**
 * Service para gerenciar filas assíncronas
 * 
 * Permite processamento assíncrono de operações
 * Compatível com SQS (AWS), Redis Queue, etc.
 */
class QueueService
{
    public function __construct(
        private DriverFactory $driverFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Enfileira processamento de saques agendados
     */
    public function queueScheduledWithdrawsProcessing(): bool
    {
        try {
            $driver = $this->driverFactory->get('default');
            $job = new ProcessScheduledWithdrawsJob();
            
            $driver->push($job);
            
            $this->logger->info('Scheduled withdraws processing queued');
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to queue scheduled withdraws processing', [
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Enfileira processamento de saque específico
     */
    public function queueWithdrawProcessing(string $withdrawId): bool
    {
        try {
            $driver = $this->driverFactory->get('default');
            $job = new ProcessScheduledWithdrawsJob($withdrawId);
            
            $driver->push($job);
            
            $this->logger->info('Withdraw processing queued', [
                'withdraw_id' => $withdrawId,
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to queue withdraw processing', [
                'withdraw_id' => $withdrawId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
}

