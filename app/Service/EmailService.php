<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AccountWithdraw;
use Hyperf\Mailer\MailerFactory;
use Psr\Log\LoggerInterface;

class EmailService
{
    public function __construct(
        private MailerFactory $mailerFactory,
        private LoggerInterface $logger,
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

            $mailer = $this->mailerFactory->get();
            $mailer->to($pix->key)->send(function ($message) use ($subject, $body) {
                $message->subject($subject)->html($body);
            });

            $this->logger->info('Withdraw notification email sent', [
                'withdraw_id' => $withdraw->id,
                'email' => $pix->key,
            ]);

            return true;
        } catch (\Exception $e) {
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

