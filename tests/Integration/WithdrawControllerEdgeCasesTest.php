<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Model\Account;
use Hyperf\DbConnection\Db;
use Tests\TestCase;

/**
 * Testes E2E para edge cases do endpoint de saque
 * Cobre validações, casos limite e erros
 */
class WithdrawControllerEdgeCasesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();
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
                key VARCHAR(255),
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

    private function createAccount(string $id, string $name, string $balance): Account
    {
        $account = new Account();
        $account->id = $id;
        $account->name = $name;
        $account->balance = $balance;
        $account->save();

        return $account;
    }

    public function testWithdrawWithInvalidUUIDFormat(): void
    {
        $response = $this->client->post('/account/invalid-uuid/balance/withdraw', [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid account ID format', $data['error']);
    }

    public function testWithdrawWithMissingRequiredFields(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'amount' => 100.00,
            // Faltando method e pix
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('messages', $data);
    }

    public function testWithdrawWithInvalidMethod(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'INVALID_METHOD',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
        ]);

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testWithdrawWithInvalidPixType(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'invalid_type',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
        ]);

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testWithdrawWithInvalidEmail(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'invalid-email',
            ],
            'amount' => 100.00,
        ]);

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testWithdrawWithNegativeAmount(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => -100.00,
        ]);

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testWithdrawWithZeroAmount(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 0,
        ]);

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testWithdrawWithVerySmallAmount(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 0.001, // Menor que 0.01
        ]);

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testWithdrawWithExactBalance(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '100.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00, // Exatamente o saldo
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        
        // Verificar saldo ficou zerado
        $account = Account::find($accountId);
        $this->assertEquals('0.00', $account->balance);
    }

    public function testWithdrawWithAmountGreaterThanBalance(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '100.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.01, // Um centavo a mais
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testWithdrawWithInvalidScheduleFormat(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
            'schedule' => 'invalid-date-format',
        ]);

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testWithdrawWithScheduleInPast(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $pastDate = (new \DateTime())->modify('-1 day')->format('Y-m-d H:i');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
            'schedule' => $pastDate,
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('past date', $data['error']);
    }

    public function testWithdrawWithScheduleExactlyNow(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        // Data exatamente agora (pode ser processado imediatamente ou agendado)
        $now = (new \DateTime())->format('Y-m-d H:i');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
            'schedule' => $now,
        ]);

        // Pode aceitar ou rejeitar dependendo da implementação
        // Assumindo que aceita (agendado para agora)
        $this->assertContains($response->getStatusCode(), [201, 400]);
    }

    public function testWithdrawWithNonExistentAccount(): void
    {
        $nonExistentAccountId = '999e9999-e99b-99d9-a999-999999999999';

        $response = $this->client->post("/account/{$nonExistentAccountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Account not found', $data['error']);
    }

    public function testWithdrawWithVeryLargeAmount(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 999999999.99,
        ]);

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testWithdrawWithDecimalPrecision(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 123.45,
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        
        // Verificar saldo com precisão decimal
        $account = Account::find($accountId);
        $this->assertEquals('876.55', $account->balance);
    }
}

