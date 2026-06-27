<?php

namespace app\service\payment;

use app\service\system\JsonStoreService;
use stdClass;

class LocalPayoutEventStore
{
    private const STORE = 'payout_events_local';

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
            self::loadRows()
        );

        usort($rows, static function (object $left, object $right): int {
            return strcmp((string)($right->event_time ?? ''), (string)($left->event_time ?? ''));
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

        $rows = self::loadRows();
        $eventKey = self::eventKey($eventType, $kind, $referenceNo, $record, $meta);
        foreach ($rows as $row) {
            if ((string)($row['event_key'] ?? '') === $eventKey) {
                return self::hydrate(self::normalize($row));
            }
        }

        $timestamp = trim((string)($meta['event_time'] ?? ''));
        if ($timestamp === '') {
            $timestamp = self::eventTime($eventType, $record);
        }
        if ($timestamp === '') {
            $timestamp = date('Y-m-d H:i:s');
        }

        $row = self::normalize([
            'id' => self::nextId($rows),
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'kind' => $kind,
            'reference_no' => $referenceNo,
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

    private static function normalize(array $row): array
    {
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
