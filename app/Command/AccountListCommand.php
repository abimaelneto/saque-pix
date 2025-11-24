<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Account;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Helper\Table;

#[Command]
class AccountListCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('account:list');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Listar todas as contas');
    }

    public function handle()
    {
        $accounts = Account::orderBy('created_at', 'desc')->limit(50)->get();

        if ($accounts->isEmpty()) {
            $this->warn('Nenhuma conta encontrada.');
            return 0;
        }

        $table = new Table($this->output);
        $table->setHeaders(['ID', 'Nome', 'Saldo', 'Criado em']);

        foreach ($accounts as $account) {
            $table->addRow([
                substr($account->id, 0, 8) . '...',
                $account->name,
                'R$ ' . number_format((float) $account->balance, 2, ',', '.'),
                $account->created_at?->format('Y-m-d H:i:s') ?? '-',
            ]);
        }

        $table->render();
        $this->info("Total: {$accounts->count()} conta(s)");

        return 0;
    }
}

