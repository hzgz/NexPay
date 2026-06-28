<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\model\Order;
use app\service\payment\CallbackService;
use app\service\payment\LocalOrderEventStore;
use app\service\payment\LocalOrderStore;
use app\service\payment\OrderStatusService;
use app\service\payment\OrderService;
use Throwable;

class AdminOrderService
{
    public static function retryCallback(array $payload = [], string $operator = 'admin'): array
    {
        [$tradeNo, $outTradeNo] = self::resolveOrderIdentity($payload);
        $order = self::findOrder($tradeNo, $outTradeNo);

        if (self::isDeleted($order)) {
            throw new BusinessException('订单已删除，无法执行手动回调', StatusCode::VALIDATION_ERROR);
        }

        if (LocalOrderStore::isBusinessOrder($order)) {
            throw new BusinessException('管理员后台不支持对商户订单执行手动回调', StatusCode::VALIDATION_ERROR);
        }

        if ((int)($order->status ?? -1) !== OrderService::STATUS_SUCCESS) {
            throw new BusinessException('仅支付成功订单支持手动回调', StatusCode::VALIDATION_ERROR);
        }

        $notifyUrl = trim((string)($order->notify_url ?? ''));
        if ($notifyUrl === '') {
            throw new BusinessException('该订单未配置异步通知地址，无法执行手动回调', StatusCode::VALIDATION_ERROR);
        }

        $merchantId = (int)($order->merchant_id ?? 0);
        $merchant = $merchantId > 0 ? OrderService::gatewayMerchantById($merchantId) : null;
        if (!$merchant) {
            throw new BusinessException('当前订单缺少可用商户信息，无法执行手动回调', StatusCode::VALIDATION_ERROR);
        }

        $callback = CallbackService::findCallbackByOrderId((int)($order->id ?? 0));
        if ($callback === null) {
            CallbackService::enqueueOrder($order, $merchant, true);
            $callback = CallbackService::findCallbackByOrderId((int)($order->id ?? 0));
        }

        if ($callback === null) {
            throw new BusinessException('该订单暂无可重试回调', StatusCode::NOT_FOUND);
        }

        $result = CallbackService::resendNow((int)($callback->id ?? 0));

        self::appendAdminLog($operator, '手动回调订单', $order, [
            'callback_id' => (int)($callback->id ?? 0),
            'notify_url' => $notifyUrl,
            'checked' => (int)($result['checked'] ?? 0),
            'succeeded' => (int)($result['succeeded'] ?? 0),
            'deferred' => (int)($result['deferred'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'message' => (string)($result['message'] ?? ''),
        ]);

        return [
            'callback_id' => (int)($callback->id ?? 0),
            'notify_url' => $notifyUrl,
            'checked' => (int)($result['checked'] ?? 0),
            'succeeded' => (int)($result['succeeded'] ?? 0),
            'deferred' => (int)($result['deferred'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'message' => (string)($result['message'] ?? ''),
        ] + self::statusSnapshot($order);
    }

    public static function deleteOrder(array $payload = [], string $operator = 'admin'): array
    {
        [$tradeNo, $outTradeNo] = self::resolveOrderIdentity($payload);
        $order = self::findOrder($tradeNo, $outTradeNo);

        if (self::isDeleted($order)) {
            throw new BusinessException('订单已删除，请刷新列表', StatusCode::VALIDATION_ERROR);
        }

        $deletedAt = date('Y-m-d H:i:s');
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $notifyPayload['admin_delete'] = [
            'deleted_at' => $deletedAt,
            'deleted_by' => trim($operator) !== '' ? $operator : 'admin',
        ];

        $updated = OrderService::saveOrder($order, [
            'deleted_at' => $deletedAt,
            'notify_payload' => $notifyPayload,
        ]);
        CallbackService::cancelOrderCallbacks($updated);
        LocalOrderEventStore::recordDeleted($updated, [
            'source' => 'admin_delete',
            'deleted_at' => $deletedAt,
        ]);

        self::appendAdminLog($operator, '删除订单', $updated, [
            'deleted_at' => $deletedAt,
        ]);

        return self::statusSnapshot($updated);
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

    private static function findOrder(string $tradeNo, string $outTradeNo): object
    {
        if ($tradeNo !== '') {
            return OrderService::findByTradeNoForRead($tradeNo, [
                'source' => 'admin-order-service-trade-no-read',
            ]);
        }

        if (\database_available()) {
            try {
                $order = Order::where('out_trade_no', $outTradeNo)->order('id', 'desc')->find();
                if ($order) {
                    return OrderService::normalizeOrderForRead($order, [
                        'source' => 'admin-order-service-out-trade-no-read',
                    ]);
                }
            } catch (Throwable) {
            }
        }

        foreach (array_reverse(LocalOrderStore::allOrders()) as $order) {
            if (trim((string)($order->out_trade_no ?? '')) === $outTradeNo) {
                return OrderService::normalizeOrderForRead($order, [
                    'source' => 'admin-order-service-out-trade-no-read',
                ]);
            }
        }

        throw new BusinessException('订单不存在', StatusCode::NOT_FOUND);
    }

    private static function isDeleted(object $order): bool
    {
        return trim((string)($order->deleted_at ?? '')) !== '';
    }

    private static function appendAdminLog(string $operator, string $action, object $order, array $detail = []): void
    {
        CompensationAuditLogService::admin([
            'operator' => trim($operator) !== '' ? $operator : 'admin',
            'merchant_id' => (int)($order->merchant_id ?? 0),
            'action' => $action . '：' . (string)($order->trade_no ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => array_merge([
                'trade_no' => (string)($order->trade_no ?? ''),
                'out_trade_no' => (string)($order->out_trade_no ?? ''),
                'amount' => (string)($order->amount ?? ''),
                'status' => (string)($order->status ?? ''),
            ], $detail),
        ]);
    }

    private static function statusSnapshot(object $order): array
    {
        $status = OrderStatusService::forOperations($order);

        return [
            'trade_no' => (string)($order->trade_no ?? ''),
            'out_trade_no' => (string)($order->out_trade_no ?? ''),
            'status' => (string)($status['label'] ?? ''),
            'status_code' => (int)($status['code'] ?? OrderStatusService::DISPLAY_PENDING),
            'status_key' => (string)($status['key'] ?? 'pending'),
            'status_theme' => (string)($status['theme'] ?? 'warning'),
            'payment_status_label' => (string)($status['payment_status_label'] ?? ''),
            'payment_status_code' => (int)($status['payment_status_code'] ?? 0),
            'payment_status_key' => (string)($status['payment_status_key'] ?? ''),
            'callback_status_label' => (string)($status['callback_status_label'] ?? ''),
            'callback_status_code' => (int)($status['callback_status_code'] ?? 0),
            'callback_status_key' => (string)($status['callback_status_key'] ?? ''),
            'callback_status_theme' => (string)($status['callback_status_theme'] ?? 'warning'),
            'callback_status_hint' => (string)($status['callback_status_hint'] ?? ''),
            'callback_count' => (int)($order->callback_count ?? 0),
            'notify_url' => trim((string)($order->notify_url ?? '')),
            'deleted_at' => (string)($order->deleted_at ?? ''),
            'is_deleted' => self::isDeleted($order),
        ];
    }
}
