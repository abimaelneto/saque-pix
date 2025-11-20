<?php

declare(strict_types=1);

return [
    'default' => [
        'driver' => env('MAIL_MAILER', 'smtp'),
        'host' => env('MAIL_HOST', 'mailhog'),
        'port' => (int) env('MAIL_PORT', 1025),
        'encryption' => env('MAIL_ENCRYPTION', null),
        'username' => env('MAIL_USERNAME', null),
        'password' => env('MAIL_PASSWORD', null),
        'timeout' => 10,
        'from' => [
            'address' => env('MAIL_FROM_ADDRESS', 'noreply@saque-pix.local'),
            'name' => env('MAIL_FROM_NAME', 'Saque PIX'),
        ],
    ],
];

