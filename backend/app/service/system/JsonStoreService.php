<?php

namespace app\service\system;

/**
 * Local JSON persistence used for admin system modules while
 * database-backed storage is still being completed.
 */
class JsonStoreService
{
    public static function load(string $name, array $default = []): array
    {
        $path = self::path($name);
        if (!is_file($path)) {
            self::save($name, $default);
            return $default;
        }

        $contents = (string)file_get_contents($path);
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function save(string $name, array $payload): array
    {
        $path = self::path($name);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $path,
            json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            )
        );

        return $payload;
    }

    private static function path(string $name): string
    {
        return runtime_path() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . $name . '.json';
    }
}
