<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Middleware para adicionar Correlation ID em todas requisições
 * 
 * Facilita rastreamento e debugging em sistemas distribuídos
 * Correlation ID é propagado através de toda a requisição
 */
class CorrelationIdMiddleware implements MiddlewareInterface
{
    public const CORRELATION_ID_HEADER = 'X-Correlation-ID';
    public const CORRELATION_ID_CONTEXT_KEY = 'correlation_id';

    public function __construct(
        private ResponseInterface $response,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        // Obter correlation ID do header ou gerar novo
        $correlationId = $request->getHeaderLine(self::CORRELATION_ID_HEADER);
        
        if (empty($correlationId)) {
            // Gerar novo UUID se não fornecido
            $correlationId = Uuid::uuid4()->toString();
        } else {
            // Validar formato UUID
            if (!Uuid::isValid($correlationId)) {
                // Se não for UUID válido, gerar novo
                $correlationId = Uuid::uuid4()->toString();
            }
        }

        // Armazenar no contexto Hyperf para uso em toda a requisição
        Context::set(self::CORRELATION_ID_CONTEXT_KEY, $correlationId);

        // Adicionar ao request como atributo
        $request = $request->withAttribute('correlation_id', $correlationId);

        // Processar requisição
        $response = $handler->handle($request);

        // Adicionar correlation ID no header da resposta
        $response = $response->withHeader(self::CORRELATION_ID_HEADER, $correlationId);

        return $response;
    }
}

