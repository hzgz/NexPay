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

namespace support;

/**
 * Class Request
 * @package support
 */
class Request extends \Webman\Http\Request
{
    public function __get(string $name): mixed
    {
        return match ($name) {
            'siteurl' => $this->siteUrl(),
            'clientip' => $this->clientIp(),
            default => $this->input($name),
        };
    }

    public function get(?string $name = null, mixed $default = null, mixed $filter = null): mixed
    {
        $value = parent::get($name, $default);
        return $this->applyFilter($value, $filter);
    }

    public function post(?string $name = null, mixed $default = null, mixed $filter = null): mixed
    {
        $value = parent::post($name, $default);
        return $this->applyFilter($value, $filter);
    }

    public function siteUrl(): string
    {
        $scheme = $this->header('x-forwarded-proto', is_https() ? 'https' : 'http');
        $host = $this->host(true);
        return strtolower((string)$scheme) . '://' . $host . '/';
    }

    public function clientIp(): string
    {
        return $this->getRealIp(false);
    }

    private function applyFilter(mixed $value, mixed $filter): mixed
    {
        if ($filter === null || $filter === '') {
            return $value;
        }

        if (is_string($filter) && function_exists($filter)) {
            if (is_array($value)) {
                return array_map($filter, $value);
            }
            return $filter($value);
        }

        if (is_callable($filter)) {
            return $filter($value);
        }

        return $value;
    }
}
