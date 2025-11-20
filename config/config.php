<?php

declare(strict_types=1);

use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;

$config = new Config([
    'app_name' => env('APP_NAME', 'Saque PIX'),
    'app_env' => env('APP_ENV', 'local'),
    'app_debug' => env('APP_DEBUG', true),
]);

return $config;

