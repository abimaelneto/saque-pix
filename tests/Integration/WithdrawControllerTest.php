<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Model\Account;
use Hyperf\DbConnection\Db;
use Tests\TestCase;

class WithdrawControllerTest extends TestCase
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
        // Criar tabelas de teste
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

    public function testWithdrawEndpointReturnsErrorForInvalidAccountId(): void
    {
        $response = $this->client->post('/account/invalid-id/balance/withdraw', [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
            'schedule' => null,
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testWithdrawEndpointReturnsErrorForValidationFailure(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'INVALID',
            'amount' => -100,
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testWithdrawEndpointCreatesImmediateWithdraw(): void
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
            'schedule' => null,
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertFalse($data['data']['scheduled']);
        $this->assertTrue($data['data']['done']);

        // Verificar saldo foi deduzido
        $account = Account::find($accountId);
        $this->assertEquals('900.00', $account->balance);
    }

    public function testWithdrawEndpointCreatesScheduledWithdraw(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $futureDate = (new \DateTime())->modify('+1 day')->format('Y-m-d H:i');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
            'schedule' => $futureDate,
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        
        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['scheduled']);
        $this->assertFalse($data['data']['done']);

        // Verificar saldo NÃO foi deduzido ainda
        $account = Account::find($accountId);
        $this->assertEquals('1000.00', $account->balance);
    }

    public function testWithdrawEndpointReturnsErrorForInsufficientBalance(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '50.00');

        $response = $this->client->post("/account/{$accountId}/balance/withdraw", [
            'method' => 'PIX',
            'pix' => [
                'type' => 'email',
                'key' => 'test@email.com',
            ],
            'amount' => 100.00,
            'schedule' => null,
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $data);
    }
}

