<?php

declare(strict_types=1);

return [
    'app_name' => env('APP_NAME', 'Saque PIX'),
    'app_env' => env('APP_ENV', 'local'),
    'app_debug' => env('APP_DEBUG', true),
    // Em desenvolvimento local, desabilitar cache para hot reload
    'cache_enabled' => env('APP_ENV', 'local') !== 'local',
];

