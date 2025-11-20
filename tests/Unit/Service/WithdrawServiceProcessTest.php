<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Model\Account;
use App\Model\AccountWithdraw;
use App\Model\AccountWithdrawPix;
use App\Repository\AccountRepository;
use App\Repository\AccountWithdrawRepository;
use App\Service\DistributedLockService;
use App\Service\EmailService;
use App\Service\WithdrawService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Testes unitários para processamento de saques
 * Cobre RF03 - Processar Saques Agendados
 */
class WithdrawServiceProcessTest extends TestCase
{
    private WithdrawService $service;
    private MockObject $accountRepository;
    private MockObject $withdrawRepository;
    private MockObject $emailService;
    private MockObject $logger;
    private MockObject $lockService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->accountRepository = $this->createMock(AccountRepository::class);
        $this->withdrawRepository = $this->createMock(AccountWithdrawRepository::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->lockService = $this->createMock(DistributedLockService::class);

        $this->service = new WithdrawService();
        
        // Usar reflection para injetar dependências mockadas
        $reflection = new \ReflectionClass($this->service);
        
        $accountRepoProp = $reflection->getProperty('accountRepository');
        $accountRepoProp->setAccessible(true);
        $accountRepoProp->setValue($this->service, $this->accountRepository);

        $withdrawRepoProp = $reflection->getProperty('withdrawRepository');
        $withdrawRepoProp->setAccessible(true);
        $withdrawRepoProp->setValue($this->service, $this->withdrawRepository);

        $emailServiceProp = $reflection->getProperty('emailService');
        $emailServiceProp->setAccessible(true);
        $emailServiceProp->setValue($this->service, $this->emailService);

        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($this->service, $this->logger);

        $lockServiceProp = $reflection->getProperty('lockService');
        $lockServiceProp->setAccessible(true);
        $lockServiceProp->setValue($this->service, $this->lockService);
        
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($this->service, $this->logger);
    }

    public function testProcessWithdrawThrowsExceptionWhenWithdrawNotFound(): void
    {
        $withdrawId = '123e4567-e89b-12d3-a456-426614174000';

        $this->withdrawRepository
            ->expects($this->once())
            ->method('findById')
            ->with($withdrawId)
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Withdraw not found');

        $this->service->processWithdraw($withdrawId);
    }

    public function testProcessWithdrawReturnsFalseWhenAlreadyProcessed(): void
    {
        $withdrawId = '123e4567-e89b-12d3-a456-426614174000';
        $withdraw = new AccountWithdraw();
        $withdraw->id = $withdrawId;
        $withdraw->done = true;

        $this->withdrawRepository
            ->expects($this->once())
            ->method('findById')
            ->with($withdrawId)
            ->willReturn($withdraw);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Withdraw already processed');

        $result = $this->service->processWithdraw($withdrawId);
        
        $this->assertFalse($result);
    }

    public function testProcessWithdrawMarksAsErrorWhenInsufficientBalance(): void
    {
        $withdrawId = '123e4567-e89b-12d3-a456-426614174000';
        $accountId = '550e8400-e29b-41d4-a716-446655440000';
        
        $withdraw = new AccountWithdraw();
        $withdraw->id = $withdrawId;
        $withdraw->account_id = $accountId;
        $withdraw->amount = '100.00';
        $withdraw->done = false;

        $this->withdrawRepository
            ->expects($this->exactly(2))
            ->method('findById')
            ->with($withdrawId)
            ->willReturn($withdraw);

        $this->accountRepository
            ->expects($this->once())
            ->method('hasSufficientBalance')
            ->with($accountId, '100.00')
            ->willReturn(false);

        $this->withdrawRepository
            ->expects($this->once())
            ->method('markAsError')
            ->with($withdrawId, 'Insufficient balance at processing time');

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Insufficient balance for scheduled withdraw');

        $result = $this->service->processWithdraw($withdrawId);
        
        $this->assertFalse($result);
    }

