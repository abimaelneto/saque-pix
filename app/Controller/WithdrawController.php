<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\WithdrawRequestDTO;
use App\Service\WithdrawService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Validation\Annotation\Validation;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller(prefix: '/account')]
class WithdrawController
{
    public function __construct(
        private WithdrawService $withdrawService,
        private ValidatorFactoryInterface $validatorFactory,
    ) {
    }

    #[PostMapping(path: '/{accountId}/balance/withdraw')]
    public function withdraw(
        string $accountId,
        RequestInterface $request,
        ResponseInterface $response
    ): PsrResponseInterface {
        $data = $request->all();

        // Validar UUID da conta
        if (!\Ramsey\Uuid\Uuid::isValid($accountId)) {
            return $response->json([
                'error' => 'Invalid account ID format',
            ])->withStatus(400);
        }

        // Validar dados do request
        $validator = $this->validatorFactory->make($data, [
            'method' => 'required|string|in:PIX',
            'pix.type' => 'required|string|in:email',
            'pix.key' => 'required|email',
            'amount' => 'required|numeric|min:0.01',
            'schedule' => 'nullable|date_format:Y-m-d H:i',
        ]);

        if ($validator->fails()) {
            return $response->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()->all(),
            ])->withStatus(422);
        }

        try {
            $dto = new WithdrawRequestDTO(
                accountId: $accountId,
                method: $data['method'],
                pixType: $data['pix']['type'],
                pixKey: $data['pix']['key'],
                amount: (string) $data['amount'],
                schedule: $data['schedule'] ?? null,
            );

            $withdraw = $this->withdrawService->createWithdraw($dto);

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
            return $response->json([
                'error' => $e->getMessage(),
            ])->withStatus(400);
        } catch (\Exception $e) {
            return $response->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ])->withStatus(500);
        }
    }
}

