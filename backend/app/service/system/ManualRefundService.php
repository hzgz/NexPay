<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\LocalFundStore;
use app\service\payment\LocalTransferStore;
use app\service\payment\OrderService;

class ManualRefundService
{
    public static function confirm(string $refundNo, string $operator, array $payload = []): array
    {
        $refundNo = trim($refundNo);
        $operator = trim($operator) ?: 'admin';
        $proofNo = trim((string)($payload['proof_no'] ?? $payload['txid'] ?? ''));
        $remark = trim((string)($payload['remark'] ?? ''));

        if ($refundNo === '') {
            throw new BusinessException('退款单号不能为空', StatusCode::VALIDATION_ERROR);
        }

        if ($proofNo === '') {
            throw new BusinessException('请填写真实退款凭证号或流水号', StatusCode::VALIDATION_ERROR);
        }

        $refund = LocalTransferStore::findRefundByNo($refundNo);
        if (!$refund) {
            throw new BusinessException('退款记录不存在', StatusCode::NOT_FOUND);
        }
        if (!LocalTransferStore::isBusinessRefund($refund)) {
            throw new BusinessException('测试或联调退款不允许人工确认', StatusCode::VALIDATION_ERROR);
        }

        if ((int)($refund->status ?? 0) === 1) {
            throw new BusinessException('退款单已处理，不能重复确认', StatusCode::VALIDATION_ERROR);
        }

        $merchantId = (int)($refund->merchant_id ?? 0);
        $amount = self::formatMoney($refund->reducemoney ?? $refund->money ?? 0);
        if ($merchantId <= 0 || (float)$amount <= 0) {
            throw new BusinessException('退款记录金额或商户无效', StatusCode::VALIDATION_ERROR);
        }

        $order = OrderService::findByTradeNoForRead((string)($refund->trade_no ?? ''), [
            'source' => 'manual-refund-order-read',
        ]);
        if ((int)($order->merchant_id ?? 0) !== $merchantId) {
            throw new BusinessException('退款单与原订单商户不一致', StatusCode::BUSINESS_ERROR);
        }
        if ((int)($order->status ?? 0) !== OrderService::STATUS_SUCCESS) {
            throw new BusinessException('仅支付成功订单支持确认退款', StatusCode::BUSINESS_ERROR);
        }

        $refunded = (float)LocalTransferStore::sumRefundMoney($merchantId, (string)($order->trade_no ?? ''));
        $orderAmount = (float)($order->amount ?? 0);
        if ($refunded + (float)$amount > $orderAmount + 0.00001) {
            throw new BusinessException('累计退款金额不能超过原订单金额', StatusCode::VALIDATION_ERROR);
        }

        $balance = LocalFundStore::balanceForMerchant($merchantId);
        if ((float)($balance['available'] ?? '0.00') < (float)$amount) {
            throw new BusinessException('商户余额不足，无法确认退款扣款', StatusCode::BUSINESS_ERROR);
        }

        $now = date('Y-m-d H:i:s');
        $updated = OrderService::completeRefund($refund, [
            'source' => 'manual-refund-confirm',
            'created_at' => $now,
            'amount' => $amount,
            'trade_no' => (string)($order->trade_no ?? ''),
            'out_trade_no' => (string)($order->out_trade_no ?? ''),
            'out_refund_no' => (string)($refund->out_refund_no ?? ''),
            'proof_no' => $proofNo,
            'operator' => $operator,
            'remark' => $remark,
            'result' => 'manual_refunded',
        ]);

        self::appendAuditLog($updated, $operator, $proofNo, $remark);

        return [
            'refund' => self::shapeRefund($updated),
            'balance' => LocalFundStore::balanceForMerchant($merchantId),
        ];
    }

    public static function shapeRefund(?object $refund): array
    {
        if (!$refund) {
            return [];
        }

        return [
            'refund_no' => (string)($refund->refund_no ?? ''),
            'out_refund_no' => (string)($refund->out_refund_no ?? ''),
            'trade_no' => (string)($refund->trade_no ?? ''),
            'out_trade_no' => (string)($refund->out_trade_no ?? ''),
            'merchant_id' => (int)($refund->merchant_id ?? 0),
            'money' => self::formatMoney($refund->money ?? 0),
            'reducemoney' => self::formatMoney($refund->reducemoney ?? $refund->money ?? 0),
            'status' => (int)($refund->status ?? 0),
            'status_text' => (int)($refund->status ?? 0) === 1 ? '退款成功' : '未执行',
            'result' => (string)($refund->result ?? ''),
            'errmsg' => (string)($refund->last_error ?? ''),
            'proof_no' => (string)($refund->proof_no ?? ''),
            'operator' => (string)($refund->operator ?? ''),
            'remark' => (string)($refund->remark ?? ''),
            'created_at' => (string)($refund->created_at ?? ''),
            'finished_at' => (string)($refund->finished_at ?? ''),
        ];
    }

    private static function appendAuditLog(object $refund, string $operator, string $proofNo, string $remark): void
    {
        $detail = [
            'refund_no' => (string)($refund->refund_no ?? ''),
            'out_refund_no' => (string)($refund->out_refund_no ?? ''),
            'trade_no' => (string)($refund->trade_no ?? ''),
            'amount' => self::formatMoney($refund->reducemoney ?? $refund->money ?? 0),
            'proof_no' => $proofNo,
            'remark' => $remark,
            'result' => (string)($refund->result ?? 'manual_refunded'),
        ];

        $action = '人工确认退款: ' . (string)($refund->refund_no ?? '');

        CompensationAuditLogService::merchant([
            'operator' => $operator,
            'merchant_id' => (int)($refund->merchant_id ?? 0),
            'action' => $action,
            'ip' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => $detail,
        ]);

        CompensationAuditLogService::admin([
            'operator' => $operator,
            'merchant_id' => (int)($refund->merchant_id ?? 0),
            'action' => $action,
            'ip' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => $detail,
        ]);
    }

    private static function formatMoney(mixed $amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }
}
