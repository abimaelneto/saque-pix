<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\WithdrawService;
use Hyperf\AsyncQueue\Job;

/**
 * Job assíncrono para processar saques agendados
 * 
 * Permite processamento assíncrono via queue system
 * Compatível com serverless (SQS, etc.)
 */
class ProcessScheduledWithdrawsJob extends Job
{
    public function __construct(
        private ?string $withdrawId = null, // Se null, processa todos pendentes
    ) {
    }

    public function handle(): void
    {
        $container = \Hyperf\Context\ApplicationContext::getContainer();
        $withdrawService = $container->get(WithdrawService::class);
        
        if ($this->withdrawId) {
            // Processar saque específico
            $withdrawService->processWithdraw($this->withdrawId);
        } else {
            // Processar todos os pendentes
            $withdrawService->processScheduledWithdraws();
        }
    }
}

