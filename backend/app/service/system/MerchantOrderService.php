<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\model\CallbackQueue;
use app\service\payment\CallbackService;
use app\service\payment\LocalOrderStore;
use app\service\payment\OrderService;
use Throwable;

class MerchantOrderService
{
    public static function retryCallback(int $merchantId, array $payload = [], string $operator = ''): array
    {
        [$tradeNo, $outTradeNo] = self::resolveOrderIdentity($payload);
        $order = self::merchantOrder($merchantId, $tradeNo, $outTradeNo);

        if (self::isDeleted($order)) {
            throw new BusinessException('订单已删除，无法执行手动回调', StatusCode::VALIDATION_ERROR);
        }

        $action = self::manualAction($order);
        if ($action === 'confirm') {
            return self::confirmPendingOrder($merchantId, $order, $payload, $operator);
        }

        if ($action === 'retry') {
            return self::retrySucceededOrder($merchantId, $order, $payload, $operator);
        }

        throw new BusinessException(self::manualActionBlockedMessage($order), StatusCode::VALIDATION_ERROR);
    }

    public static function deleteOrder(int $merchantId, array $payload = []): array
    {
        [$tradeNo, $outTradeNo] = self::resolveOrderIdentity($payload);
        $order = self::merchantOrder($merchantId, $tradeNo, $outTradeNo);

        if (self::isDeleted($order)) {
            throw new BusinessException('订单已删除，请刷新列表', StatusCode::VALIDATION_ERROR);
        }

        $deletedAt = date('Y-m-d H:i:s');
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $notifyPayload['merchant_delete'] = [
            'deleted_at' => $deletedAt,
            'deleted_by' => 'merchant',
        ];

        $updated = OrderService::saveOrder($order, [
            'deleted_at' => $deletedAt,
            'notify_payload' => $notifyPayload,
        ]);

        return [
            'trade_no' => (string)($updated->trade_no ?? $order->trade_no ?? ''),
            'out_trade_no' => (string)($updated->out_trade_no ?? $order->out_trade_no ?? ''),
            'deleted_at' => $deletedAt,
        ];
    }

