<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Account;
use App\Repository\AccountRepository;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Ramsey\Uuid\Uuid;

// Rotas definidas em config/routes.php
class AccountController
{
    public function __construct(
        private AccountRepository $accountRepository,
        private ValidatorFactoryInterface $validatorFactory,
    ) {
    }

    public function list(ResponseInterface $response): PsrResponseInterface
    {
        try {
            $accounts = Account::orderBy('created_at', 'desc')->limit(100)->get();
            
            return $response->json([
                'success' => true,
                'data' => $accounts->map(function ($account) {
                    return [
                        'id' => $account->id,
                        'name' => $account->name,
                        'balance' => $account->balance,
                        'created_at' => $account->created_at?->format('Y-m-d H:i:s'),
                    ];
                }),
                'count' => $accounts->count(),
            ]);
        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'error' => 'Failed to list accounts',
                'message' => env('APP_ENV') === 'production' ? 'Internal server error' : $e->getMessage(),
            ])->withStatus(500);
        }
    }

    public function get(string $id, ResponseInterface $response): PsrResponseInterface
    {
        // Validar UUID
        if (!Uuid::isValid($id)) {
            return $response->json([
                'success' => false,
                'error' => 'Invalid account ID format',
                'message' => 'Account ID must be a valid UUID',
            ])->withStatus(400);
        }

        try {
            $account = $this->accountRepository->findById($id);
            
            if (!$account) {
                return $response->json([
                    'success' => false,
                    'error' => 'Account not found',
                    'message' => "Account with ID '{$id}' does not exist",
                ])->withStatus(404);
            }

            return $response->json([
                'success' => true,
                'data' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'balance' => $account->balance,
                    'created_at' => $account->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $account->updated_at?->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'error' => 'Failed to get account',
                'message' => env('APP_ENV') === 'production' ? 'Internal server error' : $e->getMessage(),
            ])->withStatus(500);
        }
    }

    public function create(RequestInterface $request, ResponseInterface $response): PsrResponseInterface
    {
        $data = $request->getParsedBody() ?? [];
        
        $validator = $this->validatorFactory->make($data, [
            'name' => 'required|string|max:255',
            'balance' => 'nullable|numeric|min:0',
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
            $account = new Account();
            $account->id = Uuid::uuid4()->toString();
            $account->name = trim($data['name']);
            $account->balance = (string) ($data['balance'] ?? '0.00');
            $account->save();

            return $response->json([
                'success' => true,
                'data' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'balance' => $account->balance,
                    'created_at' => $account->created_at->format('Y-m-d H:i:s'),
                ],
                'message' => 'Account created successfully',
            ])->withStatus(201);
        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'error' => 'Failed to create account',
                'message' => env('APP_ENV') === 'production' ? 'Internal server error' : $e->getMessage(),
            ])->withStatus(500);
        }
    }
}

