<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AccountWithdraw;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

class EmailService
{
    public function __construct(
        private LoggerInterface $logger,
        private MetricsService $metricsService,
    ) {
    }

    public function sendWithdrawNotification(AccountWithdraw $withdraw): bool
    {
        try {
            $pix = $withdraw->pix;
            
            if (!$pix) {
                $this->logger->error('PIX data not found for withdraw', [
                    'withdraw_id' => $withdraw->id,
                ]);
                return false;
            }

            $processedAt = $withdraw->processed_at ?? $withdraw->created_at;
            if ($processedAt instanceof \DateTime) {
                $dateTime = $processedAt->format('d/m/Y H:i:s');
            } elseif (is_string($processedAt)) {
                $dateTime = $processedAt;
            } else {
                $dateTime = date('d/m/Y H:i:s');
            }

            $subject = 'Saque PIX Realizado';
            $body = $this->buildEmailBody($withdraw, $pix, $dateTime);

            // Em ambiente de testes, não tentar enviar emails reais
            if (env('APP_ENV') === 'testing') {
                $this->logger->info('Email send skipped in testing environment', [
                    'withdraw_id' => $withdraw->id,
                    'email' => $pix->key,
                ]);

                return true;
            }

            // Implementação usando mail() do PHP
            // Em produção serverless, usar serviço de email (SES, SendGrid, etc.)
            // ou enfileirar via queue system
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . env('MAIL_FROM_ADDRESS', 'noreply@saque-pix.local') . "\r\n";
            
            $sent = @mail($pix->key, $subject, $body, $headers);

            $this->metricsService->recordEmailSent($sent);
            
            $this->logger->info('Withdraw notification email sent', [
                'withdraw_id' => $withdraw->id,
                'email' => $pix->key,
                'sent' => $sent,
            ]);

            return $sent;
        } catch (\Exception $e) {
            $this->metricsService->recordEmailSent(false);
            
            $this->logger->error('Failed to send withdraw notification email', [
                'withdraw_id' => $withdraw->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function buildEmailBody(AccountWithdraw $withdraw, $pix, string $dateTime): string
    {
        $amount = number_format((float) $withdraw->amount, 2, ',', '.');

        return "
        <html>
        <body>
            <h2>Saque PIX Realizado</h2>
            <p>Informamos que seu saque PIX foi efetuado com sucesso.</p>
            <p><strong>Data e Hora do Saque:</strong> {$dateTime}</p>
            <p><strong>Valor Sacado:</strong> R$ {$amount}</p>
            <p><strong>Tipo de Chave PIX:</strong> {$pix->type}</p>
            <p><strong>Chave PIX:</strong> {$pix->key}</p>
        </body>
        </html>
        ";
    }
}

