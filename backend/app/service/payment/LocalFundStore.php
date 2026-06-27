<?php

namespace app\service\payment;

use app\model\MerchantBalance;
use app\service\system\JsonStoreService;
use app\service\system\PackageService;
use app\service\system\PaymentMetaService;
use stdClass;
use Throwable;

class LocalFundStore
{
    private const FLOW_STORE = 'fund_flows_local';

    public static function recordOrderSuccess(object $order, object $merchant): ?object
    {
        $merchantId = (int)($order->merchant_id ?? 0);
        $tradeNo = trim((string)($order->trade_no ?? ''));
        if ($merchantId <= 0 || $tradeNo === '') {
            return null;
        }

        $payload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
        $business = trim((string)($meta['business'] ?? ''));

        if ($business === 'merchant_register_fee') {
            return null;
        }

        if ($business === 'merchant_package_purchase') {
            return null;
        }

        if (!LocalOrderStore::isBusinessOrder($order) && $business !== 'merchant_recharge') {
            return null;
        }

        if ($business === 'merchant_recharge') {
            $methodCode = PaymentMetaService::normalizeMethodCode((string)($order->channel_code ?? ''));
            return self::credit(
                $merchantId,
                (string)($order->amount ?? '0.00'),
                '余额充值',
                'recharge',
                $tradeNo,
                (string)($order->pay_time ?? date('Y-m-d H:i:s')),
                [
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string)($order->out_trade_no ?? ''),
                    'channel_code' => $methodCode,
                    'method_name' => $methodCode !== '' ? PaymentMetaService::friendlyMethodName($methodCode) : '',
                ]
            );
        }

        $amount = (float)($order->amount ?? 0);
        $fee = max(0.0, (float)($order->platform_fee ?? 0));
        $net = max(0, $amount - $fee);
        if ($net <= 0) {
            return null;
        }