    private static function confirmPendingOrder(int $merchantId, object $order, array $payload, string $operator): array
    {
        $proofNo = trim((string)($payload['proof_no'] ?? $payload['txid'] ?? ''));
        $remark = trim((string)($payload['remark'] ?? ''));
        if ($proofNo === '') {
            throw new BusinessException('请填写交易订单号', StatusCode::VALIDATION_ERROR);
        }

        $notifyUrl = trim((string)($order->notify_url ?? ''));
        if ($notifyUrl === '') {
            throw new BusinessException('该订单未配置异步通知地址，无法执行手动回调', StatusCode::VALIDATION_ERROR);
        }

        $expireAt = strtotime((string)($order->expire_time ?? ''));
        if ($expireAt !== false && $expireAt < time()) {
            OrderService::saveOrder($order, ['status' => OrderService::STATUS_EXPIRED]);
            throw new BusinessException('订单已过期，不能人工确认成功', StatusCode::VALIDATION_ERROR);
        }

        $merchant = OrderService::gatewayMerchantById($merchantId);
        if (!$merchant) {
            throw new BusinessException('当前订单缺少可用商户信息，无法执行手动回调', StatusCode::VALIDATION_ERROR);
        }

        $operatorName = self::operatorName($merchantId, $operator);
        $completed = OrderService::completeOrder($order, [
            'source' => 'merchant_manual_confirm',
            'confirmations' => 1,
            'txid' => $proofNo,
            'manual_operator' => $operatorName,
            'manual_remark' => $remark,
        ]);

        CallbackService::enqueueOrder($completed, $merchant, true);
        $callback = CallbackService::syncOrderPayload($completed, $merchant);
        if ($callback === null) {
            $callback = self::findCallbackByOrderId((int)($completed->id ?? 0));
        }
        if ($callback === null) {
            throw new BusinessException('订单已确认成功，但未生成可回调记录', StatusCode::BUSINESS_ERROR);
        }

        $result = CallbackService::resendNow((int)($callback->id ?? 0));
        self::appendManualLog($operatorName, $completed, 'confirm', $notifyUrl, $proofNo, $remark, $result);

        return [
            'action' => 'confirm',
            'trade_no' => (string)($completed->trade_no ?? ''),
            'out_trade_no' => (string)($completed->out_trade_no ?? ''),
            'callback_id' => (int)($callback->id ?? 0),
            'notify_url' => $notifyUrl,
            'txid' => (string)($completed->txid ?? ''),
            'pay_time' => (string)($completed->pay_time ?? ''),
            'checked' => (int)($result['checked'] ?? 0),
            'succeeded' => (int)($result['succeeded'] ?? 0),
            'deferred' => (int)($result['deferred'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'message' => (string)($result['message'] ?? ''),
        ];
    }

    private static function retrySucceededOrder(int $merchantId, object $order, array $payload, string $operator): array
    {
        $notifyUrl = trim((string)($order->notify_url ?? ''));
        if ($notifyUrl === '') {
            throw new BusinessException('该订单未配置异步通知地址，无法执行手动回调', StatusCode::VALIDATION_ERROR);
        }

        $merchant = OrderService::gatewayMerchantById($merchantId);
        if (!$merchant) {
            throw new BusinessException('当前订单缺少可用商户信息，无法执行手动回调', StatusCode::VALIDATION_ERROR);
        }

        $proofNo = trim((string)($payload['proof_no'] ?? $payload['txid'] ?? ''));
        $remark = trim((string)($payload['remark'] ?? ''));
        if ($proofNo !== '' && $proofNo !== (string)($order->txid ?? '')) {
            $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
            $notifyPayload['api_trade_no'] = $proofNo;
            $order = OrderService::saveOrder($order, [
                'txid' => $proofNo,
                'notify_payload' => $notifyPayload,
            ]);
        }

        $callback = self::findCallbackByOrderId((int)($order->id ?? 0));
        if ($callback === null) {
            CallbackService::enqueueOrder($order, $merchant, true);
            $callback = self::findCallbackByOrderId((int)($order->id ?? 0));
        }

        $callback = CallbackService::syncOrderPayload($order, $merchant) ?? $callback;
        if ($callback === null) {
            throw new BusinessException('该订单暂无可重试回调', StatusCode::NOT_FOUND);
        }

        $result = CallbackService::resendNow((int)($callback->id ?? 0));
        self::appendManualLog(
            self::operatorName($merchantId, $operator),
            $order,
            'retry',
            $notifyUrl,
            $proofNo !== '' ? $proofNo : (string)($order->txid ?? ''),
            $remark,
            $result
        );

        return [
            'action' => 'retry',
            'trade_no' => (string)($order->trade_no ?? ''),
            'out_trade_no' => (string)($order->out_trade_no ?? ''),
            'callback_id' => (int)($callback->id ?? 0),
            'notify_url' => $notifyUrl,
            'checked' => (int)($result['checked'] ?? 0),
            'succeeded' => (int)($result['succeeded'] ?? 0),
            'deferred' => (int)($result['deferred'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'message' => (string)($result['message'] ?? ''),
        ];
    }

    private static function manualAction(object $order): string
    {
        $notifyUrl = trim((string)($order->notify_url ?? ''));
        if ($notifyUrl === '' || self::isDeleted($order)) {
            return 'none';
        }

        $status = (int)($order->status ?? -1);
        if ($status === OrderService::STATUS_SUCCESS) {
            return 'retry';
        }

        if ($status !== OrderService::STATUS_PENDING) {
            return 'none';
        }

        if (!self::isChannelTestOrder($order)) {
            return 'none';
        }

        $expireAt = strtotime((string)($order->expire_time ?? ''));
        if ($expireAt !== false && $expireAt < time()) {
            return 'none';
        }

        return 'confirm';
    }

    private static function manualActionBlockedMessage(object $order): string
    {
        $notifyUrl = trim((string)($order->notify_url ?? ''));
        if ($notifyUrl === '') {
            return '该订单未配置异步通知地址，无法执行手动回调';
        }

        if (self::isDeleted($order)) {
            return '订单已删除，无法执行手动回调';
        }

        $status = (int)($order->status ?? -1);
        if ($status === OrderService::STATUS_EXPIRED) {
            return '订单已过期，不能人工确认成功';
        }

        if ($status === OrderService::STATUS_CLOSED) {
            return '订单已关闭，不能执行手动回调';
        }

        if ($status === OrderService::STATUS_FAILED) {
            return '订单已失败，不能执行手动回调';
        }

        if ($status === OrderService::STATUS_PENDING) {
            if (self::isChannelTestOrder($order)) {
                return '订单未过期时才支持人工确认成功';
            }

            return '当前订单暂不支持人工确认成功';
        }

        return '当前订单暂不支持手动回调';
    }

    private static function resolveOrderIdentity(array $payload): array
    {
        $tradeNo = trim((string)($payload['trade_no'] ?? ''));
        $outTradeNo = trim((string)($payload['out_trade_no'] ?? ''));

        if ($tradeNo === '' && $outTradeNo === '') {
            throw new BusinessException('缺少订单号', StatusCode::BAD_REQUEST);
        }

        return [$tradeNo, $outTradeNo];
    }

    private static function merchantOrder(int $merchantId, string $tradeNo, string $outTradeNo): object
    {
        if ($merchantId <= 0) {
            throw new BusinessException('商户信息无效', StatusCode::UNAUTHORIZED);
        }

        return OrderService::gatewayMerchantOrder(
            $merchantId,
            $tradeNo !== '' ? $tradeNo : null,
            $outTradeNo !== '' ? $outTradeNo : null
        );
    }

    private static function findCallbackByOrderId(int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        if (\database_available()) {
            try {
                return CallbackQueue::where('order_id', $orderId)->order('id', 'desc')->find();
            } catch (Throwable) {
            }
        }

        return LocalOrderStore::findCallbackByOrderId($orderId);
    }

    private static function operatorName(int $merchantId, string $operator): string
    {
        $operator = trim($operator);
        if ($operator !== '') {
            return $operator;
        }

        $credential = AccountService::merchantCredentialById($merchantId);
        if (is_array($credential)) {
            $name = trim((string)($credential['username'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return 'merchant';
    }

    private static function appendManualLog(
        string $operator,
        object $order,
        string $action,
        string $notifyUrl,
        string $proofNo,
        string $remark,
        array $result
    ): void {
        $detail = [
            'trade_no' => (string)($order->trade_no ?? ''),
            'out_trade_no' => (string)($order->out_trade_no ?? ''),
            'amount' => (string)($order->amount ?? ''),
            'status' => (string)($order->status ?? ''),
            'manual_action' => $action,
            'notify_url' => $notifyUrl,
            'proof_no' => $proofNo,
            'remark' => $remark,
            'checked' => (int)($result['checked'] ?? 0),
            'succeeded' => (int)($result['succeeded'] ?? 0),
            'deferred' => (int)($result['deferred'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'message' => (string)($result['message'] ?? ''),
        ];

        $actionText = $action === 'confirm' ? '商户人工确认订单' : '商户手动回调订单';
        $entry = [
            'operator' => $operator !== '' ? $operator : 'merchant',
            'merchant_id' => (int)($order->merchant_id ?? 0),
            'action' => $actionText . '：' . (string)($order->trade_no ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => $detail,
        ];

        CompensationAuditLogService::merchant($entry);
        CompensationAuditLogService::admin($entry);
    }

    private static function isChannelTestOrder(object $order): bool
    {
        $payload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
        $sourceProtocol = strtolower(trim((string)($meta['source_protocol'] ?? '')));
        if ($sourceProtocol === 'channel_test') {
            return true;
        }

        $param = strtolower(trim((string)($order->param ?? '')));
        if ($param === 'channel-test') {
            return true;
        }

        return str_starts_with(strtoupper(trim((string)($order->trade_no ?? ''))), 'TST');
    }

    private static function isDeleted(object $order): bool
    {
        return trim((string)($order->deleted_at ?? '')) !== '';
    }
}
