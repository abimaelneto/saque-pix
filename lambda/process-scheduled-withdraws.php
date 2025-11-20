<?php

declare(strict_types=1);

/**
 * AWS Lambda Function para processar saques agendados
 * 
 * Esta função é executada via CloudWatch Events (cron)
 * ou pode ser invocada manualmente
 * 
 * Configuração CloudWatch Events:
 * - Rule: process-scheduled-withdraws
 * - Schedule: rate(1 minute)
 * - Target: Esta Lambda Function
 */

require __DIR__ . '/../bootstrap.php';

use App\Handler\LambdaHandler;
use Hyperf\Context\ApplicationContext;

$container = ApplicationContext::getContainer();
$handler = $container->get(LambdaHandler::class);

/**
 * Lambda Handler
 */
return function (array $event, object $context) use ($handler): array {
    return $handler->handle($event, $context);
};

