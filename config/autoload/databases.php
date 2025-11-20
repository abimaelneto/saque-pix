<?php

declare(strict_types=1);

use Hyperf\Database\Commands\ModelOption;

return [
    'default' => [
        'driver' => env('DB_CONNECTION', 'mysql'),
        'host' => env('DB_HOST', 'mysql'),
        'port' => (int) env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'saque_pix'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', 'root'),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => env('DB_PREFIX', ''),
        'pool' => [
            'min_connections' => (int) env('DB_POOL_MIN', 1),
            'max_connections' => (int) env('DB_POOL_MAX', 50),
            'connect_timeout' => (float) env('DB_CONNECT_TIMEOUT', 10.0),
            'wait_timeout' => (float) env('DB_WAIT_TIMEOUT', 3.0),
            'heartbeat' => (float) env('DB_HEARTBEAT', -1),
            'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
                'uses' => '',
                'refresh_fillable' => true,
                'table_mapping' => [],
                'with_comments' => true,
                'property_case' => ModelOption::PROPERTY_SNAKE_CASE,
            ],
        ],
    ],
];

