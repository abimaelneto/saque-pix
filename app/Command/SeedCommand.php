<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Account;
use App\Model\AccountWithdraw;
use App\Model\AccountWithdrawPix;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

#[Command]
class SeedCommand extends HyperfCommand
{
    public function __construct(
        protected ContainerInterface $container,
    ) {
        parent::__construct('db:seed');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Popular banco de dados com dados de exemplo para observabilidade');
    }

    public function handle()
    {
        $this->info('🌱 Populando banco de dados com dados de exemplo...');
        $this->line('');

        // Limpar dados existentes (opcional)
        if ($this->confirm('Deseja limpar dados existentes antes de popular?', false)) {
            $this->info('🧹 Limpando dados existentes...');
            $this->cleanDatabase();
            $this->line('');
        }

        // Criar contas
        $this->info('📝 Criando contas...');
        $accounts = $this->createAccounts();
        $this->info("✅ {$accounts->count()} contas criadas");
        $this->line('');

        // Criar saques imediatos (sucessos)
        $this->info('💰 Criando saques imediatos (sucessos)...');
        $immediateSuccess = $this->createImmediateWithdraws($accounts, true);
        $this->info("✅ {$immediateSuccess} saques imediatos criados");
        $this->line('');

        // Criar saques imediatos (erros)
        $this->info('❌ Criando saques imediatos (erros - saldo insuficiente)...');
        $immediateErrors = $this->createImmediateWithdraws($accounts, false);
        $this->info("✅ {$immediateErrors} saques com erro criados");
        $this->line('');

        // Criar saques agendados (processados)
        $this->info('📅 Criando saques agendados (processados)...');
        $scheduledProcessed = $this->createScheduledWithdraws($accounts, true);
        $this->info("✅ {$scheduledProcessed} saques agendados processados criados");
        $this->line('');

        // Criar saques agendados (pendentes)
        $this->info('⏳ Criando saques agendados (pendentes)...');
        $scheduledPending = $this->createScheduledWithdraws($accounts, false);
        $this->info("✅ {$scheduledPending} saques agendados pendentes criados");
        $this->line('');

        // Criar saques agendados (erros)
        $this->info('⚠️ Criando saques agendados (erros)...');
        $scheduledErrors = $this->createScheduledWithdraws($accounts, false, true);
        $this->info("✅ {$scheduledErrors} saques agendados com erro criados");
        $this->line('');

        // Resumo
        $this->info('📊 Resumo:');
        $this->line("   Contas: " . count($accounts));
        $this->line("   Saques imediatos (sucesso): {$immediateSuccess}");
        $this->line("   Saques imediatos (erro): {$immediateErrors}");
        $this->line("   Saques agendados (processados): {$scheduledProcessed}");
        $this->line("   Saques agendados (pendentes): {$scheduledPending}");
        $this->line("   Saques agendados (erro): {$scheduledErrors}");
        $this->line('');
        $this->info('✅ Seed concluído! Agora você pode verificar os gráficos de observabilidade.');
        $this->line('');
        $this->line('💡 Dica: Acesse Grafana em http://localhost:3000 para visualizar as métricas');

        return 0;
    }

