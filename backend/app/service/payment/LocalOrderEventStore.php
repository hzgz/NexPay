<?php

namespace app\service\payment;

use app\service\system\JsonStoreService;
use stdClass;

class LocalOrderEventStore
{
    private const STORE = 'order_events_local';

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
        ], $meta));
    }

    public static function all(int $limit = 0): array
    {
        $rows = array_map(
            static fn(array $row): object => self::hydrate(self::normalize($row)),
            self::loadRows()
        );

        usort($rows, static function (object $left, object $right): int {
            return strcmp((string)($right->event_time ?? ''), (string)($left->event_time ?? ''));
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

        $rows = self::loadRows();
        $eventKey = self::eventKey($eventType, $tradeNo, $meta);
        foreach ($rows as $row) {
            if ((string)($row['event_key'] ?? '') === $eventKey) {
                return self::hydrate(self::normalize($row));
            }
        }

        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $requestMeta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $timestamp = trim((string)($meta['event_time'] ?? ''));
        if ($timestamp === '') {
            $timestamp = match ($eventType) {
                'order_paid' => trim((string)($order->pay_time ?? '')),
                'order_expired' => trim((string)($order->updated_at ?? $order->expire_time ?? '')),
                'callback_enqueued' => trim((string)($order->updated_at ?? $order->created_at ?? '')),
                'callback_retry', 'callback_success', 'callback_failed' => trim((string)($order->updated_at ?? '')),
                default => trim((string)($order->created_at ?? '')),
            };
        }
        if ($timestamp === '') {
            $timestamp = date('Y-m-d H:i:s');
        }

        $row = self::normalize([
            'id' => self::nextId($rows),
            'event_key' => $eventKey,
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

        $rows[] = $row;
        JsonStoreService::save(self::STORE, $rows);

        return self::hydrate($row);
    }

    private static function loadRows(): array
    {
        $rows = JsonStoreService::load(self::STORE, []);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
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
            $manualRetry = !empty($meta['manual_retry']) ? 'manual' : 'auto';
            $runtimeException = !empty($meta['runtime_exception']) ? 'exception' : 'normal';
            return implode(':', array_filter([
                $eventType,
                $tradeNo,
                (string)$retryCount,
                $statusText,
                $manualRetry,
                $runtimeException,
            ], static fn(string $value): bool => $value !== ''));
        }

        return $eventType . ':' . $tradeNo;
    }

    private static function normalize(array $row): array
    {
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
        ], $row);
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
