<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WithdrawService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class WithdrawProcessCommand extends HyperfCommand
{
    public function __construct(
        protected ContainerInterface $container,
        protected WithdrawService $withdrawService,
    ) {
        parent::__construct('withdraw:process');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Processar saques agendados pendentes');
        $this->addOption('update-past', 'u', InputOption::VALUE_NONE, 'Atualizar saques agendados para o passado antes de processar');
    }

    public function handle()
    {
        $updatePast = $this->input->getOption('update-past');

        if ($updatePast) {
            $this->info('🔄 Atualizando saques agendados para o passado...');
            
            $updated = Db::statement("
                UPDATE account_withdraw 
                SET scheduled_for = DATE_SUB(NOW(), INTERVAL 1 HOUR)
                WHERE scheduled = TRUE AND done = FALSE
            ");
            
            $count = Db::selectOne("
                SELECT COUNT(*) as count 
                FROM account_withdraw 
                WHERE scheduled = TRUE AND done = FALSE
            ");
            
            $this->info("✅ {$count->count} saque(s) atualizado(s) para o passado");
        }

        $this->info('🔄 Processando saques agendados...');
        
        $startTime = microtime(true);
        $processed = $this->withdrawService->processScheduledWithdraws();
        $duration = microtime(true) - $startTime;

        if ($processed > 0) {
            $this->info("✅ {$processed} saque(s) processado(s) com sucesso!");
            $this->line("⏱️  Tempo: " . number_format($duration, 2) . "s");
        } else {
            $this->warn("⚠️  Nenhum saque agendado pendente para processar.");
        }

        return 0;
    }
}

