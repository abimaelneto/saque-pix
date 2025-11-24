<?php

declare(strict_types=1);

return [
    'driver' => \Hyperf\Watcher\Driver\ScanFileDriver::class,
    'bin' => 'php',
    'watch' => [
        'dir' => [
            BASE_PATH . '/app',
            BASE_PATH . '/config',
        ],
        'file' => [
            BASE_PATH . '/.env',
        ],
        'name' => ['*.php'],
        'ignore' => [
            BASE_PATH . '/vendor',
            BASE_PATH . '/runtime',
            BASE_PATH . '/.git',
        ],
    ],
];
