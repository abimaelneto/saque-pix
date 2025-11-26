<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Middleware de Autenticação para Rotas Administrativas
 * 
 * Protege rotas /admin/* com autenticação específica
 * Suporta tanto token JWT quanto token de admin simples para desenvolvimento
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ?LoggerInterface $logger = null
    ) {
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
        $path = $request->getUri()->getPath();
        
        // Aplicar em rotas /admin e /accounts (rotas administrativas)
        // EXCETO /admin (página HTML) que deve ser acessível
        if (!str_starts_with($path, '/admin') && !str_starts_with($path, '/accounts')) {
            return $handler->handle($request);
        }

        // Permitir acesso à página HTML /admin sem autenticação
        if ($path === '/admin') {
            return $handler->handle($request);
        }

        // Em desenvolvimento, permitir acesso sem autenticação para facilitar testes
        // Mas registrar o acesso para auditoria
        if (in_array(env('APP_ENV'), ['local', 'testing'])) {
            if ($this->logger) {
                $this->logger->info('Admin access in development mode', [
                    'path' => $path,
                    'ip' => $this->getClientIp($request),
                ]);
            }
            return $handler->handle($request);
        }

        // Em produção, requer autenticação
        $adminToken = $request->getHeaderLine('X-Admin-Token');
        $authHeader = $request->getHeaderLine('Authorization');
        
        // Verificar token de admin específico
        $adminSecret = env('ADMIN_SECRET_TOKEN');
        if (!empty($adminSecret) && $adminToken === $adminSecret) {
            return $handler->handle($request);
        }

        // Verificar se tem JWT válido E se é admin
        if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $userData = $this->validateAdminToken($token);
            
            if ($userData && ($userData['is_admin'] ?? false)) {
                return $handler->handle($request);
            }
        }

        // Acesso negado
        if ($this->logger) {
            $this->logger->warning('Unauthorized admin access attempt', [
                'path' => $path,
                'ip' => $this->getClientIp($request),
            ]);
        }

        return new \Hyperf\HttpMessage\Server\Response(
            403,
            ['Content-Type' => 'application/json'],
            json_encode([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Admin access denied. Valid admin token required.',
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Valida token JWT e verifica se é admin
     */
    private function validateAdminToken(string $token): ?array
    {
        $jwtSecret = env('JWT_SECRET');
        
        if (empty($jwtSecret)) {
            return null;
        }

        try {
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($jwtSecret, 'HS256'));
            $decodedArray = (array) $decoded;
            
            // Verificar se é admin
            $isAdmin = $decodedArray['is_admin'] ?? $decodedArray['admin'] ?? false;
            
            if (!$isAdmin) {
                return null;
            }

            return [
                'user_id' => $decodedArray['user_id'] ?? $decodedArray['sub'] ?? null,
                'is_admin' => true,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $headers = [
            'X-Forwarded-For',
            'X-Real-Ip',
            'CF-Connecting-Ip',
        ];

        foreach ($headers as $header) {
            $ip = $request->getHeaderLine($header);
            if (!empty($ip)) {
                $ips = explode(',', $ip);
                return trim($ips[0]);
            }
        }

        $serverParams = $request->getServerParams();
        return $serverParams['remote_addr'] ?? 'unknown';
    }
}

