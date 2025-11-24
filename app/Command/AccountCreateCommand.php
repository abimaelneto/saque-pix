<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Account;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class AccountCreateCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('account:create');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Criar uma nova conta');
        $this->addArgument('name', InputArgument::REQUIRED, 'Nome da conta');
        $this->addOption('balance', 'b', InputOption::VALUE_REQUIRED, 'Saldo inicial', '0.00');
    }

    public function handle()
    {
        $name = $this->input->getArgument('name');
        $balance = (float) $this->input->getOption('balance');

        try {
            $account = new Account();
            $account->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
            $account->name = $name;
            $account->balance = (string) $balance;
            $account->save();

            $this->info("✅ Conta criada com sucesso!");
            $this->line("ID: {$account->id}");
            $this->line("Nome: {$account->name}");
            $this->line("Saldo: R$ " . number_format((float) $account->balance, 2, ',', '.'));

            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Erro ao criar conta: " . $e->getMessage());
            return 1;
        }
    }
}

