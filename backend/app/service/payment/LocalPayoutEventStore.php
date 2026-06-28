<?php

namespace app\service\payment;

use app\service\system\JsonStoreService;
use stdClass;
use think\facade\Db;
use Throwable;

class LocalPayoutEventStore
{
    private const STORE = 'payout_events_local';
    private const TABLE = 'payout_events';

    private static ?bool $dbReady = null;

    public static function recordRefundCreated(object $refund, array $meta = []): object
    {
        return self::append('refund_created', 'refund', $refund, $meta);
    }

    public static function recordRefundPending(object $refund, array $meta = []): object
    {
        return self::append('refund_pending', 'refund', $refund, $meta);
    }

    public static function recordRefundManualPending(object $refund, array $meta = []): object
    {
        return self::append('refund_manual_pending', 'refund', $refund, $meta);
    }

    public static function recordRefundSuccess(object $refund, array $meta = []): object
    {
        return self::append('refund_success', 'refund', $refund, $meta);
    }

    public static function recordRefundFailed(object $refund, array $meta = []): object
    {
        return self::append('refund_failed', 'refund', $refund, $meta);
    }

    public static function recordTransferCreated(object $transfer, array $meta = []): object
    {
        return self::append('transfer_created', 'transfer', $transfer, $meta);
    }

    public static function recordTransferPending(object $transfer, array $meta = []): object
    {
        return self::append('transfer_pending', 'transfer', $transfer, $meta);
    }

    public static function recordTransferManualPending(object $transfer, array $meta = []): object
    {
        return self::append('transfer_manual_pending', 'transfer', $transfer, $meta);
    }

    public static function recordTransferSuccess(object $transfer, array $meta = []): object
    {
        return self::append('transfer_success', 'transfer', $transfer, $meta);
    }

    public static function recordTransferFailed(object $transfer, array $meta = []): object
    {
        return self::append('transfer_failed', 'transfer', $transfer, $meta);
    }

    public static function recordTransferRejected(object $transfer, array $meta = []): object
    {
        return self::append('transfer_rejected', 'transfer', $transfer, $meta);
    }

