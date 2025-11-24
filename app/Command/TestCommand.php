<?php

declare(strict_types=1);

namespace App\Command;

use App\DTO\WithdrawRequestDTO;
use App\Model\Account;
use App\Service\WithdrawService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Psr\Container\ContainerInterface;

#[Command]
class TestCommand extends HyperfCommand
{
    public function __construct(
        protected ContainerInterface $container,
        protected WithdrawService $withdrawService,
    ) {
        parent::__construct('test:manual');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Executar testes manuais guiados');
    }

    public function handle()
    {
        $this->info('🧪 Testes Manuais - Saque PIX');
        $this->line('');
        $this->line('Este comando guiará você através dos principais testes do sistema.');
        $this->line('');

        // Teste 1: Criar conta
        $this->info('📝 Teste 1: Criar Conta');
        $name = $this->ask('Nome da conta', 'Conta de Teste');
        $balance = (float) $this->ask('Saldo inicial', '1000.00');
        
        try {
            $account = new Account();
            $account->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
            $account->name = $name;
            $account->balance = (string) $balance;
            $account->save();
            
            $this->info("✅ Conta criada: {$account->id}");
            $accountId = $account->id;
        } catch (\Exception $e) {
            $this->error("❌ Erro: " . $e->getMessage());
            return 1;
        }

        $this->line('');

        // Teste 2: Saque imediato
        $this->info('💰 Teste 2: Saque Imediato');
        if ($this->confirm('Deseja criar um saque imediato?', true)) {
            $amount = $this->ask('Valor do saque', '100.00');
            $pixKey = $this->ask('Chave PIX (email)', 'teste@email.com');
            
            try {
                $dto = new WithdrawRequestDTO(
                    accountId: $accountId,
                    method: 'PIX',
                    pixType: 'email',
                    pixKey: $pixKey,
                    amount: $amount,
                    schedule: null,
                );
                
                $withdraw = $this->withdrawService->createWithdraw($dto);
                $this->info("✅ Saque criado e processado: {$withdraw->id}");
                $this->line("   Verifique o email no Mailhog: http://localhost:8025");
            } catch (\Exception $e) {
                $this->error("❌ Erro: " . $e->getMessage());
            }
        }

        $this->line('');

        // Teste 3: Saque agendado
        $this->info('📅 Teste 3: Saque Agendado');
        if ($this->confirm('Deseja criar um saque agendado?', true)) {
            $amount = $this->ask('Valor do saque', '200.00');
            $pixKey = $this->ask('Chave PIX (email)', 'agendado@email.com');
            
            try {
                $dto = new WithdrawRequestDTO(
                    accountId: $accountId,
                    method: 'PIX',
                    pixType: 'email',
                    pixKey: $pixKey,
                    amount: $amount,
                    schedule: (new \DateTime())->modify('+1 hour')->format('Y-m-d H:i'),
                );
                
                $withdraw = $this->withdrawService->createWithdraw($dto);
                $this->info("✅ Saque agendado criado: {$withdraw->id}");
                $this->line("   Agendado para: " . $withdraw->scheduled_for?->format('d/m/Y H:i:s'));
                
                if ($this->confirm('Deseja processar o saque agendado agora?', true)) {
                    // Atualizar para o passado
                    Db::statement("
                        UPDATE account_withdraw 
                        SET scheduled_for = DATE_SUB(NOW(), INTERVAL 1 HOUR)
                        WHERE id = '{$withdraw->id}'
                    ");
                    
                    $processed = $this->withdrawService->processScheduledWithdraws();
                    $this->info("✅ {$processed} saque(s) processado(s)");
                    $this->line("   Verifique o email no Mailhog: http://localhost:8025");
                }
            } catch (\Exception $e) {
                $this->error("❌ Erro: " . $e->getMessage());
            }
        }

        $this->line('');
        $this->info('✅ Testes concluídos!');
        $this->line('');
        $this->line('📚 Para mais testes, consulte: TESTES-MANUAIS.md');
        $this->line('📊 Para ver estatísticas: php bin/hyperf.php stats');
        $this->line('📈 Para ver métricas: php bin/hyperf.php metrics');

        return 0;
    }
}

