<?php

declare(strict_types=1);

/**
 * AWS Lambda Handler
 * 
 * Handler para executar a aplicação em ambiente serverless (AWS Lambda)
 * Compatível com API Gateway e Lambda Function URLs
 */

use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$container = require __DIR__ . '/bootstrap.php';

/**
 * Lambda Handler Function
 * 
 * @param array $event - Evento do Lambda (API Gateway ou Function URL)
 * @param object $context - Contexto do Lambda
 * @return array - Resposta formatada para API Gateway
 */
return function (array $event, object $context): array {
    try {
        // Converter evento do Lambda para PSR-7 Request
        $request = createRequestFromLambdaEvent($event);
        
        // Obter container
        $container = ApplicationContext::getContainer();
        
        // Processar requisição através do Hyperf
        $response = processRequest($container, $request);
        
        // Converter resposta PSR-7 para formato Lambda
        return formatLambdaResponse($response);
        
    } catch (\Throwable $e) {
        return [
            'statusCode' => 500,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE),
        ];
    }
};

/**
 * Cria PSR-7 Request a partir do evento Lambda
 */
function createRequestFromLambdaEvent(array $event): ServerRequestInterface
{
    $method = $event['httpMethod'] ?? $event['requestContext']['http']['method'] ?? 'GET';
    $path = $event['path'] ?? $event['rawPath'] ?? '/';
    $query = $event['queryStringParameters'] ?? [];
    $headers = $event['headers'] ?? [];
    $body = $event['body'] ?? '';
    
    // Parse body se for JSON
    if (isset($headers['content-type']) && strpos($headers['content-type'], 'application/json') !== false) {
        $body = json_decode($body, true) ?? [];
    }
    
    // Criar request usando Hyperf
    $container = ApplicationContext::getContainer();
    $request = $container->get(RequestInterface::class);
    
    // Configurar request (simplificado - em produção usar implementação completa)
    return $request;
}

/**
 * Processa requisição através do Hyperf
 */
function processRequest($container, ServerRequestInterface $request): PsrResponseInterface
{
    $httpServer = $container->get(\Hyperf\HttpServer\Server::class);
    return $httpServer->onRequest($request);
}

/**
 * Formata resposta PSR-7 para formato Lambda
 */
function formatLambdaResponse(PsrResponseInterface $response): array
{
    $body = (string) $response->getBody();
    $headers = [];
    
    foreach ($response->getHeaders() as $name => $values) {
        $headers[strtolower($name)] = implode(', ', $values);
    }
    
    return [
        'statusCode' => $response->getStatusCode(),
        'headers' => $headers,
        'body' => $body,
    ];
}