    public static function all(int $limit = 0): array
    {
        $rows = array_map(
            static fn(array $row): object => self::hydrate(self::normalize($row)),
            self::mergedRows($limit)
        );

        usort($rows, static function (object $left, object $right): int {
            $compare = strcmp((string)($right->event_time ?? ''), (string)($left->event_time ?? ''));
            if ($compare !== 0) {
                return $compare;
            }

            return (int)($right->id ?? 0) <=> (int)($left->id ?? 0);
        });

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    private static function append(string $eventType, string $kind, object $record, array $meta = []): object
    {
        $referenceNo = self::referenceNo($kind, $record);
        if ($referenceNo === '') {
            return self::hydrate(self::normalize([
                'event_type' => $eventType,
                'kind' => $kind,
                'meta' => $meta,
            ]));
        }

        $row = self::buildRow($eventType, $kind, $record, $meta);
        $stored = self::storeDatabaseRow($row);
        if ($stored === null) {
            $stored = self::storeJsonRow($row);
        }

        return self::hydrate(self::normalize($stored));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function mergedRows(int $limit = 0): array
    {
        $items = [];

        foreach (self::loadDatabaseRows($limit > 0 ? $limit * 3 : 0) as $row) {
            $normalized = self::normalize($row);
            $key = trim((string)($normalized['event_key'] ?? ''));
            if ($key === '') {
                $key = 'db:' . (string)($normalized['id'] ?? uniqid('payout_', true));
            }

            $items[$key] = $normalized;
        }

        foreach (self::loadJsonRows() as $row) {
            $normalized = self::normalize($row);
            $key = trim((string)($normalized['event_key'] ?? ''));
            if ($key === '') {
                $key = 'json:' . (string)($normalized['id'] ?? uniqid('payout_', true));
            }

            if (!isset($items[$key])) {
                $items[$key] = $normalized;
            }
        }

        $rows = array_values($items);
        usort($rows, static function (array $left, array $right): int {
            $compare = strcmp((string)($right['event_time'] ?? ''), (string)($left['event_time'] ?? ''));
            if ($compare !== 0) {
                return $compare;
            }

            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildRow(string $eventType, string $kind, object $record, array $meta = []): array
    {
        $timestamp = trim((string)($meta['event_time'] ?? ''));
        if ($timestamp === '') {
            $timestamp = self::eventTime($eventType, $record);
        }
        if ($timestamp === '') {
            $timestamp = date('Y-m-d H:i:s');
        }

        return self::normalize([
            'id' => 0,
            'event_key' => self::eventKey($eventType, $kind, self::referenceNo($kind, $record), $record, $meta),
            'event_type' => $eventType,
            'kind' => $kind,
            'reference_no' => self::referenceNo($kind, $record),
            'trade_no' => (string)($record->trade_no ?? ''),
            'out_trade_no' => (string)($record->out_trade_no ?? ''),
            'out_refund_no' => (string)($record->out_refund_no ?? ''),
            'out_biz_no' => (string)($record->out_biz_no ?? ''),
            'merchant_id' => (int)($record->merchant_id ?? 0),
            'channel_plugin_code' => (string)($record->channel_plugin_code ?? ''),
            'channel_id' => (int)($record->channel_id ?? 0),
            'target_type' => (string)($record->type ?? ''),
            'target_account' => (string)($record->account ?? ''),
            'target_name' => (string)($record->name ?? ''),
            'amount' => self::amountFor($kind, $record),
            'status' => (int)($meta['status'] ?? $record->status ?? 0),
            'result' => trim((string)($meta['result'] ?? $record->result ?? '')),
            'event_time' => $timestamp,
            'meta' => array_replace([
                'status' => (int)($record->status ?? 0),
                'result' => trim((string)($record->result ?? '')),
                'last_error' => trim((string)($record->last_error ?? '')),
                'proof_no' => trim((string)($record->proof_no ?? '')),
                'channel_order_no' => trim((string)($record->channel_order_no ?? '')),
                'channel_trade_no' => trim((string)($record->channel_trade_no ?? '')),
                'operator' => trim((string)($record->operator ?? '')),
                'remark' => trim((string)($record->remark ?? '')),
            ], $meta),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadJsonRows(): array
    {
        $rows = JsonStoreService::load(self::STORE, []);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadDatabaseRows(int $limit = 0): array
    {
        if (!self::databaseReady()) {
            return [];
        }

        try {
            $query = Db::table(self::TABLE)->order('event_time', 'desc')->order('id', 'desc');
            if ($limit > 0) {
                $query->limit($limit);
            }

            $rows = $query->select()->toArray();
            if (!is_array($rows)) {
                return [];
            }

            return array_map(static function (mixed $row): array {
                return self::fromDatabaseRow((array)$row);
            }, $rows);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function storeDatabaseRow(array $row): ?array
    {
        if (!self::databaseReady()) {
            return null;
        }

        try {
            $existing = Db::table(self::TABLE)->where('event_key', (string)($row['event_key'] ?? ''))->find();
            if (is_array($existing) && $existing !== []) {
                return self::fromDatabaseRow($existing);
            }

            $payload = self::toDatabaseRow($row);
            $id = (int)Db::table(self::TABLE)->insertGetId($payload);
            $payload['id'] = $id;

            return self::fromDatabaseRow($payload);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function storeJsonRow(array $row): array
    {
        $rows = self::loadJsonRows();
        foreach ($rows as $existing) {
            if ((string)($existing['event_key'] ?? '') === (string)($row['event_key'] ?? '')) {
                return self::normalize($existing);
            }
        }

        $row['id'] = self::nextId($rows);
        $rows[] = self::normalize($row);
        JsonStoreService::save(self::STORE, $rows);

        return self::normalize($row);
    }

    private static function nextId(array $rows): int
    {
        $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function referenceNo(string $kind, object $record): string
    {
        return trim((string)($kind === 'refund' ? ($record->refund_no ?? '') : ($record->biz_no ?? '')));
    }

    private static function amountFor(string $kind, object $record): string
    {
        $amount = $kind === 'refund'
            ? ($record->reducemoney ?? $record->money ?? 0)
            : ($record->money ?? 0);

        return number_format((float)$amount, 2, '.', '');
    }

    private static function eventTime(string $eventType, object $record): string
    {
        return match ($eventType) {
            'refund_success', 'transfer_success' => trim((string)($record->finished_at ?? $record->updated_at ?? '')),
            'transfer_rejected' => trim((string)($record->rejected_at ?? $record->updated_at ?? '')),
            default => trim((string)($record->updated_at ?? $record->created_at ?? '')),
        };
    }

    private static function eventKey(string $eventType, string $kind, string $referenceNo, object $record, array $meta = []): string
    {
        $result = strtolower(trim((string)($meta['result'] ?? $record->result ?? '')));
        $proofNo = trim((string)($meta['proof_no'] ?? $record->proof_no ?? ''));
        $channelOrderNo = trim((string)($meta['channel_order_no'] ?? $record->channel_order_no ?? ''));
        $channelTradeNo = trim((string)($meta['channel_trade_no'] ?? $record->channel_trade_no ?? ''));
        $operator = strtolower(trim((string)($meta['operator'] ?? $record->operator ?? '')));
        $lastError = trim((string)($meta['last_error'] ?? $record->last_error ?? ''));
        $status = (int)($meta['status'] ?? $record->status ?? 0);

        $parts = [
            strtolower(trim($eventType)),
            strtolower(trim($kind)),
            $referenceNo,
            (string)$status,
            $result,
            $proofNo,
            $channelOrderNo,
            $channelTradeNo,
            $operator,
        ];

        if ($lastError !== '') {
            $parts[] = substr(md5($lastError), 0, 12);
        }

        return implode(':', array_filter($parts, static fn(string $value): bool => $value !== ''));
    }

    private static function databaseReady(): bool
    {
        if (self::$dbReady !== null) {
            return self::$dbReady;
        }

        if (!function_exists('database_available') || !database_available()) {
            self::$dbReady = false;
            return false;
        }

        try {
            self::$dbReady = Db::query("SHOW TABLES LIKE '" . self::TABLE . "'") !== [];
        } catch (Throwable) {
            self::$dbReady = false;
        }

        return self::$dbReady;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function toDatabaseRow(array $row): array
    {
        return [
            'event_key' => (string)($row['event_key'] ?? ''),
            'event_type' => (string)($row['event_type'] ?? ''),
            'kind' => (string)($row['kind'] ?? ''),
            'reference_no' => (string)($row['reference_no'] ?? ''),
            'trade_no' => (string)($row['trade_no'] ?? ''),
            'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
            'out_refund_no' => (string)($row['out_refund_no'] ?? ''),
            'out_biz_no' => (string)($row['out_biz_no'] ?? ''),
            'merchant_id' => (int)($row['merchant_id'] ?? 0),
            'channel_plugin_code' => (string)($row['channel_plugin_code'] ?? ''),
            'channel_id' => (int)($row['channel_id'] ?? 0),
            'target_type' => (string)($row['target_type'] ?? ''),
            'target_account' => (string)($row['target_account'] ?? ''),
            'target_name' => (string)($row['target_name'] ?? ''),
            'amount' => number_format((float)($row['amount'] ?? 0), 8, '.', ''),
            'status' => (int)($row['status'] ?? 0),
            'result' => (string)($row['result'] ?? ''),
            'event_time' => (string)($row['event_time'] ?? date('Y-m-d H:i:s')),
            'meta' => json_encode($row['meta'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function fromDatabaseRow(array $row): array
    {
        $meta = $row['meta'] ?? [];
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($meta)) {
            $meta = [];
        }

        $row['meta'] = $meta;
        return self::normalize($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $meta = $row['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        return array_replace([
            'id' => 0,
            'event_key' => '',
            'event_type' => '',
            'kind' => '',
            'reference_no' => '',
            'trade_no' => '',
            'out_trade_no' => '',
            'out_refund_no' => '',
            'out_biz_no' => '',
            'merchant_id' => 0,
            'channel_plugin_code' => '',
            'channel_id' => 0,
            'target_type' => '',
            'target_account' => '',
            'target_name' => '',
            'amount' => '0.00',
            'status' => 0,
            'result' => '',
            'event_time' => '',
            'meta' => [],
            'created_at' => '',
        ], $row, [
            'id' => (int)($row['id'] ?? 0),
            'merchant_id' => (int)($row['merchant_id'] ?? 0),
            'channel_id' => (int)($row['channel_id'] ?? 0),
            'status' => (int)($row['status'] ?? 0),
            'amount' => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
            'meta' => $meta,
        ]);
    }

    private static function hydrate(array $row): object
    {
        $record = new stdClass();
        foreach ($row as $key => $value) {
            $record->$key = $value;
        }

        return $record;
    }
}
