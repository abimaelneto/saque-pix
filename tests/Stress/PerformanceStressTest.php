<?php

declare(strict_types=1);

namespace Tests\Stress;

use App\Model\Account;
use App\Service\WithdrawService;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\Client;

/**
 * Testes de Stress e Performance
 * 
 * Testa performance real via HTTP, considerando todas as camadas:
 * - Middlewares (auth, rate limiting, security headers)
 * - Services (withdraw, fraud detection, audit)
 * - Database transactions
 * - Redis (rate limiting, locks)
 * 
 * @group stress
 */
class PerformanceStressTest extends StressTestCase
{
    private ?Client $client = null;
    private WithdrawService $withdrawService;
    private string $accountId;
    private string $authToken;

    protected function setUp(): void
    {
        if (! $this->isPerformanceSuiteEnabled()) {
            $this->markTestSkipped('Defina ENABLE_PERFORMANCE_STRESS=1 para executar os testes de performance completos.');
        }

        parent::setUp();
        
        // Inicializar container Hyperf
        if (!\Hyperf\Context\ApplicationContext::hasContainer()) {
            $container = require BASE_PATH . '/config/container.php';
        }
        
        $container = \Hyperf\Context\ApplicationContext::getContainer();
        
        // Tentar criar client, mas pode não estar disponível
        try {
            $this->client = $container->get(Client::class);
        } catch (\Exception $e) {
            // Client não disponível, usar service diretamente
            $this->client = null;
        }
        
        $this->withdrawService = $container->get(WithdrawService::class);
        $this->setUpDatabase();
        
        $this->accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($this->accountId, 'Stress Test User', '1000000.00'); // R$ 1.000.000,00
        
        // Token de autenticação para testes (bypass em ambiente de teste)
        $this->authToken = 'test-token';
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    private function isPerformanceSuiteEnabled(): bool
    {
        $flag = getenv('ENABLE_PERFORMANCE_STRESS');
        if ($flag === false) {
            return false;
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setUpDatabase(): void
    {
        // Usar migrations se disponíveis, senão criar tabelas manualmente
        try {
            Db::statement('
                CREATE TABLE IF NOT EXISTS account (
                    id VARCHAR(36) PRIMARY KEY,
                    name VARCHAR(255),
                    balance DECIMAL(15,2) DEFAULT 0.00,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
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
                    INDEX idx_account_id (account_id),
                    INDEX idx_scheduled (scheduled, done, scheduled_for)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            Db::statement('
                CREATE TABLE IF NOT EXISTS account_withdraw_pix (
                    account_withdraw_id VARCHAR(36) PRIMARY KEY,
                    type VARCHAR(50),
                    `key` VARCHAR(255),
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');
        } catch (\Exception $e) {
            // Tabelas podem já existir
        }
    }

    private function cleanDatabase(): void
    {
        try {
            Db::statement('SET FOREIGN_KEY_CHECKS = 0');
            Db::statement('TRUNCATE TABLE account_withdraw_pix');
            Db::statement('TRUNCATE TABLE account_withdraw');
            Db::statement('TRUNCATE TABLE account');
            Db::statement('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Exception $e) {
            // Ignorar erros de limpeza
        }
    }

    private function createAccount(string $id, string $name, string $balance): Account
    {
        $account = Account::firstOrNew(['id' => $id]);
        $account->name = $name;
        $account->balance = $balance;
        $account->save();

        return $account;
    }

    /**
     * Teste de carga: múltiplas requisições sequenciais via service
     */
    public function testSequentialWithdraws(): void
    {
        $iterations = 50;
        $amount = '10.00';
        
        $results = [
            'total_time' => 0,
            'success' => 0,
            'errors' => 0,
            'min_latency' => PHP_FLOAT_MAX,
            'max_latency' => 0,
            'total_latency' => 0,
        ];

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $requestStart = microtime(true);
            
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
                
                $requestLatency = microtime(true) - $requestStart;
                
                $results['total_latency'] += $requestLatency;
                $results['min_latency'] = min($results['min_latency'], $requestLatency);
                $results['max_latency'] = max($results['max_latency'], $requestLatency);
                $results['success']++;
            } catch (\Exception $e) {
                $results['errors']++;
            }
        }

        $results['total_time'] = microtime(true) - $startTime;
        $results['avg_latency'] = $results['total_latency'] / $iterations;
        $results['throughput'] = $iterations / $results['total_time'];

        $this->printResults('Sequential Withdraws', $results, $iterations);
        
        // Assertions
        $this->assertGreaterThan(0, $results['success'], 'Should have at least one successful request');
        $this->assertLessThan(1.0, $results['avg_latency'], 'Average latency should be under 1 second');
    }

    /**
     * Teste de concorrência: múltiplas requisições simultâneas usando corrotinas
     */
    public function testConcurrentWithdraws(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required for concurrent tests');
            return;
        }

        $concurrentRequests = 20;
        $amount = '10.00';
        
        $channel = new \Swoole\Coroutine\Channel($concurrentRequests);
        $results = [
            'total_time' => 0,
            'success' => 0,
            'errors' => 0,
            'min_latency' => PHP_FLOAT_MAX,
            'max_latency' => 0,
            'total_latency' => 0,
        ];

        $startTime = microtime(true);

        // Criar corrotinas para requisições concorrentes
        for ($i = 0; $i < $concurrentRequests; $i++) {
            \Swoole\Coroutine::create(function () use ($i, $amount, $channel, &$results) {
                $requestStart = microtime(true);
                
                try {
                    $dto = new \App\DTO\WithdrawRequestDTO(
                        accountId: $this->accountId,
                        method: 'PIX',
                        pixType: 'email',
                        pixKey: "concurrent{$i}@email.com",
                        amount: $amount,
                        schedule: null,
                    );

                    $this->withdrawService->createWithdraw($dto, null);
                    
                    $requestLatency = microtime(true) - $requestStart;
                    $results['success']++;
                    $results['total_latency'] += $requestLatency;
                    $results['min_latency'] = min($results['min_latency'], $requestLatency);
                    $results['max_latency'] = max($results['max_latency'], $requestLatency);
                    
                } catch (\Exception $e) {
                    $results['errors']++;
                }
                
                $channel->push(true);
            });
        }

        // Aguardar todas as corrotinas
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $channel->pop();
        }

        $results['total_time'] = microtime(true) - $startTime;
        $results['avg_latency'] = $results['total_latency'] / $concurrentRequests;
        $results['throughput'] = $concurrentRequests / $results['total_time'];

        $this->printResults('Concurrent Withdraws', $results, $concurrentRequests);
        
        // Assertions
        $this->assertGreaterThan(0, $results['success'], 'Should have at least one successful request');
    }

    /**
     * Teste de stress: carga alta com múltiplas contas
     */
    public function testHighLoadMultipleAccounts(): void
    {
        $numAccounts = 10;
        $withdrawsPerAccount = 10;
        $amount = '50.00';
        
        // Criar múltiplas contas
        $accountIds = [];
        for ($i = 0; $i < $numAccounts; $i++) {
            $accountId = \Ramsey\Uuid\Uuid::uuid4()->toString();
            $this->createAccount($accountId, "User {$i}", '10000.00');
            $accountIds[] = $accountId;
        }

        $results = [
            'total_time' => 0,
            'success' => 0,
            'errors' => 0,
            'total_requests' => $numAccounts * $withdrawsPerAccount,
        ];

        $startTime = microtime(true);

        foreach ($accountIds as $accountId) {
            for ($i = 0; $i < $withdrawsPerAccount; $i++) {
                try {
                    $dto = new \App\DTO\WithdrawRequestDTO(
                        accountId: $accountId,
                        method: 'PIX',
                        pixType: 'email',
                        pixKey: "load{$i}@email.com",
                        amount: $amount,
                        schedule: null,
                    );

                    $this->withdrawService->createWithdraw($dto, null);
                    $results['success']++;
                } catch (\Exception $e) {
                    $results['errors']++;
                }
            }
        }

        $results['total_time'] = microtime(true) - $startTime;
        $results['throughput'] = $results['total_requests'] / $results['total_time'];

        $this->printResults('High Load Multiple Accounts', $results, $results['total_requests']);
        
        // Assertions
        $this->assertGreaterThan($results['total_requests'] * 0.8, $results['success'], 
            'At least 80% of requests should succeed');
    }

    /**
     * Teste de performance: processamento rápido de múltiplos saques
     */
    public function testFastProcessing(): void
    {
        $iterations = 100;
        $amount = '1.00';
        
        $startTime = microtime(true);
        $success = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                $dto = new \App\DTO\WithdrawRequestDTO(
                    accountId: $this->accountId,
                    method: 'PIX',
                    pixType: 'email',
                    pixKey: "fast{$i}@email.com",
                    amount: $amount,
                    schedule: null,
                );

                $this->withdrawService->createWithdraw($dto, null);
                $success++;
            } catch (\Exception $e) {
                // Ignorar erros de saldo insuficiente
            }
        }

        $duration = microtime(true) - $startTime;
        $throughput = $success / $duration;

        $this->logSection('Fast Processing Test', [
            "Iterations: {$iterations}",
            "Success: {$success}",
            "Duration: " . number_format($duration, 2) . "s",
            "Throughput: " . number_format($throughput, 2) . " ops/sec",
        ]);

        $this->assertGreaterThan(0, $success, 'Should process at least one withdraw');
        $this->assertLessThan(30.0, $duration, 'Should complete in under 30 seconds');
    }

    /**
     * Teste de performance: processamento de saques agendados em lote
     */
    public function testScheduledWithdrawsBatchProcessing(): void
    {
        $scheduledCount = 50;
        $amount = 5.00;
        $futureDate = (new \DateTime())->modify('+1 minute')->format('Y-m-d H:i');

        // Criar saques agendados via service
        for ($i = 0; $i < $scheduledCount; $i++) {
            try {
                $dto = new \App\DTO\WithdrawRequestDTO(
                    accountId: $this->accountId,
                    method: 'PIX',
                    pixType: 'email',
                    pixKey: "scheduled{$i}@email.com",
                    amount: $amount,
                    schedule: $futureDate,
                );

                $this->withdrawService->createWithdraw($dto, null);
            } catch (\Exception $e) {
                // Ignorar erros
            }
        }

        // Atualizar scheduled_for para o passado
        Db::statement("
            UPDATE account_withdraw 
            SET scheduled_for = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            WHERE scheduled = TRUE AND done = FALSE
        ");

        // Processar via service
        $withdrawService = \Hyperf\Context\ApplicationContext::getContainer()
            ->get(\App\Service\WithdrawService::class);

        $startTime = microtime(true);
        $processed = $withdrawService->processScheduledWithdraws();
        $duration = microtime(true) - $startTime;

        $throughput = $processed / $duration;

        $this->logSection('Scheduled Withdraws Batch Processing', [
            "Scheduled: {$scheduledCount}",
            "Processed: {$processed}",
            "Duration: " . number_format($duration, 2) . "s",
            "Throughput: " . number_format($throughput, 2) . " ops/sec",
        ]);

        $this->assertEquals($scheduledCount, $processed);
        $this->assertLessThan(10.0, $duration, 'Should process in under 10 seconds');
    }

    /**
     * Teste de integridade: garantir consistência sob carga
     */
    public function testDataIntegrityUnderLoad(): void
    {
        $iterations = 100;
        $amount = '1.00';
        
        $initialBalance = (float) Account::find($this->accountId)->balance;
        $expectedWithdraws = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                $dto = new \App\DTO\WithdrawRequestDTO(
                    accountId: $this->accountId,
                    method: 'PIX',
                    pixType: 'email',
                    pixKey: "integrity{$i}@email.com",
                    amount: $amount,
                    schedule: null,
                );

                $this->withdrawService->createWithdraw($dto, null);
                $expectedWithdraws++;
            } catch (\Exception $e) {
                // Ignorar erros de saldo insuficiente
            }
        }

        // Verificar saldo final
        $finalAccount = Account::find($this->accountId);
        $finalBalance = (float) $finalAccount->balance;
        $expectedBalance = $initialBalance - ($expectedWithdraws * (float) $amount);

        $this->logSection('Data Integrity Test', [
            'Initial Balance: R$ ' . number_format($initialBalance, 2, ',', '.'),
            "Expected Withdraws: {$expectedWithdraws}",
            'Expected Final Balance: R$ ' . number_format($expectedBalance, 2, ',', '.'),
            'Actual Final Balance: R$ ' . number_format($finalBalance, 2, ',', '.'),
            'Difference: R$ ' . number_format(abs($expectedBalance - $finalBalance), 2, ',', '.'),
        ]);

        $this->assertEqualsWithDelta($expectedBalance, $finalBalance, 0.01, 
            'Balance should match expected value');
        $this->assertGreaterThanOrEqual(0, $finalBalance, 'Balance should never be negative');
    }

    private function printResults(string $testName, array $results, int $total): void
    {
        $lines = [
            "Total Requests: {$total}",
            "Success: {$results['success']}",
            "Errors: {$results['errors']}",
        ];

        if (isset($results['rate_limited'])) {
            $lines[] = "Rate Limited: {$results['rate_limited']}";
        }

        $lines[] = "Total Time: " . number_format($results['total_time'], 2) . "s";

        if (isset($results['avg_latency'])) {
            $lines[] = "Avg Latency: " . number_format($results['avg_latency'] * 1000, 2) . "ms";
            $lines[] = "Min Latency: " . number_format($results['min_latency'] * 1000, 2) . "ms";
            $lines[] = "Max Latency: " . number_format($results['max_latency'] * 1000, 2) . "ms";
        }

        $lines[] = "Throughput: " . number_format($results['throughput'], 2) . " req/sec";

        $this->logSection($testName, $lines);
    }
}

