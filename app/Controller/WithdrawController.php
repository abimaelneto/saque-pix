<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\WithdrawRequestDTO;
use App\Service\AuditService;
use App\Service\WithdrawService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Validation\Annotation\Validation;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Rotas definidas em config/routes.php
class WithdrawController
{
    public function __construct(
        private WithdrawService $withdrawService,
        private ValidatorFactoryInterface $validatorFactory,
        private AuditService $auditService,
    ) {
    }

    public function withdraw(
        string $accountId,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): PsrResponseInterface {
        // Validar que usuário está autenticado
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->json([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Authentication required',
            ])->withStatus(401);
        }

        // Verificar autorização: usuário só pode acessar sua própria conta
        $userAccountId = $request->getAttribute('account_id');
        
        // Se account_id não foi fornecido no token, não permitir acesso
        // (em produção, o token JWT deve sempre incluir account_id)
        if (!$userAccountId) {
            $this->auditService->logUnauthorizedAccess(
                "withdraw:{$accountId}",
                $userId,
                null
            );
            
            return $response->json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Account ID not found in token. Access denied.',
            ])->withStatus(403);
        }
        
        if ($userAccountId !== $accountId) {
            $this->auditService->logUnauthorizedAccess(
                "withdraw:{$accountId}",
                $userId,
                $userAccountId
            );
            
            return $response->json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'You do not have permission to access this account',
            ])->withStatus(403);
        }

        $data = $request->getParsedBody() ?? [];
        
        // Obter idempotency key do header (padrão: Idempotency-Key)
        $idempotencyKey = $request->getHeaderLine('Idempotency-Key') 
            ?: $request->getHeaderLine('X-Idempotency-Key')
            ?: null;

        // Validar UUID da conta
        if (!\Ramsey\Uuid\Uuid::isValid($accountId)) {
            return $response->json([
                'success' => false,
                'error' => 'Invalid account ID format',
                'message' => 'Account ID must be a valid UUID',
            ])->withStatus(400);
        }

        // Validar e sanitizar amount (limite máximo)
        $maxAmount = 50000.00; // R$ 50.000,00 por saque
        if (isset($data['amount']) && (float) $data['amount'] > $maxAmount) {
            return $response->json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Amount exceeds maximum allowed',
                'errors' => ["Amount cannot exceed R$ " . number_format($maxAmount, 2, ',', '.')],
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
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Invalid input data',
                'errors' => $validator->errors()->all(),
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

            $withdraw = $this->withdrawService->createWithdraw($dto, $userId, $idempotencyKey);

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
            // Log do erro
            $logger = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Psr\Log\LoggerInterface::class);
            $logger->warning('Invalid argument in withdraw', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            
            // Verificar se é "Account not found" para retornar 404
            if (stripos($e->getMessage(), 'account not found') !== false) {
                return $response->json([
                    'success' => false,
                    'error' => 'Account not found',
                    'message' => "Account with ID '{$accountId}' does not exist",
                ])->withStatus(404);
            }
            
            // Verificar se é "Insufficient balance" para retornar 400
            if (stripos($e->getMessage(), 'insufficient balance') !== false) {
                return $response->json([
                    'success' => false,
                    'error' => 'Insufficient balance',
                    'message' => 'Account does not have sufficient balance for this withdrawal',
                ])->withStatus(400);
            }
                
            return $response->json([
                'success' => false,
                'error' => 'Invalid request',
                'message' => env('APP_ENV') === 'production' 
                    ? 'Invalid request parameters' 
                    : $e->getMessage(),
            ])->withStatus(400);
        } catch (\RuntimeException $e) {
            // Log do erro
            \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Psr\Log\LoggerInterface::class)
                ->warning('Runtime error in withdraw', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);

            return $response->json([
                'success' => false,
                'error' => 'Request failed',
                'message' => $e->getMessage(),
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
                'success' => false,
                'error' => 'Internal server error',
                'message' => env('APP_ENV') === 'production' 
                    ? 'An unexpected error occurred' 
                    : $e->getMessage(),
            ])->withStatus(500);
        }
    }

    /**
     * Cancela um saque agendado
     * DELETE /account/{accountId}/withdraw/{withdrawId}
     */
    public function cancel(
        string $accountId,
        string $withdrawId,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): PsrResponseInterface {
        // Validar que usuário está autenticado
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->json([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Authentication required',
            ])->withStatus(401);
        }

        // Verificar autorização: usuário só pode cancelar saques da própria conta
        $userAccountId = $request->getAttribute('account_id');
        
        if (!$userAccountId) {
            $this->auditService->logUnauthorizedAccess(
                "cancel_withdraw:{$withdrawId}",
                $userId,
                null
            );
            
            return $response->json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Account ID not found in token. Access denied.',
            ])->withStatus(403);
        }
        
        if ($userAccountId !== $accountId) {
            $this->auditService->logUnauthorizedAccess(
                "cancel_withdraw:{$withdrawId}",
                $userId,
                $userAccountId
            );
            
            return $response->json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'You do not have permission to cancel this withdraw',
            ])->withStatus(403);
        }

        // Validar UUIDs
        if (!\Ramsey\Uuid\Uuid::isValid($accountId)) {
            return $response->json([
                'success' => false,
                'error' => 'Invalid account ID format',
                'message' => 'Account ID must be a valid UUID',
            ])->withStatus(400);
        }

        if (!\Ramsey\Uuid\Uuid::isValid($withdrawId)) {
            return $response->json([
                'success' => false,
                'error' => 'Invalid withdraw ID format',
                'message' => 'Withdraw ID must be a valid UUID',
            ])->withStatus(400);
        }

        try {
            $cancelled = $this->withdrawService->cancelScheduledWithdraw($withdrawId, $userId);

            if (!$cancelled) {
                return $response->json([
                    'success' => false,
                    'error' => 'Failed to cancel withdraw',
                    'message' => 'Unable to cancel the withdraw',
                ])->withStatus(500);
            }

            // Buscar withdraw atualizado
            $withdraw = $this->withdrawService->getWithdrawById($withdrawId);

            return $response->json([
                'success' => true,
                'data' => [
                    'id' => $withdraw->id,
                    'account_id' => $withdraw->account_id,
                    'cancelled' => true,
                    'error' => $withdraw->error,
                    'error_reason' => $withdraw->error_reason,
                    'updated_at' => $withdraw->updated_at->format('Y-m-d H:i:s'),
                ],
                'message' => 'Scheduled withdraw cancelled successfully',
            ]);
        } catch (\InvalidArgumentException $e) {
            $logger = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Psr\Log\LoggerInterface::class);
            $logger->warning('Invalid argument in cancel withdraw', [
                'account_id' => $accountId,
                'withdraw_id' => $withdrawId,
                'error' => $e->getMessage(),
            ]);

            // Verificar tipo de erro
            if (stripos($e->getMessage(), 'not found') !== false) {
                return $response->json([
                    'success' => false,
                    'error' => 'Withdraw not found',
                    'message' => "Withdraw with ID '{$withdrawId}' does not exist",
                ])->withStatus(404);
            }

            if (stripos($e->getMessage(), 'only scheduled') !== false) {
                return $response->json([
                    'success' => false,
                    'error' => 'Invalid withdraw type',
                    'message' => 'Only scheduled withdraws can be cancelled',
                ])->withStatus(400);
            }

            if (stripos($e->getMessage(), 'already processed') !== false) {
                return $response->json([
                    'success' => false,
                    'error' => 'Cannot cancel',
                    'message' => 'Cannot cancel an already processed withdraw',
                ])->withStatus(400);
            }

            return $response->json([
                'success' => false,
                'error' => 'Invalid request',
                'message' => env('APP_ENV') === 'production' 
                    ? 'Invalid request parameters' 
                    : $e->getMessage(),
            ])->withStatus(400);
        } catch (\Exception $e) {
            $logger = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Psr\Log\LoggerInterface::class);
            $logger->error('Error cancelling withdraw', [
                'account_id' => $accountId,
                'withdraw_id' => $withdrawId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => env('APP_ENV') === 'production' 
                    ? 'An unexpected error occurred' 
                    : $e->getMessage(),
            ])->withStatus(500);
        }
    }

    /**
     * Lista saques de uma conta
     * GET /account/{accountId}/withdraws
     */
    public function list(
        string $accountId,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): PsrResponseInterface {
        // Validar que usuário está autenticado
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->json([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Authentication required',
            ])->withStatus(401);
        }

        // Verificar autorização: usuário só pode ver saques da própria conta
        $userAccountId = $request->getAttribute('account_id');
        
        if (!$userAccountId) {
            $this->auditService->logUnauthorizedAccess(
                "list_withdraws:{$accountId}",
                $userId,
                null
            );
            
            return $response->json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Account ID not found in token. Access denied.',
            ])->withStatus(403);
        }
        
        if ($userAccountId !== $accountId) {
            $this->auditService->logUnauthorizedAccess(
                "list_withdraws:{$accountId}",
                $userId,
                $userAccountId
            );
            
            return $response->json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'You do not have permission to access this account',
            ])->withStatus(403);
        }

        // Validar UUID da conta
        if (!\Ramsey\Uuid\Uuid::isValid($accountId)) {
            return $response->json([
                'success' => false,
                'error' => 'Invalid account ID format',
                'message' => 'Account ID must be a valid UUID',
            ])->withStatus(400);
        }

        try {
            $withdraws = \App\Model\AccountWithdraw::with(['pix'])
                ->where('account_id', $accountId)
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get();

            return $response->json([
                'success' => true,
                'count' => $withdraws->count(),
                'data' => $withdraws->map(function ($withdraw) {
                    return [
                        'id' => $withdraw->id,
                        'account_id' => $withdraw->account_id,
                        'method' => $withdraw->method,
                        'amount' => $withdraw->amount,
                        'scheduled' => $withdraw->scheduled,
                        'scheduled_for' => $withdraw->scheduled_for?->format('Y-m-d H:i:s'),
                        'done' => $withdraw->done,
                        'error' => $withdraw->error,
                        'error_reason' => $withdraw->error_reason,
                        'processed_at' => $withdraw->processed_at?->format('Y-m-d H:i:s'),
                        'created_at' => $withdraw->created_at->format('Y-m-d H:i:s'),
                        'pix' => $withdraw->pix ? [
                            'type' => $withdraw->pix->type,
                            'key' => $withdraw->pix->key,
                        ] : null,
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            $logger = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Psr\Log\LoggerInterface::class);
            $logger->error('Error listing withdraws', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return $response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => env('APP_ENV') === 'production' 
                    ? 'An unexpected error occurred' 
                    : $e->getMessage(),
            ])->withStatus(500);
        }
    }
}

