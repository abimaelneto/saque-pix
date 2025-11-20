<?php

declare(strict_types=1);

namespace App\Handler;

use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Router\Router;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handler para AWS Lambda
 * 
 * Processa eventos do API Gateway ou Lambda Function URLs
 * e retorna respostas formatadas para Lambda
 */
class LambdaHandler
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    /**
     * Processa evento do Lambda
     * 
     * @param array $event - Evento do Lambda
     * @param object $context - Contexto do Lambda
     * @return array - Resposta formatada
     */
    public function handle(array $event, object $context): array
    {
        try {
            // Detectar tipo de evento
            if (isset($event['httpMethod']) || isset($event['requestContext']['http'])) {
                return $this->handleHttpEvent($event);
            }
            
            // Evento customizado (ex: CloudWatch Events para cron)
            if (isset($event['source']) && $event['source'] === 'aws.events') {
                return $this->handleScheduledEvent($event);
            }
            
            return $this->createResponse(400, ['error' => 'Unsupported event type']);
            
        } catch (\Throwable $e) {
            return $this->createErrorResponse($e);
        }
    }

    /**
     * Processa evento HTTP (API Gateway ou Function URL)
     */
    private function handleHttpEvent(array $event): array
    {
        $method = $event['httpMethod'] ?? $event['requestContext']['http']['method'] ?? 'GET';
        $path = $event['path'] ?? $event['rawPath'] ?? '/';
        $queryParams = $event['queryStringParameters'] ?? [];
        $headers = $this->normalizeHeaders($event['headers'] ?? []);
        $body = $this->parseBody($event['body'] ?? '', $headers);
        
        // Criar request PSR-7
        $request = $this->createPsr7Request($method, $path, $queryParams, $headers, $body);
        
        // Processar através do Hyperf
        $response = $this->processHttpRequest($request);
        
        // Formatar resposta para Lambda
        return $this->formatHttpResponse($response);
    }

    /**
     * Processa evento agendado (CloudWatch Events)
     */
    private function handleScheduledEvent(array $event): array
    {
        // Processar saques agendados
        $withdrawService = ApplicationContext::getContainer()->get(\App\Service\WithdrawService::class);
        $processed = $withdrawService->processScheduledWithdraws();
        
        return $this->createResponse(200, [
            'message' => 'Scheduled withdraws processed',
            'processed' => $processed,
            'timestamp' => date('c'),
        ]);
    }

    /**
     * Cria request PSR-7 a partir dos dados do evento
     */
    private function createPsr7Request(
        string $method,
        string $path,
        array $queryParams,
        array $headers,
        mixed $body
    ): ServerRequestInterface {
        // Usar implementação do Hyperf para criar request
        // Em produção, usar implementação completa de ServerRequestInterface
        // Por enquanto, usar request do container
        $request = ApplicationContext::getContainer()->get(\Hyperf\HttpServer\Contract\RequestInterface::class);
        
        // TODO: Configurar request com método, path, headers, body
        // Por enquanto, retornar request básico do container
        return $request;
    }

    /**
     * Processa requisição HTTP através do Hyperf
     */
    private function processHttpRequest(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $httpServer = ApplicationContext::getContainer()->get(\Hyperf\HttpServer\Server::class);
        return $httpServer->onRequest($request);
    }

    /**
     * Formata resposta HTTP para Lambda
     */
    private function formatHttpResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower($name)] = implode(', ', $values);
        }
        
        return [
            'statusCode' => $response->getStatusCode(),
            'headers' => $responseHeaders,
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * Normaliza headers (API Gateway usa lowercase, Function URL pode variar)
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = $value;
        }
        return $normalized;
    }

    /**
     * Parse do body baseado no content-type
     */
    private function parseBody(string $body, array $headers): mixed
    {
        $contentType = $headers['content-type'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            return json_decode($body, true) ?? [];
        }
        
        return $body;
    }

    /**
     * Cria resposta de sucesso
     */
    private function createResponse(int $statusCode, mixed $data): array
    {
        return [
            'statusCode' => $statusCode,
            'headers' => [
                'content-type' => 'application/json',
            ],
            'body' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Cria resposta de erro
     */
    private function createErrorResponse(\Throwable $e): array
    {
        return $this->createResponse(500, [
            'error' => 'Internal server error',
            'message' => $e->getMessage(),
        ]);
    }
}

