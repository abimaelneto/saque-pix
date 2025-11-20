<?php

declare(strict_types=1);

namespace Tests\Stress;

use App\Model\Account;
use App\Service\WithdrawService;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Ramsey\Uuid\Uuid;

/**
 * Stress Tests para validar performance e concorrência
 * 
 * @group stress
 */
class WithdrawStressTest extends StressTestCase
{
    private WithdrawService $withdrawService;
    private string $accountId;
    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();
        $this->cleanDatabase();
        
        $container = \Hyperf\Context\ApplicationContext::getContainer();
        $this->withdrawService = $container->get(WithdrawService::class);
        $this->redis = $container->get(Redis::class);
        $this->resetRedis();
        
        $this->accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($this->accountId, 'Stress Test User', '100000.00');
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    private function setUpDatabase(): void
    {
        Db::statement('
            CREATE TABLE IF NOT EXISTS account (
                id VARCHAR(36) PRIMARY KEY,
                name VARCHAR(255),
                balance DECIMAL(15,2) DEFAULT 0.00,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        ');

        Db::statement('
            CREATE TABLE IF NOT EXISTS account_withdraw (
                id VARCHAR(36) PRIMARY KEY,
                account_id VARCHAR(36),
                method VARCHAR(50),
                amount DECIMAL(15,2),
                scheduled BOOLEAN DEFAULT FALSE,
                scheduled_for DATETIME NULL,
                done BOOLEAN DEFAULT FALSE,
                error BOOLEAN DEFAULT FALSE,
                error_reason TEXT NULL,
                processed_at DATETIME NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                FOREIGN KEY (account_id) REFERENCES account(id) ON DELETE CASCADE
            )
        ');

        Db::statement('
            CREATE TABLE IF NOT EXISTS account_withdraw_pix (
                account_withdraw_id VARCHAR(36) PRIMARY KEY,
                type VARCHAR(50),
                `key` VARCHAR(255),
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                FOREIGN KEY (account_withdraw_id) REFERENCES account_withdraw(id) ON DELETE CASCADE
            )
        ');
    }

    private function cleanDatabase(): void
    {
        Db::statement('SET FOREIGN_KEY_CHECKS = 0');
        Db::statement('TRUNCATE TABLE account_withdraw_pix');
        Db::statement('TRUNCATE TABLE account_withdraw');
        Db::statement('TRUNCATE TABLE account');
        Db::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function resetRedis(): void
    {
        try {
            $this->redis->flushDB();
        } catch (\Throwable $e) {
            // Ignorar erros ao limpar Redis
        }
    }

    private function createAccount(string $id, string $name, string $balance): Account
    {
        $account = new Account();
        $account->id = $id;
        $account->name = $name;
        $account->balance = $balance;
        $account->save();

        return $account;
    }

    /**
     * Teste de performance: múltiplos saques sequenciais
     */
    public function testMultipleSequentialWithdraws(): void
    {
        $iterations = 100;
        $amount = '10.00';
        
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $dto = new \App\DTO\WithdrawRequestDTO(
                accountId: $this->accountId,
                method: 'PIX',
                pixType: 'email',
                pixKey: "test{$i}@email.com",
                amount: $amount,
                schedule: null,
            );

            $this->withdrawService->createWithdraw($dto, null);
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $throughput = $iterations / $duration;

        $this->logSection('Sequential Withdraws', [
            "Iterations: {$iterations}",
            "Duration: " . number_format($duration, 2) . "s",
            "Throughput: " . number_format($throughput, 2) . " ops/sec",
        ]);

        // Verificar saldo final
        $account = Account::find($this->accountId);
        $expectedBalance = 100000.00 - ($iterations * (float) $amount);
        $this->assertEqualsWithDelta($expectedBalance, (float) $account->balance, 0.01);
    }

    /**
     * Teste de concorrência: múltiplos saques simultâneos
     */
    public function testConcurrentWithdraws(): void
    {
        $concurrentRequests = 50;
        $amount = '10.00';
        
        $startTime = microtime(true);

        $promises = [];
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $dto = new \App\DTO\WithdrawRequestDTO(
                accountId: $this->accountId,
                method: 'PIX',
                pixType: 'email',
                pixKey: "test{$i}@email.com",
                amount: $amount,
                schedule: null,
            );

            // Em ambiente real, isso seria feito com corrotinas
            // Aqui simulamos com chamadas sequenciais
            $this->withdrawService->createWithdraw($dto, null);
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $throughput = $concurrentRequests / $duration;

        $this->logSection('Concurrent Withdraws', [
            "Concurrent Requests: {$concurrentRequests}",
            "Duration: " . number_format($duration, 2) . "s",
            "Throughput: " . number_format($throughput, 2) . " ops/sec",
        ]);

        // Verificar saldo final
        $account = Account::find($this->accountId);
        $expectedBalance = 100000.00 - ($concurrentRequests * (float) $amount);
        $this->assertEqualsWithDelta($expectedBalance, (float) $account->balance, 0.01);
    }

    /**
     * Teste de stress: processamento de muitos saques agendados
     */
    public function testScheduledWithdrawsProcessing(): void
    {
        $scheduledCount = (int) (getenv('STRESS_SCHEDULED_COUNT') ?: 1000);
        $amount = '5.00';
        $targetSeconds = (float) (getenv('STRESS_SCHEDULED_MAX_SECONDS') ?: 6.0);
        $concurrency = (int) (getenv('STRESS_SCHEDULED_CONCURRENCY') ?: 100);
        $futureDate = (new \DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');
        $timestamp = date('Y-m-d H:i:s');

        $this->bulkCreateScheduledWithdraws($scheduledCount, $amount, $futureDate, $timestamp);
        $pendingBefore = Db::table('account_withdraw')
            ->where('scheduled', true)
            ->where('done', false)
            ->count();
        $this->assertEquals($scheduledCount, $pendingBefore, 'Falha ao preparar os saques agendados para o teste.');

        $pastTimestamp = date('Y-m-d H:i:s', time() - 60);
        $updatedRows = Db::table('account_withdraw')
            ->where('scheduled', true)
            ->where('done', false)
            ->update(['scheduled_for' => $pastTimestamp]);
        $this->assertEquals(
            $scheduledCount,
            $updatedRows,
            'Falha ao atualizar as datas de agendamento para o passado.'
        );

        $pendingReady = Db::table('account_withdraw')
            ->where('scheduled', true)
            ->where('done', false)
            ->where('error', false)
            ->where('scheduled_for', '<=', date('Y-m-d H:i:s'))
            ->count();
        $this->assertEquals(
            $scheduledCount,
            $pendingReady,
            'Os saques agendados não ficaram prontos para processamento.'
        );

        $repository = \Hyperf\Context\ApplicationContext::getContainer()
            ->get(\App\Repository\AccountWithdrawRepository::class);
        $this->assertEquals(
            $scheduledCount,
            $repository->findPendingScheduled()->count(),
            'O repositório não retornou todos os saques pendentes.'
        );

        $startTime = microtime(true);
        $processed = $this->withdrawService->processScheduledWithdraws($concurrency);
        $endTime = microtime(true);
        
        $duration = max($endTime - $startTime, 0.0001);
        $throughput = $processed / $duration;

        $this->logSection('Scheduled Withdraws Processing', [
            "Scheduled Count: {$scheduledCount}",
            "Processed: {$processed}",
            "Duration: " . number_format($duration, 2) . "s",
            "Throughput: " . number_format($throughput, 2) . " ops/sec",
            "Target Duration <= {$targetSeconds}s",
        ]);

        $this->assertEquals($scheduledCount, $processed, 'Nem todos os saques agendados foram processados.');
        $this->assertLessThanOrEqual(
            $targetSeconds,
            $duration,
            sprintf('Processamento demorou %.2fs (target %.2fs)', $duration, $targetSeconds)
        );

        $account = Account::find($this->accountId);
        $expectedBalance = 100000.00 - ($scheduledCount * (float) $amount);
        $this->assertEqualsWithDelta($expectedBalance, (float) $account->balance, 0.01);
    }

    private function bulkCreateScheduledWithdraws(int $count, string $amount, string $schedule, string $timestamp): void
    {
        if ($count <= 0) {
            return;
        }

        $batchSize = 250;
        $range = range(0, $count - 1);
        foreach (array_chunk($range, $batchSize) as $chunk) {
            $withdrawRows = [];
            $pixRows = [];

            foreach ($chunk as $index) {
                $id = Uuid::uuid4()->toString();
                $withdrawRows[] = [
                    'id' => $id,
                    'account_id' => $this->accountId,
                    'method' => 'PIX',
                    'amount' => $amount,
                    'scheduled' => true,
                    'scheduled_for' => $schedule,
                    'done' => false,
                    'error' => false,
                    'error_reason' => null,
                    'processed_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                $pixRows[] = [
                    'account_withdraw_id' => $id,
                    'type' => 'email',
                    'key' => sprintf('stress%04d@email.com', $index),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            Db::table('account_withdraw')->insert($withdrawRows);
            Db::table('account_withdraw_pix')->insert($pixRows);
        }
    }

    /**
     * Teste de integridade: garantir que saldo nunca fica negativo
     */
    public function testBalanceNeverGoesNegative(): void
    {
        $account = Account::find($this->accountId);
        $initialBalance = (float) $account->balance;
        
        $iterations = 1000;
        $amount = '1.00';

        for ($i = 0; $i < $iterations; $i++) {
            try {
                $dto = new \App\DTO\WithdrawRequestDTO(
                    accountId: $this->accountId,
                    method: 'PIX',
                    pixType: 'email',
                    pixKey: "test{$i}@email.com",
                    amount: $amount,
                    schedule: null,
                );

                $this->withdrawService->createWithdraw($dto, null);
                
                // Verificar saldo após cada saque
                $account->refresh();
                $this->assertGreaterThanOrEqual(0, (float) $account->balance);
            } catch (\InvalidArgumentException $e) {
                // Esperado quando saldo insuficiente
                $this->assertStringContainsString('Insufficient balance', $e->getMessage());
            }
        }

        $finalAccount = Account::find($this->accountId);
        $this->assertGreaterThanOrEqual(0, (float) $finalAccount->balance);
    }
}

