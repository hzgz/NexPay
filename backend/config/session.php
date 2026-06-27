<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Session\FileSessionHandler;
use Webman\Session\RedisSessionHandler;
use Webman\Session\RedisClusterSessionHandler;

return [

    'type' => env('SESSION_DRIVER', 'redis'), // file or redis or redis_cluster

    'handler' => env('SESSION_DRIVER', 'redis') === 'file'
        ? FileSessionHandler::class
        : (env('SESSION_DRIVER', 'redis') === 'redis_cluster'
            ? RedisClusterSessionHandler::class
            : RedisSessionHandler::class),

    'config' => [
        'file' => [
            'save_path' => runtime_path() . '/sessions',
        ],
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'auth' => env('REDIS_PASSWORD', ''),
            'timeout' => 2,
            'database' => (int)env('SESSION_REDIS_DB', env('REDIS_DB', 0)),
            'prefix' => env('SESSION_PREFIX', 'nexpay_session_'),
        ],
        'redis_cluster' => [
            'host' => ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7001'],
            'timeout' => 2,
            'auth' => env('REDIS_PASSWORD', ''),
            'prefix' => env('SESSION_PREFIX', 'nexpay_session_'),
        ]
    ],

    'session_name' => env('SESSION_NAME', 'NEXPAYSESSID'),
    
    'auto_update_timestamp' => false,

    'lifetime' => 7*24*60*60,

    'cookie_lifetime' => 365*24*60*60,

    'cookie_path' => '/',

    'domain' => '',
    
    'http_only' => true,

    'secure' => (bool)env('SESSION_SECURE_COOKIE', false),
    
    'same_site' => env('SESSION_SAME_SITE', 'Lax'),

    'gc_probability' => [1, 1000],

];
