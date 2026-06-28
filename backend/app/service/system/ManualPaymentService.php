<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\LocalOrderStore;
use app\service\payment\OrderService;

class ManualPaymentService
{
    public static function confirm(string $tradeNo, string $operator, array $payload = []): array
    {
        $tradeNo = trim($tradeNo);
        $operator = trim($operator) ?: 'admin';
        $proofNo = trim((string)($payload['proof_no'] ?? $payload['txid'] ?? ''));
        $remark = trim((string)($payload['remark'] ?? ''));

        if ($tradeNo === '') {
            throw new BusinessException('订单号不能为空', StatusCode::VALIDATION_ERROR);
        }

        if ($proofNo === '') {
            throw new BusinessException('请填写真实收款凭证号或流水号', StatusCode::VALIDATION_ERROR);
        }

        $order = OrderService::findByTradeNoForRead($tradeNo, [
            'source' => 'manual-payment-read',
        ]);
        if (self::isMerchantOwnedOrder($order)) {
            throw new BusinessException('管理员后台不支持对商户订单执行人工确认收款', StatusCode::VALIDATION_ERROR);
        }

        if (!LocalOrderStore::isBusinessOrder($order) && !self::isMerchantRechargeOrder($order)) {
            throw new BusinessException('测试或联调订单不能人工确认收款', StatusCode::VALIDATION_ERROR);
        }

        if ((int)$order->status !== OrderService::STATUS_PENDING) {
            throw new BusinessException('仅待支付订单可人工确认收款', StatusCode::VALIDATION_ERROR);
        }

        $expireAt = strtotime((string)($order->expire_time ?? ''));
        if ($expireAt !== false && $expireAt < time()) {
            OrderService::expireOrder($order, [
                'source' => 'manual-payment-expired-check',
                'event_time' => date('Y-m-d H:i:s'),
            ]);
            throw new BusinessException('订单已过期，不能人工确认收款', StatusCode::VALIDATION_ERROR);
        }

        $completed = OrderService::completeOrder($order, [
            'source' => 'manual_confirm',
            'confirmations' => 1,
            'txid' => $proofNo,
            'manual_operator' => $operator,
            'manual_remark' => $remark,
        ]);

        self::appendAuditLog($completed, $operator, $proofNo, $remark);

        return [
            'trade_no' => (string)$completed->trade_no,
            'out_trade_no' => (string)$completed->out_trade_no,
            'status' => (int)$completed->status,
            'status_text' => '支付成功',
            'txid' => (string)$completed->txid,
            'pay_time' => (string)$completed->pay_time,
            'callback_status' => (int)$completed->callback_status,
        ];
    }

    private static function appendAuditLog(object $order, string $operator, string $proofNo, string $remark): void
    {
        $detail = [
            'trade_no' => (string)($order->trade_no ?? ''),
            'out_trade_no' => (string)($order->out_trade_no ?? ''),
            'amount' => (string)($order->amount ?? ''),
            'proof_no' => $proofNo,
            'remark' => $remark,
            'result' => 'manual_confirm',
        ];

        CompensationAuditLogService::merchant([
            'operator' => $operator,
            'merchant_id' => (int)($order->merchant_id ?? 0),
            'action' => '人工确认收款：' . (string)($order->trade_no ?? ''),
            'ip' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => $detail,
        ]);

        CompensationAuditLogService::admin([
            'operator' => $operator,
            'merchant_id' => (int)($order->merchant_id ?? 0),
            'action' => '人工确认收款：' . (string)($order->trade_no ?? ''),
            'ip' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => $detail,
        ]);
    }

    private static function isMerchantRechargeOrder(object $order): bool
    {
        $payload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];

        return (string)($meta['business'] ?? '') === 'merchant_recharge'
            && !str_starts_with(strtoupper((string)($order->txid ?? $order->api_trade_no ?? '')), 'MOCK');
    }

    private static function isMerchantOwnedOrder(object $order): bool
    {
        return (int)($order->merchant_id ?? 0) > 0;
    }
}
