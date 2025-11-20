<?php

declare(strict_types=1);

return [
    'http' => [
        \App\Middleware\SecurityHeadersMiddleware::class, // Primeiro: headers de segurança
        \App\Middleware\RateLimitMiddleware::class,      // Segundo: rate limiting
        \App\Middleware\AuthMiddleware::class,             // Terceiro: autenticação
        \App\Middleware\MetricsMiddleware::class,         // Último: métricas
    ],
];

