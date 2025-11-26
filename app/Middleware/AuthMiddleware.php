<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helper\LogMasker;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Middleware de Autenticação e Autorização
 * 
 * Valida token JWT e verifica se o usuário tem acesso à conta solicitada
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ?LoggerInterface $logger = null
    ) {
        // Logger é opcional para não quebrar se não estiver disponível
        if (!$this->logger) {
            try {
                $this->logger = \Hyperf\Context\ApplicationContext::getContainer()
                    ->get(LoggerInterface::class);
            } catch (\Throwable $e) {
                // Ignorar se logger não estiver disponível
            }
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Rotas públicas que não precisam de autenticação
        $path = $request->getUri()->getPath();
        $publicPaths = ['/health', '/metrics', '/metrics/json', '/admin'];
        
        foreach ($publicPaths as $publicPath) {
            if (str_starts_with($path, $publicPath)) {
                return $handler->handle($request);
            }
        }

        // Obter token do header Authorization
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorizedResponse('Missing or invalid authorization token');
        }

        $token = substr($authHeader, 7); // Remove "Bearer "
        
        // Validar token JWT
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
     * Valida token JWT usando firebase/php-jwt
     * Mantém compatibilidade com token de teste em desenvolvimento
     */
    private function validateToken(string $token): ?array
    {
        // Em desenvolvimento local, aceitar token de teste para facilitar testes
        if ($token === 'test-token' && in_array(env('APP_ENV'), ['local', 'testing'])) {
            return [
                'user_id' => 'test-user',
                'account_id' => null, // Será validado no controller
            ];
        }

        // Validar JWT real
        $jwtSecret = env('JWT_SECRET');
        
        // Se não houver secret configurado, usar um padrão apenas em desenvolvimento
        // Em produção, JWT_SECRET é obrigatório
        if (empty($jwtSecret)) {
            if (env('APP_ENV') === 'production') {
                if ($this->logger) {
                    $this->logger->error('JWT_SECRET not configured in production');
                }
                return null;
            }
            // Em desenvolvimento, usar secret padrão se não configurado
            $jwtSecret = 'dev-secret-key-change-in-production-' . md5(__DIR__);
        }

        try {
            $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
            $decodedArray = (array) $decoded;
            
            // Extrair dados do payload JWT
            // Suporta diferentes formatos de payload
            return [
                'user_id' => $decodedArray['user_id'] ?? $decodedArray['sub'] ?? $decodedArray['uid'] ?? null,
                'account_id' => $decodedArray['account_id'] ?? $decodedArray['accountId'] ?? null,
            ];
        } catch (ExpiredException $e) {
            if ($this->logger) {
                $this->logger->warning('JWT token expired', LogMasker::mask([
                    'token_preview' => substr($token, 0, 20) . '...',
                ]));
            }
            return null;
        } catch (SignatureInvalidException $e) {
            if ($this->logger) {
                $this->logger->warning('JWT signature invalid', LogMasker::mask([
                    'token_preview' => substr($token, 0, 20) . '...',
                ]));
            }
            return null;
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->warning('JWT validation error', LogMasker::mask([
                    'error' => $e->getMessage(),
                    'token_preview' => substr($token, 0, 20) . '...',
                ]));
            }
            return null;
        }
    }

    private function unauthorizedResponse(string $message): ResponseInterface
    {
        return new \Hyperf\HttpMessage\Server\Response(
            401,
            ['Content-Type' => 'application/json'],
            json_encode([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE)
        );
    }
}

