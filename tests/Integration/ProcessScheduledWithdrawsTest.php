<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Command\ProcessScheduledWithdrawsCommand;
use App\Model\Account;
use App\Model\AccountWithdraw;
use App\Model\AccountWithdrawPix;
use App\Service\WithdrawService;
use Hyperf\DbConnection\Db;
use Tests\TestCase;

/**
 * Testes E2E para processamento de saques agendados (RF03)
 * Testa o fluxo completo do cron job
 */
class ProcessScheduledWithdrawsTest extends TestCase
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

    private function createAccount(string $id, string $name, string $balance): Account
    {
        $account = new Account();
        $account->id = $id;
        $account->name = $name;
        $account->balance = $balance;
        $account->save();

        return $account;
    }

    public function testProcessScheduledWithdrawsProcessesPendingWithdraws(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        // Criar saque agendado para o passado (deve ser processado)
        $pastDate = (new \DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        
        $withdraw = new AccountWithdraw();
        $withdraw->id = '550e8400-e29b-41d4-a716-446655440000';
        $withdraw->account_id = $accountId;
        $withdraw->method = 'PIX';
        $withdraw->amount = '100.00';
        $withdraw->scheduled = true;
        $withdraw->scheduled_for = $pastDate;
        $withdraw->done = false;
        $withdraw->save();

        $pix = new AccountWithdrawPix();
        $pix->account_withdraw_id = $withdraw->id;
        $pix->type = 'email';
        $pix->key = 'test@email.com';
        $pix->save();

        // Processar saques agendados
        $service = \Hyperf\Context\ApplicationContext::getContainer()->get(WithdrawService::class);
        $processed = $service->processScheduledWithdraws();

        $this->assertEquals(1, $processed);

        // Verificar que foi processado
        $withdraw->refresh();
        $withdraw->load('pix'); // Carregar relacionamento pix
        $this->assertTrue($withdraw->done);
        $this->assertFalse($withdraw->error);
        $this->assertNotNull($withdraw->processed_at);

        // Verificar saldo foi deduzido
        $account = Account::find($accountId);
        $this->assertEquals('900.00', $account->balance);
    }

    public function testProcessScheduledWithdrawsMarksErrorWhenInsufficientBalance(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '50.00'); // Saldo insuficiente

        $pastDate = (new \DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        
        $withdraw = new AccountWithdraw();
        $withdraw->id = '550e8400-e29b-41d4-a716-446655440000';
        $withdraw->account_id = $accountId;
        $withdraw->method = 'PIX';
        $withdraw->amount = '100.00'; // Mais que o saldo
        $withdraw->scheduled = true;
        $withdraw->scheduled_for = $pastDate;
        $withdraw->done = false;
        $withdraw->save();

        $pix = new AccountWithdrawPix();
        $pix->account_withdraw_id = $withdraw->id;
        $pix->type = 'email';
        $pix->key = 'test@email.com';
        $pix->save();

        $service = $this->container->get(WithdrawService::class);
        $processed = $service->processScheduledWithdraws();

        $this->assertEquals(0, $processed); // Nenhum processado com sucesso

        // Verificar que foi marcado como erro
        $withdraw->refresh();
        $this->assertFalse($withdraw->done);
        $this->assertTrue($withdraw->error);
        $this->assertEquals('Insufficient balance at processing time', $withdraw->error_reason);

        // Verificar saldo NÃO foi deduzido
        $account = Account::find($accountId);
        $this->assertEquals('50.00', $account->balance);
    }

    public function testProcessScheduledWithdrawsIgnoresFutureScheduledWithdraws(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $futureDate = (new \DateTime())->modify('+1 day')->format('Y-m-d H:i:s');
        
        $withdraw = new AccountWithdraw();
        $withdraw->id = '550e8400-e29b-41d4-a716-446655440000';
        $withdraw->account_id = $accountId;
        $withdraw->method = 'PIX';
        $withdraw->amount = '100.00';
        $withdraw->scheduled = true;
        $withdraw->scheduled_for = $futureDate;
        $withdraw->done = false;
        $withdraw->save();

        $pix = new AccountWithdrawPix();
        $pix->account_withdraw_id = $withdraw->id;
        $pix->type = 'email';
        $pix->key = 'test@email.com';
        $pix->save();

        $service = $this->container->get(WithdrawService::class);
        $processed = $service->processScheduledWithdraws();

        $this->assertEquals(0, $processed); // Não processa saques futuros

        // Verificar que NÃO foi processado
        $withdraw->refresh();
        $this->assertFalse($withdraw->done);
        $this->assertNull($withdraw->processed_at);

        // Verificar saldo NÃO foi deduzido
        $account = Account::find($accountId);
        $this->assertEquals('1000.00', $account->balance);
    }

    public function testProcessScheduledWithdrawsIgnoresAlreadyProcessedWithdraws(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $this->createAccount($accountId, 'Test User', '1000.00');

        $pastDate = (new \DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        
        $withdraw = new AccountWithdraw();
        $withdraw->id = '550e8400-e29b-41d4-a716-446655440000';
        $withdraw->account_id = $accountId;
        $withdraw->method = 'PIX';
        $withdraw->amount = '100.00';
        $withdraw->scheduled = true;
        $withdraw->scheduled_for = $pastDate;
        $withdraw->done = true; // Já processado
        $withdraw->processed_at = new \DateTime();
        $withdraw->save();

        $service = $this->container->get(WithdrawService::class);
        $processed = $service->processScheduledWithdraws();

        $this->assertEquals(0, $processed); // Não processa novamente
    }

    public function testProcessScheduledWithdrawsProcessesMultipleWithdraws(): void
    {
        $accountId1 = '123e4567-e89b-12d3-a456-426614174000';
        $accountId2 = '223e4567-e89b-12d3-a456-426614174000';
        
        $this->createAccount($accountId1, 'User 1', '1000.00');
        $this->createAccount($accountId2, 'User 2', '500.00');

        $pastDate = (new \DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');

        // Saque 1
        $withdraw1 = new AccountWithdraw();
        $withdraw1->id = '550e8400-e29b-41d4-a716-446655440000';
        $withdraw1->account_id = $accountId1;
        $withdraw1->method = 'PIX';
        $withdraw1->amount = '100.00';
        $withdraw1->scheduled = true;
        $withdraw1->scheduled_for = $pastDate;
        $withdraw1->done = false;
        $withdraw1->save();

        $pix1 = new AccountWithdrawPix();
        $pix1->account_withdraw_id = $withdraw1->id;
        $pix1->type = 'email';
        $pix1->key = 'user1@email.com';
        $pix1->save();

        // Saque 2
        $withdraw2 = new AccountWithdraw();
        $withdraw2->id = '650e8400-e29b-41d4-a716-446655440000';
        $withdraw2->account_id = $accountId2;
        $withdraw2->method = 'PIX';
        $withdraw2->amount = '50.00';
        $withdraw2->scheduled = true;
        $withdraw2->scheduled_for = $pastDate;
        $withdraw2->done = false;
        $withdraw2->save();

        $pix2 = new AccountWithdrawPix();
        $pix2->account_withdraw_id = $withdraw2->id;
        $pix2->type = 'email';
        $pix2->key = 'user2@email.com';
        $pix2->save();

        $service = $this->container->get(WithdrawService::class);
        $processed = $service->processScheduledWithdraws();

        $this->assertEquals(2, $processed);

        // Verificar ambos foram processados
        $withdraw1->refresh();
        $withdraw2->refresh();
        $this->assertTrue($withdraw1->done);
        $this->assertTrue($withdraw2->done);

        // Verificar saldos
        $account1 = Account::find($accountId1);
        $account2 = Account::find($accountId2);
        $this->assertEquals('900.00', $account1->balance);
        $this->assertEquals('450.00', $account2->balance);
    }

    public function testProcessScheduledWithdrawsHandlesMixedScenarios(): void
    {
        $accountId1 = '123e4567-e89b-12d3-a456-426614174000';
        $accountId2 = '223e4567-e89b-12d3-a456-426614174000';
        $accountId3 = '323e4567-e89b-12d3-a456-426614174000';
        
        $this->createAccount($accountId1, 'User 1', '1000.00');
        $this->createAccount($accountId2, 'User 2', '50.00'); // Saldo insuficiente
        $this->createAccount($accountId3, 'User 3', '500.00');

        $pastDate = (new \DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        $futureDate = (new \DateTime())->modify('+1 day')->format('Y-m-d H:i:s');

        // Saque 1: Deve processar (saldo suficiente)
        $withdraw1 = new AccountWithdraw();
        $withdraw1->id = '550e8400-e29b-41d4-a716-446655440000';
        $withdraw1->account_id = $accountId1;
        $withdraw1->method = 'PIX';
        $withdraw1->amount = '100.00';
        $withdraw1->scheduled = true;
        $withdraw1->scheduled_for = $pastDate;
        $withdraw1->done = false;
        $withdraw1->save();

        $pix1 = new AccountWithdrawPix();
        $pix1->account_withdraw_id = $withdraw1->id;
        $pix1->type = 'email';
        $pix1->key = 'user1@email.com';
        $pix1->save();

        // Saque 2: Deve marcar como erro (saldo insuficiente)
        $withdraw2 = new AccountWithdraw();
        $withdraw2->id = '650e8400-e29b-41d4-a716-446655440000';
        $withdraw2->account_id = $accountId2;
        $withdraw2->method = 'PIX';
        $withdraw2->amount = '100.00';
        $withdraw2->scheduled = true;
        $withdraw2->scheduled_for = $pastDate;
        $withdraw2->done = false;
        $withdraw2->save();

        $pix2 = new AccountWithdrawPix();
        $pix2->account_withdraw_id = $withdraw2->id;
        $pix2->type = 'email';
        $pix2->key = 'user2@email.com';
        $pix2->save();

        // Saque 3: Deve ignorar (data futura)
        $withdraw3 = new AccountWithdraw();
        $withdraw3->id = '750e8400-e29b-41d4-a716-446655440000';
        $withdraw3->account_id = $accountId3;
        $withdraw3->method = 'PIX';
        $withdraw3->amount = '100.00';
        $withdraw3->scheduled = true;
        $withdraw3->scheduled_for = $futureDate;
        $withdraw3->done = false;
        $withdraw3->save();

        $service = $this->container->get(WithdrawService::class);
        $processed = $service->processScheduledWithdraws();

        $this->assertEquals(1, $processed); // Apenas 1 processado com sucesso

        // Verificar resultados
        $withdraw1->refresh();
        $withdraw2->refresh();
        $withdraw3->refresh();

        $this->assertTrue($withdraw1->done);
        $this->assertFalse($withdraw1->error);

        $this->assertFalse($withdraw2->done);
        $this->assertTrue($withdraw2->error);
        $this->assertEquals('Insufficient balance at processing time', $withdraw2->error_reason);

        $this->assertFalse($withdraw3->done);
        $this->assertFalse($withdraw3->error);
    }
}

