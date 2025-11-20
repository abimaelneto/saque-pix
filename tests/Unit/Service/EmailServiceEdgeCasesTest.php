<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Model\AccountWithdraw;
use App\Model\AccountWithdrawPix;
use App\Service\EmailService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Testes unitários para edge cases do EmailService
 * Cobre RF04 - Enviar Email de Notificação
 */
class EmailServiceEdgeCasesTest extends TestCase
{
    private EmailService $service;
    private MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new EmailService($this->logger);
    }

    public function testSendWithdrawNotificationWithDateTimeProcessedAt(): void
    {
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->amount = '100.00';
        $withdraw->processed_at = new \DateTime('2026-01-01 15:30:00');
        $withdraw->pix = $pix;

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Withdraw notification email sent');

        $result = $this->service->sendWithdrawNotification($withdraw);
        
        $this->assertIsBool($result);
    }

    public function testSendWithdrawNotificationWithStringProcessedAt(): void
    {
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->amount = '100.00';
        $withdraw->processed_at = '2026-01-01 15:30:00';
        $withdraw->pix = $pix;

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Withdraw notification email sent');

        $result = $this->service->sendWithdrawNotification($withdraw);
        
        $this->assertIsBool($result);
    }

    public function testSendWithdrawNotificationWithCreatedAtFallback(): void
    {
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->amount = '100.00';
        $withdraw->processed_at = null;
        $withdraw->created_at = new \DateTime('2026-01-01 14:00:00');
        $withdraw->pix = $pix;

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Withdraw notification email sent');

        $result = $this->service->sendWithdrawNotification($withdraw);
        
        $this->assertIsBool($result);
    }

    public function testSendWithdrawNotificationWithNullProcessedAtAndCreatedAt(): void
    {
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->amount = '100.00';
        $withdraw->processed_at = null;
        $withdraw->created_at = null;
        $withdraw->pix = $pix;

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Withdraw notification email sent');

        $result = $this->service->sendWithdrawNotification($withdraw);
        
        $this->assertIsBool($result);
    }

    public function testSendWithdrawNotificationWithLargeAmount(): void
    {
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->amount = '999999.99';
        $withdraw->created_at = new \DateTime();
        $withdraw->pix = $pix;

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Withdraw notification email sent');

        $result = $this->service->sendWithdrawNotification($withdraw);
        
        $this->assertIsBool($result);
    }

    public function testSendWithdrawNotificationWithSmallAmount(): void
    {
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->amount = '0.01';
        $withdraw->created_at = new \DateTime();
        $withdraw->pix = $pix;

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Withdraw notification email sent');

        $result = $this->service->sendWithdrawNotification($withdraw);
        
        $this->assertIsBool($result);
    }

    public function testSendWithdrawNotificationWithLongEmail(): void
    {
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'very.long.email.address.that.might.cause.issues@verylongdomainname.example.com';

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->amount = '100.00';
        $withdraw->created_at = new \DateTime();
        $withdraw->pix = $pix;

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Withdraw notification email sent');

        $result = $this->service->sendWithdrawNotification($withdraw);
        
        $this->assertIsBool($result);
    }
}

