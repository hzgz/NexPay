<?php

namespace app\service\payment;

use app\service\system\JsonStoreService;
use stdClass;
use think\facade\Db;
use Throwable;

class LocalOrderEventStore
{
    private const STORE = 'order_events_local';
    private const TABLE = 'order_events';

    private static ?bool $dbReady = null;

    public static function recordCreated(object $order, array $meta = []): object
    {
        return self::append('order_created', $order, array_replace([
            'status' => (int)($order->status ?? OrderService::STATUS_PENDING),
            'status_text' => 'pending',
        ], $meta));
    }

    public static function recordPaid(object $order, array $meta = []): object
    {
        return self::append('order_paid', $order, array_replace([
            'status' => (int)($order->status ?? OrderService::STATUS_SUCCESS),
            'status_text' => 'success',
            'pay_time' => (string)($order->pay_time ?? ''),
            'txid' => trim((string)($order->txid ?? '')),
        ], $meta));
    }

    public static function recordExpired(object $order, array $meta = []): object
    {
        return self::append('order_expired', $order, array_replace([
            'status' => (int)($order->status ?? OrderService::STATUS_EXPIRED),
            'status_text' => 'expired',
        ], $meta));
    }

    public static function recordClosed(object $order, array $meta = []): object
    {
        return self::append('order_closed', $order, array_replace([
            'status' => (int)($order->status ?? OrderService::STATUS_CLOSED),
            'status_text' => 'closed',
            'closed_at' => (string)($order->updated_at ?? ''),
        ], $meta));
    }

    public static function recordDeleted(object $order, array $meta = []): object
    {
        return self::append('order_deleted', $order, array_replace([
            'status' => (int)($order->status ?? OrderService::STATUS_CLOSED),
            'status_text' => 'deleted',
            'deleted_at' => (string)($order->deleted_at ?? ''),
        ], $meta));
    }

    public static function recordCallbackRetry(object $order, array $meta = []): object
    {
        return self::append('callback_retry', $order, array_replace([
            'status' => (int)($order->callback_status ?? 1),
            'status_text' => 'retrying',
        ], $meta));
    }

    public static function recordCallbackEnqueued(object $order, array $meta = []): object
    {
        return self::append('callback_enqueued', $order, array_replace([
            'status' => (int)($order->callback_status ?? 0),
            'status_text' => 'queued',
        ], $meta));
    }

    public static function recordCallbackSuccess(object $order, array $meta = []): object
    {
        return self::append('callback_success', $order, array_replace([
            'status' => (int)($order->callback_status ?? 2),
            'status_text' => 'success',
        ], $meta));
    }

    public static function recordCallbackFailed(object $order, array $meta = []): object
    {
        return self::append('callback_failed', $order, array_replace([
            'status' => (int)($order->callback_status ?? 3),
            'status_text' => 'failed',
            'reason_key' => 'failed',
        ], $meta));
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

    private static function append(string $eventType, object $order, array $meta = []): object
    {
        $tradeNo = trim((string)($order->trade_no ?? ''));
        if ($tradeNo === '') {
            return self::hydrate(self::normalize([
                'event_type' => $eventType,
                'meta' => $meta,
            ]));
        }

        $row = self::buildRow($eventType, $order, $meta);
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
                $key = 'db:' . (string)($normalized['id'] ?? uniqid('event_', true));
            }

            $items[$key] = $normalized;
        }

