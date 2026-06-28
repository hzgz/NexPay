<?php

namespace app\service\payment;

use app\service\system\JsonStoreService;
use stdClass;
use Throwable;

class LocalTransferStore
{
    private const REFUND_STORE = 'refunds_local';
    private const TRANSFER_STORE = 'transfers_local';

    public static function createRefund(array $payload): object
    {
        $rows = self::loadRefunds();
        $row = self::normalizeRefund(array_merge($payload, [
            'id' => self::nextId($rows),
            'created_at' => $payload['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $payload['updated_at'] ?? date('Y-m-d H:i:s'),
        ]));
        $rows[] = $row;
        self::saveRefunds($rows);
        $refund = self::hydrateRefund($row);
        self::recordRefundLifecycleEvent($refund, null);
        return $refund;
    }

    public static function findRefund(int $merchantId, ?string $refundNo, ?string $outRefundNo, ?string $tradeNo = null): ?object
    {
        $refundNo = trim((string)$refundNo);
        $outRefundNo = trim((string)$outRefundNo);
        $tradeNo = trim((string)$tradeNo);
        $hasRefundKey = $refundNo !== '' || $outRefundNo !== '';

        foreach (self::loadRefunds() as $row) {
            if ((int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if ($tradeNo !== '' && $hasRefundKey && ($row['trade_no'] ?? '') !== $tradeNo) {
                continue;
            }

            if ($refundNo !== '' && ($row['refund_no'] ?? '') === $refundNo) {
                return self::hydrateRefund($row);
            }

            if ($outRefundNo !== '' && ($row['out_refund_no'] ?? '') === $outRefundNo) {
                return self::hydrateRefund($row);
            }

            if (!$hasRefundKey && $tradeNo !== '' && ($row['trade_no'] ?? '') === $tradeNo) {
                return self::hydrateRefund($row);
            }
        }

        return null;
    }

    public static function findRefundByNo(string $refundNo): ?object
    {
        $refundNo = trim($refundNo);
        if ($refundNo === '') {
            return null;
        }

        foreach (self::loadRefunds() as $row) {
            if (($row['refund_no'] ?? '') === $refundNo) {
                return self::hydrateRefund($row);
            }
        }

        return null;
    }

    public static function findRefundByAnyNo(string $refundNo = '', string $outRefundNo = ''): ?object
    {
        $refundNo = trim($refundNo);
        $outRefundNo = trim($outRefundNo);
        if ($refundNo === '' && $outRefundNo === '') {
            return null;
        }

        foreach (self::loadRefunds() as $row) {
            if ($refundNo !== '' && ($row['refund_no'] ?? '') === $refundNo) {
                return self::hydrateRefund($row);
            }

            if ($outRefundNo !== '' && ($row['out_refund_no'] ?? '') === $outRefundNo) {
                return self::hydrateRefund($row);
            }
        }

        return null;
    }

    public static function findRefundByTradeNo(string $tradeNo): ?object
    {
        $tradeNo = trim($tradeNo);
        if ($tradeNo === '') {
            return null;
        }

        $matches = [];
        foreach (self::loadRefunds() as $row) {
            if (($row['trade_no'] ?? '') === $tradeNo) {
                $matches[] = $row;
            }
        }

        if (count($matches) === 1) {
            return self::hydrateRefund($matches[0]);
        }

        $pending = array_values(array_filter(
            $matches,
            static fn(array $row): bool => (int)($row['status'] ?? 0) === 0
        ));

        return count($pending) === 1 ? self::hydrateRefund($pending[0]) : null;
    }

    public static function updateRefund(string $refundNo, array $changes): ?object
    {
        $refundNo = trim($refundNo);
        if ($refundNo === '') {
            return null;
        }

        $rows = self::loadRefunds();
        foreach ($rows as $index => $row) {
            if (($row['refund_no'] ?? '') !== $refundNo) {
                continue;
            }

            $before = self::hydrateRefund($row);
            $rows[$index] = self::normalizeRefund(array_merge($row, $changes, [
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            self::saveRefunds($rows);
            $refund = self::hydrateRefund($rows[$index]);
            self::recordRefundLifecycleEvent($refund, $before);
            return $refund;
        }

        return null;
    }

    public static function createTransfer(array $payload): object
    {
        $rows = self::loadTransfers();
        $row = self::normalizeTransfer(array_merge($payload, [
            'id' => self::nextId($rows),
            'created_at' => $payload['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $payload['updated_at'] ?? date('Y-m-d H:i:s'),
        ]));
        $rows[] = $row;
        self::saveTransfers($rows);
        $transfer = self::hydrateTransfer($row);
        self::recordTransferLifecycleEvent($transfer, null);
        return $transfer;
    }

    public static function updateTransfer(string $bizNo, array $changes): ?object
    {
        $rows = self::loadTransfers();
        foreach ($rows as $index => $row) {
            if (($row['biz_no'] ?? '') !== $bizNo) {
                continue;
            }

            $before = self::hydrateTransfer($row);
            $rows[$index] = self::normalizeTransfer(array_merge($row, $changes, [
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            self::saveTransfers($rows);
            $transfer = self::hydrateTransfer($rows[$index]);
            self::recordTransferLifecycleEvent($transfer, $before);
            return $transfer;
        }

        return null;
    }

    public static function findTransferByBizNo(?string $bizNo, ?string $outBizNo = null): ?object
    {
        foreach (self::loadTransfers() as $row) {
            if ($bizNo !== null && $bizNo !== '' && ($row['biz_no'] ?? '') === $bizNo) {
                return self::hydrateTransfer($row);
            }

            if ($outBizNo !== null && $outBizNo !== '' && ($row['out_biz_no'] ?? '') === $outBizNo) {
                return self::hydrateTransfer($row);
            }
        }

        return null;
    }

    public static function refunds(int $limit = 0, int $merchantId = 0, bool $businessOnly = false): array
    {
        $items = [];
        foreach (self::loadRefunds() as $row) {
            if ($merchantId > 0 && (int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if ($businessOnly && !self::isBusinessRefund($row)) {
                continue;
            }

            $items[] = self::hydrateRefund($row);
        }

        usort($items, static fn(object $left, object $right): int => strcmp((string)$right->created_at, (string)$left->created_at));

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    }

    public static function transfers(int $limit = 0, int $merchantId = 0, bool $businessOnly = false): array
    {
        $items = [];
        foreach (self::loadTransfers() as $row) {
            if ($merchantId > 0 && (int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if ($businessOnly && !self::isBusinessTransfer($row)) {
                continue;
            }

            $items[] = self::hydrateTransfer($row);
        }

        usort($items, static fn(object $left, object $right): int => strcmp((string)$right->created_at, (string)$left->created_at));

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    }

    public static function businessRefunds(int $merchantId = 0, int $limit = 0): array
    {
        return self::refunds($limit, $merchantId, true);
    }

    public static function businessTransfers(int $merchantId = 0, int $limit = 0): array
    {
        return self::transfers($limit, $merchantId, true);
    }

    public static function isBusinessRefund(mixed $refund): bool
    {
        $row = self::rowFromMixed($refund);
        $tradeNo = trim((string)($row['trade_no'] ?? ''));
        $merchantId = (int)($row['merchant_id'] ?? 0);
        $outTradeNo = strtoupper(trim((string)($row['out_trade_no'] ?? '')));

        $order = null;
        if ($tradeNo !== '') {
            try {
                $order = OrderService::findByTradeNoForRead($tradeNo, [
                    'source' => 'local-transfer-business-refund-read',
                ]);
            } catch (Throwable) {
                $order = LocalOrderStore::findByTradeNo($tradeNo);
            }
        } elseif ($merchantId > 0 && $outTradeNo !== '') {
            try {
                $order = OrderService::gatewayMerchantOrderForRead(
                    $merchantId,
                    null,
                    $outTradeNo,
                    [
                        'source' => 'local-transfer-business-refund-merchant-order-read',
                    ]
                );
            } catch (Throwable) {
            }
        }
        if ($order !== null) {
            if (!LocalOrderStore::isBusinessOrder($order)) {
                return false;
            }

            return !self::hasTestMarker($row, [
                'manual refund verification',
                'test-refund',
                'out-test-refund',
                'rf-manual-',
                '楠岃瘉',
                '楠岃瘉',
            ]);
        }

        foreach (['VERIFY', 'TEST-', 'OUT-TEST', 'CB', 'PLUGIN-CALLBACK'] as $prefix) {
            if (str_starts_with($outTradeNo, $prefix)) {
                return false;
            }
        }

        return !self::hasTestMarker($row, [
            'manual refund verification',
            'test-refund',
            'out-test-refund',
            'rf-manual-',
            '验证',
            '验证',
        ]);
    }

    public static function isBusinessTransfer(mixed $transfer): bool
    {
        $row = self::rowFromMixed($transfer);
        $outBizNo = strtoupper(trim((string)($row['out_biz_no'] ?? '')));
        if (str_starts_with($outBizNo, 'CBT') || str_starts_with($outBizNo, 'CBF')) {
            return false;
        }

        $account = strtolower(trim((string)($row['account'] ?? '')));
        if (in_array($account, ['demo@example.com', 'notify@example.com', 'fail@example.com'], true)) {
            return false;
        }

        return !self::hasTestMarker($row, [
            'manual transfer verification',
            'transfer notify verification',
            '测试',
            '测试收款人',
            '验证',
            '验证收款人',
        ]);
    }

    public static function sumRefundMoney(int $merchantId, string $tradeNo): string
    {
        if ($merchantId <= 0 || $tradeNo === '') {
            return '0.00';
        }

        $total = 0.0;
        foreach (self::businessRefunds($merchantId) as $refund) {
            if ((string)($refund->trade_no ?? '') !== $tradeNo) {
                continue;
            }
            if ((int)($refund->status ?? 0) !== 1) {
                continue;
            }

            $total += (float)($refund->reducemoney ?? $refund->money ?? 0);
        }

        return number_format($total, 2, '.', '');
    }

    public static function balanceForMerchant(int $merchantId): array
    {
        $balance = 0.0;
        foreach (self::loadTransfers() as $row) {
            if ((int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            $balance = max($balance, (float)($row['available_money'] ?? 0));
        }

        return [
            'available_money' => number_format($balance, 2, '.', ''),
            'transfer_rate' => '0.00',
        ];
    }

    private static function loadRefunds(): array
    {
        return JsonStoreService::load(self::REFUND_STORE, []);
    }

    private static function saveRefunds(array $rows): void
    {
        JsonStoreService::save(self::REFUND_STORE, array_values($rows));
    }

    private static function loadTransfers(): array
    {
        return JsonStoreService::load(self::TRANSFER_STORE, []);
    }

    private static function saveTransfers(array $rows): void
    {
        JsonStoreService::save(self::TRANSFER_STORE, array_values($rows));
    }

    private static function nextId(array $rows): int
    {
        $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function normalizeRefund(array $row): array
    {
        return array_merge([
            'id' => 0,
            'merchant_id' => 0,
            'trade_no' => '',
            'out_trade_no' => '',
            'refund_no' => '',
            'out_refund_no' => '',
            'money' => '0.00',
            'reducemoney' => '0.00',
            'status' => 0,
            'result' => '',
            'last_error' => '',
            'channel_order_no' => '',
            'channel_trade_no' => '',
            'channel_plugin_code' => '',
            'channel_id' => 0,
            'raw_response' => [],
            'proof_no' => '',
            'operator' => '',
            'remark' => '',
            'finished_at' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $row);
    }

    private static function normalizeTransfer(array $row): array
    {
        return array_merge([
            'id' => 0,
            'merchant_id' => 0,
            'biz_no' => '',
            'out_biz_no' => '',
            'type' => '',
            'account' => '',
            'name' => '',
            'money' => '0.00',
            'status' => 0,
            'available_money' => '0.00',
            'transfer_rate' => '0.00',
            'channel_order_no' => '',
            'channel_trade_no' => '',
            'channel_plugin_code' => '',
            'channel_id' => 0,
            'result' => '',
            'last_error' => '',
            'raw_response' => [],
            'proof_no' => '',
            'operator' => '',
            'remark' => '',
            'finished_at' => '',
            'rejected_at' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $row);
    }

    private static function hydrateRefund(array $row): object
    {
        return self::toObject(self::normalizeRefund($row));
    }

    private static function hydrateTransfer(array $row): object
    {
        return self::toObject(self::normalizeTransfer($row));
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

    private static function hasTestMarker(array $row, array $extraMarkers = []): bool
    {
        $text = strtolower((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach (array_merge([
            'verify-openapi',
            'v2-smoke',
            'mapi-test',
            'submit-test',
            'channel-test',
            'plugin-callback-verify',
            'homepage_payment_test',
            'mock_fallback',
            'demo@example.com',
            'notify@example.com',
            'fail@example.com',
        ], $extraMarkers) as $marker) {
            $marker = strtolower((string)$marker);
            if ($marker !== '' && str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function toObject(array $row): object
    {
        $record = new stdClass();
        foreach ($row as $key => $value) {
            $record->$key = $value;
        }

        return $record;
    }

    private static function recordRefundLifecycleEvent(object $refund, ?object $before = null): void
    {
        $result = trim((string)($refund->result ?? ''));
        $status = (int)($refund->status ?? 0);
        $meta = self::payoutEventMeta($refund, $before);

        if ($before === null) {
            LocalPayoutEventStore::recordRefundCreated($refund, $meta);
        }

        if ($status === 1) {
            LocalPayoutEventStore::recordRefundSuccess($refund, $meta);
            return;
        }

        if ($status === 2) {
            LocalPayoutEventStore::recordRefundFailed($refund, $meta);
            return;
        }

        if ($result === 'manual_refund_pending') {
            LocalPayoutEventStore::recordRefundManualPending($refund, $meta);
            return;
        }

        if ($status === 0) {
            LocalPayoutEventStore::recordRefundPending($refund, $meta);
        }
    }

    private static function recordTransferLifecycleEvent(object $transfer, ?object $before = null): void
    {
        $result = trim((string)($transfer->result ?? ''));
        $status = (int)($transfer->status ?? 0);
        $meta = self::payoutEventMeta($transfer, $before);

        if ($before === null) {
            LocalPayoutEventStore::recordTransferCreated($transfer, $meta);
        }

        if ($result === 'manual_transfer_pending') {
            LocalPayoutEventStore::recordTransferManualPending($transfer, $meta);
            return;
        }

        if ($status === 1) {
            LocalPayoutEventStore::recordTransferSuccess($transfer, $meta);
            return;
        }

        if ($status === 2) {
            if ($result === 'manual_rejected') {
                LocalPayoutEventStore::recordTransferRejected($transfer, $meta);
                return;
            }

            LocalPayoutEventStore::recordTransferFailed($transfer, $meta);
            return;
        }

        if ($status === 0) {
            LocalPayoutEventStore::recordTransferPending($transfer, $meta);
        }
    }

    private static function payoutEventMeta(object $current, ?object $before = null): array
    {
        $meta = [
            'status' => (int)($current->status ?? 0),
            'result' => trim((string)($current->result ?? '')),
            'last_error' => trim((string)($current->last_error ?? '')),
            'proof_no' => trim((string)($current->proof_no ?? '')),
            'channel_order_no' => trim((string)($current->channel_order_no ?? '')),
            'channel_trade_no' => trim((string)($current->channel_trade_no ?? '')),
            'operator' => trim((string)($current->operator ?? '')),
            'remark' => trim((string)($current->remark ?? '')),
            'event_time' => trim((string)($current->updated_at ?? $current->created_at ?? '')),
        ];

        if ($before !== null) {
            $meta['previous_status'] = (int)($before->status ?? 0);
            $meta['previous_result'] = trim((string)($before->result ?? ''));
            $meta['status_changed'] = (int)($before->status ?? 0) !== (int)($current->status ?? 0);
            $meta['result_changed'] = trim((string)($before->result ?? '')) !== trim((string)($current->result ?? ''));
            $meta['last_error_changed'] = trim((string)($before->last_error ?? '')) !== trim((string)($current->last_error ?? ''));
        }

        return $meta;
    }
}
