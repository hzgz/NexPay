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

$sessionDriver = strtolower(trim((string)env('SESSION_DRIVER', 'redis')));
$sessionSecureCookie = filter_var(env('SESSION_SECURE_COOKIE', null), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
$sessionSameSite = trim((string)env('SESSION_SAME_SITE', 'Lax'));
$sessionDomain = trim((string)env('SESSION_DOMAIN', ''));
$sessionGcNumerator = max(0, (int)env('SESSION_GC_NUMERATOR', 1));
$sessionGcDenominator = max(1, (int)env('SESSION_GC_DENOMINATOR', 1000));

return [

    'type' => $sessionDriver, // file or redis or redis_cluster

    'handler' => $sessionDriver === 'file'
        ? FileSessionHandler::class
        : ($sessionDriver === 'redis_cluster'
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

    'lifetime' => max(1800, (int)env('SESSION_LIFETIME', 7 * 24 * 60 * 60)),

    'cookie_lifetime' => max(0, (int)env('SESSION_COOKIE_LIFETIME', 7 * 24 * 60 * 60)),

    'cookie_path' => '/',

    'domain' => $sessionDomain,
    
    'http_only' => true,

    'secure' => $sessionSecureCookie ?? false,
    
    'same_site' => in_array($sessionSameSite, ['Lax', 'Strict', 'None'], true) ? $sessionSameSite : 'Lax',

    'gc_probability' => [$sessionGcNumerator, $sessionGcDenominator],

];
