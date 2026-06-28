<?php

namespace app\service\home;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\OrderService;
use app\service\system\AccountService;
use app\service\system\PaymentMetaService;
use app\service\system\SettingsService;
use app\service\system\SystemBusinessPaymentService;

class DemoCheckoutService
{
    public static function config(array $payload = []): array
    {
        $settings = SettingsService::all(false);
        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
        $basic = is_array($settings['basic'] ?? null) ? $settings['basic'] : [];
        $frontendTest = self::frontendTestSettings($payment);
        $enabled = (bool)($frontendTest['enabled'] ?? false);
        $contextPayload = [];
        $methodCode = SettingsService::resolveEnabledPaymentMethodCode(
            'frontend_test',
            (string)($payload['method'] ?? $payload['method_code'] ?? $payload['type'] ?? '')
        );
        if ($methodCode !== '') {
            $contextPayload['channel_code'] = $methodCode;
        }
        $preferredMerchantId = (int)($payload['merchant_id'] ?? 0);
        if ($preferredMerchantId > 0) {
            $contextPayload['merchant_id'] = $preferredMerchantId;
        }
        $context = $enabled
            ? SystemBusinessPaymentService::inspectContext('frontend_test', $contextPayload)
            : ['ok' => false, 'message' => '首页测试支付已关闭'];
        $available = $enabled && (bool)($context['ok'] ?? false);
        $merchantId = 0;
        if ($available) {
            $merchantId = (int)($context['local_merchant_id'] ?? $context['merchant_id'] ?? 0);
        } else {
            try {
                $merchantId = SystemBusinessPaymentService::resolveFrontendTestLocalMerchantId($contextPayload);
            } catch (\Throwable) {
                $merchantId = 0;
            }
        }

        return [
            'enabled' => $available,
            'title' => 'NexPay 支付测试',
            'subtitle' => $available
                ? '用于首页游客支付测试，会先创建真实本地订单，再跳转到收银台。'
                : '请先启用首页测试支付，并为可用商户配置至少一个已启用通道。',
            'default_amount' => (string)($frontendTest['amount'] ?? ''),
            'min_amount' => (string)($frontendTest['min_amount'] ?? '0.10'),
            'auto_complete' => (bool)($frontendTest['auto_complete'] ?? false),
            'providers' => self::providerOptions(),
            'methods' => self::demoMethodOptions(),
            'site_name' => (string)($basic['site_name'] ?? 'NexPay 聚合支付系统'),
            'merchant_id' => $merchantId > 0 ? (string)$merchantId : '',
            'merchant_name' => self::merchantName($merchantId),
            'disabled_reason' => $available ? '' : (string)($context['message'] ?? '首页测试支付不可用'),
        ];
    }

