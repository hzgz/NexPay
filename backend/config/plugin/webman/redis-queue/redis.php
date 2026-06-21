<?php

return [
    'default' => [
        'host' => sprintf(
            'redis://%s:%s',
            env('REDIS_HOST', '127.0.0.1'),
            env('REDIS_PORT', 6379)
        ),
        'options' => [
            'auth' => env('REDIS_PASSWORD', null) ?: null,
            'db' => (int)env('REDIS_DB', 0),
            'prefix' => env('QUEUE_PREFIX', 'epay_plus:'),
            'max_attempts' => 5,
            'retry_seconds' => 5,
        ],
    ],
];
