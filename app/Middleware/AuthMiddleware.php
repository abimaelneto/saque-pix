<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware de Autenticação e Autorização
 * 
 * Valida token JWT e verifica se o usuário tem acesso à conta solicitada
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (env('APP_ENV') === 'testing') {
            return $handler->handle($request);
        }

        // Obter token do header Authorization
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorizedResponse('Missing or invalid authorization token');
        }

        $token = substr($authHeader, 7); // Remove "Bearer "
        
        // Validar token (em produção, usar biblioteca JWT como firebase/php-jwt)
        $userData = $this->validateToken($token);
        
        if (!$userData) {
            return $this->unauthorizedResponse('Invalid or expired token');
        }

        // Adicionar dados do usuário ao request para uso nos controllers
        $request = $request->withAttribute('user_id', $userData['user_id']);
        $request = $request->withAttribute('account_id', $userData['account_id'] ?? null);
        
        return $handler->handle($request);
    }

    /**
     * Valida token JWT (implementação simplificada)
     * Em produção, usar biblioteca JWT adequada
     */
    private function validateToken(string $token): ?array
    {
        // Em produção, implementar validação JWT real
        // Por enquanto, aceita token de teste para desenvolvimento
        if ($token === 'test-token' && env('APP_ENV') === 'local') {
            return [
                'user_id' => 'test-user',
                'account_id' => null, // Será validado no controller
            ];
        }

        // TODO: Implementar validação JWT real
        // Exemplo com firebase/php-jwt:
        // try {
        //     $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
        //     return (array) $decoded;
        // } catch (\Exception $e) {
        //     return null;
        // }

        return null;
    }

    private function unauthorizedResponse(string $message): ResponseInterface
    {
        return new \Hyperf\HttpMessage\Server\Response(
            401,
            ['Content-Type' => 'application/json'],
            json_encode([
                'error' => 'Unauthorized',
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE)
        );
    }
}

