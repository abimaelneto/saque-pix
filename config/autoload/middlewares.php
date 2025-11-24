<?php

declare(strict_types=1);

return [
    'http' => [
        \App\Middleware\CorrelationIdMiddleware::class,  // Primeiro: correlation ID (deve ser o primeiro)
        \App\Middleware\SecurityHeadersMiddleware::class, // Headers de segurança
        \App\Middleware\RateLimitMiddleware::class,      // Rate limiting
        \App\Middleware\AuthMiddleware::class,            // Autenticação
        \App\Middleware\MetricsMiddleware::class,         // Último: métricas
    ],
];