        return self::credit(
            $merchantId,
            number_format($net, 2, '.', ''),
            '订单入账',
            'order_income',
            $tradeNo,
            (string)($order->pay_time ?? date('Y-m-d H:i:s')),
            [
                'trade_no' => $tradeNo,
                'out_trade_no' => (string)($order->out_trade_no ?? ''),
                'gross_amount' => number_format($amount, 2, '.', ''),
                'platform_fee' => number_format($fee, 8, '.', ''),
                'channel_code' => (string)($order->channel_code ?? ''),
                'subject' => (string)($order->subject ?? ''),
            ]
        );
    }

    public static function credit(
        int $merchantId,
        string $amount,
        string $type,
        string $refType,
        string $refNo,
        string $createdAt = '',
        array $meta = []
    ): object {
        return self::appendFlow($merchantId, abs((float)$amount), $type, $refType, $refNo, $createdAt, $meta);
    }

    public static function debit(
        int $merchantId,
        string $amount,
        string $type,
        string $refType,
        string $refNo,
        string $createdAt = '',
        array $meta = []
    ): object {
        return self::appendFlow($merchantId, -abs((float)$amount), $type, $refType, $refNo, $createdAt, $meta);
    }

    public static function findFlowByReference(int $merchantId, string $refType, string $refNo): ?object
    {
        $refType = trim($refType);
        $refNo = trim($refNo);
        if ($merchantId <= 0 || $refType === '' || $refNo === '') {
            return null;
        }

        foreach (self::loadFlows() as $row) {
            if (self::isSameBusinessEvent($row, $merchantId, $refType, $refNo, '')) {
                return self::hydrateFlow($row);
            }
        }

        return null;
    }

    public static function balanceForMerchant(int $merchantId): array
    {
        $available = 0.0;
        $totalRecharge = 0.0;
        $totalConsumption = 0.0;

        foreach (self::businessFlowsForMerchant($merchantId, 0) as $row) {
            if ((string)($row['status'] ?? 'success') !== 'success') {
                continue;
            }

            $amount = (float)($row['amount'] ?? 0);
            $available += $amount;

            if (($row['ref_type'] ?? '') === 'recharge') {
                $totalRecharge += max(0, $amount);
            }

            if ($amount < 0) {
                $totalConsumption += abs($amount);
            } elseif (($row['ref_type'] ?? '') === 'settlement_reject') {
                $totalConsumption -= min($totalConsumption, max(0, $amount));
            }
        }

        return [
            'available' => self::formatMoney($available),
            'frozen' => '0.00',
            'total_recharge' => self::formatMoney($totalRecharge),
            'total_consumption' => self::formatMoney($totalConsumption),
        ];
    }

    public static function flowsForMerchant(int $merchantId, int $limit = 100): array
    {
        return self::flowsForMerchantByScope($merchantId, $limit, false);
    }

    public static function businessFlowsForMerchant(int $merchantId, int $limit = 100): array
    {
        return self::flowsForMerchantByScope($merchantId, $limit, true);
    }

    public static function hasBusinessFlowsForMerchant(int $merchantId): bool
    {
        if ($merchantId <= 0) {
            return false;
        }

        foreach (self::loadFlows() as $row) {
            if ((int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if (self::isBusinessFlow($row)) {
                return true;
            }
        }

        return false;
    }

    public static function syncMerchantBalanceSnapshot(int $merchantId): void
    {
        if ($merchantId <= 0 || !database_available()) {
            return;
        }

        try {
            $snapshot = self::balanceForMerchant($merchantId);
            $balance = MerchantBalance::where('merchant_id', $merchantId)->find();
            if (!$balance) {
                $balance = new MerchantBalance();
                $balance->merchant_id = $merchantId;
            }

            $balance->balance = self::formatMoney($snapshot['available'] ?? '0.00');
            $balance->frozen_balance = self::formatMoney($snapshot['frozen'] ?? '0.00');
            $balance->total_recharge = self::formatMoney($snapshot['total_recharge'] ?? '0.00');
            $balance->total_consumption = self::formatMoney($snapshot['total_consumption'] ?? '0.00');
            $balance->save();
        } catch (Throwable) {
        }
    }

    public static function isBusinessFlow(mixed $flow): bool
    {
        $row = self::rowFromMixed($flow);
        $refType = trim((string)($row['ref_type'] ?? ''));
        $refNo = trim((string)($row['ref_no'] ?? ''));
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        if ($refType === 'order_income') {
            $tradeNo = trim((string)($meta['trade_no'] ?? $refNo));
            $order = $tradeNo !== '' ? LocalOrderStore::findByTradeNo($tradeNo) : null;
            return $order !== null && LocalOrderStore::isBusinessOrder($order);
        }

        if ($refType === 'recharge') {
            $tradeNo = trim((string)($meta['trade_no'] ?? $refNo));
            $order = $tradeNo !== '' ? LocalOrderStore::findByTradeNo($tradeNo) : null;
            if ($order === null) {
                return !self::hasTestMarker($row);
            }

            if (!self::isBusinessRechargeOrder($order)) {
                return self::hasApprovedSettlementUsingRecharge($row);
            }

            return !self::isLinkedToTestPackagePurchase($row);
        }

        if ($refType === 'package_purchase') {
            return !self::hasTestMarker($row) && PackageService::isBusinessPackage($meta);
        }

        if ($refType === 'refund') {
            $refund = LocalTransferStore::findRefundByNo($refNo);
            return $refund !== null && LocalTransferStore::isBusinessRefund($refund);
        }

        if ($refType === 'transfer') {
            $transfer = LocalTransferStore::findTransferByBizNo($refNo, (string)($meta['out_biz_no'] ?? ''));
            return $transfer !== null && LocalTransferStore::isBusinessTransfer($transfer);
        }

        if ($refType === 'settlement_withdraw' || $refType === 'settlement_reject') {
            $settlement = LocalSettlementStore::find($refNo);
            if ($settlement !== null) {
                if (!LocalSettlementStore::isBusinessSettlement($settlement)) {
                    return false;
                }

                if ((int)($settlement->merchant_id ?? 0) !== (int)($row['merchant_id'] ?? 0)) {
                    return false;
                }

                if ($refType === 'settlement_reject') {
                    return (int)($settlement->status ?? 0) === 2;
                }

                return in_array((int)($settlement->status ?? 0), [0, 1, 2], true)
                    || (string)($settlement->result ?? '') === 'pending_manual_review';
            }

            return false;
        }

        return !self::hasTestMarker($row);
    }

    public static function sumRecharge(): string
    {
        $total = 0.0;
        foreach (self::businessFlowsForMerchant(0, 0) as $row) {
            if (($row['ref_type'] ?? '') !== 'recharge') {
                continue;
            }
            if ((string)($row['status'] ?? 'success') !== 'success') {
                continue;
            }

            $total += max(0, (float)($row['amount'] ?? 0));
        }

        return self::formatMoney($total);
    }

    private static function appendFlow(
        int $merchantId,
        float $amount,
        string $type,
        string $refType,
        string $refNo,
        string $createdAt,
        array $meta
    ): object {
        $refNo = trim($refNo);
        $refType = trim($refType);
        $createdAt = trim($createdAt) !== '' ? trim($createdAt) : date('Y-m-d H:i:s');
        $rows = self::loadFlows();

        foreach ($rows as $row) {
            if (self::isSameBusinessEvent($row, $merchantId, $refType, $refNo, $type)) {
                return self::hydrateFlow($row);
            }
        }

        $balanceBefore = (float)self::balanceForMerchant($merchantId)['available'];
        $balanceAfter = $balanceBefore + $amount;
        $row = self::normalizeFlow([
            'id' => self::nextId($rows),
            'merchant_id' => $merchantId,
            'type' => $type,
            'amount' => self::formatSignedMoney($amount),
            'balance_after' => self::formatMoney($balanceAfter),
            'ref_type' => $refType,
            'ref_no' => $refNo,
            'status' => 'success',
            'meta' => $meta,
            'created_at' => $createdAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $rows[] = $row;
        self::saveFlows($rows);
        self::syncMerchantBalanceSnapshot($merchantId);
        return self::hydrateFlow($row);
    }

    private static function flowsForMerchantByScope(int $merchantId, int $limit, bool $businessOnly): array
    {
        $items = [];
        foreach (self::loadFlows() as $row) {
            if ($merchantId > 0 && (int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if ($businessOnly && !self::isBusinessFlow($row)) {
                continue;
            }

            $items[] = self::normalizeFlow($row);
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    }

    private static function loadFlows(): array
    {
        return JsonStoreService::load(self::FLOW_STORE, []);
    }

    private static function saveFlows(array $rows): void
    {
        JsonStoreService::save(self::FLOW_STORE, array_values($rows));
    }

    private static function normalizeFlow(array $row): array
    {
        return array_merge([
            'id' => 0,
            'merchant_id' => 0,
            'type' => '',
            'amount' => '0.00',
            'balance_after' => '0.00',
            'ref_type' => '',
            'ref_no' => '',
            'status' => 'success',
            'meta' => [],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $row);
    }

    private static function nextId(array $rows): int
    {
        $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function hydrateFlow(array $row): object
    {
        $record = new stdClass();
        foreach (self::normalizeFlow($row) as $key => $value) {
            $record->$key = $value;
        }

        return $record;
    }

    private static function isSameBusinessEvent(array $row, int $merchantId, string $refType, string $refNo, string $type): bool
    {
        if ((int)($row['merchant_id'] ?? 0) !== $merchantId) {
            return false;
        }

        if ((string)($row['ref_type'] ?? '') !== $refType || (string)($row['ref_no'] ?? '') !== $refNo) {
            return false;
        }

        if ($refType !== '' && $refNo !== '') {
            return true;
        }

        return $type === '' || (string)($row['type'] ?? '') === $type;
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

    private static function isBusinessRechargeOrder(object $order): bool
    {
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        if (trim((string)($meta['business'] ?? '')) !== 'merchant_recharge') {
            return LocalOrderStore::isBusinessOrder($order);
        }

        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $channelOrderNo = strtoupper(trim((string)(
            $order->txid
            ?? $order->api_trade_no
            ?? $notifyPayload['api_trade_no']
            ?? ''
        )));
        if (str_starts_with($channelOrderNo, 'MOCK')) {
            return false;
        }

        return !self::hasTestMarker(self::rowFromMixed($order));
    }

    private static function isLinkedToTestPackagePurchase(array $rechargeFlow): bool
    {
        $merchantId = (int)($rechargeFlow['merchant_id'] ?? 0);
        $createdAt = strtotime((string)($rechargeFlow['created_at'] ?? ''));
        if ($merchantId <= 0 || $createdAt === false) {
            return false;
        }

        foreach (self::loadFlows() as $flow) {
            if ((int)($flow['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if ((string)($flow['ref_type'] ?? '') !== 'package_purchase') {
                continue;
            }

            if (!self::hasTestMarker($flow)) {
                continue;
            }

            $packageAt = strtotime((string)($flow['created_at'] ?? ''));
            if ($packageAt !== false && abs($packageAt - $createdAt) <= 1800) {
                return true;
            }
        }

        return false;
    }

    private static function hasApprovedSettlementUsingRecharge(array $rechargeFlow): bool
    {
        $merchantId = (int)($rechargeFlow['merchant_id'] ?? 0);
        $amount = (float)($rechargeFlow['amount'] ?? 0);
        $createdAt = strtotime((string)($rechargeFlow['created_at'] ?? ''));
        if ($merchantId <= 0 || $amount <= 0 || $createdAt === false) {
            return false;
        }

        foreach (self::loadFlows() as $flow) {
            if ((int)($flow['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if ((string)($flow['ref_type'] ?? '') !== 'settlement_withdraw') {
                continue;
            }

            $settlement = LocalSettlementStore::find((string)($flow['ref_no'] ?? ''));
            if ($settlement === null || (int)($settlement->status ?? 0) !== 1) {
                continue;
            }

            $settlementAt = strtotime((string)($flow['created_at'] ?? ''));
            if ($settlementAt === false || abs($settlementAt - $createdAt) > 1800) {
                continue;
            }

            if ((float)($flow['amount'] ?? 0) < 0 && abs((float)$flow['amount']) <= $amount + 0.00001) {
                return true;
            }
        }

        return false;
    }

    private static function hasTestMarker(array $row): bool
    {
        $text = strtolower((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ([
            'verify-openapi',
            'test-refund',
            'manual refund verification',
            'manual transfer verification',
            'plugin-callback',
            'plugin-transfer-notify',
            'plugin-refund-notify',
            'mock_fallback',
            'demo@example.com',
            'notify@example.com',
            'fail@example.com',
            '真实闭环测试套餐',
            '本地开放接口联调订单',
            '插件回调链路验证订单',
            '测试',
            '验证',
            '测试',
            '验证',
        ] as $marker) {
            if ($marker !== '' && str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private static function formatSignedMoney(float $amount): string
    {
        $formatted = self::formatMoney(abs($amount));
        return $amount < 0 ? '-' . $formatted : $formatted;
    }
}