    private function cleanDatabase(): void
    {
        Db::statement('SET FOREIGN_KEY_CHECKS = 0');
        Db::table('account_withdraw_pix')->truncate();
        Db::table('account_withdraw')->truncate();
        Db::table('account')->truncate();
        Db::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function createAccounts(): array
    {
        $accountsData = [
            ['name' => 'João Silva', 'balance' => '5000.00'],
            ['name' => 'Maria Santos', 'balance' => '10000.00'],
            ['name' => 'Pedro Oliveira', 'balance' => '2500.00'],
            ['name' => 'Ana Costa', 'balance' => '7500.00'],
            ['name' => 'Carlos Ferreira', 'balance' => '15000.00'],
            ['name' => 'Julia Almeida', 'balance' => '3000.00'],
            ['name' => 'Roberto Lima', 'balance' => '8000.00'],
            ['name' => 'Fernanda Souza', 'balance' => '12000.00'],
            ['name' => 'Lucas Martins', 'balance' => '6000.00'],
            ['name' => 'Patricia Rocha', 'balance' => '9000.00'],
        ];

        $created = [];

        foreach ($accountsData as $accountData) {
            $account = new Account();
            $account->id = Uuid::uuid4()->toString();
            $account->name = $accountData['name'];
            $account->balance = $accountData['balance'];
            $account->save();
            $created[] = $account;
        }

        return $created;
    }

    private function createImmediateWithdraws(array $accounts, bool $success): int
    {
        $count = 0;
        $now = new \DateTime();

        // Criar saques nas últimas 24 horas
        for ($i = 0; $i < 50; $i++) {
            $account = $accounts[array_rand($accounts)];
            $hoursAgo = rand(0, 23);
            $minutesAgo = rand(0, 59);
            $createdAt = (clone $now)->modify("-{$hoursAgo} hours")->modify("-{$minutesAgo} minutes");

            $amount = (string) (rand(10, 500) + (rand(0, 99) / 100));

            if ($success) {
                // Saque bem-sucedido
                $withdraw = $this->createWithdraw([
                    'account_id' => $account->id,
                    'method' => 'PIX',
                    'amount' => $amount,
                    'scheduled' => false,
                    'done' => true,
                    'error' => false,
                    'processed_at' => (clone $createdAt)->modify('+1 second'),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $this->createPixData($withdraw->id, $createdAt);
                $count++;
            } else {
                // Saque com erro (saldo insuficiente)
                // Criar saque com valor maior que o saldo
                $amount = (string) ((float) $account->balance + 100);

                $withdraw = $this->createWithdraw([
                    'account_id' => $account->id,
                    'method' => 'PIX',
                    'amount' => $amount,
                    'scheduled' => false,
                    'done' => true,
                    'error' => true,
                    'error_reason' => 'Insufficient balance',
                    'processed_at' => (clone $createdAt)->modify('+1 second'),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $this->createPixData($withdraw->id, $createdAt);
                $count++;
            }
        }

        return $count;
    }

    private function createScheduledWithdraws(array $accounts, bool $processed, bool $withError = false): int
    {
        $count = 0;
        $now = new \DateTime();

        for ($i = 0; $i < 30; $i++) {
            $account = $accounts[array_rand($accounts)];
            $amount = (string) (rand(50, 1000) + (rand(0, 99) / 100));

            if ($processed) {
                // Saque agendado já processado
                $scheduledFor = (clone $now)->modify('-' . rand(1, 7) . ' days')->modify('+' . rand(0, 23) . ' hours');
                $createdAt = (clone $scheduledFor)->modify('-1 day');
                $processedAt = (clone $scheduledFor)->modify('+1 minute');

                $withdraw = $this->createWithdraw([
                    'account_id' => $account->id,
                    'method' => 'PIX',
                    'amount' => $amount,
                    'scheduled' => true,
                    'scheduled_for' => $scheduledFor,
                    'done' => true,
                    'error' => false,
                    'processed_at' => $processedAt,
                    'created_at' => $createdAt,
                    'updated_at' => $processedAt,
                ]);

                $this->createPixData($withdraw->id, $createdAt);
                $count++;
            } elseif ($withError) {
                // Saque agendado com erro
                $scheduledFor = (clone $now)->modify('-' . rand(1, 3) . ' days')->modify('+' . rand(0, 23) . ' hours');
                $createdAt = (clone $scheduledFor)->modify('-1 day');
                $processedAt = (clone $scheduledFor)->modify('+1 minute');

                $withdraw = $this->createWithdraw([
                    'account_id' => $account->id,
                    'method' => 'PIX',
                    'amount' => $amount,
                    'scheduled' => true,
                    'scheduled_for' => $scheduledFor,
                    'done' => true,
                    'error' => true,
                    'error_reason' => 'Insufficient balance at processing time',
                    'processed_at' => $processedAt,
                    'created_at' => $createdAt,
                    'updated_at' => $processedAt,
                ]);

                $this->createPixData($withdraw->id, $createdAt);
                $count++;
            } else {
                // Saque agendado pendente
                $scheduledFor = (clone $now)->modify('+' . rand(1, 7) . ' days')->modify('+' . rand(0, 23) . ' hours');
                $createdAt = (clone $now)->modify('-' . rand(0, 2) . ' days');

                $withdraw = $this->createWithdraw([
                    'account_id' => $account->id,
                    'method' => 'PIX',
                    'amount' => $amount,
                    'scheduled' => true,
                    'scheduled_for' => $scheduledFor,
                    'done' => false,
                    'error' => false,
                    'processed_at' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $this->createPixData($withdraw->id, $createdAt);
                $count++;
            }
        }

        return $count;
    }

    private function createWithdraw(array $data): AccountWithdraw
    {
        $withdraw = new AccountWithdraw();
        $withdraw->id = Uuid::uuid4()->toString();
        $withdraw->account_id = $data['account_id'];
        $withdraw->method = $data['method'];
        $withdraw->amount = $data['amount'];
        $withdraw->scheduled = $data['scheduled'];
        $withdraw->scheduled_for = $data['scheduled_for'] ?? null;
        $withdraw->done = $data['done'];
        $withdraw->error = $data['error'];
        $withdraw->error_reason = $data['error_reason'] ?? null;
        $withdraw->processed_at = $data['processed_at'];
        $withdraw->created_at = $data['created_at'];
        $withdraw->updated_at = $data['updated_at'];
        $withdraw->save();

        return $withdraw;
    }

    private function createPixData(string $withdrawId, \DateTime $createdAt): void
    {
        $pix = new AccountWithdrawPix();
        $pix->account_withdraw_id = $withdrawId;
        $pix->type = 'email';
        $pix->key = 'user' . rand(1000, 9999) . '@example.com';
        $pix->created_at = $createdAt;
        $pix->updated_at = $createdAt;
        $pix->save();
    }
}

