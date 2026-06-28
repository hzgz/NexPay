<?php

declare(strict_types=1);

namespace app\service\payment;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\system\ConfigService;
use app\service\system\EncodingRepairService;
use app\service\system\SettlementService;
use Throwable;

class GatewayCompatService
{
    public static function createForV1(array $payload): array
    {
        return OrderService::createFromV1($payload);
    }

    public static function createForV1Fallback(array $payload): array
    {
        return OrderService::createFromV1Fallback($payload);
    }

    public static function createForV2(array $payload): array
    {
        return OrderService::createFromV2($payload);
    }

    public static function queryForV1(array $query): array
    {
        $act = strtolower(trim((string)($query['act'] ?? 'query')));
        if ($act === 'refundapi') {
            return self::refundApiForV1($query);
        }

        $merchant = self::resolveMerchantByPid((string)($query['pid'] ?? ''));
        self::assertV1MerchantKey($merchant, $query);

        $supportedActions = ['query', 'order', 'orders', 'settle', 'refund', 'refundquery', 'close'];
        if (!in_array($act, $supportedActions, true)) {
            throw new BusinessException('Unsupported V1 action: ' . $act, StatusCode::BAD_REQUEST);
        }

        return match ($act) {
            'query' => [
                'code' => 1,
                'pid' => self::merchantPid($merchant),
                'key' => (string)($merchant->mch_key ?? ''),
                'active' => (int)($merchant->status ?? 0) === 1 ? 1 : 0,
                'money' => self::merchantBalance($merchant),
                'type' => 4,
                'account' => (string)($merchant->email ?? ''),
                'username' => (string)($merchant->name ?? $merchant->username ?? ''),
                'orders' => self::countMerchantOrders((int)$merchant->id),
                'order_today' => self::countMerchantOrdersToday((int)$merchant->id, 'today'),
                'order_lastday' => self::countMerchantOrdersToday((int)$merchant->id, 'yesterday'),
            ],
            'order' => self::buildV1OrderQueryResponse($merchant, $query),
            'orders' => [
                'code' => 0,
                'msg' => 'Order list query succeeded',
                'data' => self::merchantOrdersPageV1(
                    (int)$merchant->id,
                    max(1, (int)($query['page'] ?? 1)),
                    max(1, min(50, (int)($query['limit'] ?? 20)))
                ),
            ],
            'settle' => [
                'code' => 0,
                'msg' => 'Settlement list query succeeded',
                'data' => self::merchantSettlePageV1((int)$merchant->id),
            ],
            'refund' => self::refundForV1($merchant, $query),
            'refundquery' => self::refundQueryForV1($merchant, $query),
            'close' => self::closeForV1($merchant, $query),
        };
    }


    public static function queryForV2(array $payload): array
    {
        $merchant = self::resolveV2Merchant($payload);
        $order = self::findMerchantOrderForRead(
            (int)$merchant->id,
            self::stringOrNull($payload['trade_no'] ?? null),
            self::stringOrNull($payload['out_trade_no'] ?? null),
            [
                'source' => 'gateway-v2-query',
            ]
        );

        return self::signV2Response([
            'code' => 0,
            'msg' => 'success',
            'trade_no' => (string)$order->trade_no,
            'out_trade_no' => (string)$order->out_trade_no,
            'api_trade_no' => (string)($order->txid ?? ''),
            'type' => (string)$order->channel_code,
            'status' => (int)$order->status === OrderService::STATUS_SUCCESS ? 1 : 0,
            'trade_status' => (int)$order->status === OrderService::STATUS_SUCCESS ? 'TRADE_SUCCESS' : 'WAIT_BUYER_PAY',
            'pid' => self::merchantPid($merchant),
            'addtime' => (string)($order->created_at ?? ''),
            'endtime' => (string)($order->pay_time ?? ''),
            'name' => (string)($order->subject ?? ''),
            'money' => self::formatMoney($order->amount ?? 0),
            'param' => (string)($order->param ?? ''),
            'buyer' => OrderService::buyerForOrder($order),
            'clientip' => (string)($order->client_ip ?? ''),
        ]);
    }

