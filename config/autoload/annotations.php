<?php

declare(strict_types=1);

return [
    'scan' => [
        'paths' => [
            BASE_PATH . '/app',
        ],
        'ignore_annotations' => [
            'mixin',
        ],
        // Em desenvolvimento, desabilitar cache de annotations para hot reload
        'cacheable' => env('APP_ENV', 'local') !== 'local',
        // Ignorar controllers para rotas (usamos config/routes.php)
        'collectors' => [
            // Remover RouteCollector para não processar anotações de rotas
        ],
    ],
];

