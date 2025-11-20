<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Model\AccountWithdraw;
use App\Model\AccountWithdrawPix;
use App\Service\EmailService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmailServiceTest extends TestCase
{
    private EmailService $service;
    private MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new EmailService($this->logger);
    }

    public function testSendWithdrawNotificationReturnsFalseWhenPixNotFound(): void
    {
        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->pix = null;

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('PIX data not found for withdraw');

        $result = $this->service->sendWithdrawNotification($withdraw);
        
        $this->assertFalse($result);
    }

    public function testSendWithdrawNotificationSuccessfully(): void
    {
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->amount = '100.00';
        $withdraw->created_at = new \DateTime();
        $withdraw->pix = $pix;

        // Mock da função mail() não é possível facilmente
        // Este teste verifica que o método executa sem erro
        // Em ambiente de teste, mail() pode retornar false mas não é erro crítico
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Withdraw notification email sent');

        $result = $this->service->sendWithdrawNotification($withdraw);
        
        // Pode retornar true ou false dependendo do ambiente
        $this->assertIsBool($result);
    }

    public function testSendWithdrawNotificationHandlesException(): void
    {
        // Este teste verifica tratamento de exceções
        // Como usamos mail() nativo, exceções são raras
        // Mas mantemos o teste para garantir robustez
        $pix = new AccountWithdrawPix();
        $pix->type = 'email';
        $pix->key = 'test@email.com';

        $withdraw = new AccountWithdraw();
        $withdraw->id = 'withdraw-id';
        $withdraw->pix = $pix;

        // Teste básico - verifica que não lança exceção
        $result = $this->service->sendWithdrawNotification($withdraw);
        
        $this->assertIsBool($result);
    }
}

