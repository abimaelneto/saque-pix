<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\DTO\WithdrawRequestDTO;
use App\Model\Account;
use App\Model\AccountWithdraw;
use App\Model\AccountWithdrawPix;
use App\Factory\WithdrawMethodStrategyFactory;
use App\Repository\AccountRepository;
use App\Repository\AccountWithdrawRepository;
use App\Service\AuditService;
use App\Service\DistributedLockService;
use App\Service\EmailService;
use App\Service\EventDispatcherService;
use App\Service\FraudDetectionService;
use App\Service\MetricsService;
use App\Service\WithdrawService;
use Hyperf\DbConnection\Db;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WithdrawServiceTest extends TestCase
{
    private WithdrawService $service;
    private MockObject $accountRepository;
    private MockObject $withdrawRepository;
    private MockObject $emailService;
    private MockObject $logger;
    private MockObject $lockService;
    private MockObject $metricsService;
    private MockObject $fraudDetectionService;
    private MockObject $auditService;
    private MockObject $strategyFactory;
    private MockObject $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->accountRepository = $this->createMock(AccountRepository::class);
        $this->withdrawRepository = $this->createMock(AccountWithdrawRepository::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->lockService = $this->createMock(DistributedLockService::class);
        $this->metricsService = $this->createMock(MetricsService::class);
        $this->fraudDetectionService = $this->createMock(FraudDetectionService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->strategyFactory = $this->createMock(WithdrawMethodStrategyFactory::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherService::class);

        $this->service = new WithdrawService(
            $this->accountRepository,
            $this->withdrawRepository,
            $this->emailService,
            $this->logger,
            $this->lockService,
            $this->metricsService,
            $this->fraudDetectionService,
            $this->auditService,
            $this->strategyFactory,
            $this->eventDispatcher
        );
    }

    public function testCreateWithdrawThrowsExceptionWhenAccountNotFound(): void
    {
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: null,
        );

        $this->accountRepository
            ->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Account not found');

        $this->service->createWithdraw($dto);
    }

    public function testCreateWithdrawThrowsExceptionWhenInsufficientBalance(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $account = new Account();
        $account->id = $accountId;
        $account->balance = '50.00';

        $dto = new WithdrawRequestDTO(
            accountId: $accountId,
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: null,
        );

        $this->accountRepository
            ->expects($this->once())
            ->method('findById')
            ->willReturn($account);

        $this->accountRepository
            ->expects($this->once())
            ->method('hasSufficientBalance')
            ->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient balance');

        $this->service->createWithdraw($dto);
    }

    public function testCreateWithdrawThrowsExceptionWhenScheduleIsInPast(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $account = new Account();
        $account->id = $accountId;
        $account->balance = '200.00';

        $pastDate = (new \DateTime())->modify('-1 day')->format('Y-m-d H:i');
        
        $dto = new WithdrawRequestDTO(
            accountId: $accountId,
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: $pastDate,
        );

        $this->accountRepository
            ->expects($this->once())
            ->method('findById')
            ->willReturn($account);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot schedule withdraw for past date');

        $this->service->createWithdraw($dto);
    }

    public function testCreateImmediateWithdrawSuccessfully(): void
    {
        $accountId = '123e4567-e89b-12d3-a456-426614174000';
        $account = new Account();
        $account->id = $accountId;
        $account->balance = '200.00';

        $dto = new WithdrawRequestDTO(
            accountId: $accountId,
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: null,
        );

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->account_id = $accountId;

        $this->accountRepository
            ->expects($this->once())
            ->method('findById')
            ->willReturn($account);

        $this->accountRepository
            ->expects($this->once())
            ->method('hasSufficientBalance')
            ->willReturn(true);

        $this->withdrawRepository
            ->expects($this->once())
            ->method('create')
            ->willReturn($withdraw);

        $this->withdrawRepository
            ->expects($this->exactly(2))
            ->method('findById')
            ->willReturn($withdraw);

        $this->accountRepository
            ->expects($this->once())
            ->method('updateBalance')
            ->willReturn(true);

        $this->withdrawRepository
            ->expects($this->once())
            ->method('markAsDone')
            ->willReturn(true);

        // Criar PIX mockado
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';
        $withdraw->setRelation('pix', $pix);

        $this->emailService
            ->expects($this->once())
            ->method('sendWithdrawNotification')
            ->with($withdraw)
            ->willReturn(true);

        // Mock do processWithdraw que será chamado internamente
        $result = $this->service->createWithdraw($dto);
        
        $this->assertInstanceOf(AccountWithdraw::class, $result);
    }
}