    public static function refundForV2(array $payload): array
    {
        $merchant = self::resolveV2Merchant($payload);
        OpenApiGuardService::assertV2ReplayProtection((int)($merchant->id ?? 0), $payload, 'pay.refund');
        $refund = self::createOrFindRefund($merchant, $payload);

        if ((int)($refund->status ?? 0) === 0 && (string)($refund->result ?? '') === 'plugin_refund_pending') {
            $refund = OrderService::syncPendingRefundStatus($refund);
        }

        return self::signV2Response([
            'code' => 0,
            'msg' => 'success',
            'refund_no' => (string)$refund->refund_no,
            'out_refund_no' => (string)$refund->out_refund_no,
            'trade_no' => (string)$refund->trade_no,
            'out_trade_no' => (string)$refund->out_trade_no,
            'money' => self::formatMoney($refund->money ?? 0),
            'reducemoney' => self::formatMoney($refund->reducemoney ?? 0),
            'status' => (int)($refund->status ?? 0),
            'result' => (string)($refund->result ?? ''),
            'errmsg' => (string)($refund->last_error ?? ''),
            'addtime' => (string)($refund->created_at ?? ''),
            'endtime' => (string)($refund->updated_at ?? $refund->created_at ?? ''),
        ]);
    }

    public static function refundQueryForV2(array $payload): array
    {
        $merchant = self::resolveV2Merchant($payload);
        $refund = self::findRefundRecord(
            (int)$merchant->id,
            self::stringOrNull($payload['refund_no'] ?? null),
            self::stringOrNull($payload['out_refund_no'] ?? null),
            self::stringOrNull($payload['trade_no'] ?? null)
        );

        if ($refund && (int)($refund->status ?? 0) === 0 && (string)($refund->result ?? '') === 'plugin_refund_pending') {
            $refund = OrderService::syncPendingRefundStatus($refund);
        }

        if (!$refund) {
            throw new BusinessException('Refund record not found', StatusCode::NOT_FOUND);
        }

        return self::signV2Response([
            'code' => 0,
            'msg' => 'success',
            'refund_no' => (string)$refund->refund_no,
            'out_refund_no' => (string)$refund->out_refund_no,
            'trade_no' => (string)$refund->trade_no,
            'out_trade_no' => (string)$refund->out_trade_no,
            'money' => self::formatMoney($refund->money ?? 0),
            'reducemoney' => self::formatMoney($refund->reducemoney ?? 0),
            'status' => (int)($refund->status ?? 0),
            'result' => (string)($refund->result ?? ''),
            'errmsg' => (string)($refund->last_error ?? ''),
            'addtime' => (string)($refund->created_at ?? ''),
            'endtime' => (string)($refund->updated_at ?? $refund->created_at ?? ''),
        ]);
    }


    public static function closeForV2(array $payload): array
    {
        $merchant = self::resolveV2Merchant($payload);
        OpenApiGuardService::assertV2ReplayProtection((int)($merchant->id ?? 0), $payload, 'pay.close');
        $order = self::findMerchantOrderForRead(
            (int)$merchant->id,
            self::stringOrNull($payload['trade_no'] ?? null),
            self::stringOrNull($payload['out_trade_no'] ?? null),
            [
                'source' => 'gateway-v2-close-order-read',
            ]
        );

        if ((int)$order->status === OrderService::STATUS_PENDING) {
            OrderService::closePendingOrder($order, [
                'source' => 'gateway-v2-close',
                'event_time' => date('Y-m-d H:i:s'),
            ]);
        }

        return self::signV2Response([
            'code' => 0,
            'msg' => 'success',
        ]);
    }

    public static function merchantInfoForV2(array $payload): array
    {
        $merchant = self::resolveV2Merchant($payload);

        return self::signV2Response([
            'code' => 0,
            'msg' => 'success',
            'pid' => self::merchantPid($merchant),
            'status' => (int)($merchant->status ?? 0),
            'pay_status' => 1,
            'settle_status' => 1,
            'money' => self::merchantBalance($merchant),
            'settle_type' => 4,
            'settle_account' => '',
            'settle_name' => '',
            'order_num' => self::countMerchantOrders((int)$merchant->id),
            'order_num_today' => self::countMerchantOrdersToday((int)$merchant->id, 'today'),
            'order_num_lastday' => self::countMerchantOrdersToday((int)$merchant->id, 'yesterday'),
            'order_money_today' => self::sumMerchantOrdersByDay((int)$merchant->id, 'today'),
            'order_money_lastday' => self::sumMerchantOrdersByDay((int)$merchant->id, 'yesterday'),
        ]);
    }

    public static function merchantOrdersForV2(array $payload): array
    {
        $merchant = self::resolveV2Merchant($payload);
        $limit = max(1, min(50, (int)($payload['limit'] ?? 20)));
        $offset = max(0, (int)($payload['offset'] ?? 0));
        $page = max(1, (int)($payload['page'] ?? ((int)floor($offset / $limit) + 1)));
        $statusFilter = array_key_exists('status', $payload) && $payload['status'] !== '' ? (int)$payload['status'] : null;

        return self::signV2Response([
            'code' => 0,
            'msg' => 'success',
            'data' => self::merchantOrdersPage((int)$merchant->id, $page, $limit, $statusFilter),
        ]);
    }

