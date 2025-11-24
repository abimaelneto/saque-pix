<?php

declare(strict_types=1);

namespace App\Command;

use App\DTO\WithdrawRequestDTO;
use App\Service\WithdrawService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class WithdrawCreateCommand extends HyperfCommand
{
    public function __construct(
        protected ContainerInterface $container,
        protected WithdrawService $withdrawService,
    ) {
        parent::__construct('withdraw:create');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Criar um saque PIX');
        $this->addArgument('account-id', InputArgument::REQUIRED, 'ID da conta (UUID)');
        $this->addOption('amount', 'a', InputOption::VALUE_REQUIRED, 'Valor do saque', null);
        $this->addOption('pix-key', 'k', InputOption::VALUE_REQUIRED, 'Chave PIX (email)', null);
        $this->addOption('schedule', 's', InputOption::VALUE_OPTIONAL, 'Data/hora para agendamento (Y-m-d H:i)', null);
    }

    public function handle()
    {
        $accountId = $this->input->getArgument('account-id');
        $amount = $this->input->getOption('amount');
        $pixKey = $this->input->getOption('pix-key');
        $schedule = $this->input->getOption('schedule');

        if (!$amount || !$pixKey) {
            $this->error('❌ --amount e --pix-key são obrigatórios');
            $this->line('Exemplo: php bin/hyperf.php withdraw:create {account-id} --amount=100.00 --pix-key=usuario@email.com');
            return 1;
        }

        try {
            $dto = new WithdrawRequestDTO(
                accountId: $accountId,
                method: 'PIX',
                pixType: 'email',
                pixKey: $pixKey,
                amount: $amount,
                schedule: $schedule,
            );

            $withdraw = $this->withdrawService->createWithdraw($dto);

            $this->info("✅ Saque criado com sucesso!");
            $this->line("ID: {$withdraw->id}");
            $this->line("Conta: {$withdraw->account_id}");
            $this->line("Valor: R$ " . number_format((float) $withdraw->amount, 2, ',', '.'));
            $this->line("Agendado: " . ($withdraw->scheduled ? 'Sim' : 'Não'));
            
            if ($withdraw->scheduled) {
                $this->line("Agendado para: " . $withdraw->scheduled_for?->format('d/m/Y H:i:s'));
            }
            
            $this->line("Status: " . ($withdraw->done ? '✅ Processado' : ($withdraw->error ? '❌ Erro' : '⏳ Pendente')));
            
            if ($withdraw->error && $withdraw->error_reason) {
                $this->warn("Motivo do erro: {$withdraw->error_reason}");
            }

            return 0;
        } catch (\InvalidArgumentException $e) {
            $this->error("❌ Erro de validação: " . $e->getMessage());
            return 1;
        } catch (\Exception $e) {
            $this->error("❌ Erro ao criar saque: " . $e->getMessage());
            return 1;
        }
    }
}