    public static function create(array $payload): array
    {
        $settings = SettingsService::all(false);
        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
        $frontendTest = self::frontendTestSettings($payment);

        if (!(bool)($frontendTest['enabled'] ?? false)) {
            throw new BusinessException('首页测试支付已关闭', StatusCode::BUSINESS_ERROR);
        }

        $provider = self::normalizeProviderSelection((string)($payload['provider'] ?? 'system'), (string)($frontendTest['mode'] ?? 'v2'));
        $methodCode = SettingsService::resolveEnabledPaymentMethodCode(
            'frontend_test',
            (string)($payload['method'] ?? $payload['method_code'] ?? $payload['type'] ?? '')
        );
        if ($methodCode === '') {
            throw new BusinessException('请选择支付方式', StatusCode::VALIDATION_ERROR);
        }

        $amount = self::resolveDemoAmount(
            $payload,
            (string)($frontendTest['amount'] ?? ''),
            (string)($frontendTest['min_amount'] ?? '0.10')
        );
        $subject = OrderService::normalizeGatewayOrderSubject(
            (string)($payload['subject'] ?? ''),
            'NexPay 支付测试订单'
        );

        $merchantId = self::resolveDemoMerchantId($methodCode, (int)($payload['merchant_id'] ?? 0));
        $context = SystemBusinessPaymentService::inspectContext('frontend_test', [
            'merchant_id' => $merchantId,
            'channel_code' => $methodCode,
            'provider' => $provider['provider'],
            'mode' => $provider['mode'],
            'amount' => $amount,
        ]);
        if (!(bool)($context['ok'] ?? false)) {
            throw new BusinessException((string)($context['message'] ?? '首页测试支付不可用'), StatusCode::BUSINESS_ERROR);
        }

        $tradeNoSeed = date('YmdHis') . random_int(100000, 999999);
        $outTradeNo = self::requestedOutTradeNo($payload, $tradeNoSeed);

        $order = SystemBusinessPaymentService::createBusinessOrder(
            'frontend_test',
            $merchantId,
            'homepage_payment_test',
            [
            'merchant_id' => $merchantId,
            'out_trade_no' => $outTradeNo,
            'channel_code' => $methodCode,
            'channel_category' => 2,
            'subject' => $subject,
            'amount' => $amount,
            'notify_url' => '',
            'return_url' => '/demo',
            'client_ip' => (string)($payload['client_ip'] ?? ''),
            'param' => 'homepage-payment-test',
            'provider' => $provider['provider'],
            'mode' => $provider['mode'],
            'request_payload' => [
                '_meta' => [
                    'business' => 'homepage_payment_test',
                    'merchant_id' => $merchantId,
                    'requested_provider' => $provider['value'],
                    'requested_method' => $methodCode,
                ],
            ],
            ],
            [
                'requested_provider' => $provider['value'],
            ]
        );

        return [
            'trade_no' => (string)$order->trade_no,
            'out_trade_no' => (string)$order->out_trade_no,
            'provider' => $provider['value'],
            'amount' => $amount,
            'pay_url' => SystemBusinessPaymentService::submitUrl((string)$order->trade_no),
            'submit_url' => SystemBusinessPaymentService::submitUrl((string)$order->trade_no),
            'checkout_url' => SystemBusinessPaymentService::checkoutUrl((string)$order->trade_no),
            'status_url' => '/pay/status/' . rawurlencode((string)$order->trade_no),
            'expire_time' => (string)$order->expire_time,
        ];
    }

    public static function status(string $tradeNo): array
    {
        $status = SystemBusinessPaymentService::paymentResult($tradeNo);
        $status['status_url'] = '/pay/status/' . rawurlencode($tradeNo);
        $status['checkout_url'] = SystemBusinessPaymentService::checkoutUrl($tradeNo);
        $status['submit_url'] = SystemBusinessPaymentService::submitUrl($tradeNo);
        $status['pay_url'] = SystemBusinessPaymentService::submitUrl($tradeNo);

        return $status;
    }

    private static function frontendTestSettings(array $payment): array
    {
        $source = is_array($payment['frontend_test'] ?? null) ? $payment['frontend_test'] : [];
        $mode = strtolower(trim((string)($source['mode'] ?? $payment['epay_version'] ?? 'v2'))) === 'v1' ? 'v1' : 'v2';

        return [
            'provider' => strtolower(trim((string)($source['provider'] ?? 'system'))) === 'epay' ? 'epay' : 'system',
            'mode' => $mode,
            'payment_url' => trim((string)($source['payment_url'] ?? '')),
            'merchant_id' => trim((string)($source['merchant_id'] ?? '')),
            'merchant_md5' => trim((string)($source['merchant_md5'] ?? '')),
            'platform_public_key' => trim((string)($source['platform_public_key'] ?? '')),
            'merchant_private_key' => trim((string)($source['merchant_private_key'] ?? '')),
            'min_amount' => trim((string)($source['min_amount'] ?? '0.10')),
            'enabled' => (bool)($source['enabled'] ?? $payment['payment_test_enabled'] ?? false),
            'amount' => trim((string)($source['amount'] ?? $payment['test_default_amount'] ?? '')),
            'auto_complete' => (bool)($source['auto_complete'] ?? $payment['test_auto_complete'] ?? false),
        ];
    }

