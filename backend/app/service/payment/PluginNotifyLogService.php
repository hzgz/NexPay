<?php

namespace app\service\payment;

use app\service\system\JsonStoreService;
use support\Request;

class PluginNotifyLogService
{
    private const STORE = 'plugin_notify_logs';

    public static function write(array $payload): array
    {
        $items = JsonStoreService::load(self::STORE, []);
        $items[] = [
            'id' => self::nextId($items),
            'action' => (string)($payload['action'] ?? ''),
            'stage' => (string)($payload['stage'] ?? 'gateway'),
            'trade_no' => (string)($payload['trade_no'] ?? ''),
            'channel_id' => (int)($payload['channel_id'] ?? 0),
            'merchant_id' => (int)($payload['merchant_id'] ?? 0),
            'plugin_code' => (string)($payload['plugin_code'] ?? ''),
            'method_code' => (string)($payload['method_code'] ?? ''),
            'status' => (string)($payload['status'] ?? ''),
            'message' => (string)($payload['message'] ?? ''),
            'result_type' => (string)($payload['result_type'] ?? ''),
            'request' => is_array($payload['request'] ?? null) ? $payload['request'] : [],
            'context' => self::normalizeArray(is_array($payload['context'] ?? null) ? $payload['context'] : []),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $items = array_slice($items, -500);
        JsonStoreService::save(self::STORE, $items);

        return end($items) ?: [];
    }

    public static function logs(int $limit = 100): array
    {
        $items = JsonStoreService::load(self::STORE, []);
        $items = array_values(array_filter($items, static fn($item): bool => is_array($item)));
        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return array_slice($items, 0, max(1, min(500, $limit)));
    }

    public static function requestSnapshot(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        $rawBody = (string)$request->rawBody();
        if (strlen($rawBody) > 4000) {
            $rawBody = substr($rawBody, 0, 4000) . '...';
        }

        return [
            'ip' => method_exists($request, 'getRealIp') ? (string)$request->getRealIp() : '',
            'query' => is_array($request->get()) ? $request->get() : [],
            'form' => is_array($request->post()) ? $request->post() : [],
            'raw_body' => $rawBody,
        ];
    }

    private static function nextId(array $items): int
    {
        $ids = array_map(static fn($item): int => is_array($item) ? (int)($item['id'] ?? 0) : 0, $items);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function normalizeArray(array $items, int $depth = 0): array
    {
        if ($depth >= 4) {
            return ['_truncated' => true];
        }

        $normalized = [];
        foreach ($items as $key => $value) {
            $key = is_int($key) ? $key : (string)$key;
            if (is_array($value)) {
                $normalized[$key] = self::normalizeArray($value, $depth + 1);
                continue;
            }

            if (is_string($value)) {
                $normalized[$key] = strlen($value) > 1000 ? substr($value, 0, 1000) . '...' : $value;
                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $normalized[$key] = $value;
                continue;
            }

            $normalized[$key] = get_debug_type($value);
        }

        return $normalized;
    }
}
