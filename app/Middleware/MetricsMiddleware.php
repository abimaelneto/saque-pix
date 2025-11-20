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
        
        // Obter método e path
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        
        // Processar requisição
        $response = $handler->handle($request);
        
        // Calcular duração
        $duration = microtime(true) - $startTime;
        $statusCode = $response->getStatusCode();
        
        // Registrar métricas
        $this->metricsService->recordHttpRequest($method, $path, $statusCode, $duration);
        
        return $response;
    }
}

