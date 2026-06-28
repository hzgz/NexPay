<?php

namespace app\service\payment;

use app\model\MerchantBalance;
use app\service\system\JsonStoreService;
use app\service\system\PackageService;
use app\service\system\PaymentMetaService;
use app\service\system\SystemBusinessPaymentService;
use stdClass;
use think\facade\Db;
use Throwable;

class LocalFundStore
{
    private const FLOW_STORE = 'fund_flows_local';
    private const TABLE = 'fund_flows';

    private static ?bool $dbReady = null;

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
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($order->channel_code ?? ''));

        if (SystemBusinessPaymentService::isFundBusiness($business) && $business !== 'merchant_recharge') {
            return self::recordSystemBusinessOrderSuccess($order, $business, $methodCode);
        }

        if (!LocalOrderStore::isBusinessOrder($order) && !SystemBusinessPaymentService::isFundBusiness($business)) {
            return null;
        }

        if ($business === 'merchant_recharge') {
            return self::credit(
                $merchantId,
                (string)($order->amount ?? '0.00'),
                '余额充值',
                'recharge',
                $tradeNo,
                (string)($order->pay_time ?? date('Y-m-d H:i:s')),
                [
                    'business' => $business,
                    'order_amount' => number_format((float)($order->amount ?? 0), 2, '.', ''),
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string)($order->out_trade_no ?? ''),
                    'channel_code' => $methodCode,
                    'method_name' => $methodCode !== '' ? PaymentMetaService::friendlyMethodName($methodCode) : '',
                ]
            );
        }

        $amount = (float)($order->amount ?? 0);
        $fee = max(0.0, (float)($order->platform_fee ?? 0));
        $net = max(0.0, $amount - $fee);
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
                'business' => $business,
                'order_amount' => number_format($amount, 2, '.', ''),
                'trade_no' => $tradeNo,
                'out_trade_no' => (string)($order->out_trade_no ?? ''),
                'gross_amount' => number_format($amount, 2, '.', ''),
                'platform_fee' => number_format($fee, 8, '.', ''),
                'channel_code' => (string)($order->channel_code ?? ''),
                'subject' => (string)($order->subject ?? ''),
            ]
        );
    }

    private static function recordSystemBusinessOrderSuccess(object $order, string $business, string $methodCode): ?object
    {
        $merchantId = (int)($order->merchant_id ?? 0);
        $tradeNo = trim((string)($order->trade_no ?? ''));
        if ($merchantId <= 0 || $tradeNo === '') {
            return null;
        }

        $flowType = match (strtolower(trim($business))) {
            'merchant_register_fee' => '注册收费',
            'merchant_package_purchase' => '套餐购买',
            default => '',
        };

        $refType = match (strtolower(trim($business))) {
            'merchant_register_fee' => 'register_fee',
            'merchant_package_purchase' => 'package_purchase',
            default => '',
        };

        if ($flowType === '' || $refType === '') {
            return null;
        }

        $payload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
        $orderAmount = (float)($order->amount ?? $order->payable_amount ?? 0);
        $displayMethodCode = $methodCode !== '' ? $methodCode : PaymentMetaService::normalizeMethodCode((string)($meta['requested_method'] ?? ''));
        $displayMethodName = self::firstNonEmptyString(
            (string)($meta['method_name'] ?? ''),
            $displayMethodCode !== '' ? PaymentMetaService::friendlyMethodName($displayMethodCode) : ''
        );
        $createdAt = (string)($order->pay_time ?? date('Y-m-d H:i:s'));

        if ($business === 'merchant_package_purchase' && $displayMethodCode === 'balance') {
            return self::debit(
                $merchantId,
                number_format($orderAmount, 2, '.', ''),
                $flowType,
                $refType,
                $tradeNo,
                $createdAt,
                [
                    'business' => $business,
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string)($order->out_trade_no ?? ''),
                    'channel_code' => $displayMethodCode,
                    'method_name' => $displayMethodName !== '' ? $displayMethodName : '余额支付',
                    'subject' => (string)($order->subject ?? ''),
                    'order_amount' => number_format($orderAmount, 2, '.', ''),
                    'gross_amount' => number_format($orderAmount, 2, '.', ''),
                ]
            );
        }

        return self::credit(
            $merchantId,
            '0.00',
            $flowType,
            $refType,
            $tradeNo,
            $createdAt,
            [
                'business' => $business,
                'business_only' => true,
                'trade_no' => $tradeNo,
                'out_trade_no' => (string)($order->out_trade_no ?? ''),
                'channel_code' => $displayMethodCode,
                'method_name' => $displayMethodName,
                'subject' => (string)($order->subject ?? ''),
                'order_amount' => number_format($orderAmount, 2, '.', ''),
            ]
        );
    }

    public static function recordRefundSuccess(object $refund, array $context = []): object
    {
        $merchantId = (int)($context['merchant_id'] ?? $refund->merchant_id ?? 0);
        $refundNo = trim((string)($context['refund_no'] ?? $refund->refund_no ?? ''));
        $amount = self::formatMoney((float)($context['amount'] ?? $refund->reducemoney ?? $refund->money ?? 0));
        $channelCode = PaymentMetaService::normalizeMethodCode((string)($context['channel_code'] ?? $context['method_code'] ?? ''));
        $methodName = self::firstNonEmptyString(
            (string)($context['method_name'] ?? ''),
            $channelCode !== '' ? PaymentMetaService::friendlyMethodName($channelCode) : '',
            '原路退款'
        );

        return self::debit(
            $merchantId,
            $amount,
            '退款扣款',
            'refund',
            $refundNo,
            self::firstNonEmptyString(
                (string)($context['created_at'] ?? ''),
                (string)($refund->finished_at ?? ''),
                (string)($refund->updated_at ?? ''),
                (string)($refund->created_at ?? ''),
                date('Y-m-d H:i:s')
            ),
            self::compactMeta([
                'business' => 'refund',
                'payout_kind' => 'refund',
                'payout_status' => 'success',
                'source' => self::firstNonEmptyString((string)($context['source'] ?? ''), 'refund'),
                'reference_no' => $refundNo,
                'trade_no' => self::firstNonEmptyString((string)($context['trade_no'] ?? ''), (string)($refund->trade_no ?? '')),
                'out_trade_no' => self::firstNonEmptyString((string)($context['out_trade_no'] ?? ''), (string)($refund->out_trade_no ?? '')),
                'out_refund_no' => self::firstNonEmptyString((string)($context['out_refund_no'] ?? ''), (string)($refund->out_refund_no ?? '')),
                'refund_amount' => $amount,
                'subject' => self::firstNonEmptyString((string)($context['subject'] ?? ''), '订单退款'),
                'method_name' => $methodName,
                'channel_code' => $channelCode,
                'plugin_code' => trim((string)($context['plugin_code'] ?? $refund->channel_plugin_code ?? '')),
                'channel_order_no' => self::firstNonEmptyString((string)($context['channel_order_no'] ?? ''), (string)($refund->channel_order_no ?? '')),
                'channel_trade_no' => self::firstNonEmptyString((string)($context['channel_trade_no'] ?? ''), (string)($refund->channel_trade_no ?? '')),
                'proof_no' => self::firstNonEmptyString((string)($context['proof_no'] ?? ''), (string)($refund->proof_no ?? '')),
                'operator' => self::firstNonEmptyString((string)($context['operator'] ?? ''), (string)($refund->operator ?? '')),
                'remark' => self::firstNonEmptyString((string)($context['remark'] ?? ''), (string)($refund->remark ?? '')),
                'result' => self::firstNonEmptyString((string)($context['result'] ?? ''), (string)($refund->result ?? '')),
            ])
        );
    }

    public static function recordTransferSuccess(object $transfer, array $context = []): object
    {
        $merchantId = (int)($context['merchant_id'] ?? $transfer->merchant_id ?? 0);
        $bizNo = trim((string)($context['biz_no'] ?? $transfer->biz_no ?? ''));
        $amount = self::formatMoney((float)($context['amount'] ?? $transfer->money ?? 0));

        return self::debit(
            $merchantId,
            $amount,
            '代付扣款',
            'transfer',
            $bizNo,
            self::firstNonEmptyString(
                (string)($context['created_at'] ?? ''),
                (string)($transfer->finished_at ?? ''),
                (string)($transfer->updated_at ?? ''),
                (string)($transfer->created_at ?? ''),
                date('Y-m-d H:i:s')
            ),
            self::compactMeta([
                'business' => 'transfer',
                'payout_kind' => 'transfer',
                'payout_status' => 'success',
                'source' => self::firstNonEmptyString((string)($context['source'] ?? ''), 'transfer'),
                'reference_no' => $bizNo,
                'out_biz_no' => self::firstNonEmptyString((string)($context['out_biz_no'] ?? ''), (string)($transfer->out_biz_no ?? '')),
                'transfer_amount' => $amount,
                'subject' => self::firstNonEmptyString((string)($context['subject'] ?? ''), '余额代付'),
                'method_name' => self::firstNonEmptyString((string)($context['method_name'] ?? ''), '余额代付'),
                'plugin_code' => trim((string)($context['plugin_code'] ?? $transfer->channel_plugin_code ?? '')),
                'type' => self::firstNonEmptyString((string)($context['type'] ?? ''), (string)($transfer->type ?? '')),
                'target_type' => self::firstNonEmptyString((string)($context['type'] ?? ''), (string)($transfer->type ?? '')),
                'account' => self::maskAccount(self::firstNonEmptyString((string)($context['account'] ?? ''), (string)($transfer->account ?? ''))),
                'target_account' => self::maskAccount(self::firstNonEmptyString((string)($context['account'] ?? ''), (string)($transfer->account ?? ''))),
                'name' => self::firstNonEmptyString((string)($context['name'] ?? ''), (string)($transfer->name ?? '')),
                'target_name' => self::firstNonEmptyString((string)($context['name'] ?? ''), (string)($transfer->name ?? '')),
                'channel_order_no' => self::firstNonEmptyString((string)($context['channel_order_no'] ?? ''), (string)($transfer->channel_order_no ?? '')),
                'channel_trade_no' => self::firstNonEmptyString((string)($context['channel_trade_no'] ?? ''), (string)($transfer->channel_trade_no ?? '')),
                'proof_no' => self::firstNonEmptyString((string)($context['proof_no'] ?? ''), (string)($transfer->proof_no ?? '')),
                'operator' => self::firstNonEmptyString((string)($context['operator'] ?? ''), (string)($transfer->operator ?? '')),
                'remark' => self::firstNonEmptyString((string)($context['remark'] ?? ''), (string)($transfer->remark ?? '')),
                'result' => self::firstNonEmptyString((string)($context['result'] ?? ''), (string)($transfer->result ?? '')),
            ])
        );
    }

    public static function recordSettlementWithdraw(object $settlement, array $context = []): object
    {
        $merchantId = (int)($context['merchant_id'] ?? $settlement->merchant_id ?? 0);
        $settleNo = trim((string)($context['settle_no'] ?? $settlement->settle_no ?? ''));
        $amount = self::formatMoney((float)($context['amount'] ?? $settlement->money ?? 0));
        $accountType = self::firstNonEmptyString((string)($context['account_type'] ?? ''), (string)($settlement->account_type ?? ''));

        return self::debit(
            $merchantId,
            $amount,
            '提现申请',
            'settlement_withdraw',
            $settleNo,
            self::firstNonEmptyString(
                (string)($context['created_at'] ?? ''),
                (string)($settlement->created_at ?? ''),
                date('Y-m-d H:i:s')
            ),
            self::compactMeta([
                'business' => 'settlement_withdraw',
                'payout_kind' => 'settlement',
                'payout_status' => 'pending_review',
                'source' => self::firstNonEmptyString((string)($context['source'] ?? ''), 'settlement'),
                'reference_no' => $settleNo,
                'out_settle_no' => self::firstNonEmptyString((string)($context['out_settle_no'] ?? ''), (string)($settlement->out_settle_no ?? '')),
                'settlement_amount' => $amount,
                'subject' => self::firstNonEmptyString((string)($context['subject'] ?? ''), '提现申请'),
                'method_name' => self::firstNonEmptyString(
                    (string)($context['method_name'] ?? ''),
                    $accountType !== '' ? PaymentMetaService::friendlyMethodName($accountType) : '',
                    '余额提现'
                ),
                'account_type' => $accountType,
                'account' => self::maskAccount(self::firstNonEmptyString((string)($context['account'] ?? ''), (string)($settlement->account ?? ''))),
                'account_name' => self::firstNonEmptyString((string)($context['account_name'] ?? ''), (string)($settlement->account_name ?? '')),
                'remark' => self::firstNonEmptyString((string)($context['remark'] ?? ''), (string)($settlement->remark ?? '')),
                'result' => self::firstNonEmptyString((string)($context['result'] ?? ''), (string)($settlement->result ?? '')),
                'operator' => self::firstNonEmptyString((string)($context['operator'] ?? ''), (string)($settlement->operator ?? '')),
            ])
        );
    }

    public static function recordSettlementReject(object $settlement, array $context = []): object
    {
        $merchantId = (int)($context['merchant_id'] ?? $settlement->merchant_id ?? 0);
        $settleNo = trim((string)($context['settle_no'] ?? $settlement->settle_no ?? ''));
        $amount = self::formatMoney((float)($context['amount'] ?? $settlement->money ?? 0));
        $accountType = self::firstNonEmptyString((string)($context['account_type'] ?? ''), (string)($settlement->account_type ?? ''));

        return self::credit(
            $merchantId,
            $amount,
            '提现退回',
            'settlement_reject',
            $settleNo,
            self::firstNonEmptyString(
                (string)($context['created_at'] ?? ''),
                (string)($settlement->audited_at ?? ''),
                (string)($settlement->updated_at ?? ''),
                date('Y-m-d H:i:s')
            ),
            self::compactMeta([
                'business' => 'settlement_reject',
                'payout_kind' => 'settlement',
                'payout_status' => 'rejected',
                'source' => self::firstNonEmptyString((string)($context['source'] ?? ''), 'settlement'),
                'reference_no' => $settleNo,
                'out_settle_no' => self::firstNonEmptyString((string)($context['out_settle_no'] ?? ''), (string)($settlement->out_settle_no ?? '')),
                'settlement_amount' => $amount,
                'subject' => self::firstNonEmptyString((string)($context['subject'] ?? ''), '提现退回'),
                'method_name' => self::firstNonEmptyString(
                    (string)($context['method_name'] ?? ''),
                    $accountType !== '' ? PaymentMetaService::friendlyMethodName($accountType) : '',
                    '提现退回'
                ),
                'account_type' => $accountType,
                'account' => self::maskAccount(self::firstNonEmptyString((string)($context['account'] ?? ''), (string)($settlement->account ?? ''))),
                'account_name' => self::firstNonEmptyString((string)($context['account_name'] ?? ''), (string)($settlement->account_name ?? '')),
                'reason' => self::firstNonEmptyString((string)($context['reason'] ?? ''), (string)($settlement->last_error ?? '')),
                'remark' => self::firstNonEmptyString((string)($context['remark'] ?? ''), (string)($settlement->remark ?? '')),
                'result' => self::firstNonEmptyString((string)($context['result'] ?? ''), (string)($settlement->result ?? '')),
                'operator' => self::firstNonEmptyString((string)($context['operator'] ?? ''), (string)($settlement->operator ?? '')),
            ])
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
        $merchantId = max(0, $merchantId);
        $refType = trim($refType);
        $refNo = trim($refNo);
        if ($merchantId <= 0 || $refType === '' || $refNo === '') {
            return null;
        }

        foreach (self::mergedRows() as $row) {
            if (self::matchesReference($row, $merchantId, $refType, $refNo)) {
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

        foreach (self::mergedRows() as $row) {
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
        if ($merchantId <= 0 || !function_exists('database_available') || !database_available()) {
            return;
        }

        try {
            $snapshot = self::balanceForMerchant($merchantId);
            $balance = MerchantBalance::where('merchant_id', $merchantId)->find();
            if (!$balance) {
                $balance = new MerchantBalance();
                $balance->merchant_id = $merchantId;
            }

            $balance->balance = self::formatMoney((float)($snapshot['available'] ?? 0));
            $balance->frozen_balance = self::formatMoney((float)($snapshot['frozen'] ?? 0));
            $balance->total_recharge = self::formatMoney((float)($snapshot['total_recharge'] ?? 0));
            $balance->total_consumption = self::formatMoney((float)($snapshot['total_consumption'] ?? 0));
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
            $order = self::resolveOrderByTradeNo($tradeNo);
            return $order !== null && LocalOrderStore::isBusinessOrder($order);
        }

        if ($refType === 'recharge') {
            $tradeNo = trim((string)($meta['trade_no'] ?? $refNo));
            $order = self::resolveOrderByTradeNo($tradeNo);
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

    private static function resolveOrderByTradeNo(string $tradeNo): ?object
    {
        $tradeNo = trim($tradeNo);
        if ($tradeNo === '') {
            return null;
        }

        try {
            return OrderService::findByTradeNoForRead($tradeNo, [
                'source' => 'local-fund-flow-order-read',
            ]);
        } catch (Throwable) {
        }

        return LocalOrderStore::findByTradeNo($tradeNo);
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

            $total += max(0.0, (float)($row['amount'] ?? 0));
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
        $merchantId = max(0, $merchantId);
        $type = trim($type);
        $refType = trim($refType);
        $refNo = trim($refNo);
        $createdAt = trim($createdAt) !== '' ? trim($createdAt) : date('Y-m-d H:i:s');

        foreach (self::mergedRows() as $row) {
            if (self::isSameBusinessEvent($row, $merchantId, $refType, $refNo, $type)) {
                return self::hydrateFlow($row);
            }
        }

        $balanceBefore = (float)(self::balanceForMerchant($merchantId)['available'] ?? 0);
        $balanceAfter = $balanceBefore + $amount;
        $row = self::normalizeFlow([
            'id' => 0,
            'event_key' => self::eventKey($merchantId, $refType, $refNo, $type, $amount, $createdAt),
            'merchant_id' => $merchantId,
            'type' => $type,
            'amount' => self::formatSignedMoney($amount),
            'balance_before' => self::formatMoney($balanceBefore),
            'balance_after' => self::formatMoney($balanceAfter),
            'ref_type' => $refType,
            'ref_no' => $refNo,
            'status' => 'success',
            'meta' => $meta,
            'created_at' => $createdAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $stored = self::storeDatabaseRow($row);
        if ($stored === null) {
            $stored = self::storeJsonRow($row);
        }

        self::syncMerchantBalanceSnapshot($merchantId);

        return self::hydrateFlow($stored);
    }

    private static function flowsForMerchantByScope(int $merchantId, int $limit, bool $businessOnly): array
    {
        $items = [];

        foreach (self::mergedRows($limit > 0 ? $limit * 3 : 0) as $row) {
            if ($merchantId > 0 && (int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            if ($businessOnly && !self::isBusinessFlow($row)) {
                continue;
            }

            $items[] = self::normalizeFlow($row);
        }

        usort($items, static function (array $left, array $right): int {
            $compare = strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
            if ($compare !== 0) {
                return $compare;
            }

            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function mergedRows(int $limit = 0): array
    {
        $items = [];

        foreach (self::loadDatabaseRows($limit > 0 ? $limit * 3 : 0) as $row) {
            $normalized = self::normalizeFlow($row);
            $key = trim((string)($normalized['event_key'] ?? ''));
            if ($key === '') {
                $key = 'db:' . (string)($normalized['id'] ?? uniqid('flow_', true));
            }

            $items[$key] = $normalized;
        }

        foreach (self::loadJsonRows() as $row) {
            $normalized = self::normalizeFlow($row);
            $key = trim((string)($normalized['event_key'] ?? ''));
            if ($key === '') {
                $key = 'json:' . (string)($normalized['id'] ?? uniqid('flow_', true));
            }

            if (!isset($items[$key])) {
                $items[$key] = $normalized;
            }
        }

        $rows = array_values($items);
        usort($rows, static function (array $left, array $right): int {
            $compare = strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
            if ($compare !== 0) {
                return $compare;
            }

            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadJsonRows(): array
    {
        $rows = JsonStoreService::load(self::FLOW_STORE, []);
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
            $query = Db::table(self::TABLE)->order('created_at', 'desc')->order('id', 'desc');
            if ($limit > 0) {
                $query->limit($limit);
            }

            $rows = $query->select()->toArray();
            if (!is_array($rows)) {
                return [];
            }

            return array_map(static fn(mixed $row): array => self::fromDatabaseRow((array)$row), $rows);
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
            $query = Db::table(self::TABLE)->where('merchant_id', (int)($row['merchant_id'] ?? 0));
            $eventKey = trim((string)($row['event_key'] ?? ''));
            if ($eventKey !== '') {
                $existing = Db::table(self::TABLE)->where('event_key', $eventKey)->find();
                if (is_array($existing) && $existing !== []) {
                    return self::fromDatabaseRow($existing);
                }
            } else {
                $existing = $query
                    ->where('ref_type', (string)($row['ref_type'] ?? ''))
                    ->where('ref_no', (string)($row['ref_no'] ?? ''))
                    ->find();
                if (is_array($existing) && $existing !== []) {
                    return self::fromDatabaseRow($existing);
                }
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
            if (self::isSameBusinessEvent($existing, (int)($row['merchant_id'] ?? 0), (string)($row['ref_type'] ?? ''), (string)($row['ref_no'] ?? ''), (string)($row['type'] ?? ''))) {
                return self::normalizeFlow($existing);
            }
        }

        $row['id'] = self::nextId($rows);
        $rows[] = self::normalizeFlow($row);
        JsonStoreService::save(self::FLOW_STORE, $rows);

        return self::normalizeFlow($row);
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

    private static function matchesReference(array $row, int $merchantId, string $refType, string $refNo): bool
    {
        if ((int)($row['merchant_id'] ?? 0) !== $merchantId) {
            return false;
        }

        if (trim((string)($row['ref_type'] ?? '')) !== $refType) {
            return false;
        }

        $candidates = [
            (string)($row['ref_no'] ?? ''),
        ];
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        foreach (['trade_no', 'out_trade_no', 'reference_no', 'order_no'] as $key) {
            $candidates[] = (string)($meta[$key] ?? '');
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && strcasecmp($candidate, $refNo) === 0) {
                return true;
            }
        }

        return false;
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

        foreach (self::mergedRows() as $flow) {
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

        foreach (self::mergedRows() as $flow) {
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
            'mock_fallback',
            'demo@example.com',
            'notify@example.com',
            'fail@example.com',
            '真实闭环测试套餐',
            '本地开放接口联调订单',
            '插件回调链路验证订单',
            '测试',
            '验证',
        ] as $marker) {
            if ($marker !== '' && str_contains($text, strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    private static function eventKey(int $merchantId, string $refType, string $refNo, string $type, float $amount, string $createdAt): string
    {
        $parts = [
            (string)$merchantId,
            strtolower(trim($refType)),
            trim($refNo),
        ];

        if ($refType === '' || $refNo === '') {
            $parts[] = strtolower(trim($type));
            $parts[] = self::formatSignedMoney($amount);
            $parts[] = trim($createdAt);
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
            'merchant_id' => (int)($row['merchant_id'] ?? 0),
            'type' => (string)($row['type'] ?? ''),
            'amount' => number_format((float)($row['amount'] ?? 0), 8, '.', ''),
            'balance_before' => number_format((float)($row['balance_before'] ?? 0), 8, '.', ''),
            'balance_after' => number_format((float)($row['balance_after'] ?? 0), 8, '.', ''),
            'ref_type' => (string)($row['ref_type'] ?? ''),
            'ref_no' => (string)($row['ref_no'] ?? ''),
            'status' => (string)($row['status'] ?? 'success'),
            'meta' => json_encode($row['meta'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => (string)($row['updated_at'] ?? date('Y-m-d H:i:s')),
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

        return self::normalizeFlow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeFlow(array $row): array
    {
        $meta = $row['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        return array_replace([
            'id' => 0,
            'event_key' => '',
            'merchant_id' => 0,
            'type' => '',
            'amount' => '0.00',
            'balance_before' => '0.00',
            'balance_after' => '0.00',
            'ref_type' => '',
            'ref_no' => '',
            'status' => 'success',
            'meta' => [],
            'created_at' => '',
            'updated_at' => '',
        ], $row, [
            'id' => (int)($row['id'] ?? 0),
            'merchant_id' => (int)($row['merchant_id'] ?? 0),
            'amount' => self::formatMoney((float)($row['amount'] ?? 0)),
            'balance_before' => self::formatMoney((float)($row['balance_before'] ?? 0)),
            'balance_after' => self::formatMoney((float)($row['balance_after'] ?? 0)),
            'meta' => $meta,
        ]);
    }

    private static function firstNonEmptyString(string ...$values): string
    {
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function compactMeta(array $meta): array
    {
        return array_filter($meta, static function (mixed $value): bool {
            if ($value === null) {
                return false;
            }

            if (is_string($value)) {
                return trim($value) !== '';
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });
    }

    private static function maskAccount(string $account): string
    {
        $account = trim($account);
        if ($account === '') {
            return '';
        }

        if (str_contains($account, '@')) {
            [$name, $domain] = explode('@', $account, 2);
            return substr($name, 0, 2) . '***@' . $domain;
        }

        if (strlen($account) <= 7) {
            return substr($account, 0, 1) . '***' . substr($account, -1);
        }

        return substr($account, 0, 3) . '****' . substr($account, -4);
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