    public static function transferSubmitForV2(array $payload): array
    {
        $merchant = self::resolveV2Merchant($payload);
        OpenApiGuardService::assertV2ReplayProtection((int)($merchant->id ?? 0), $payload, 'transfer.submit');

        $type = trim((string)($payload['type'] ?? ''));
        if (!in_array($type, ['alipay', 'wxpay', 'qqpay', 'bank'], true)) {
            throw new BusinessException('Unsupported transfer type', StatusCode::VALIDATION_ERROR);
        }

        $account = trim((string)($payload['account'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $money = self::formatMoney($payload['money'] ?? '0');
        $outBizNo = trim((string)($payload['out_biz_no'] ?? ''));
        if ($account === '' || $name === '' || $outBizNo === '') {
            throw new BusinessException('Transfer parameters are incomplete', StatusCode::VALIDATION_ERROR);
        }
        if ((float)$money <= 0) {
            throw new BusinessException('Transfer amount must be greater than 0', StatusCode::VALIDATION_ERROR);
        }

        $transfer = LocalTransferStore::findTransferByBizNo(null, $outBizNo);
        if ($transfer && (int)($transfer->merchant_id ?? 0) !== (int)$merchant->id) {
            throw new BusinessException('External transfer number is already in use', StatusCode::BUSINESS_ERROR);
        }

        if ($transfer && (int)($transfer->status ?? 0) !== 1) {
            $error = trim((string)($transfer->last_error ?? 'Transfer record has not completed successfully'));
            throw new BusinessException($error !== '' ? $error : self::transferCapabilityError($type), StatusCode::BUSINESS_ERROR);
        }

        if (!$transfer) {
            if ((float)self::merchantBalance($merchant) < (float)$money) {
                throw new BusinessException('Insufficient balance for transfer', StatusCode::BUSINESS_ERROR);
            }

            $channel = self::resolveTransferChannel((int)$merchant->id, $type);
            $readiness = $channel !== null
                ? PluginExecutorService::canExecute($channel, 'transfer')
                : [
                    'ok' => false,
                    'result' => 'unsupported_capability',
                    'errmsg' => self::transferCapabilityError($type),
                ];

            $transfer = LocalTransferStore::createTransfer([
                'merchant_id' => (int)$merchant->id,
                'biz_no' => self::generateTransferNo(),
                'out_biz_no' => $outBizNo,
                'type' => $type,
                'account' => $account,
                'name' => $name,
                'money' => $money,
                'status' => 0,
                'available_money' => self::merchantBalance($merchant),
                'transfer_rate' => '0.00',
                'result' => (string)($readiness['result'] ?? 'unsupported_capability'),
                'last_error' => (string)($readiness['errmsg'] ?? self::transferCapabilityError($type)),
                'channel_plugin_code' => (string)($channel['plugin_code'] ?? ''),
                'channel_id' => (int)($channel['id'] ?? 0),
            ]);

            if (!$readiness['ok']) {
                throw new BusinessException((string)($readiness['errmsg'] ?? self::transferCapabilityError($type)), StatusCode::BUSINESS_ERROR);
            }

            $result = PluginExecutorService::transfer($channel, $transfer);
            $transfer = self::applyTransferPluginResult($transfer, $result, $merchant);
            if ((int)($transfer->status ?? 0) !== 1 && (string)($transfer->result ?? '') !== 'plugin_transfer_pending') {
                $error = trim((string)($transfer->last_error ?? 'Transfer request failed'));
                throw new BusinessException($error !== '' ? $error : self::transferCapabilityError($type), StatusCode::BUSINESS_ERROR);
            }
        }

        return self::signV2Response(self::transferResponse($transfer));
    }

    public static function transferQueryForV2(array $payload): array
    {
        $merchant = self::resolveV2Merchant($payload);
        $transfer = LocalTransferStore::findTransferByBizNo(
            self::stringOrNull($payload['biz_no'] ?? null),
            self::stringOrNull($payload['out_biz_no'] ?? null)
        );
        if (!$transfer || (int)$transfer->merchant_id !== (int)$merchant->id) {
            throw new BusinessException('Transfer record not found', StatusCode::NOT_FOUND);
        }

        if ((int)($transfer->status ?? 0) === 0 && (string)($transfer->result ?? '') === 'plugin_transfer_pending') {
            $channel = self::resolveSavedTransferChannel($transfer);
            if ($channel !== null) {
                $queryResult = PluginExecutorService::queryTransfer($channel, $transfer);
                if (($queryResult['result'] ?? '') !== 'unsupported_capability') {
                    $transfer = self::applyTransferPluginResult($transfer, $queryResult, $merchant);
                }
            }
        }

        return self::signV2Response(self::transferResponse($transfer));
    }

    public static function transferBalanceForV2(array $payload): array
    {
        $merchant = self::resolveV2Merchant($payload);

        return self::signV2Response([
            'code' => 0,
            'msg' => 'success',
            'available_money' => self::merchantBalance($merchant),
            'transfer_rate' => '0.00',
        ]);
    }

    public static function errorResponseForV2(Throwable $exception): array
    {
        $code = $exception instanceof BusinessException ? $exception->errorCode() : -1;

        return self::signV2Response([
            'code' => $code,
            'msg' => self::normalizeGatewayErrorMessageSafe($exception->getMessage()),
        ]);
    }

    public static function normalizeGatewayErrorMessage(string $message): string
    {
        $normalized = trim((string)EncodingRepairService::repair($message));
        if ($normalized === '') {
            return '支付请求失败';
        }

        if (
            str_contains($normalized, 'cashier?')
            && str_contains($normalized, 'other=1')
        ) {
            return '上游易支付商户未配置当前支付方式的可用自定义通道';
        }

        foreach ([
            '未配置当前支付方式的可用通道',
            '未配置当前支付方式的可用自定义通道',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return '上游易支付商户未配置当前支付方式的可用自定义通道';
            }
        }

        return $normalized;
    }

    public static function normalizeGatewayErrorMessageSafe(string $message): string
    {
        $normalized = trim((string)EncodingRepairService::repair($message));
        if ($normalized === '') {
            return '支付请求失败';
        }

        if (str_contains($normalized, 'cashier?') && str_contains($normalized, 'other=1')) {
            return '上游易支付商户未配置当前支付方式的可用自定义通道';
        }

        foreach ([
            '未配置当前支付方式的可用通道',
            '未配置当前支付方式的可用自定义通道',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return '上游易支付商户未配置当前支付方式的可用自定义通道';
            }
        }

        return $normalized;
    }

    private static function buildV1OrderQueryResponse(object $merchant, array $query): array
    {
        $order = self::findMerchantOrderForRead(
            (int)$merchant->id,
            self::stringOrNull($query['trade_no'] ?? null),
            self::stringOrNull($query['out_trade_no'] ?? null),
            [
                'source' => 'gateway-v1-order-query',
            ]
        );

        return [
            'code' => 0,
            'trade_no' => (string)$order->trade_no,
            'out_trade_no' => (string)$order->out_trade_no,
            'api_trade_no' => (string)($order->txid ?? ''),
            'bill_trade_no' => OrderService::billTradeNoForOrder($order),
            'bill_mch_trade_no' => OrderService::billMchTradeNoForOrder($order),
            'type' => self::exportV1MethodCode((string)$order->channel_code),
            'pid' => self::merchantPid($merchant),
            'addtime' => (string)($order->created_at ?? ''),
            'endtime' => (string)($order->pay_time ?? ''),
            'name' => (string)($order->subject ?? ''),
            'money' => self::formatMoney($order->amount ?? 0),
            'param' => (string)($order->param ?? ''),
            'buyer' => OrderService::buyerForOrder($order),
            'clientip' => (string)($order->client_ip ?? ''),
            'status' => (int)$order->status === OrderService::STATUS_SUCCESS ? 1 : 0,
            'refundmoney' => LocalTransferStore::sumRefundMoney((int)$merchant->id, (string)$order->trade_no),
            'trade_status' => (int)$order->status === OrderService::STATUS_SUCCESS ? 'TRADE_SUCCESS' : 'WAIT_BUYER_PAY',
        ];
    }

    private static function refundForV1(object $merchant, array $payload): array
    {
        $refund = self::createOrFindRefund($merchant, $payload);
        $outRefundNo = trim((string)($payload['out_refund_no'] ?? $payload['refund_no'] ?? ''));
        $isDuplicate = $outRefundNo !== ''
            && strcasecmp((string)($refund->out_refund_no ?? ''), $outRefundNo) === 0
            && (int)($refund->status ?? 0) === 1;

        return [
            'code' => 0,
            'msg' => $isDuplicate ? 'Refund request already succeeded' : 'Refund request accepted',
            'refund_no' => (string)$refund->refund_no,
            'out_refund_no' => (string)$refund->out_refund_no,
            'trade_no' => (string)$refund->trade_no,
            'out_trade_no' => (string)$refund->out_trade_no,
            'uid' => self::merchantPid($merchant),
            'money' => self::formatMoney($refund->money ?? 0),
            'reducemoney' => self::formatMoney($refund->reducemoney ?? 0),
            'status' => (int)($refund->status ?? 0),
            'result' => (string)($refund->result ?? ''),
            'errmsg' => (string)($refund->last_error ?? ''),
        ];
    }


    private static function refundQueryForV1(object $merchant, array $payload): array
    {
        $refundNo = self::stringOrNull($payload['refund_no'] ?? null);
        $outRefundNo = self::stringOrNull($payload['out_refund_no'] ?? null);
        if ($refundNo === null && $outRefundNo === null) {
            throw new BusinessException('out_refund_no or refund_no is required', StatusCode::BAD_REQUEST);
        }

        $refund = self::findRefundRecord((int)$merchant->id, $refundNo, $outRefundNo, null);
        if ($refund && (int)($refund->status ?? 0) === 0 && (string)($refund->result ?? '') === 'plugin_refund_pending') {
            $refund = OrderService::syncPendingRefundStatus($refund);
        }

        if (!$refund) {
            throw new BusinessException('Refund record not found', StatusCode::NOT_FOUND);
        }

        return [
            'code' => 0,
            'refund_no' => (string)$refund->refund_no,
            'out_refund_no' => (string)$refund->out_refund_no,
            'trade_no' => (string)$refund->trade_no,
            'out_trade_no' => (string)$refund->out_trade_no,
            'uid' => self::merchantPid($merchant),
            'money' => self::formatMoney($refund->money ?? 0),
            'reducemoney' => self::formatMoney($refund->reducemoney ?? 0),
            'status' => (int)($refund->status ?? 0),
            'result' => (string)($refund->result ?? ''),
            'errmsg' => (string)($refund->last_error ?? ''),
            'addtime' => (string)($refund->created_at ?? ''),
            'endtime' => (string)($refund->updated_at ?? $refund->created_at ?? ''),
        ];
    }


    private static function closeForV1(object $merchant, array $payload): array
    {
        $order = self::findMerchantOrderForRead(
            (int)$merchant->id,
            self::stringOrNull($payload['trade_no'] ?? null),
            self::stringOrNull($payload['out_trade_no'] ?? null),
            [
                'source' => 'gateway-v1-close-order-read',
            ]
        );

        if ((int)$order->status === OrderService::STATUS_PENDING) {
            OrderService::closePendingOrder($order, [
                'source' => 'gateway-v1-close',
                'event_time' => date('Y-m-d H:i:s'),
            ]);
        } elseif ((int)$order->status !== OrderService::STATUS_CLOSED) {
            return [
                'code' => -1,
                'msg' => 'Order status does not support close',
            ];
        }

        return [
            'code' => 0,
            'msg' => 'Order closed successfully',
        ];
    }


    private static function refundApiForV1(array $payload): array
    {
        $tradeNo = trim((string)($payload['trade_no'] ?? ''));
        if ($tradeNo === '') {
            throw new BusinessException('Order number is required', StatusCode::BAD_REQUEST);
        }

        $signature = strtolower(trim((string)($payload['key'] ?? '')));
        if ($signature === '') {
            throw new BusinessException('Internal refund signature is required', StatusCode::UNAUTHORIZED);
        }

        $expected = strtolower(md5($tradeNo . ConfigService::internalRefundSecret() . $tradeNo));
        if (!hash_equals($expected, $signature)) {
            throw new BusinessException('Internal refund signature verification failed', StatusCode::UNAUTHORIZED);
        }

        $order = OrderService::findByTradeNoForRead($tradeNo, [
            'source' => 'gateway-v1-refund-api-read',
        ]);
        $merchant = self::resolveMerchantById((int)$order->merchant_id);
        if (!$merchant) {
            throw new BusinessException('Merchant for refund order not found', StatusCode::NOT_FOUND);
        }

        return self::refundForV1($merchant, $payload);
    }


    private static function createOrFindRefund(object $merchant, array $payload): object
    {
        $money = self::formatMoney($payload['money'] ?? 0);
        if ((float)$money <= 0) {
            throw new BusinessException('Refund amount must be greater than 0', StatusCode::VALIDATION_ERROR);
        }

        $order = self::findMerchantOrderForRead(
            (int)$merchant->id,
            self::stringOrNull($payload['trade_no'] ?? null),
            self::stringOrNull($payload['out_trade_no'] ?? null),
            [
                'source' => 'gateway-refund-order-read',
            ]
        );

        if ((int)($order->status ?? 0) !== OrderService::STATUS_SUCCESS) {
            throw new BusinessException('Only successful orders can be refunded', StatusCode::BUSINESS_ERROR);
        }

        if ((int)($order->channel_category ?? 0) === 1) {
            throw new BusinessException('On-chain payment orders do not support refund', StatusCode::BUSINESS_ERROR);
        }

        if ((float)$money > (float)($order->amount ?? 0)) {
            throw new BusinessException('Refund amount cannot exceed order amount', StatusCode::VALIDATION_ERROR);
        }

        $refundNo = self::stringOrNull($payload['refund_no'] ?? null);
        $outRefundNo = self::stringOrNull($payload['out_refund_no'] ?? $payload['refund_no'] ?? null);
        $existing = self::findRefundRecord((int)$merchant->id, $refundNo, $outRefundNo, (string)$order->trade_no);
        if ($existing) {
            $existingResult = (string)($existing->result ?? '');
            if ((int)($existing->status ?? 0) === 0 && $existingResult === 'plugin_refund_pending') {
                return $existing;
            }

            if ((int)($existing->status ?? 0) !== 1) {
                $error = trim((string)($existing->last_error ?? 'Refund request already exists and has not completed successfully'));
                throw new BusinessException($error, StatusCode::BUSINESS_ERROR);
            }

            return $existing;
        }

        $finalRefundNo = $refundNo ?? self::generateRefundNo();
        $finalOutRefundNo = $outRefundNo ?? $finalRefundNo;
        $legacyChannel = self::legacyChannelForOrder($order);
        $channelId = (int)($legacyChannel['id'] ?? $order->merchant_channel_id ?? 0);
        $channelPluginCode = (string)($legacyChannel['plugin_code'] ?? $legacyChannel['plugin'] ?? '');
        $readiness = self::refundReadiness($order);
        if (!$readiness['ok']) {
            LocalTransferStore::createRefund([
                'merchant_id' => (int)$merchant->id,
                'trade_no' => (string)$order->trade_no,
                'out_trade_no' => (string)$order->out_trade_no,
                'refund_no' => $finalRefundNo,
                'out_refund_no' => $finalOutRefundNo,
                'money' => $money,
                'reducemoney' => $money,
                'status' => 0,
                'result' => (string)($readiness['result'] ?? 'unsupported_capability'),
                'last_error' => (string)($readiness['errmsg'] ?? self::refundCapabilityError($order)),
                'channel_id' => $channelId,
                'channel_plugin_code' => $channelPluginCode,
            ]);

            throw new BusinessException((string)($readiness['errmsg'] ?? self::refundCapabilityError($order)), StatusCode::BUSINESS_ERROR);
        }

        $refunded = (float)LocalTransferStore::sumRefundMoney((int)$merchant->id, (string)$order->trade_no);
        if ($refunded + (float)$money > (float)($order->amount ?? 0) + 0.00001) {
            throw new BusinessException('Refund amount exceeds remaining refundable balance', StatusCode::VALIDATION_ERROR);
        }

        $balance = LocalFundStore::balanceForMerchant((int)$merchant->id);
        if ((float)($balance['available'] ?? '0.00') < (float)$money) {
            throw new BusinessException('Insufficient merchant balance for refund', StatusCode::BUSINESS_ERROR);
        }

        $refund = LocalTransferStore::createRefund([
            'merchant_id' => (int)$merchant->id,
            'trade_no' => (string)$order->trade_no,
            'out_trade_no' => (string)$order->out_trade_no,
            'refund_no' => $finalRefundNo,
            'out_refund_no' => $finalOutRefundNo,
            'money' => $money,
            'reducemoney' => $money,
            'status' => 0,
            'result' => 'plugin_refund_pending',
            'last_error' => '',
            'channel_id' => $channelId,
            'channel_plugin_code' => $channelPluginCode,
        ]);

        $pluginFailureMessage = 'Refund plugin execution failed';
        $result = PluginExecutorService::refund($order, $refund);
        if (!$result['ok']) {
            LocalTransferStore::updateRefund($finalRefundNo, [
                'status' => 0,
                'result' => (string)($result['result'] ?? 'plugin_failed'),
                'last_error' => (string)($result['errmsg'] ?? $pluginFailureMessage),
                'raw_response' => $result['raw'] ?? [],
            ]);

            throw new BusinessException((string)($result['errmsg'] ?? $pluginFailureMessage), StatusCode::BUSINESS_ERROR);
        }

        $now = date('Y-m-d H:i:s');
        $pluginCode = (string)($result['channel']['plugin_code'] ?? '');
        $channelOrderNo = (string)($result['channel_order_no'] ?? '');
        $channelTradeNo = (string)($result['channel_trade_no'] ?? '');

        if ((int)($result['status'] ?? 1) === 0) {
            $resultChannelId = (int)($result['channel']['id'] ?? $refund->channel_id ?? $channelId);
            return LocalTransferStore::updateRefund($finalRefundNo, [
                'status' => 0,
                'result' => (string)($result['result'] ?? 'plugin_refund_pending'),
                'last_error' => (string)($result['errmsg'] ?? 'Refund request is pending provider confirmation'),
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelTradeNo,
                'channel_plugin_code' => $pluginCode,
                'channel_id' => $resultChannelId,
                'raw_response' => $result['raw'] ?? [],
            ]) ?? $refund;
        }

        if ((int)($result['status'] ?? 1) === 2) {
            $resultChannelId = (int)($result['channel']['id'] ?? $refund->channel_id ?? $channelId);
            LocalTransferStore::updateRefund($finalRefundNo, [
                'status' => 2,
                'result' => (string)($result['result'] ?? 'plugin_refund_failed'),
                'last_error' => (string)($result['errmsg'] ?? 'Refund plugin returned a failed result'),
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelTradeNo,
                'channel_plugin_code' => $pluginCode,
                'channel_id' => $resultChannelId,
                'raw_response' => $result['raw'] ?? [],
            ]);

            throw new BusinessException((string)($result['errmsg'] ?? 'Refund plugin returned a failed result'), StatusCode::BUSINESS_ERROR);
        }

        $resultChannelId = (int)($result['channel']['id'] ?? $refund->channel_id ?? $channelId);
        $rawResponse = is_array($result['raw'] ?? null) ? $result['raw'] : [];

        return OrderService::completeRefund(LocalTransferStore::findRefundByNo($finalRefundNo) ?? $refund, [
            'source' => 'plugin-refund-submit',
            'created_at' => $now,
            'amount' => $money,
            'trade_no' => (string)$order->trade_no,
            'out_trade_no' => (string)$order->out_trade_no,
            'out_refund_no' => $finalOutRefundNo,
            'plugin_code' => $pluginCode,
            'channel_order_no' => $channelOrderNo,
            'channel_trade_no' => $channelTradeNo,
            'proof_no' => $channelOrderNo !== '' ? $channelOrderNo : $channelTradeNo,
            'operator' => 'plugin:' . $pluginCode,
            'result' => (string)($result['result'] ?? 'plugin_refunded'),
            'raw_response' => $rawResponse,
            'channel_id' => $resultChannelId,
        ]);
    }


    private static function merchantOrdersPageV1(int $merchantId, int $page, int $limit): array
    {
        return OrderService::gatewayMerchantOrdersPageV1($merchantId, $page, $limit);
    }

    private static function merchantOrdersPage(int $merchantId, int $page, int $limit, ?int $statusFilter = null): array
    {
        return OrderService::gatewayMerchantOrdersPage($merchantId, $page, $limit, $statusFilter);
    }

    private static function merchantSettlePageV1(int $merchantId): array
    {
        return SettlementService::v1Settlements($merchantId);
    }

    private static function resolveV2Merchant(array $payload): object
    {
        $merchant = self::resolveMerchantByPid((string)($payload['pid'] ?? ''));
        if (!self::verifyV2Signature($payload, $merchant, (string)($payload['sign'] ?? ''))) {
            throw new BusinessException('V2 signature verification failed', StatusCode::UNAUTHORIZED);
        }
        OpenApiGuardService::assertV2Timestamp($payload);

        return $merchant;
    }


    private static function resolveMerchantByPid(string $pid): object
    {
        return OrderService::gatewayMerchantByPid($pid);
    }

    private static function resolveMerchantById(int $merchantId): ?object
    {
        return OrderService::gatewayMerchantById($merchantId);
    }

    private static function findMerchantOrder(int $merchantId, ?string $tradeNo, ?string $outTradeNo): object
    {
        return OrderService::gatewayMerchantOrder($merchantId, $tradeNo, $outTradeNo);
    }

    private static function findMerchantOrderForRead(
        int $merchantId,
        ?string $tradeNo,
        ?string $outTradeNo,
        array $options = []
    ): object {
        return OrderService::gatewayMerchantOrderForRead($merchantId, $tradeNo, $outTradeNo, $options);
    }

    private static function findRefundRecord(int $merchantId, ?string $refundNo, ?string $outRefundNo, ?string $tradeNo): ?object
    {
        return LocalTransferStore::findRefund($merchantId, $refundNo, $outRefundNo, $tradeNo);
    }

    private static function resolveTransferChannel(int $merchantId, string $type): ?array
    {
        return OrderService::gatewayTransferChannel($merchantId, $type);
    }

    private static function resolveSavedTransferChannel(object $transfer): ?array
    {
        return OrderService::gatewaySavedTransferChannel($transfer);
    }

    private static function applyTransferPluginResult(object $transfer, array $result, object $merchant): object
    {
        return OrderService::gatewayApplyTransferPluginResult($transfer, $result, $merchant);
    }

    private static function transferCapabilityError(string $type = ''): string
    {
        return OrderService::gatewayTransferCapabilityError($type);
    }

    private static function refundReadiness(object $order): array
    {
        try {
            $channel = self::legacyChannelForOrder($order);
            return PluginExecutorService::canExecute($channel, 'refund');
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'result' => 'unsupported_capability',
                'errmsg' => self::refundCapabilityError($order),
            ];
        }
    }

    private static function refundCapabilityError(object $order): string
    {
        $identity = LegacyChannelFormatter::identityForOrder($order);
        $pluginCode = trim((string)($identity['plugin_code'] ?? ''));
        $channelCode = trim((string)($identity['channel_code'] ?? ''));

        if ($pluginCode === '' || $pluginCode === 'mock_fallback') {
            return 'Current order channel does not declare refund capability.';
        }

        return 'Plugin ' . $pluginCode . ' has not been wired into the unified refund executor'
            . ($channelCode !== '' ? ' (channel: ' . $channelCode . ')' : '')
            . '.';
    }

    private static function legacyChannelForOrder(object $order): array
    {
        try {
            return LegacyPaymentGatewayService::legacyChannelArray($order);
        } catch (Throwable) {
            return [];
        }
    }

    private static function countMerchantOrders(int $merchantId): int
    {
        return OrderService::gatewayMerchantOrderCount($merchantId);
    }

    private static function countMerchantOrdersToday(int $merchantId, string $mode): int
    {
        return OrderService::gatewayMerchantOrderDayCount($merchantId, $mode);
    }

    private static function sumMerchantOrdersByDay(int $merchantId, string $mode): string
    {
        return OrderService::gatewayMerchantOrderDayAmount($merchantId, $mode);
    }

    private static function merchantBalance(object $merchant): string
    {
        return OrderService::gatewayMerchantBalance($merchant);
    }

    private static function merchantPid(object $merchant): string
    {
        return OrderService::gatewayMerchantPid($merchant);
    }

    private static function assertV1MerchantKey(object $merchant, array $query): void
    {
        if ((string)($query['key'] ?? '') !== (string)($merchant->mch_key ?? '')) {
            throw new BusinessException('Merchant key verification failed', StatusCode::UNAUTHORIZED);
        }
    }


    private static function verifyV2Signature(array $payload, object $merchant, string $signature): bool
    {
        return OrderService::gatewayVerifyV2Signature($payload, $merchant, $signature);
    }

    private static function signV2Response(array $response): array
    {
        $response['timestamp'] = (string)time();
        $response['sign_type'] = 'RSA';
        $response['sign'] = SignService::rsaSign($response, ConfigService::platformPrivateKey());

        return $response;
    }

    private static function exportV1MethodCode(string $code): string
    {
        $normalized = strtolower(trim($code));

        return match ($normalized) {
            'wechat', 'wechatpay' => 'wxpay',
            'wxpay' => 'wxpay',
            'alipay' => 'alipay',
            'qq', 'qqwallet', 'qqpay' => 'qqpay',
            'unionpay', 'union', 'yinlian', 'yunshanfu', 'cloudquickpass', 'ecny', 'bank' => 'bank',
            'douyin', 'douyinpay' => 'douyinpay',
            'trc20', 'usdt', 'usdt-trc20', 'usdttrc20' => 'usdttrc20',
            'polygon', 'matic', 'usdtpolygon' => 'usdtpolygon',
            'aptos', 'usdtaptos' => 'usdtaptos',
            default => $normalized,
        };
    }

    private static function generateRefundNo(): string
    {
        return 'RF' . date('YmdHis') . random_int(100000, 999999);
    }

    private static function generateTransferNo(): string
    {
        return 'TR' . date('YmdHis') . random_int(100000, 999999);
    }

    private static function transferResponse(object $transfer): array
    {
        return [
            'code' => 0,
            'msg' => 'success',
            'biz_no' => (string)($transfer->biz_no ?? ''),
            'out_biz_no' => (string)($transfer->out_biz_no ?? ''),
            'type' => (string)($transfer->type ?? ''),
            'account' => (string)($transfer->account ?? ''),
            'name' => (string)($transfer->name ?? ''),
            'money' => self::formatMoney($transfer->money ?? 0),
            'status' => (int)($transfer->status ?? 0),
            'result' => (string)($transfer->result ?? ''),
            'errmsg' => (string)($transfer->last_error ?? ''),
            'channel_order_no' => (string)($transfer->channel_order_no ?? ''),
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $string = trim((string)$value);
        return $string === '' ? null : $string;
    }

    private static function formatMoney(mixed $amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }
}
