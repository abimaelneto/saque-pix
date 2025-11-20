<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Model\Account;
use App\Repository\AccountRepository;
use Hyperf\Database\Model\Model;
use PHPUnit\Framework\TestCase;

class AccountRepositoryTest extends TestCase
{
    private AccountRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new AccountRepository();
    }

    public function testHasSufficientBalanceReturnsFalseWhenAccountNotFound(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        
        // Mock do Model::find()
        $this->mockStaticMethod(Account::class, 'find', null);

        $result = $this->repository->hasSufficientBalance($accountId, '100.00');
        
        $this->assertFalse($result);
    }

    public function testHasSufficientBalanceReturnsTrueWhenBalanceIsSufficient(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $account = new Account();
        $account->id = $accountId;
        $account->balance = '200.00';

        // Usar mock real do modelo
        $mock = \Mockery::mock('alias:' . Account::class);
        $mock->shouldReceive('find')
            ->with($accountId)
            ->andReturn($account);

        $result = $this->repository->hasSufficientBalance($accountId, '100.00');
        
        $this->assertTrue($result);
    }

    public function testHasSufficientBalanceReturnsFalseWhenBalanceIsInsufficient(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $account = new Account();
        $account->id = $accountId;
        $account->balance = '50.00';

        $mock = \Mockery::mock('alias:' . Account::class);
        $mock->shouldReceive('find')
            ->with($accountId)
            ->andReturn($account);

        $result = $this->repository->hasSufficientBalance($accountId, '100.00');
        
        $this->assertFalse($result);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}

