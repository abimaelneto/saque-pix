<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\AccountWithdraw;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class WithdrawListCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('withdraw:list');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Listar saques');
        $this->addOption('pending', 'p', InputOption::VALUE_NONE, 'Apenas saques agendados pendentes');
        $this->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limite de resultados', '20');
    }

    public function handle()
    {
        $pending = $this->input->getOption('pending');
        $limit = (int) $this->input->getOption('limit');

        if ($pending) {
            $withdraws = AccountWithdraw::with('pix')
                ->where('scheduled', true)
                ->where('done', false)
                ->orderBy('scheduled_for', 'asc')
                ->limit($limit)
                ->get();
            
            $this->info("📅 Saques Agendados Pendentes:");
        } else {
            $withdraws = AccountWithdraw::with('pix')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
            
            $this->info("💰 Saques Recentes:");
        }

        if ($withdraws->isEmpty()) {
            $this->warn('Nenhum saque encontrado.');
            return 0;
        }

        $table = new Table($this->output);
        $table->setHeaders(['ID', 'Conta', 'Valor', 'Status', 'Agendado', 'PIX', 'Criado em']);

        foreach ($withdraws as $withdraw) {
            $status = match (true) {
                $withdraw->done => '✅ Processado',
                $withdraw->error => '❌ Erro',
                default => '⏳ Pendente',
            };

            $scheduled = $withdraw->scheduled 
                ? ($withdraw->scheduled_for?->format('d/m/Y H:i') ?? '-')
                : 'Não';

            $table->addRow([
                substr($withdraw->id, 0, 8) . '...',
                substr($withdraw->account_id, 0, 8) . '...',
                'R$ ' . number_format((float) $withdraw->amount, 2, ',', '.'),
                $status,
                $scheduled,
                $withdraw->pix?->key ?? '-',
                $withdraw->created_at?->format('d/m/Y H:i') ?? '-',
            ]);
        }

        $table->render();
        $this->info("Total: {$withdraws->count()} saque(s)");

        return 0;
    }
}

