<?php

declare(strict_types=1);

return [
    'mode' => SWOOLE_PROCESS,
    'servers' => [
        [
            'name' => 'http',
            'type' => \Hyperf\Server\Server::SERVER_HTTP,
            'host' => env('SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('SERVER_PORT', 9501),
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                \Hyperf\Server\Event::ON_REQUEST => [\Hyperf\HttpServer\Server::class, 'onRequest'],
            ],
            'options' => [
                'enable_coroutine' => true,
                'worker_num' => swoole_cpu_num(),
                'max_request' => 10000,
                'open_tcp_nodelay' => true,
                'max_coroutine' => 100000,
                'enable_static_handler' => false,
                'document_root' => BASE_PATH . '/public',
            ],
        ],
    ],
    'settings' => [
        'enable_coroutine' => true,
        'worker_num' => swoole_cpu_num(),
        'pid_file' => BASE_PATH . '/runtime/hyperf.pid',
        'open_tcp_nodelay' => true,
        'max_coroutine' => 100000,
        'open_http2_protocol' => false,
        'max_request' => 10000,
        'socket_buffer_size' => 2 * 1024 * 1024,
        'buffer_output_size' => 2 * 1024 * 1024,
    ],
    'callbacks' => [
        \Hyperf\Server\Event::ON_WORKER_START => [\Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
        \Hyperf\Server\Event::ON_PIPE_MESSAGE => [\Hyperf\Framework\Bootstrap\PipeMessageCallback::class, 'onPipeMessage'],
        \Hyperf\Server\Event::ON_WORKER_EXIT => [\Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],
    ],
];

