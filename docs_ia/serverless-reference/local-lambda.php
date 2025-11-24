<?php

declare(strict_types=1);

/**
 * Simulador Local de Lambda
 * 
 * Permite testar a aplicação como se fosse Lambda, mas rodando localmente
 * Sem necessidade de recursos em nuvem ou configuração complexa
 * 
 * Uso:
 *   php local-lambda.php http GET /account/123/balance/withdraw
 *   php local-lambda.php cron
 */

require __DIR__ . '/bootstrap.php';

use App\Handler\LambdaHandler;
use Hyperf\Context\ApplicationContext;

$container = ApplicationContext::getContainer();
$handler = $container->get(LambdaHandler::class);

// Parse argumentos
$command = $argv[1] ?? 'help';

if ($command === 'help') {
    echo "Uso:\n";
    echo "  php local-lambda.php http <METHOD> <PATH> [BODY]\n";
    echo "  php local-lambda.php cron\n";
    echo "\n";
    echo "Exemplos:\n";
    echo "  php local-lambda.php http GET /\n";
    echo "  php local-lambda.php http POST /account/123/balance/withdraw '{\"method\":\"PIX\",\"pix\":{\"type\":\"email\",\"key\":\"test@email.com\"},\"amount\":100}'\n";
    echo "  php local-lambda.php cron\n";
    exit(0);
}

if ($command === 'http') {
    $method = $argv[2] ?? 'GET';
    $path = $argv[3] ?? '/';
    $body = $argv[4] ?? '{}';
    
    // Criar evento simulado do API Gateway
    $event = [
        'httpMethod' => $method,
        'path' => $path,
        'headers' => [
            'Content-Type' => 'application/json',
            'Host' => 'localhost:9501',
        ],
        'queryStringParameters' => null,
        'body' => $body,
        'requestContext' => [
            'requestId' => uniqid('local-', true),
            'http' => [
                'method' => $method,
                'path' => $path,
            ],
        ],
    ];
    
    $context = (object) [
        'functionName' => 'local-lambda',
        'functionVersion' => '$LATEST',
        'memoryLimitInMB' => 512,
        'awsRequestId' => uniqid('local-', true),
    ];
    
    $response = $handler->handle($event, $context);
    
    // Exibir resposta
    echo "Status: {$response['statusCode']}\n";
    echo "Headers:\n";
    foreach ($response['headers'] as $key => $value) {
        echo "  {$key}: {$value}\n";
    }
    echo "\nBody:\n";
    echo $response['body'] . "\n";
    
} elseif ($command === 'cron') {
    // Criar evento simulado do CloudWatch Events
    $event = [
        'version' => '0',
        'id' => uniqid('local-', true),
        'detail-type' => 'Scheduled Event',
        'source' => 'aws.events',
        'account' => '123456789012',
        'time' => date('c'),
        'region' => 'us-east-1',
        'resources' => [
            'arn:aws:events:us-east-1:123456789012:rule/process-scheduled-withdraws',
        ],
        'detail' => [],
    ];
    
    $context = (object) [
        'functionName' => 'process-scheduled-withdraws',
        'functionVersion' => '$LATEST',
        'memoryLimitInMB' => 512,
        'awsRequestId' => uniqid('local-', true),
    ];
    
    echo "Processando saques agendados...\n";
    $response = $handler->handle($event, $context);
    
    echo "Status: {$response['statusCode']}\n";
    echo "Body:\n";
    echo $response['body'] . "\n";
    
} else {
    echo "Comando desconhecido: {$command}\n";
    echo "Use 'help' para ver os comandos disponíveis.\n";
    exit(1);
}

