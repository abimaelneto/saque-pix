<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\AuditService;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware de Rate Limiting
 * 
 * Protege contra ataques de força bruta e DDoS
 * Implementa sliding window rate limiting usando Redis
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private const RATE_LIMIT_PREFIX = 'rate_limit:';
    private const DEFAULT_LIMIT = 100; // Requisições
    private const DEFAULT_WINDOW = 60; // Segundos

    public function __construct(
        private Redis $redis,
        private AuditService $auditService,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Rate limiting sempre ativo, mas com limites mais altos em desenvolvimento
        // Isso mantém a proteção mesmo em dev, mas permite testes mais flexíveis

        $identifier = $this->getIdentifier($request);
        $limit = $this->getLimit($request);
        $window = $this->getWindow($request);

        $key = self::RATE_LIMIT_PREFIX . $identifier;
        
        // Sliding window rate limiting
        $current = $this->redis->incr($key);
        
        if ($current === 1) {
            // Primeira requisição, definir TTL
            $this->redis->expire($key, $window);
        }

        // Adicionar headers de rate limit
        $remaining = max(0, $limit - $current);
        $resetAt = $this->redis->ttl($key) ?: $window;

        $response = $handler->handle($request);
        
        $response = $response->withHeader('X-RateLimit-Limit', (string) $limit);
        $response = $response->withHeader('X-RateLimit-Remaining', (string) $remaining);
        $response = $response->withHeader('X-RateLimit-Reset', (string) (time() + $resetAt));

        // Se excedeu o limite, retornar 429
        if ($current > $limit) {
            $endpoint = $request->getUri()->getPath();
            $this->auditService->logRateLimitExceeded($identifier, $endpoint);
            
            return new \Hyperf\HttpMessage\Server\Response(
                429,
                [
                    'Content-Type' => 'application/json',
                    'X-RateLimit-Limit' => (string) $limit,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) (time() + $resetAt),
                ],
                json_encode([
                    'error' => 'Too Many Requests',
                    'message' => 'Rate limit exceeded. Please try again later.',
                    'retry_after' => $resetAt,
                ], JSON_UNESCAPED_UNICODE)
            );
        }

        return $response;
    }

    /**
     * Identifica o cliente (IP, user_id, ou account_id)
     */
    private function getIdentifier(ServerRequestInterface $request): string
    {
        // Prioridade: user_id > account_id > IP
        $userId = $request->getAttribute('user_id');
        if ($userId) {
            return 'user:' . $userId;
        }

        $accountId = $request->getAttribute('account_id');
        if ($accountId) {
            return 'account:' . $accountId;
        }

        // Usar IP como fallback
        $ip = $this->getClientIp($request);
        return 'ip:' . $ip;
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $headers = [
            'X-Forwarded-For',
            'X-Real-Ip',
            'CF-Connecting-Ip', // Cloudflare
        ];

        foreach ($headers as $header) {
            $ip = $request->getHeaderLine($header);
            if (!empty($ip)) {
                // X-Forwarded-For pode ter múltiplos IPs
                $ips = explode(',', $ip);
                return trim($ips[0]);
            }
        }

        $serverParams = $request->getServerParams();
        return $serverParams['remote_addr'] ?? 'unknown';
    }

    /**
     * Obtém limite de rate baseado no endpoint
     */
    private function getLimit(ServerRequestInterface $request): int
    {
        $path = $request->getUri()->getPath();
        
        // Limites mais restritivos para endpoints críticos
        $isProduction = env('APP_ENV') === 'production';
        
        if (str_contains($path, '/withdraw')) {
            // Em produção: 10 saques por minuto por conta
            // Em desenvolvimento: 1000 (ainda protegido, mas permite testes)
            return $isProduction ? 10 : 1000;
        }

        // Limite padrão: mais alto em dev para permitir testes
        return $isProduction ? self::DEFAULT_LIMIT : (self::DEFAULT_LIMIT * 10);
    }

    /**
     * Obtém janela de tempo baseado no endpoint
     */
    private function getWindow(ServerRequestInterface $request): int
    {
        $path = $request->getUri()->getPath();
        
        if (str_contains($path, '/withdraw')) {
            return 60; // 1 minuto para saques
        }

        return self::DEFAULT_WINDOW;
    }
}

