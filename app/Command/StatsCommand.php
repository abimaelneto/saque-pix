<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Account;
use App\Model\AccountWithdraw;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;

#[Command]
class StatsCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('stats');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Exibir estatísticas do sistema');
    }

    public function handle()
    {
        $totalAccounts = Account::count();
        $totalWithdraws = AccountWithdraw::count();
        $totalScheduled = AccountWithdraw::where('scheduled', true)->count();
        $totalProcessed = AccountWithdraw::where('done', true)->count();
        $totalErrors = AccountWithdraw::where('error', true)->count();
        $totalPending = AccountWithdraw::where('scheduled', true)
            ->where('done', false)
            ->count();
        
        $totalAmount = AccountWithdraw::where('done', true)->sum('amount');
        $avgAmount = AccountWithdraw::where('done', true)->avg('amount');

        $this->info('📊 Estatísticas do Sistema');
        $this->line('');
        
        $this->line("📁 Contas: {$totalAccounts}");
        $this->line('');
        
        $this->line("💰 Saques:");
        $this->line("   Total: {$totalWithdraws}");
        $this->line("   Agendados: {$totalScheduled}");
        $this->line("   Processados: {$totalProcessed}");
        $this->line("   Pendentes: {$totalPending}");
        $this->line("   Erros: {$totalErrors}");
        $this->line('');
        
        $this->line("💵 Valores:");
        $this->line("   Total Processado: R$ " . number_format((float) $totalAmount, 2, ',', '.'));
        if ($avgAmount) {
            $this->line("   Média por Saque: R$ " . number_format((float) $avgAmount, 2, ',', '.'));
        }

        return 0;
    }
}