        foreach (self::loadJsonRows() as $row) {
            $normalized = self::normalize($row);
            $key = trim((string)($normalized['event_key'] ?? ''));
            if ($key === '') {
                $key = 'json:' . (string)($normalized['id'] ?? uniqid('event_', true));
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
    private static function buildRow(string $eventType, object $order, array $meta = []): array
    {
        $tradeNo = trim((string)($order->trade_no ?? ''));
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $requestMeta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $timestamp = trim((string)($meta['event_time'] ?? ''));
        if ($timestamp === '') {
            $timestamp = match ($eventType) {
                'order_paid' => trim((string)($order->pay_time ?? '')),
                'order_expired' => trim((string)($order->updated_at ?? $order->expire_time ?? '')),
                'order_closed' => trim((string)($order->updated_at ?? '')),
                'order_deleted' => trim((string)($order->deleted_at ?? $order->updated_at ?? '')),
                'callback_enqueued' => trim((string)($order->updated_at ?? $order->created_at ?? '')),
                'callback_retry', 'callback_success', 'callback_failed' => trim((string)($order->updated_at ?? '')),
                default => trim((string)($order->created_at ?? '')),
            };
        }
        if ($timestamp === '') {
            $timestamp = date('Y-m-d H:i:s');
        }

        return self::normalize([
            'id' => 0,
            'event_key' => self::eventKey($eventType, $tradeNo, $meta),
            'event_type' => $eventType,
            'trade_no' => $tradeNo,
            'out_trade_no' => (string)($order->out_trade_no ?? ''),
            'merchant_id' => (int)($order->merchant_id ?? 0),
            'merchant_channel_id' => (int)($order->merchant_channel_id ?? 0),
            'channel_code' => (string)($order->channel_code ?? ''),
            'business' => strtolower(trim((string)($requestMeta['business'] ?? ''))),
            'source_protocol' => strtolower(trim((string)($requestMeta['source_protocol'] ?? ''))),
            'subject' => (string)($order->subject ?? ''),
            'amount' => number_format((float)($order->amount ?? 0), 2, '.', ''),
            'payable_amount' => number_format((float)($order->payable_amount ?? $order->amount ?? 0), 2, '.', ''),
            'status' => (int)($meta['status'] ?? $order->status ?? 0),
            'event_time' => $timestamp,
            'meta' => $meta,
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

    private static function eventKey(string $eventType, string $tradeNo, array $meta = []): string
    {
        $eventType = strtolower(trim($eventType));
        $tradeNo = trim($tradeNo);

        if (in_array($eventType, ['callback_enqueued', 'callback_retry', 'callback_success', 'callback_failed'], true)) {
            $retryCount = max(0, (int)($meta['retry_count'] ?? 0));
            $statusText = strtolower(trim((string)($meta['status_text'] ?? '')));
            $reasonKey = strtolower(trim((string)($meta['reason_key'] ?? '')));
            $manualRetry = !empty($meta['manual_retry']) ? 'manual' : 'auto';
            $runtimeException = !empty($meta['runtime_exception']) ? 'exception' : 'normal';
            return implode(':', array_filter([
                $eventType,
                $tradeNo,
                (string)$retryCount,
                $statusText,
                $reasonKey,
                $manualRetry,
                $runtimeException,
            ], static fn(string $value): bool => $value !== ''));
        }

        return $eventType . ':' . $tradeNo;
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
            'trade_no' => (string)($row['trade_no'] ?? ''),
            'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
            'merchant_id' => (int)($row['merchant_id'] ?? 0),
            'merchant_channel_id' => (int)($row['merchant_channel_id'] ?? 0),
            'channel_code' => (string)($row['channel_code'] ?? ''),
            'business' => (string)($row['business'] ?? ''),
            'source_protocol' => (string)($row['source_protocol'] ?? ''),
            'subject' => (string)($row['subject'] ?? ''),
            'amount' => number_format((float)($row['amount'] ?? 0), 8, '.', ''),
            'payable_amount' => number_format((float)($row['payable_amount'] ?? 0), 8, '.', ''),
            'status' => (int)($row['status'] ?? 0),
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
            'trade_no' => '',
            'out_trade_no' => '',
            'merchant_id' => 0,
            'merchant_channel_id' => 0,
            'channel_code' => '',
            'business' => '',
            'source_protocol' => '',
            'subject' => '',
            'amount' => '0.00',
            'payable_amount' => '0.00',
            'status' => 0,
            'event_time' => '',
            'meta' => [],
            'created_at' => '',
        ], $row, [
            'id' => (int)($row['id'] ?? 0),
            'merchant_id' => (int)($row['merchant_id'] ?? 0),
            'merchant_channel_id' => (int)($row['merchant_channel_id'] ?? 0),
            'status' => (int)($row['status'] ?? 0),
            'amount' => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
            'payable_amount' => number_format((float)($row['payable_amount'] ?? 0), 2, '.', ''),
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
