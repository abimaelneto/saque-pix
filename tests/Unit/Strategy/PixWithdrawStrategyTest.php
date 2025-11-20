<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use App\DTO\WithdrawRequestDTO;
use App\Model\AccountWithdraw;
use App\Repository\AccountWithdrawPixRepository;
use App\Strategy\PixWithdrawStrategy;
use Hyperf\DbConnection\Db;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PixWithdrawStrategyTest extends TestCase
{
    private PixWithdrawStrategy $strategy;
    private AccountWithdrawPixRepository $pixRepository;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setUpDatabase();
        
        $this->pixRepository = $this->createMock(AccountWithdrawPixRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->strategy = new PixWithdrawStrategy(
            $this->pixRepository,
            $this->logger
        );
    }

    private function setUpDatabase(): void
    {
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
                updated_at TIMESTAMP NULL
            )
        ');

        Db::statement('
            CREATE TABLE IF NOT EXISTS account_withdraw_pix (
                account_withdraw_id VARCHAR(36) PRIMARY KEY,
                type VARCHAR(50),
                `key` VARCHAR(255),
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        ');
    }

    public function testGetMethodName(): void
    {
        $this->assertEquals('PIX', $this->strategy->getMethodName());
    }

    public function testValidateWithValidEmail(): void
    {
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
        );

        $this->strategy->validate($dto);
        $this->assertTrue(true); // Se chegou aqui, não lançou exceção
    }

    public function testValidateWithInvalidEmail(): void
    {
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'invalid-email',
            amount: '100.00',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');
        
        $this->strategy->validate($dto);
    }

    public function testValidateWithInvalidPixType(): void
    {
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'invalid',
            pixKey: 'test@email.com',
            amount: '100.00',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PIX type');
        
        $this->strategy->validate($dto);
    }

    public function testProcessWithValidWithdraw(): void
    {
        $withdraw = new AccountWithdraw();
        $withdraw->id = '123e4567-e89b-12d3-a456-426614174000';
        $withdraw->account_id = '123e4567-e89b-12d3-a456-426614174001';
        $withdraw->method = 'PIX';
        $withdraw->amount = '100.00';
        
        $pix = new \App\Model\AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';
        
        $withdraw->setRelation('pix', $pix);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('PIX withdraw processed', $this->anything());

        $result = $this->strategy->process($withdraw);
        
        $this->assertTrue($result);
    }

    public function testProcessWithMissingPixData(): void
    {
        $withdraw = new AccountWithdraw();
        $withdraw->id = '123e4567-e89b-12d3-a456-426614174000';
        $withdraw->account_id = '123e4567-e89b-12d3-a456-426614174001';
        $withdraw->method = 'PIX';
        $withdraw->amount = '100.00';

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PIX data not found for withdraw', $this->anything());

        $result = $this->strategy->process($withdraw);
        
        $this->assertFalse($result);
    }
}

