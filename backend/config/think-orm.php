<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'type' => 'mysql',
            'hostname' => env('DB_HOST', '127.0.0.1'),
            'database' => env('DB_DATABASE', 'nexpay'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'hostport' => env('DB_PORT', '3306'),
            'params' => [
                \PDO::ATTR_TIMEOUT => 3,
            ],
            'charset' => 'utf8mb4',
            'prefix' => env('DB_PREFIX', ''),
            'break_reconnect' => true,
            'pool' => [
                'max_connections' => 10,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
    'paginator' => '',
];
