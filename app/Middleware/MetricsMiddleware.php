<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\MetricsService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware para coletar métricas de requisições HTTP
 */
class MetricsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MetricsService $metricsService,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startTime = microtime(true);
        
        // Obter método e path ANTES de processar (para garantir que temos os dados mesmo se houver erro)
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        
        try {
            // Processar requisição
            $response = $handler->handle($request);
            
            // Calcular duração
            $duration = microtime(true) - $startTime;
            $statusCode = $response->getStatusCode();
            
            // Registrar métricas (sempre, mesmo para erros)
            $this->metricsService->recordHttpRequest($method, $path, $statusCode, $duration);
            
            return $response;
        } catch (\Throwable $e) {
            // Em caso de exceção não capturada, registrar como erro 500
            $duration = microtime(true) - $startTime;
            $this->metricsService->recordHttpRequest($method, $path, 500, $duration);
            throw $e;
        }
    }
}