    private static function resolveDemoAmount(array $payload, string $configuredAmount, string $minimumAmount): string
    {
        $minimum = self::normalizeMinimumAmount($minimumAmount);
        $requested = trim((string)($payload['amount'] ?? ''));
        if ($requested !== '') {
            if (!is_numeric($requested)) {
                throw new BusinessException('测试金额格式不正确', StatusCode::VALIDATION_ERROR);
            }

            $amount = number_format((float)$requested, 2, '.', '');
            OrderService::assertPositiveOrderAmount($amount, '测试金额');
            if ((float)$amount < (float)$minimum) {
                throw new BusinessException('测试金额不能低于 ' . $minimum . ' 元', StatusCode::VALIDATION_ERROR);
            }

            return $amount;
        }

        $configured = trim($configuredAmount);
        if ($configured !== '' && is_numeric($configured)) {
            $amount = number_format((float)$configured, 2, '.', '');
            if ((float)$amount > 0) {
                return (float)$amount < (float)$minimum ? $minimum : $amount;
            }
        }

        return self::randomDemoAmount((float)$minimum);
    }

    private static function randomDemoAmount(float $minimumAmount = 0.10): string
    {
        $lower = max(1, (int)ceil($minimumAmount * 100));
        if ($lower > 9999) {
            return number_format($minimumAmount, 2, '.', '');
        }

        return number_format(random_int($lower, 9999) / 100, 2, '.', '');
    }

    private static function normalizeMinimumAmount(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return '0.10';
        }

        $amount = (float)$trimmed;
        if ($amount <= 0) {
            return '0.10';
        }

        return number_format($amount, 2, '.', '');
    }

    private static function requestedOutTradeNo(array $payload, string $tradeNo): string
    {
        $requested = OrderService::normalizeGatewayOutTradeNo((string)($payload['trade_no'] ?? ''));
        if ($requested !== '') {
            return $requested;
        }

        return 'public-test-' . $tradeNo;
    }

    private static function providerOptions(): array
    {
        return [
            ['value' => 'system', 'label' => '系统商户'],
            ['value' => 'epay_v1', 'label' => '易支付 V1'],
            ['value' => 'epay_v2', 'label' => '易支付 V2'],
        ];
    }

    private static function demoMethodOptions(): array
    {
        return SettingsService::frontendPaymentMethodOptions('frontend_test');
    }

    private static function resolveDemoMerchantId(string $requestedMethod = '', int $preferredMerchantId = 0): int
    {
        $contextPayload = [
            'channel_code' => PaymentMetaService::normalizeMethodCode($requestedMethod),
        ];
        if ($preferredMerchantId > 0) {
            $contextPayload['merchant_id'] = $preferredMerchantId;
        }

        $context = SystemBusinessPaymentService::inspectContext('frontend_test', $contextPayload);
        if ((bool)($context['ok'] ?? false)) {
            $localMerchantId = (int)($context['local_merchant_id'] ?? 0);
            if ($localMerchantId > 0) {
                return $localMerchantId;
            }

            if ((int)($context['merchant_id'] ?? 0) > 0) {
                return (int)$context['merchant_id'];
            }
        }

        throw new BusinessException((string)($context['message'] ?? '首页测试支付缺少可用商户通道'), StatusCode::BUSINESS_ERROR);
    }

    private static function normalizeProviderSelection(string $value, string $fallbackMode = 'v2'): array
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'epay', 'epay_v1', 'v1' => ['value' => 'epay_v1', 'provider' => 'epay', 'mode' => 'v1'],
            'epay_v2', 'v2' => ['value' => 'epay_v2', 'provider' => 'epay', 'mode' => 'v2'],
            'system' => ['value' => 'system', 'provider' => 'system', 'mode' => strtolower(trim($fallbackMode)) === 'v1' ? 'v1' : 'v2'],
            default => throw new BusinessException('支付来源无效', StatusCode::VALIDATION_ERROR),
        };
    }

    private static function merchantName(int $merchantId): string
    {
        if ($merchantId <= 0) {
            return '系统商户';
        }

        $credential = AccountService::merchantCredentialById($merchantId);
        if (!is_array($credential)) {
            return '系统商户';
        }

        $name = trim((string)($credential['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $username = trim((string)($credential['username'] ?? ''));
        return $username !== '' ? $username : '系统商户';
    }

}
