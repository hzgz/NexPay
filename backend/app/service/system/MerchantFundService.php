<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\OrderService;

class MerchantFundService
{
    public static function rechargeOptions(int $merchantId): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        $options = [];
        foreach (SettingsService::frontendPaymentMethodOptions('system_checkout') as $item) {
            $methodCode = trim((string)($item['method_code'] ?? $item['code'] ?? ''));
            if ($methodCode === '') {
                continue;
            }

            $context = SystemBusinessPaymentService::inspectContext('system_checkout', [
                'channel_code' => $methodCode,
                'force_configured_gateway' => true,
            ]);

            if (!(bool)($context['ok'] ?? false)) {
                continue;
            }

            $methodName = trim((string)($item['name'] ?? PaymentMetaService::friendlyMethodName($methodCode)));
            $minAmount = number_format((float)($context['min_amount'] ?? '0.10'), 2, '.', '');
            $options[] = [
                'method_code' => $methodCode,
                'code' => $methodCode,
                'name' => $methodName,
                'method_name' => $methodName,
                'icon' => trim((string)($item['icon'] ?? '')),
                'desc' => '使用后台系统业务支付配置创建充值订单。',
                'min' => $minAmount,
                'max' => '0.00',
                'single_min_amount' => $minAmount,
                'single_max_amount' => '0.00',
                'daily_limit' => '0.00',
                'daily_count_limit' => 0,
                'enabled' => true,
            ];
        }

        return $options;
    }

    public static function createRechargeOrder(int $merchantId, array $payload): array
    {
        if ($merchantId <= 0) {
            throw new BusinessException('商户身份无效', StatusCode::UNAUTHORIZED);
        }

        $options = self::rechargeOptions($merchantId);
        if ($options === []) {
            throw new BusinessException('当前系统业务支付暂未配置可用充值方式', StatusCode::NOT_FOUND);
        }

        $amount = number_format((float)($payload['amount'] ?? 0), 2, '.', '');
        OrderService::assertPositiveOrderAmount($amount, '充值金额');

        $method = self::resolveRechargeMethodCode($payload);
        if ($method === '') {
            $method = trim((string)($options[0]['method_code'] ?? ''));
        }

        $method = SettingsService::resolveEnabledPaymentMethodCode('system_checkout', $method);
        if ($method === '') {
            throw new BusinessException('充值方式不存在或未启用', StatusCode::VALIDATION_ERROR);
        }

        $option = self::resolveRechargeOption($options, $method);
        if ($option === null) {
            throw new BusinessException('充值方式当前不可用', StatusCode::VALIDATION_ERROR);
        }

        $minAmount = (float)($option['min'] ?? $option['single_min_amount'] ?? 0.01);
        if ($minAmount > 0 && (float)$amount < $minAmount) {
            throw new BusinessException(
                '当前充值方式最低金额为 ' . number_format($minAmount, 2, '.', '') . ' 元',
                StatusCode::VALIDATION_ERROR
            );
        }

        $maxAmount = (float)($option['max'] ?? $option['single_max_amount'] ?? 0);
        if ($maxAmount > 0 && (float)$amount > $maxAmount) {
            throw new BusinessException(
                '当前充值方式最高金额为 ' . number_format($maxAmount, 2, '.', '') . ' 元',
                StatusCode::VALIDATION_ERROR
            );
        }

        $order = SystemBusinessPaymentService::createBusinessOrder(
            'system_checkout',
            $merchantId,
            'merchant_recharge',
            [
            'merchant_id' => $merchantId,
            'out_trade_no' => self::generateOutTradeNo($merchantId),
            'channel_code' => $method,
            'channel_category' => 2,
            'force_configured_gateway' => true,
            'notify_url' => '',
            'return_url' => '/user/funds/flows',
            'subject' => '商户余额充值',
            'amount' => $amount,
            'client_ip' => (string)($payload['client_ip'] ?? ''),
            'param' => 'merchant-recharge',
            'request_payload' => [
                '_meta' => [
                    'business' => 'merchant_recharge',
                    'merchant_id' => $merchantId,
                    'requested_method' => $method,
                ],
            ],
            ]
        );

        return [
            'trade_no' => (string)$order->trade_no,
            'out_trade_no' => (string)$order->out_trade_no,
            'amount' => $amount,
            'channel_code' => (string)$order->channel_code,
            'pay_url' => SystemBusinessPaymentService::submitUrl((string)$order->trade_no),
            'submit_url' => SystemBusinessPaymentService::submitUrl((string)$order->trade_no),
            'checkout_url' => SystemBusinessPaymentService::checkoutUrl((string)$order->trade_no),
            'status' => 'pending',
            'expire_time' => (string)$order->expire_time,
        ];
    }

    private static function resolveRechargeMethodCode(array $payload): string
    {
        return PaymentMetaService::normalizeMethodCode((string)(
            $payload['type']
            ?? $payload['method_code']
            ?? $payload['channel_code']
            ?? $payload['channel']
            ?? ''
        ));
    }

    private static function resolveRechargeOption(array $options, string $methodCode): ?array
    {
        $normalized = PaymentMetaService::normalizeMethodCode($methodCode);
        if ($normalized === '') {
            return null;
        }

        foreach ($options as $item) {
            if (PaymentMetaService::normalizeMethodCode((string)($item['method_code'] ?? '')) === $normalized) {
                return $item;
            }
        }

        return null;
    }

    private static function generateOutTradeNo(int $merchantId): string
    {
        return SystemBusinessPaymentService::fallbackBusinessOutTradeNo('RECHARGE', $merchantId);
    }
}