    public function testProcessWithdrawSuccessfully(): void
    {
        $withdrawId = '123e4567-e89b-12d3-a456-426614174000';
        $accountId = '550e8400-e29b-41d4-a716-446655440000';
        
        $withdraw = new AccountWithdraw();
        $withdraw->id = $withdrawId;
        $withdraw->account_id = $accountId;
        $withdraw->amount = '100.00';
        $withdraw->done = false;

        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';
        $withdraw->setRelation('pix', $pix);

        $this->withdrawRepository
            ->expects($this->exactly(2))
            ->method('findById')
            ->with($withdrawId)
            ->willReturn($withdraw);

        $this->accountRepository
            ->expects($this->once())
            ->method('hasSufficientBalance')
            ->with($accountId, '100.00')
            ->willReturn(true);

        $this->accountRepository
            ->expects($this->once())
            ->method('updateBalance')
            ->with($accountId, '100.00');

        $this->withdrawRepository
            ->expects($this->once())
            ->method('markAsDone')
            ->with($withdrawId);

        $this->emailService
            ->expects($this->once())
            ->method('sendWithdrawNotification')
            ->with($withdraw)
            ->willReturn(true);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Withdraw processed successfully');

        $result = $this->service->processWithdraw($withdrawId);
        
        $this->assertTrue($result);
    }

    public function testProcessScheduledWithdrawsUsesDistributedLock(): void
    {
        $pending = [];
        
        $this->lockService
            ->expects($this->once())
            ->method('executeWithLock')
            ->with(
                'process_scheduled_withdraws',
                $this->isType('callable'),
                300
            )
            ->willReturnCallback(function ($key, $callback, $ttl) {
                return $callback();
            });

        $this->withdrawRepository
            ->expects($this->once())
            ->method('findPendingScheduled')
            ->willReturn($pending);

        $result = $this->service->processScheduledWithdraws();
        
        $this->assertEquals(0, $result);
    }

    public function testProcessScheduledWithdrawsProcessesMultipleWithdraws(): void
    {
        $withdraw1 = new AccountWithdraw();
        $withdraw1->id = 'withdraw-1';
        $withdraw1->account_id = 'account-1';
        $withdraw1->amount = '50.00';
        $withdraw1->done = false;

        $withdraw2 = new AccountWithdraw();
        $withdraw2->id = 'withdraw-2';
        $withdraw2->account_id = 'account-2';
        $withdraw2->amount = '75.00';
        $withdraw2->done = false;

        $this->lockService
            ->expects($this->once())
            ->method('executeWithLock')
            ->willReturnCallback(function ($key, $callback, $ttl) {
                return $callback();
            });

        $this->withdrawRepository
            ->expects($this->once())
            ->method('findPendingScheduled')
            ->willReturn([$withdraw1, $withdraw2]);

        // Mock processWithdraw para retornar true
        $this->withdrawRepository
            ->method('findById')
            ->willReturnCallback(function ($id) use ($withdraw1, $withdraw2) {
                if ($id === 'withdraw-1') {
                    $pix = new AccountWithdrawPix();
                    $pix->type = 'email';
                    $pix->key = 'test1@email.com';
                    $withdraw1->setRelation('pix', $pix);
                    return $withdraw1;
                }
                if ($id === 'withdraw-2') {
                    $pix = new AccountWithdrawPix();
                    $pix->type = 'email';
                    $pix->key = 'test2@email.com';
                    $withdraw2->setRelation('pix', $pix);
                    return $withdraw2;
                }
                return null;
            });

        $this->accountRepository
            ->method('hasSufficientBalance')
            ->willReturn(true);

        $this->accountRepository
            ->method('updateBalance')
            ->willReturn(true);

        $this->withdrawRepository
            ->method('markAsDone')
            ->willReturn(true);

        $this->emailService
            ->method('sendWithdrawNotification')
            ->willReturn(true);

        $result = $this->service->processScheduledWithdraws();
        
        $this->assertEquals(2, $result);
    }

    public function testProcessScheduledWithdrawsHandlesErrorsGracefully(): void
    {
        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-1';
        $withdraw->account_id = 'account-1';
        $withdraw->amount = '50.00';
        $withdraw->done = false;

        $this->lockService
            ->expects($this->once())
            ->method('executeWithLock')
            ->willReturnCallback(function ($key, $callback, $ttl) {
                return $callback();
            });

        $this->withdrawRepository
            ->expects($this->once())
            ->method('findPendingScheduled')
            ->willReturn([$withdraw]);

        $this->withdrawRepository
            ->expects($this->once())
            ->method('findById')
            ->willThrowException(new \Exception('Database error'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Error processing scheduled withdraw');

        $result = $this->service->processScheduledWithdraws();
        
        $this->assertEquals(0, $result);
    }

    public function testProcessScheduledWithdrawsReturnsZeroWhenLockNotAcquired(): void
    {
        $this->lockService
            ->expects($this->once())
            ->method('executeWithLock')
            ->willReturn(null); // Lock não adquirido

        $result = $this->service->processScheduledWithdraws();
        
        $this->assertEquals(0, $result);
    }
}

