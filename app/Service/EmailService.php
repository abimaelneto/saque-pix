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
        private RetryService $retryService,
    ) {
    }

    /**
     * Envia notificação de saque agendado (quando criado)
     */
    public function sendScheduledWithdrawNotification(AccountWithdraw $withdraw): bool
    {
        try {
            $pix = $withdraw->pix;
            
            if (!$pix) {
                $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
                $this->logger->error('PIX data not found for scheduled withdraw', [
                    'correlation_id' => $correlationId,
                    'withdraw_id' => $withdraw->id,
                ]);
                return false;
            }

            $scheduledFor = $withdraw->scheduled_for;
            if ($scheduledFor instanceof \DateTime) {
                $dateTime = $scheduledFor->format('d/m/Y H:i');
            } elseif (is_string($scheduledFor)) {
                $dateTime = $scheduledFor;
            } else {
                $dateTime = 'Data não informada';
            }

            $subject = 'Saque PIX Agendado';
            $body = $this->buildScheduledEmailBody($withdraw, $pix, $dateTime);

            // Em ambiente de testes, não tentar enviar emails reais
            if (env('APP_ENV') === 'testing') {
                $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
                $this->logger->info('Scheduled email send skipped in testing environment', [
                    'correlation_id' => $correlationId,
                    'withdraw_id' => $withdraw->id,
                    'email' => $pix->key,
                ]);
                return true;
            }

            $mailHost = env('MAIL_HOST', 'mailhog');
            $mailPort = (int) env('MAIL_PORT', 1025);
            $fromAddress = env('MAIL_FROM_ADDRESS', 'noreply@saque-pix.local');
            $fromName = env('MAIL_FROM_NAME', 'Saque PIX');
            
            $sent = $this->retryService->executeWithRetry(
                function () use ($pix, $subject, $body, $mailHost, $mailPort, $fromAddress, $fromName) {
                    return $this->sendViaSMTP(
                        $mailHost,
                        $mailPort,
                        $fromAddress,
                        $fromName,
                        $pix->key,
                        $subject,
                        $body
                    );
                },
                maxRetries: 3,
                initialDelayMs: 1000
            );

            $this->metricsService->recordEmailSent($sent);
            
            $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id;
            $this->logger->info('Scheduled withdraw notification email sent', [
                'correlation_id' => $correlationId,
                'withdraw_id' => $withdraw->id,
                'email' => $pix->key,
                'sent' => $sent,
            ]);

            return $sent;
        } catch (\Exception $e) {
            $this->metricsService->recordEmailSent(false);
            
            $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
            $this->logger->error('Failed to send scheduled withdraw notification email', [
                'correlation_id' => $correlationId,
                'withdraw_id' => $withdraw->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Envia notificação de cancelamento de saque agendado
     */
    public function sendWithdrawCancellationNotification(AccountWithdraw $withdraw): bool
    {
        try {
            $pix = $withdraw->pix;
            
            if (!$pix) {
                $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
                $this->logger->error('PIX data not found for cancelled withdraw', [
                    'correlation_id' => $correlationId,
                    'withdraw_id' => $withdraw->id,
                ]);
                return false;
            }

            $subject = 'Saque PIX Cancelado';
            $body = $this->buildCancellationEmailBody($withdraw, $pix);

            if (env('APP_ENV') === 'testing') {
                $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
                $this->logger->info('Cancellation email send skipped in testing environment', [
                    'correlation_id' => $correlationId,
                    'withdraw_id' => $withdraw->id,
                    'email' => $pix->key,
                ]);
                return true;
            }

            $mailHost = env('MAIL_HOST', 'mailhog');
            $mailPort = (int) env('MAIL_PORT', 1025);
            $fromAddress = env('MAIL_FROM_ADDRESS', 'noreply@saque-pix.local');
            $fromName = env('MAIL_FROM_NAME', 'Saque PIX');
            
            $sent = $this->retryService->executeWithRetry(
                function () use ($pix, $subject, $body, $mailHost, $mailPort, $fromAddress, $fromName) {
                    return $this->sendViaSMTP(
                        $mailHost,
                        $mailPort,
                        $fromAddress,
                        $fromName,
                        $pix->key,
                        $subject,
                        $body
                    );
                },
                maxRetries: 3,
                initialDelayMs: 1000
            );

            $this->metricsService->recordEmailSent($sent);
            
            $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id;
            $this->logger->info('Withdraw cancellation notification email sent', [
                'correlation_id' => $correlationId,
                'withdraw_id' => $withdraw->id,
                'email' => $pix->key,
                'sent' => $sent,
            ]);

            return $sent;
        } catch (\Exception $e) {
            $this->metricsService->recordEmailSent(false);
            
            $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
            $this->logger->error('Failed to send withdraw cancellation notification email', [
                'correlation_id' => $correlationId,
                'withdraw_id' => $withdraw->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendWithdrawNotification(AccountWithdraw $withdraw): bool
    {
        try {
            $pix = $withdraw->pix;
            
            if (!$pix) {
                $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
                $this->logger->error('PIX data not found for withdraw', [
                    'correlation_id' => $correlationId,
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
                $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
                $this->logger->info('Email send skipped in testing environment', [
                    'correlation_id' => $correlationId,
                    'withdraw_id' => $withdraw->id,
                    'email' => $pix->key,
                ]);

                return true;
            }

            // Implementação usando SMTP diretamente com Mailhog
            // Nota: Para produção, considerar usar serviço de email (SES, SendGrid, etc.)
            // ou enfileirar via queue system (ver docs_ia/serverless-reference para exemplos)
            $mailHost = env('MAIL_HOST', 'mailhog');
            $mailPort = (int) env('MAIL_PORT', 1025);
            $fromAddress = env('MAIL_FROM_ADDRESS', 'noreply@saque-pix.local');
            $fromName = env('MAIL_FROM_NAME', 'Saque PIX');
            
            // Usar retry logic para enviar email (3 tentativas com exponential backoff)
            $sent = $this->retryService->executeWithRetry(
                function () use ($pix, $subject, $body, $mailHost, $mailPort, $fromAddress, $fromName) {
                    return $this->sendViaSMTP(
                        $mailHost,
                        $mailPort,
                        $fromAddress,
                        $fromName,
                        $pix->key,
                        $subject,
                        $body
                    );
                },
                maxRetries: 3,
                initialDelayMs: 1000 // 1 segundo inicial
            );

            $this->metricsService->recordEmailSent($sent);
            
            $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id;
            $this->logger->info('Withdraw notification email sent', [
                'correlation_id' => $correlationId,
                'withdraw_id' => $withdraw->id,
                'email' => $pix->key,
                'sent' => $sent,
            ]);

            return $sent;
        } catch (\Exception $e) {
            $this->metricsService->recordEmailSent(false);
            
            $correlationId = \Hyperf\Context\Context::get(\App\Middleware\CorrelationIdMiddleware::CORRELATION_ID_CONTEXT_KEY) ?? $withdraw->correlation_id ?? null;
            $this->logger->error('Failed to send withdraw notification email', [
                'correlation_id' => $correlationId,
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

    private function buildScheduledEmailBody(AccountWithdraw $withdraw, $pix, string $scheduledFor): string
    {
        $amount = number_format((float) $withdraw->amount, 2, ',', '.');

        return "
        <html>
        <body>
            <h2>Saque PIX Agendado</h2>
            <p>Seu saque PIX foi agendado com sucesso!</p>
            <p><strong>Valor a ser sacado:</strong> R$ {$amount}</p>
            <p><strong>Agendado para:</strong> {$scheduledFor}</p>
            <p><strong>Tipo de Chave PIX:</strong> {$pix->type}</p>
            <p><strong>Chave PIX:</strong> {$pix->key}</p>
            <p><em>Você receberá uma nova notificação quando o saque for processado.</em></p>
        </body>
        </html>
        ";
    }

    private function buildCancellationEmailBody(AccountWithdraw $withdraw, $pix): string
    {
        $amount = number_format((float) $withdraw->amount, 2, ',', '.');

        return "
        <html>
        <body>
            <h2>Saque PIX Cancelado</h2>
            <p>Informamos que seu saque PIX agendado foi cancelado.</p>
            <p><strong>Valor do saque:</strong> R$ {$amount}</p>
            <p><strong>Tipo de Chave PIX:</strong> {$pix->type}</p>
            <p><strong>Chave PIX:</strong> {$pix->key}</p>
            <p><em>O valor não foi debitado da sua conta.</em></p>
        </body>
        </html>
        ";
    }

    /**
     * Envia email via SMTP diretamente (compatível com Mailhog)
     */
    private function sendViaSMTP(
        string $host,
        int $port,
        string $fromAddress,
        string $fromName,
        string $toAddress,
        string $subject,
        string $body
    ): bool {
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        
        if (!$socket) {
            throw new \RuntimeException("Failed to connect to SMTP server: {$errstr} ({$errno})");
        }

        try {
            // Ler resposta inicial
            $this->readSMTPResponse($socket);

            // EHLO
            fwrite($socket, "EHLO {$host}\r\n");
            $this->readSMTPResponse($socket);

            // MAIL FROM
            fwrite($socket, "MAIL FROM:<{$fromAddress}>\r\n");
            $this->readSMTPResponse($socket);

            // RCPT TO
            fwrite($socket, "RCPT TO:<{$toAddress}>\r\n");
            $this->readSMTPResponse($socket);

            // DATA
            fwrite($socket, "DATA\r\n");
            $this->readSMTPResponse($socket);

            // Headers e corpo
            $emailContent = "From: {$fromName} <{$fromAddress}>\r\n";
            $emailContent .= "To: <{$toAddress}>\r\n";
            $emailContent .= "Subject: {$subject}\r\n";
            $emailContent .= "MIME-Version: 1.0\r\n";
            $emailContent .= "Content-Type: text/html; charset=UTF-8\r\n";
            $emailContent .= "\r\n";
            $emailContent .= $body;
            $emailContent .= "\r\n.\r\n";

            fwrite($socket, $emailContent);
            $this->readSMTPResponse($socket);

            // QUIT
            fwrite($socket, "QUIT\r\n");
            $this->readSMTPResponse($socket);

            return true;
        } finally {
            fclose($socket);
        }
    }

    /**
     * Lê resposta do servidor SMTP
     */
    private function readSMTPResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        
        $code = (int) substr($response, 0, 3);
        if ($code >= 400) {
            throw new \RuntimeException("SMTP error: {$response}");
        }
        
        return $response;
    }
}

