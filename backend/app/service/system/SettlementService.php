<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\LocalFundStore;
use app\service\payment\LocalSettlementStore;
use app\service\payment\OrderService;

class SettlementService
{
    private const DEFAULT_WITHDRAW_MIN_AMOUNT = '0.01';
    private const MANUAL_WITHDRAW_ACCOUNT_TYPES = ['alipay', 'bank', 'usdt'];

    public static function requestWithdraw(int $merchantId, int $userId, array $payload): array
    {
        if ($merchantId <= 0) {
            throw new BusinessException('商户身份无效', StatusCode::UNAUTHORIZED);
        }

        self::ensureWithdrawRealnamePolicy($merchantId, $userId);

        $money = number_format((float)($payload['money'] ?? $payload['amount'] ?? 0), 2, '.', '');
        $accountType = trim((string)($payload['account_type'] ?? $payload['type'] ?? ''));
        $account = trim((string)($payload['account'] ?? ''));
        $accountName = trim((string)($payload['account_name'] ?? $payload['name'] ?? ''));
        $outSettleNo = trim((string)($payload['out_settle_no'] ?? ''));

        if ((float)$money <= 0) {
            throw new BusinessException('提现金额必须大于 0', StatusCode::VALIDATION_ERROR);
        }
        if ($accountType === '' || $account === '' || $accountName === '') {
            throw new BusinessException('请完整填写提现账户信息', StatusCode::VALIDATION_ERROR);
        }
        if ($outSettleNo !== '') {
            $existing = LocalSettlementStore::findByOutSettleNo($merchantId, $outSettleNo);
            if ($existing) {
                self::assertSameWithdrawPayload($existing, $money, $accountType, $account, $accountName);
                self::ensureWithdrawFlow($existing);
                if ((int)($existing->status ?? 0) === 2) {
                    self::ensureRejectFlow($existing, (string)($existing->last_error ?? ''));
                }

                return [
                    'settlement' => self::shapeSettlement($existing),
                    'balance' => LocalFundStore::balanceForMerchant($merchantId),
                    'idempotent' => true,
                ];
            }
        }

        $balance = LocalFundStore::balanceForMerchant($merchantId);
        if ((float)($balance['available'] ?? '0.00') < (float)$money) {
            throw new BusinessException('商户余额不足，无法发起提现', StatusCode::BUSINESS_ERROR);
        }

        $settleNo = self::generateSettleNo();
        $now = date('Y-m-d H:i:s');
        $record = LocalSettlementStore::create([
            'merchant_id' => $merchantId,
            'settle_no' => $settleNo,
            'out_settle_no' => $outSettleNo,
            'type' => 'manual_withdraw',
            'account_type' => $accountType,
            'account' => $account,
            'account_name' => $accountName,
            'money' => $money,
            'fee' => '0.00',
            'real_money' => $money,
            'status' => 0,
            'result' => 'pending_manual_review',
            'remark' => trim((string)($payload['remark'] ?? '')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::ensureWithdrawFlow($record);

        return [
            'settlement' => self::shapeSettlement($record),
            'balance' => LocalFundStore::balanceForMerchant($merchantId),
        ];
    }

    public static function review(string $settleNo, string $action, string $operator, string $reason = ''): array
    {
        $settlement = LocalSettlementStore::find($settleNo);
        if (!$settlement) {
            throw new BusinessException('结算记录不存在', StatusCode::NOT_FOUND);
        }
        if (!LocalSettlementStore::isBusinessSettlement($settlement)) {
            throw new BusinessException('测试或联调结算不能审核为真实业务', StatusCode::VALIDATION_ERROR);
        }

        $action = strtolower(trim($action));
        if ((int)($settlement->status ?? 0) !== 0) {
            if ($action === 'approve' && (int)($settlement->status ?? 0) === 1) {
                return [
                    'settlement' => self::shapeSettlement($settlement),
                    'idempotent' => true,
                ];
            }

            if ($action === 'reject' && (int)($settlement->status ?? 0) === 2) {
                self::ensureRejectFlow($settlement, $reason);

                return [
                    'settlement' => self::shapeSettlement($settlement),
                    'balance' => LocalFundStore::balanceForMerchant((int)$settlement->merchant_id),
                    'idempotent' => true,
                ];
            }

            throw new BusinessException('当前结算记录已处理，不能重复审核', StatusCode::BUSINESS_ERROR);
        }

        $now = date('Y-m-d H:i:s');
        if ($action === 'approve') {
            $updated = LocalSettlementStore::update($settleNo, [
                'status' => 1,
                'result' => 'manual_approved',
                'last_error' => '',
                'operator' => $operator,
                'audited_at' => $now,
            ]);

            return [
                'settlement' => self::shapeSettlement($updated),
            ];
        }

        if ($action === 'reject') {
            $updated = LocalSettlementStore::update($settleNo, [
                'status' => 2,
                'result' => 'manual_rejected',
                'last_error' => $reason !== '' ? $reason : '后台驳回提现申请',
                'operator' => $operator,
                'audited_at' => $now,
            ]);
            self::ensureRejectFlow($updated ?? $settlement, $reason);

            return [
                'settlement' => self::shapeSettlement($updated),
                'balance' => LocalFundStore::balanceForMerchant((int)$settlement->merchant_id),
            ];
        }

        throw new BusinessException('审核动作仅支持 approve 或 reject', StatusCode::VALIDATION_ERROR);
    }

    private static function assertSameWithdrawPayload(
        object $settlement,
        string $money,
        string $accountType,
        string $account,
        string $accountName
    ): void {
        $same = number_format((float)($settlement->money ?? 0), 2, '.', '') === $money
            && trim((string)($settlement->account_type ?? '')) === $accountType
            && trim((string)($settlement->account ?? '')) === $account
            && trim((string)($settlement->account_name ?? '')) === $accountName;

        if (!$same) {
            throw new BusinessException('外部结算单号已存在且请求内容不一致', StatusCode::VALIDATION_ERROR);
        }
    }

    private static function ensureWithdrawFlow(object $settlement): void
    {
        $merchantId = (int)($settlement->merchant_id ?? 0);
        $settleNo = trim((string)($settlement->settle_no ?? ''));
        if ($merchantId <= 0 || $settleNo === '') {
            throw new BusinessException('结算记录商户或单号无效', StatusCode::VALIDATION_ERROR);
        }

        if (LocalFundStore::findFlowByReference($merchantId, 'settlement_withdraw', $settleNo)) {
            return;
        }

        $money = number_format((float)($settlement->money ?? 0), 2, '.', '');
        $balance = LocalFundStore::balanceForMerchant($merchantId);
        if ((float)($balance['available'] ?? '0.00') < (float)$money) {
            throw new BusinessException('商户余额不足，无法发起提现', StatusCode::BUSINESS_ERROR);
        }

        LocalFundStore::debit($merchantId, $money, '提现申请', 'settlement_withdraw', $settleNo, (string)($settlement->created_at ?? date('Y-m-d H:i:s')), [
            'account_type' => (string)($settlement->account_type ?? ''),
            'account' => self::maskAccount((string)($settlement->account ?? '')),
            'out_settle_no' => (string)($settlement->out_settle_no ?? ''),
        ]);
    }

    private static function ensureRejectFlow(object $settlement, string $reason): void
    {
        $merchantId = (int)($settlement->merchant_id ?? 0);
        $settleNo = trim((string)($settlement->settle_no ?? ''));
        if ($merchantId <= 0 || $settleNo === '') {
            throw new BusinessException('结算记录商户或单号无效', StatusCode::VALIDATION_ERROR);
        }

        if (LocalFundStore::findFlowByReference($merchantId, 'settlement_reject', $settleNo)) {
            return;
        }

        LocalFundStore::credit(
            $merchantId,
            (string)$settlement->money,
            '提现驳回退回',
            'settlement_reject',
            $settleNo,
            (string)($settlement->audited_at ?: date('Y-m-d H:i:s')),
            [
                'reason' => $reason,
                'out_settle_no' => (string)$settlement->out_settle_no,
            ]
        );
    }

    public static function merchantSettlements(int $merchantId): array
    {
        return array_map(
            static fn(object $item): array => self::shapeSettlement($item),
            LocalSettlementStore::businessSettlements($merchantId)
        );
    }

    public static function withdrawOptions(int $merchantId): array
    {
        $transferOptions = OrderService::gatewayTransferMethodOptions($merchantId);
        $accountTypes = $transferOptions !== [] ? $transferOptions : self::manualWithdrawAccountTypes();

        return [
            'account_types' => $accountTypes,
            'min_amount' => self::DEFAULT_WITHDRAW_MIN_AMOUNT,
            'review_mode' => 'manual',
            'transfer_enabled' => $transferOptions !== [],
        ];
    }

    public static function adminSettlements(): array
    {
        return array_map(
            static fn(object $item): array => self::shapeSettlement($item),
            LocalSettlementStore::businessSettlements(0)
        );
    }

    public static function v1Settlements(int $merchantId): array
    {
        return array_map(static function (object $item): array {
            return [
                'settle_no' => (string)$item->settle_no,
                'out_settle_no' => (string)$item->out_settle_no,
                'status' => (int)$item->status,
                'result' => (string)$item->result,
                'money' => number_format((float)$item->money, 2, '.', ''),
                'fee' => number_format((float)$item->fee, 2, '.', ''),
                'realmoney' => number_format((float)$item->real_money, 2, '.', ''),
                'account' => self::maskAccount((string)$item->account),
                'username' => (string)$item->account_name,
                'addtime' => (string)$item->created_at,
                'endtime' => (string)($item->audited_at ?: $item->updated_at),
                'errmsg' => (string)$item->last_error,
            ];
        }, LocalSettlementStore::businessSettlements($merchantId));
    }

    public static function shapeSettlement(?object $item): array
    {
        if (!$item) {
            return [];
        }

        return [
            'settle_no' => (string)$item->settle_no,
            'out_settle_no' => (string)$item->out_settle_no,
            'merchant_id' => (int)$item->merchant_id,
            'merchant' => self::merchantName((int)$item->merchant_id),
            'type' => self::typeLabel((string)$item->type),
            'account_type' => (string)$item->account_type,
            'account' => self::maskAccount((string)$item->account),
            'account_name' => (string)$item->account_name,
            'money' => number_format((float)$item->money, 2, '.', ''),
            'fee' => number_format((float)$item->fee, 2, '.', ''),
            'real_money' => number_format((float)$item->real_money, 2, '.', ''),
            'status' => self::statusLabel((int)$item->status),
            'status_code' => (int)$item->status,
            'result' => (string)$item->result,
            'errmsg' => (string)$item->last_error,
            'remark' => (string)$item->remark,
            'operator' => (string)$item->operator,
            'audited_at' => (string)$item->audited_at,
            'created_at' => (string)$item->created_at,
        ];
    }

    private static function ensureWithdrawRealnamePolicy(int $merchantId, int $userId): void
    {
        $settings = SettingsService::all(false);
        $merchant = is_array($settings['merchant'] ?? null) ? $settings['merchant'] : [];
        if (!(bool)($merchant['require_realname_before_withdraw'] ?? false)) {
            return;
        }

        if (AccountService::merchantRealnameApproved($userId, $merchantId)) {
            return;
        }

        throw new BusinessException('请先完成实名认证后再申请提现', StatusCode::BUSINESS_ERROR);
    }

    private static function generateSettleNo(): string
    {
        do {
            $settleNo = 'SET' . date('YmdHis') . random_int(100000, 999999);
        } while (LocalSettlementStore::find($settleNo));

        return $settleNo;
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

    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'manual_withdraw' => '人工提现',
            default => $type,
        };
    }

    private static function manualWithdrawAccountTypes(): array
    {
        return array_map(static fn(string $code): array => [
            'value' => $code,
            'code' => $code,
            'label' => PaymentMetaService::friendlyMethodName($code),
            'mode' => 'manual',
        ], self::MANUAL_WITHDRAW_ACCOUNT_TYPES);
    }

    private static function statusLabel(int $status): string
    {
        return match ($status) {
            1 => '已通过',
            2 => '已驳回',
            default => '待审核',
        };
    }

    private static function merchantName(int $merchantId): string
    {
        $merchant = AccountService::merchantCredentialById($merchantId);
        if ($merchant) {
            $name = AccountService::cleanMerchantDisplayName(
                (string)($merchant['name'] ?? $merchant['merchant_name'] ?? ''),
                (string)($merchant['username'] ?? ''),
                $merchantId
            );
            if ($name !== '') {
                return $name;
            }
        }

        return '商户' . $merchantId;
    }
}
