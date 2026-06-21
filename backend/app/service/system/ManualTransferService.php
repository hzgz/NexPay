<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\LocalFundStore;
use app\service\payment\LocalTransferStore;

class ManualTransferService
{
    public static function review(string $bizNo, string $action, string $operator, array $payload = []): array
    {
        $bizNo = trim($bizNo);
        $action = strtolower(trim($action));
        $operator = trim($operator) ?: 'admin';

        if ($bizNo === '') {
            throw new BusinessException('代付单号不能为空', StatusCode::VALIDATION_ERROR);
        }

        $transfer = LocalTransferStore::findTransferByBizNo($bizNo);
        if (!$transfer) {
            throw new BusinessException('代付记录不存在', StatusCode::NOT_FOUND);
        }
        if (!LocalTransferStore::isBusinessTransfer($transfer)) {
            throw new BusinessException('测试或联调代付不能审核为真实业务', StatusCode::VALIDATION_ERROR);
        }

        if ((int)($transfer->status ?? 0) !== 0) {
            throw new BusinessException('代付单已处理，不能重复审核', StatusCode::VALIDATION_ERROR);
        }

        return match ($action) {
            'approve', 'confirm' => self::approve($transfer, $operator, $payload),
            'reject' => self::reject($transfer, $operator, $payload),
            default => throw new BusinessException('审核动作仅支持 approve 或 reject', StatusCode::VALIDATION_ERROR),
        };
    }

    public static function shapeTransfer(?object $transfer): array
    {
        if (!$transfer) {
            return [];
        }

        return [
            'biz_no' => (string)$transfer->biz_no,
            'out_biz_no' => (string)$transfer->out_biz_no,
            'merchant_id' => (int)$transfer->merchant_id,
            'type' => (string)$transfer->type,
            'account' => self::maskAccount((string)$transfer->account),
            'name' => (string)$transfer->name,
            'money' => self::formatMoney($transfer->money ?? 0),
            'status' => (int)$transfer->status,
            'status_text' => self::statusText((int)$transfer->status),
            'result' => (string)$transfer->result,
            'errmsg' => (string)$transfer->last_error,
            'proof_no' => (string)($transfer->proof_no ?? $transfer->channel_order_no ?? ''),
            'operator' => (string)($transfer->operator ?? ''),
            'remark' => (string)($transfer->remark ?? ''),
            'finished_at' => (string)($transfer->finished_at ?? ''),
            'rejected_at' => (string)($transfer->rejected_at ?? ''),
            'created_at' => (string)$transfer->created_at,
        ];
    }

    private static function approve(object $transfer, string $operator, array $payload): array
    {
        $proofNo = trim((string)($payload['proof_no'] ?? $payload['channel_order_no'] ?? $payload['txid'] ?? ''));
        $remark = trim((string)($payload['remark'] ?? ''));
        if ($proofNo === '') {
            throw new BusinessException('请填写真实代付凭证号或流水号', StatusCode::VALIDATION_ERROR);
        }

        $merchantId = (int)($transfer->merchant_id ?? 0);
        $money = self::formatMoney($transfer->money ?? 0);
        if ($merchantId <= 0 || (float)$money <= 0) {
            throw new BusinessException('代付记录金额或商户无效', StatusCode::VALIDATION_ERROR);
        }

        $balance = LocalFundStore::balanceForMerchant($merchantId);
        if ((float)($balance['available'] ?? '0.00') < (float)$money) {
            throw new BusinessException('商户余额不足，无法确认代付扣款', StatusCode::BUSINESS_ERROR);
        }

        $now = date('Y-m-d H:i:s');
        $flow = LocalFundStore::debit(
            $merchantId,
            $money,
            '代付扣款',
            'transfer',
            (string)$transfer->biz_no,
            $now,
            [
                'out_biz_no' => (string)$transfer->out_biz_no,
                'type' => (string)$transfer->type,
                'account' => self::maskAccount((string)$transfer->account),
                'name' => (string)$transfer->name,
                'proof_no' => $proofNo,
                'operator' => $operator,
                'remark' => $remark,
            ]
        );

        $updated = LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
            'status' => 1,
            'available_money' => (string)($flow->balance_after ?? ''),
            'channel_order_no' => $proofNo,
            'channel_trade_no' => $proofNo,
            'result' => 'manual_transferred',
            'last_error' => '',
            'proof_no' => $proofNo,
            'operator' => $operator,
            'remark' => $remark,
            'finished_at' => $now,
        ]);

        self::appendAuditLog($updated ?? $transfer, $operator, 'approve', $proofNo, $remark);

        return [
            'transfer' => self::shapeTransfer($updated ?? $transfer),
            'balance' => LocalFundStore::balanceForMerchant($merchantId),
        ];
    }

    private static function reject(object $transfer, string $operator, array $payload): array
    {
        $reason = trim((string)($payload['reason'] ?? $payload['remark'] ?? ''));
        if ($reason === '') {
            $reason = '后台驳回代付单';
        }

        $now = date('Y-m-d H:i:s');
        $updated = LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
            'status' => 2,
            'result' => 'manual_rejected',
            'last_error' => $reason,
            'operator' => $operator,
            'remark' => $reason,
            'rejected_at' => $now,
        ]);

        self::appendAuditLog($updated ?? $transfer, $operator, 'reject', '', $reason);

        return [
            'transfer' => self::shapeTransfer($updated ?? $transfer),
            'balance' => LocalFundStore::balanceForMerchant((int)$transfer->merchant_id),
        ];
    }

    private static function appendAuditLog(object $transfer, string $operator, string $action, string $proofNo, string $remark): void
    {
        $detail = [
            'biz_no' => (string)($transfer->biz_no ?? ''),
            'out_biz_no' => (string)($transfer->out_biz_no ?? ''),
            'type' => (string)($transfer->type ?? ''),
            'account' => self::maskAccount((string)($transfer->account ?? '')),
            'name' => (string)($transfer->name ?? ''),
            'money' => self::formatMoney($transfer->money ?? 0),
            'proof_no' => $proofNo,
            'remark' => $remark,
            'result' => (string)($transfer->result ?? ''),
        ];

        $actionText = ($action === 'approve' ? '人工确认代付：' : '人工驳回代付：') . (string)($transfer->biz_no ?? '');

        CompensationAuditLogService::merchant([
            'operator' => $operator,
            'merchant_id' => (int)($transfer->merchant_id ?? 0),
            'action' => $actionText,
            'ip' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => $detail,
        ]);

        CompensationAuditLogService::admin([
            'operator' => $operator,
            'merchant_id' => (int)($transfer->merchant_id ?? 0),
            'action' => $actionText,
            'ip' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => $detail,
        ]);
    }

    private static function statusText(int $status): string
    {
        return match ($status) {
            1 => '代付成功',
            2 => '已驳回',
            default => '待处理',
        };
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

    private static function formatMoney(mixed $amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }
}
