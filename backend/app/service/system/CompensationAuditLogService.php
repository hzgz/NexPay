<?php

namespace app\service\system;

class CompensationAuditLogService
{
    private const ADMIN_STORE = 'admin_operation_logs';
    private const MERCHANT_STORE = 'merchant_operation_logs';

    public static function admin(array $entry): void
    {
        self::append(self::ADMIN_STORE, self::normalize($entry, 'admin'));
    }

    public static function merchant(array $entry): void
    {
        self::append(self::MERCHANT_STORE, self::normalize($entry, 'merchant'));
    }

    public static function adminLogs(): array
    {
        return JsonStoreService::load(self::ADMIN_STORE, []);
    }

    public static function merchantLogs(): array
    {
        return JsonStoreService::load(self::MERCHANT_STORE, []);
    }

    private static function append(string $store, array $entry): void
    {
        $logs = JsonStoreService::load($store, []);
        array_unshift($logs, $entry);
        JsonStoreService::save($store, array_slice($logs, 0, 300));
    }

    private static function normalize(array $entry, string $defaultScope): array
    {
        $detail = is_array($entry['detail'] ?? null) ? $entry['detail'] : [];

        return [
            'scope' => trim((string)($entry['scope'] ?? $defaultScope)) ?: $defaultScope,
            'operator' => trim((string)($entry['operator'] ?? 'system')) ?: 'system',
            'merchant_id' => (int)($entry['merchant_id'] ?? 0),
            'action' => trim((string)($entry['action'] ?? '')) ?: '未知操作',
            'ip' => trim((string)($entry['ip'] ?? '')),
            'created_at' => trim((string)($entry['created_at'] ?? '')) ?: date('Y-m-d H:i:s'),
            'detail' => $detail,
            'summary' => trim((string)($entry['summary'] ?? '')) ?: self::buildSummary($detail),
        ];
    }

    private static function buildSummary(array $detail): string
    {
        $parts = [];
        $priorityKeys = [
            'refund_no',
            'biz_no',
            'trade_no',
            'out_trade_no',
            'out_refund_no',
            'out_biz_no',
            'bucket',
            'mode',
            'amount',
            'proof_no',
            'reason',
            'remark',
            'message',
            'counts',
        ];

        foreach ($priorityKeys as $key) {
            if (!array_key_exists($key, $detail)) {
                continue;
            }

            $text = self::stringify($detail[$key]);
            if ($text === '') {
                continue;
            }

            $parts[] = $key . '=' . $text;
            if (count($parts) >= 4) {
                break;
            }
        }

        return implode(' / ', $parts);
    }

    private static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            $pairs = [];
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    continue;
                }

                $text = trim((string)$item);
                if ($text === '') {
                    continue;
                }

                $pairs[] = (string)$key . ':' . $text;
                if (count($pairs) >= 3) {
                    break;
                }
            }

            return implode(',', $pairs);
        }

        return trim((string)$value);
    }
}
