<?php

namespace app\service\payment;

use app\service\system\JsonStoreService;
use app\service\system\SystemBusinessPaymentService;
use stdClass;

class LocalOrderStore
{
    private const ORDER_STORE = 'orders_local';
    private const CALLBACK_STORE = 'callback_queue_local';

    public static function createOrder(array $payload): object
    {
        $rows = self::loadOrders();
        $row = self::normalizeOrder(array_merge($payload, [
            'id' => self::nextId($rows),
            'created_at' => $payload['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $payload['updated_at'] ?? date('Y-m-d H:i:s'),
        ]));
        $rows[] = $row;
        self::saveOrders($rows);
        return self::hydrateOrder($row);
    }

    public static function updateOrder(string $tradeNo, array $changes): ?object
    {
        $rows = self::loadOrders();
        foreach ($rows as $index => $row) {
            if (($row['trade_no'] ?? '') !== $tradeNo) {
                continue;
            }

            $rows[$index] = self::normalizeOrder(array_merge($row, $changes, [
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            self::saveOrders($rows);
            return self::hydrateOrder($rows[$index]);
        }

        return null;
    }

    public static function findById(int $id): ?object
    {
        foreach (self::loadOrders() as $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                return self::hydrateOrder($row);
            }
        }

        return null;
    }

    public static function findByTradeNo(string $tradeNo): ?object
    {
        foreach (self::loadOrders() as $row) {
            if (($row['trade_no'] ?? '') === $tradeNo) {
                return self::hydrateOrder($row);
            }
        }

        return null;
    }

    public static function findByMerchantOrder(int $merchantId, ?string $tradeNo, ?string $outTradeNo): ?object
    {
        foreach (self::loadOrders() as $row) {
            if ((int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if ($tradeNo !== null && $tradeNo !== '' && ($row['trade_no'] ?? '') === $tradeNo) {
                return self::hydrateOrder($row);
            }

            if ($outTradeNo !== null && $outTradeNo !== '' && ($row['out_trade_no'] ?? '') === $outTradeNo) {
                return self::hydrateOrder($row);
            }
        }

        return null;
    }

    public static function merchantOutTradeExists(int $merchantId, string $outTradeNo): bool
    {
        foreach (self::loadOrders() as $row) {
            if ((int)($row['merchant_id'] ?? 0) === $merchantId && ($row['out_trade_no'] ?? '') === $outTradeNo) {
                return true;
            }
        }

        return false;
    }

    public static function pendingOrders(int $limit = 20): array
    {
        $items = [];
        foreach (self::loadOrders() as $row) {
            if ((int)($row['status'] ?? -1) === OrderService::STATUS_PENDING) {
                $items[] = self::hydrateOrder($row);
            }
        }

        usort($items, static fn(object $a, object $b): int => strcmp((string)$a->created_at, (string)$b->created_at));
        return array_slice($items, 0, $limit);
    }

    public static function allOrders(): array
    {
        return array_map(static fn(array $row): object => self::hydrateOrder($row), self::loadOrders());
    }

    public static function ordersByMerchant(int $merchantId): array
    {
        return array_values(array_filter(self::allOrders(), static fn(object $order): bool => (int)$order->merchant_id === $merchantId));
    }

    public static function businessOrders(): array
    {
        return array_values(array_filter(
            self::allOrders(),
            static fn(object $order): bool => self::isBusinessOrder($order)
        ));
    }

    public static function businessOrdersByMerchant(int $merchantId): array
    {
        return array_values(array_filter(
            self::businessOrders(),
            static fn(object $order): bool => (int)$order->merchant_id === $merchantId
        ));
    }

    public static function countBusinessOrdersByMerchant(int $merchantId): int
    {
        return count(self::businessOrdersByMerchant($merchantId));
    }

    public static function countBusinessTodayByMerchant(int $merchantId, string $mode = 'today'): int
    {
        $target = $mode === 'yesterday'
            ? date('Y-m-d', strtotime('-1 day'))
            : date('Y-m-d');

        return count(array_filter(
            self::businessOrdersByMerchant($merchantId),
            static fn(object $order): bool => str_starts_with((string)($order->created_at ?? ''), $target)
        ));
    }

    public static function isBusinessOrder(mixed $order): bool
    {
        $row = self::rowFromMixed($order);
        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $notifyPayload = is_array($row['notify_payload'] ?? null) ? $row['notify_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];

        $business = strtolower(trim((string)($meta['business'] ?? '')));
        if (
            SystemBusinessPaymentService::isTestSyncBusiness($business)
            || SystemBusinessPaymentService::isFundBusiness($business)
        ) {
            return false;
        }

        $sourceProtocol = strtolower(trim((string)($meta['source_protocol'] ?? '')));
        if ($sourceProtocol === 'channel_test') {
            return false;
        }

        $param = strtolower(trim((string)($row['param'] ?? '')));
        if (in_array($param, [
            'demo',
            'v2-demo',
            'demo-system',
            'demo-v1',
            'demo-v2',
            'channel-test',
            'verify-openapi',
            'mapi-test',
            'submit-test',
            'submit2',
            'trc20-test',
            'trc20-submit',
            'v2-smoke',
            'plugin-callback-verify',
        ], true)) {
            return false;
        }

        $subject = strtolower(trim((string)($row['subject'] ?? '')));
        if (in_array($subject, [
            'callback event verify',
            'heartbeat test order',
            'checkorder test order',
            'pcnotify test order',
            'report test order',
        ], true)) {
            return false;
        }

        $outTradeNo = strtoupper(trim((string)($row['out_trade_no'] ?? '')));
        foreach (['DEMO-', 'TEST-TST', 'VERIFY', 'TESTV1', 'TRC20', 'V2SMOKE', 'NEG'] as $prefix) {
            if (str_starts_with($outTradeNo, $prefix)) {
                return false;
            }
        }

        $tradeNo = strtoupper(trim((string)($row['trade_no'] ?? '')));
        if (str_starts_with($tradeNo, 'TST')) {
            return false;
        }

        $channelOrderNo = strtoupper(trim((string)(
            $row['txid']
            ?? $row['api_trade_no']
            ?? $notifyPayload['api_trade_no']
            ?? $notifyPayload['bill_trade_no']
            ?? ''
        )));
        if (str_starts_with($channelOrderNo, 'MOCK')) {
            return false;
        }
        if (str_starts_with($channelOrderNo, 'PLUGIN-CALLBACK')) {
            return false;
        }

        return true;
    }

    public static function expirePendingOrders(): int
    {
        $changed = 0;
        $now = date('Y-m-d H:i:s');

        foreach (self::pendingOrders(0) as $order) {
            if ((string)($order->expire_time ?? '') >= $now) {
                continue;
            }

            OrderService::expireOrder($order, [
                'source' => 'local-expire-task',
                'event_time' => $now,
            ]);
            $changed++;
        }

        return $changed;
    }

    public static function countOrdersByMerchant(int $merchantId): int
    {
        return count(array_filter(self::loadOrders(), static fn(array $row): bool => (int)($row['merchant_id'] ?? 0) === $merchantId));
    }

    public static function countTodayByMerchant(int $merchantId, string $mode = 'today'): int
    {
        $target = $mode === 'yesterday'
            ? date('Y-m-d', strtotime('-1 day'))
            : date('Y-m-d');

        return count(array_filter(self::loadOrders(), static function (array $row) use ($merchantId, $target): bool {
            return (int)($row['merchant_id'] ?? 0) === $merchantId
                && str_starts_with((string)($row['created_at'] ?? ''), $target);
        }));
    }

    public static function createCallback(array $payload): object
    {
        $rows = self::loadCallbacks();
        $row = self::normalizeCallback(array_merge($payload, [
            'id' => self::nextId($rows),
            'created_at' => $payload['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $payload['updated_at'] ?? date('Y-m-d H:i:s'),
        ]));
        $rows[] = $row;
        self::saveCallbacks($rows);
        return self::hydrateCallback($row);
    }

    public static function updateCallback(int $id, array $changes): ?object
    {
        $rows = self::loadCallbacks();
        foreach ($rows as $index => $row) {
            if ((int)($row['id'] ?? 0) !== $id) {
                continue;
            }

            $rows[$index] = self::normalizeCallback(array_merge($row, $changes, [
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            self::saveCallbacks($rows);
            return self::hydrateCallback($rows[$index]);
        }

        return null;
    }

    public static function pendingCallbacks(int $limit = 20): array
    {
        $items = [];
        $now = date('Y-m-d H:i:s');

        foreach (self::loadCallbacks() as $row) {
            if ((int)($row['status'] ?? -1) !== 0) {
                continue;
            }

            if (($row['next_time'] ?? '') > $now) {
                continue;
            }

            $items[] = self::hydrateCallback($row);
        }

        usort($items, static fn(object $a, object $b): int => strcmp((string)$a->created_at, (string)$b->created_at));
        return array_slice($items, 0, $limit);
    }

    public static function callbacks(int $limit = 100, int $merchantId = 0): array
    {
        $items = [];

        foreach (self::loadCallbacks() as $row) {
            if ($merchantId > 0 && (int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            $items[] = self::hydrateCallback($row);
        }

        usort($items, static function (object $left, object $right): int {
            return strcmp((string)$right->updated_at, (string)$left->updated_at);
        });

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    }

    public static function hasPendingOrSuccessCallback(int $orderId): bool
    {
        foreach (self::callbacksByOrderId($orderId) as $callback) {
            if (in_array((int)($callback->status ?? -1), [0, 2], true)) {
                return true;
            }
        }

        return false;
    }

    public static function findActiveCallbackByOrderId(int $orderId): ?object
    {
        foreach (self::callbacksByOrderId($orderId) as $callback) {
            if (in_array((int)($callback->status ?? -1), [0, 2], true)) {
                return $callback;
            }
        }

        return null;
    }

    public static function findCallbackByOrderId(int $orderId): ?object
    {
        $items = self::callbacksByOrderId($orderId);
        return $items[0] ?? null;
    }

    public static function callbacksByOrderId(int $orderId): array
    {
        $orderId = max(0, $orderId);
        if ($orderId <= 0) {
            return [];
        }

        $items = [];
        foreach (self::loadCallbacks() as $row) {
            if ((int)($row['order_id'] ?? 0) !== $orderId) {
                continue;
            }

            $items[] = self::normalizeCallback($row);
        }

        usort($items, static function (array $left, array $right): int {
            $updatedCompare = strcmp((string)($right['updated_at'] ?? ''), (string)($left['updated_at'] ?? ''));
            if ($updatedCompare !== 0) {
                return $updatedCompare;
            }

            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        return array_map(static fn(array $row): object => self::hydrateCallback($row), $items);
    }

    public static function deleteMerchantOrder(int $merchantId, string $tradeNo = '', string $outTradeNo = '', int $orderId = 0): int
    {
        $merchantId = max(0, $merchantId);
        if ($merchantId <= 0) {
            return 0;
        }

        $tradeNo = trim($tradeNo);
        $outTradeNo = trim($outTradeNo);
        $orderId = max(0, $orderId);
        if ($tradeNo === '' && $outTradeNo === '' && $orderId <= 0) {
            return 0;
        }

        $rows = self::loadOrders();
        $deleted = 0;
        $next = [];

        foreach ($rows as $row) {
            $matchesMerchant = (int)($row['merchant_id'] ?? 0) === $merchantId;
            $matchesOrder = $orderId > 0 && (int)($row['id'] ?? 0) === $orderId;
            $matchesTradeNo = $tradeNo !== '' && (string)($row['trade_no'] ?? '') === $tradeNo;
            $matchesOutTradeNo = $outTradeNo !== '' && (string)($row['out_trade_no'] ?? '') === $outTradeNo;

            if ($matchesMerchant && ($matchesOrder || $matchesTradeNo || $matchesOutTradeNo)) {
                $deleted++;
                continue;
            }

            $next[] = $row;
        }

        if ($deleted > 0) {
            self::saveOrders($next);
        }

        return $deleted;
    }

    public static function deleteCallbacksByOrderId(int $orderId): int
    {
        $orderId = max(0, $orderId);
        if ($orderId <= 0) {
            return 0;
        }

        $rows = self::loadCallbacks();
        $deleted = 0;
        $next = [];

        foreach ($rows as $row) {
            if ((int)($row['order_id'] ?? 0) === $orderId) {
                $deleted++;
                continue;
            }

            $next[] = $row;
        }

        if ($deleted > 0) {
            self::saveCallbacks($next);
        }

        return $deleted;
    }

    public static function updateCallbacksByOrderId(int $orderId, array $changes): int
    {
        $orderId = max(0, $orderId);
        if ($orderId <= 0) {
            return 0;
        }

        $rows = self::loadCallbacks();
        $updated = 0;

        foreach ($rows as $index => $row) {
            if ((int)($row['order_id'] ?? 0) !== $orderId) {
                continue;
            }

            $rows[$index] = self::normalizeCallback(array_merge($row, $changes, [
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            $updated++;
        }

        if ($updated > 0) {
            self::saveCallbacks($rows);
        }

        return $updated;
    }

    private static function loadOrders(): array
    {
        return JsonStoreService::load(self::ORDER_STORE, []);
    }

    private static function saveOrders(array $rows): void
    {
        JsonStoreService::save(self::ORDER_STORE, array_values($rows));
    }

    private static function loadCallbacks(): array
    {
        return JsonStoreService::load(self::CALLBACK_STORE, []);
    }

    private static function saveCallbacks(array $rows): void
    {
        JsonStoreService::save(self::CALLBACK_STORE, array_values($rows));
    }

    private static function nextId(array $rows): int
    {
        $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function normalizeOrder(array $row): array
    {
        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $notifyPayload = is_array($row['notify_payload'] ?? null) ? $row['notify_payload'] : [];
        $legacyExt = $notifyPayload['legacy_ext'] ?? $row['legacy_ext'] ?? [];
        $buyer = trim((string)($row['buyer'] ?? $notifyPayload['buyer'] ?? $requestPayload['buyer'] ?? ''));
        $apiTradeNo = trim((string)($row['api_trade_no'] ?? $notifyPayload['api_trade_no'] ?? $row['txid'] ?? ''));
        $billTradeNo = trim((string)($row['bill_trade_no'] ?? $notifyPayload['bill_trade_no'] ?? $apiTradeNo));
        $billMchTradeNo = trim((string)($row['bill_mch_trade_no'] ?? $notifyPayload['bill_mch_trade_no'] ?? ''));
        $subject = (string)($row['subject'] ?? $row['name'] ?? '');
        $channelCode = (string)($row['channel_code'] ?? '');
        $createdAt = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
        $payTime = $row['pay_time'] ?? null;

        return array_merge([
            'id' => 0,
            'trade_no' => '',
            'out_trade_no' => '',
            'merchant_id' => 0,
            'merchant_channel_id' => 0,
            'channel_code' => '',
            'channel_category' => 2,
            'subject' => '',
            'amount' => '0.00',
            'payable_amount' => '0.00',
            'status' => 0,
            'payment_address' => '',
            'txid' => '',
            'confirmations' => 0,
            'expire_time' => '',
            'pay_time' => null,
            'platform_fee' => '0.00000000',
            'fee_deducted' => 0,
            'callback_status' => 0,
            'callback_count' => 0,
            'notify_url' => '',
            'return_url' => '',
            'client_ip' => '',
            'param' => '',
            'request_payload' => [],
            'notify_payload' => [],
            'remark' => '',
            'name' => $subject,
            'typename' => $channelCode,
            'realmoney' => (string)($row['amount'] ?? '0.00'),
            'channel' => (int)($row['merchant_channel_id'] ?? 0),
            'buyer' => $buyer,
            'api_trade_no' => $apiTradeNo,
            'bill_trade_no' => $billTradeNo,
            'bill_mch_trade_no' => $billMchTradeNo,
            'legacy_ext' => is_array($legacyExt) ? $legacyExt : [],
            'ext' => is_array($legacyExt) ? $legacyExt : [],
            'addtime' => $createdAt,
            'endtime' => $payTime,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $row);
    }

    private static function normalizeCallback(array $row): array
    {
        return array_merge([
            'id' => 0,
            'order_id' => 0,
            'merchant_id' => 0,
            'notify_url' => '',
            'payload' => [],
            'payload_hash' => '',
            'retry_count' => 0,
            'max_retry' => 5,
            'status' => 0,
            'next_time' => date('Y-m-d H:i:s'),
            'last_error' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $row);
    }

    private static function hydrateOrder(array $row): object
    {
        return self::toObject(self::normalizeOrder($row));
    }

    private static function hydrateCallback(array $row): object
    {
        return self::toObject(self::normalizeCallback($row));
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

    private static function toObject(array $row): object
    {
        $record = new stdClass();
        foreach ($row as $key => $value) {
            $record->$key = $value;
        }

        return $record;
    }
}
