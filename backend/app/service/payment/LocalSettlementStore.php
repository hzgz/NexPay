<?php

namespace app\service\payment;

use app\service\system\JsonStoreService;
use stdClass;

class LocalSettlementStore
{
    private const STORE_KEY = 'settlements_local';

    public static function create(array $payload): object
    {
        $rows = self::loadRows();
        $row = self::normalize(array_merge($payload, [
            'id' => self::nextId($rows),
            'created_at' => $payload['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $payload['updated_at'] ?? date('Y-m-d H:i:s'),
        ]));

        $rows[] = $row;
        self::saveRows($rows);

        return self::hydrate($row);
    }

    public static function update(string $settleNo, array $changes): ?object
    {
        $rows = self::loadRows();
        foreach ($rows as $index => $row) {
            if ((string)($row['settle_no'] ?? '') !== $settleNo) {
                continue;
            }

            $rows[$index] = self::normalize(array_merge($row, $changes, [
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            self::saveRows($rows);
            return self::hydrate($rows[$index]);
        }

        return null;
    }

    public static function find(string $settleNo): ?object
    {
        foreach (self::loadRows() as $row) {
            if ((string)($row['settle_no'] ?? '') === $settleNo) {
                return self::hydrate($row);
            }
        }

        return null;
    }

    public static function findByOutSettleNo(int $merchantId, string $outSettleNo): ?object
    {
        foreach (self::loadRows() as $row) {
            if ((int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if ((string)($row['out_settle_no'] ?? '') === $outSettleNo) {
                return self::hydrate($row);
            }
        }

        return null;
    }

    public static function all(int $merchantId = 0, int $limit = 200): array
    {
        $items = [];
        foreach (self::loadRows() as $row) {
            if ($merchantId > 0 && (int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            $items[] = self::hydrate($row);
        }

        usort($items, static function (object $left, object $right): int {
            return strcmp((string)$right->created_at, (string)$left->created_at);
        });

        return array_slice($items, 0, max(1, $limit));
    }

    public static function businessSettlements(int $merchantId = 0, int $limit = 200): array
    {
        $items = [];
        foreach (self::all($merchantId, 100000) as $item) {
            if (!self::isBusinessSettlement($item)) {
                continue;
            }

            $items[] = $item;
        }

        return array_slice($items, 0, max(1, $limit));
    }

    public static function isBusinessSettlement(mixed $settlement): bool
    {
        $row = self::rowFromMixed($settlement);
        $account = strtolower(trim((string)($row['account'] ?? '')));
        if (in_array($account, ['demo@example.com', 'notify@example.com', 'fail@example.com'], true)) {
            return false;
        }

        $settleNo = strtoupper(trim((string)($row['settle_no'] ?? '')));
        $outSettleNo = strtoupper(trim((string)($row['out_settle_no'] ?? '')));
        foreach ([$settleNo, $outSettleNo] as $no) {
            foreach (['VERIFY', 'TEST-', 'OUT-TEST', 'MOCK'] as $prefix) {
                if ($no !== '' && str_starts_with($no, $prefix)) {
                    return false;
                }
            }
        }

        $text = strtolower((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        foreach ([
            'verify-openapi',
            'mock_fallback',
            'mock settlement',
            'settlement test',
            'test settlement',
            '测试结算',
            '测试提现',
            '联调提现',
            '测试',
            '验证',
        ] as $marker) {
            $marker = strtolower($marker);
            if ($marker !== '' && str_contains($text, $marker)) {
                return false;
            }
        }

        return true;
    }

    private static function loadRows(): array
    {
        return JsonStoreService::load(self::STORE_KEY, []);
    }

    private static function saveRows(array $rows): void
    {
        JsonStoreService::save(self::STORE_KEY, array_values($rows));
    }

    private static function nextId(array $rows): int
    {
        $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function normalize(array $row): array
    {
        return array_merge([
            'id' => 0,
            'merchant_id' => 0,
            'settle_no' => '',
            'out_settle_no' => '',
            'type' => 'manual_withdraw',
            'account_type' => '',
            'account' => '',
            'account_name' => '',
            'money' => '0.00',
            'fee' => '0.00',
            'real_money' => '0.00',
            'status' => 0,
            'result' => 'pending_manual_review',
            'last_error' => '',
            'remark' => '',
            'operator' => '',
            'audited_at' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $row);
    }

    private static function hydrate(array $row): object
    {
        $record = new stdClass();
        foreach (self::normalize($row) as $key => $value) {
            $record->$key = $value;
        }

        return $record;
    }

    private static function rowFromMixed(mixed $record): array
    {
        if (is_array($record)) {
            return $record;
        }

        if (is_object($record) && method_exists($record, 'toArray')) {
            return $record->toArray();
        }

        if (is_object($record)) {
            return json_decode((string)json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?: [];
        }

        return [];
    }
}
