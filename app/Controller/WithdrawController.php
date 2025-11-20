<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\WithdrawRequestDTO;
use App\Service\AuditService;
use App\Service\WithdrawService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Validation\Annotation\Validation;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Controller(prefix: '/account')]
class WithdrawController
{
    public function __construct(
        private WithdrawService $withdrawService,
        private ValidatorFactoryInterface $validatorFactory,
        private AuditService $auditService,
    ) {
    }

    #[PostMapping(path: '/{accountId}/balance/withdraw')]
    public function withdraw(
        string $accountId,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): PsrResponseInterface {
        // Verificar autorização: usuário só pode acessar sua própria conta
        $userAccountId = $request->getAttribute('account_id');
        $userId = $request->getAttribute('user_id');
        
        if ($userAccountId && $userAccountId !== $accountId) {
            $this->auditService->logUnauthorizedAccess(
                "withdraw:{$accountId}",
                $userId,
                $userAccountId
            );
            
            return $response->json([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to access this account',
            ])->withStatus(403);
        }

        $data = $request->getParsedBody() ?? [];

        // Validar UUID da conta
        if (!\Ramsey\Uuid\Uuid::isValid($accountId)) {
            return $response->json([
                'error' => 'Invalid account ID format',
            ])->withStatus(400);
        }

        // Validar e sanitizar amount (limite máximo)
        $maxAmount = 50000.00; // R$ 50.000,00 por saque
        if (isset($data['amount']) && (float) $data['amount'] > $maxAmount) {
            return $response->json([
                'error' => 'Validation failed',
                'messages' => ["Amount cannot exceed R$ " . number_format($maxAmount, 2, ',', '.')],
            ])->withStatus(422);
        }

        // Validar dados do request (com sanitização)
        $validator = $this->validatorFactory->make($data, [
            'method' => 'required|string|in:PIX',
            'pix.type' => 'required|string|in:email',
            'pix.key' => 'required|email|max:255',
            'amount' => 'required|numeric|min:0.01|max:50000',
            'schedule' => 'nullable|date_format:Y-m-d H:i|after:now',
        ]);

        if ($validator->fails()) {
            return $response->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()->all(),
            ])->withStatus(422);
        }

        try {
            // Sanitizar inputs
            $sanitizedData = [
                'method' => strtoupper(trim($data['method'] ?? '')),
                'pix' => [
                    'type' => strtolower(trim($data['pix']['type'] ?? '')),
                    'key' => filter_var(trim($data['pix']['key'] ?? ''), FILTER_SANITIZE_EMAIL),
                ],
                'amount' => (string) filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT, [
                    'options' => ['min_range' => 0.01, 'max_range' => 50000]
                ]),
                'schedule' => $data['schedule'] ? trim($data['schedule']) : null,
            ];

            $dto = new WithdrawRequestDTO(
                accountId: $accountId,
                method: $sanitizedData['method'],
                pixType: $sanitizedData['pix']['type'],
                pixKey: $sanitizedData['pix']['key'],
                amount: $sanitizedData['amount'],
                schedule: $sanitizedData['schedule'],
            );

            // Registrar auditoria antes de criar
            $this->auditService->logWithdrawCreated(
                'pending', // Será atualizado após criação
                $accountId,
                $sanitizedData['amount'],
                $dto->isScheduled(),
                $userId
            );

            $withdraw = $this->withdrawService->createWithdraw($dto, $userId);

            return $response->json([
                'success' => true,
                'data' => [
                    'id' => $withdraw->id,
                    'account_id' => $withdraw->account_id,
                    'method' => $withdraw->method,
                    'amount' => $withdraw->amount,
                    'scheduled' => $withdraw->scheduled,
                    'scheduled_for' => $withdraw->scheduled_for?->format('Y-m-d H:i:s'),
                    'done' => $withdraw->done,
                    'error' => $withdraw->error,
                    'error_reason' => $withdraw->error_reason,
                    'created_at' => $withdraw->created_at->format('Y-m-d H:i:s'),
                ],
            ])->withStatus(201);
        } catch (\InvalidArgumentException $e) {
            // Não expor detalhes internos em produção
            $message = env('APP_ENV') === 'production' 
                ? 'Invalid request' 
                : $e->getMessage();
                
            return $response->json([
                'error' => $message,
            ])->withStatus(400);
        } catch (\Exception $e) {
            // Log do erro completo, mas não expor ao cliente
            \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Psr\Log\LoggerInterface::class)
                ->error('Error processing withdraw', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

            return $response->json([
                'error' => 'Internal server error',
            ])->withStatus(500);
        }
    }
}

