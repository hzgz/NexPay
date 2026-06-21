<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\LegacyPaymentGatewayService;
use app\service\payment\LocalOrderStore;
use app\service\payment\OrderService;
use Throwable;

class SystemBusinessPaymentService
{
    private const PROVIDER_SYSTEM = 'system';
    private const PROVIDER_EPAY = 'epay';
    private const MODE_V1 = 'v1';
    private const MODE_V2 = 'v2';

    public static function create(string $configKey, array $payload): object
    {
        $config = self::gatewayConfig($configKey, $payload);
        $merchantId = (int)($payload['merchant_id'] ?? 0);
        if ($merchantId <= 0) {
            throw new BusinessException('Business payment requires a merchant ID', StatusCode::VALIDATION_ERROR);
        }

        $amount = number_format((float)($payload['amount'] ?? 0), 2, '.', '');
        if ((float)$amount <= 0) {
            throw new BusinessException('Payment amount must be greater than 0', StatusCode::VALIDATION_ERROR);
        }

        $tradeNo = self::tradeNo();
        $createdAt = date('Y-m-d H:i:s');
        $returnUrl = trim((string)($payload['return_url'] ?? ''));
        $requestPayload = is_array($payload['request_payload'] ?? null) ? $payload['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $meta['source_protocol'] = $config['mode'];
        $meta['gateway_source'] = (string)($config['source'] ?? 'configured');
        if ((int)($config['carrier_merchant_id'] ?? 0) > 0) {
            $meta['carrier_merchant_id'] = (int)$config['carrier_merchant_id'];
        }
        $requestPayload['_meta'] = $meta;

        $legacyChannel = self::legacyChannelSnapshot($config, $payload);
        $requestPayload['_legacy_channel'] = $legacyChannel;
        $legacyConfig = is_array($legacyChannel['config'] ?? null) ? $legacyChannel['config'] : [];
        $paymentAddress = trim((string)($legacyConfig['payment_address'] ?? $legacyConfig['display_value'] ?? ''));

        $orderPayload = [
            'trade_no' => $tradeNo,
            'out_trade_no' => self::outTradeNo((string)($payload['out_trade_no'] ?? ''), $tradeNo),
            'merchant_id' => $merchantId,
            'merchant_channel_id' => (int)($payload['merchant_channel_id'] ?? $legacyChannel['merchant_channel_id'] ?? 0),
            'channel_code' => (string)($payload['channel_code'] ?? $legacyChannel['channel_code'] ?? ''),
            'channel_category' => (int)($payload['channel_category'] ?? $legacyChannel['channel_category'] ?? 2),
            'subject' => trim((string)($payload['subject'] ?? 'Business payment order')) ?: 'Business payment order',
            'amount' => $amount,
            'payable_amount' => $amount,
            'status' => OrderService::STATUS_PENDING,
            'notify_url' => trim((string)($payload['notify_url'] ?? '')),
            'return_url' => $returnUrl,
            'client_ip' => trim((string)($payload['client_ip'] ?? '')),
            'param' => trim((string)($payload['param'] ?? 'business-payment')),
            'expire_time' => trim((string)($payload['expire_time'] ?? date('Y-m-d H:i:s', time() + 86400))),
            'request_payload' => $requestPayload,
            'notify_payload' => [],
            'payment_address' => $paymentAddress,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        if (database_available()) {
            $order = new \app\model\Order();
            $order->save($orderPayload);
        } else {
            $order = LocalOrderStore::createOrder($orderPayload);
        }

        return $order;
    }

    public static function inspectContext(string $configKey, array $payload = []): array
    {
        try {
            return self::gatewayConfig($configKey, $payload) + ['ok' => true];
        } catch (BusinessException $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    public static function submitUrl(string $tradeNo): string
    {
        return LegacyPaymentGatewayService::entryUrl($tradeNo, 'submit');
    }

    public static function checkoutUrl(string $tradeNo): string
    {
        return rtrim((string)ConfigService::gatewayBaseUrl(), '/') . '/pay/checkout/' . rawurlencode($tradeNo);
    }

    public static function paymentResult(string $tradeNo): array
    {
        $order = OrderService::findByTradeNo($tradeNo);
        $status = (int)($order->status ?? OrderService::STATUS_PENDING);
        $statusMap = [
            OrderService::STATUS_PENDING => ['key' => 'pending', 'label' => 'Pending'],
            OrderService::STATUS_SUCCESS => ['key' => 'success', 'label' => 'Paid'],
            OrderService::STATUS_FAILED => ['key' => 'failed', 'label' => 'Failed'],
            OrderService::STATUS_EXPIRED => ['key' => 'expired', 'label' => 'Expired'],
            OrderService::STATUS_CLOSED => ['key' => 'closed', 'label' => 'Closed'],
        ];
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $statusInfo = $statusMap[$status] ?? ['key' => 'unknown', 'label' => 'Unknown'];

        return [
            'trade_no' => (string)($order->trade_no ?? ''),
            'out_trade_no' => (string)($order->out_trade_no ?? ''),
            'subject' => (string)($order->subject ?? ''),
            'amount' => number_format((float)($order->amount ?? 0), 2, '.', ''),
            'status' => $status,
            'status_key' => $statusInfo['key'],
            'status_text' => $statusInfo['label'],
            'pay_time' => (string)($order->pay_time ?? ''),
            'expire_time' => (string)($order->expire_time ?? ''),
            'channel_code' => (string)($order->channel_code ?? ''),
            'business' => (string)($meta['business'] ?? ''),
            'return_url' => (string)($order->return_url ?? ''),
            'txid' => trim((string)($order->txid ?? $notifyPayload['api_trade_no'] ?? '')),
        ];
    }

    private static function gatewayConfig(string $configKey, array $payload = []): array
    {
        $settings = SettingsService::all(false);
        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
        $config = is_array($payment[$configKey] ?? null) ? $payment[$configKey] : [];

        $provider = self::provider((string)($payload['provider'] ?? $config['provider'] ?? self::PROVIDER_SYSTEM));
        $mode = self::mode((string)($payload['mode'] ?? $config['mode'] ?? self::MODE_V2));
        $paymentUrl = self::normalizeGatewayBaseUrl((string)($config['payment_url'] ?? ''), $mode);
        $merchantId = trim((string)($config['merchant_id'] ?? ''));
        $merchantMd5 = trim((string)($config['merchant_md5'] ?? ''));
        $platformPublicKey = trim((string)($config['platform_public_key'] ?? ''));
        $merchantPrivateKey = trim((string)($config['merchant_private_key'] ?? ''));

        if (
            self::configuredGatewayReady(
                $paymentUrl,
                $merchantId,
                $merchantMd5,
                $platformPublicKey,
                $merchantPrivateKey,
                $mode
            )
        ) {
            return [
                'provider' => $provider,
                'mode' => $mode,
                'appswitch' => self::appswitch((string)($config['appswitch'] ?? ''), $mode),
                'payment_url' => $paymentUrl,
                'merchant_id' => $merchantId,
                'merchant_md5' => $merchantMd5,
                'platform_public_key' => $platformPublicKey,
                'merchant_private_key' => $merchantPrivateKey,
                'source' => 'configured',
            ];
        }

        $requestedMethod = self::requestedMethodCode($payload);
        $amount = (float)($payload['amount'] ?? 0);
        if (self::hasConfiguredCarrierRoute($config, $payload)) {
            $resolved = self::configuredCarrierGatewayConfig($config, $payload, $provider, $mode, $requestedMethod, $amount);
            if ($resolved !== null) {
                return $resolved;
            }

            throw new BusinessException('当前系统业务收款通道配置无效，请检查公共收款商户或通道状态', StatusCode::BUSINESS_ERROR);
        }

        foreach (self::candidateMerchantIds($config, $payload) as $candidateMerchantId) {
            $resolved = self::autoBoundGatewayConfig($candidateMerchantId, $provider, $mode, $requestedMethod, $amount);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if ($configKey === 'system_checkout') {
            foreach (self::candidateMerchantIds($config, $payload) as $candidateMerchantId) {
                $fallback = self::syntheticLocalGatewayConfig($candidateMerchantId);
                if ($fallback !== null) {
                    return $fallback;
                }
            }
        }

        $message = $configKey === 'frontend_test'
            ? '请先为可用商户添加已启用的支付通道'
            : '当前没有可自动绑定的商户支付通道';

        throw new BusinessException($message, StatusCode::BUSINESS_ERROR);
    }

    private static function legacyChannelSnapshot(array $config, array $payload): array
    {
        if (is_array($config['legacy_channel_snapshot'] ?? null)) {
            return $config['legacy_channel_snapshot'];
        }

        $methodCode = PaymentMetaService::normalizeMethodCode((string)($payload['method_code'] ?? $payload['channel_code'] ?? 'alipay'));
        $pluginCode = self::pluginCode($config['provider'], $config['mode']);
        $methodName = PaymentMetaService::friendlyMethodName($methodCode);
        $pluginConfig = [
            'appurl' => $config['payment_url'],
            'appid' => $config['merchant_id'],
            'appkey' => $config['mode'] === self::MODE_V1 ? $config['merchant_md5'] : $config['platform_public_key'],
            'appsecret' => $config['mode'] === self::MODE_V2 ? $config['merchant_private_key'] : '',
            'appswitch' => self::appswitch((string)($config['appswitch'] ?? ''), $config['mode']),
        ];

        return [
            'merchant_channel_id' => 0,
            'merchant_id' => (int)($payload['merchant_id'] ?? 0),
            'channel_type_id' => 0,
            'channel_code' => $methodCode,
            'channel_category' => (int)($payload['channel_category'] ?? 2),
            'payment_address' => '',
            'plugin_code' => $pluginCode,
            'plugin_kind' => 'gateway',
            'config' => [
                'method_code' => $methodCode,
                'method_name' => $methodName,
                'channel_name' => trim((string)($payload['channel_name'] ?? $methodName)),
                'plugin_code' => $pluginCode,
                'plugin_name' => self::pluginName($pluginCode),
                'plugin_kind' => 'gateway',
                'display_value' => '',
                'payment_address' => '',
                'plugin_config' => $pluginConfig,
                'appurl' => $pluginConfig['appurl'],
                'appid' => $pluginConfig['appid'],
                'appkey' => $pluginConfig['appkey'],
                'appsecret' => $pluginConfig['appsecret'],
                'appswitch' => $pluginConfig['appswitch'],
            ],
            'rate' => 0,
            'remark' => '',
        ];
    }

    private static function configuredGatewayReady(
        string $paymentUrl,
        string $merchantId,
        string $merchantMd5,
        string $platformPublicKey,
        string $merchantPrivateKey,
        string $mode
    ): bool {
        if ($paymentUrl === '' || $merchantId === '') {
            return false;
        }

        if ($mode === self::MODE_V1) {
            return $merchantMd5 !== '';
        }

        return $platformPublicKey !== '' && $merchantPrivateKey !== '';
    }

    private static function candidateMerchantIds(array $config, array $payload): array
    {
        $candidates = [];
        $requestPayload = is_array($payload['request_payload'] ?? null) ? $payload['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];

        foreach ([
            (int)($payload['merchant_id'] ?? 0),
            (int)($payload['carrier_merchant_id'] ?? 0),
            (int)($config['carrier_merchant_id'] ?? 0),
            (int)($config['merchant_id'] ?? 0),
            (int)($meta['merchant_id'] ?? 0),
            (int)($meta['carrier_merchant_id'] ?? 0),
        ] as $merchantId) {
            if ($merchantId > 0) {
                $candidates[$merchantId] = $merchantId;
            }
        }

        foreach (ResourceDataService::adminMerchants()['items'] ?? [] as $item) {
            if (!is_array($item) || (int)($item['status_code'] ?? 0) !== 1) {
                continue;
            }

            $merchantId = (int)($item['id'] ?? 0);
            if ($merchantId > 0) {
                $candidates[$merchantId] = $merchantId;
            }
        }

        return array_values($candidates);
    }

    private static function hasConfiguredCarrierRoute(array $config, array $payload): bool
    {
        $carrierChannelCode = PaymentMetaService::normalizeMethodCode((string)(
            $payload['carrier_channel_code']
            ?? $config['carrier_channel_code']
            ?? ''
        ));

        return (int)($payload['carrier_merchant_id'] ?? 0) > 0
            || (int)($config['carrier_merchant_id'] ?? 0) > 0
            || (int)($payload['carrier_channel_id'] ?? 0) > 0
            || (int)($config['carrier_channel_id'] ?? 0) > 0
            || $carrierChannelCode !== '';
    }

    private static function configuredCarrierGatewayConfig(
        array $config,
        array $payload,
        string $provider,
        string $mode,
        string $requestedMethod,
        float $amount
    ): ?array {
        $carrierMerchantId = 0;
        foreach ([
            (int)($payload['carrier_merchant_id'] ?? 0),
            (int)($config['carrier_merchant_id'] ?? 0),
        ] as $candidate) {
            if ($candidate > 0) {
                $carrierMerchantId = $candidate;
                break;
            }
        }

        if ($carrierMerchantId <= 0 || !self::merchantActive($carrierMerchantId)) {
            return null;
        }

        $preferredChannelId = 0;
        foreach ([
            (int)($payload['carrier_channel_id'] ?? 0),
            (int)($config['carrier_channel_id'] ?? 0),
        ] as $candidate) {
            if ($candidate > 0) {
                $preferredChannelId = $candidate;
                break;
            }
        }

        $preferredChannelCode = PaymentMetaService::normalizeMethodCode((string)(
            $payload['carrier_channel_code']
            ?? $config['carrier_channel_code']
            ?? ''
        ));

        $channel = self::resolveMerchantChannelSnapshot(
            $carrierMerchantId,
            $preferredChannelId,
            $requestedMethod,
            $preferredChannelCode,
            $amount
        );
        if ($channel === null) {
            return null;
        }

        $credential = AccountService::merchantCredentialById($carrierMerchantId) ?? [];
        $userId = (int)($credential['id'] ?? $carrierMerchantId);
        $merchantMd5 = MerchantApiService::ensureMd5Key($carrierMerchantId, $userId, (string)($credential['mch_key'] ?? ''));

        return [
            'provider' => $provider,
            'mode' => $mode,
            'appswitch' => self::appswitch((string)($config['appswitch'] ?? ''), $mode),
            'payment_url' => self::paymentUrlForMode(ConfigService::gatewayBaseUrl(), $mode),
            'merchant_id' => (string)$carrierMerchantId,
            'merchant_md5' => $merchantMd5,
            'platform_public_key' => trim((string)ConfigService::platformPublicKey()),
            'merchant_private_key' => trim((string)($credential['rsa_private_key'] ?? '')),
            'source' => 'collector_channel',
            'carrier_merchant_id' => $carrierMerchantId,
            'legacy_channel_snapshot' => $channel,
        ];
    }

    private static function autoBoundGatewayConfig(
        int $merchantId,
        string $provider,
        string $mode,
        string $requestedMethod,
        float $amount
    ): ?array {
        if ($merchantId <= 0 || !self::merchantActive($merchantId)) {
            return null;
        }

        $channel = self::firstAvailableChannelSnapshot($merchantId, $requestedMethod, $amount);
        if ($channel === null) {
            return null;
        }

        $credential = AccountService::merchantCredentialById($merchantId) ?? [];
        $userId = (int)($credential['id'] ?? $merchantId);
        $merchantMd5 = MerchantApiService::ensureMd5Key($merchantId, $userId, (string)($credential['mch_key'] ?? ''));

        return [
            'provider' => $provider,
            'mode' => $mode,
            'appswitch' => self::appswitch((string)($config['appswitch'] ?? ''), $mode),
            'payment_url' => self::paymentUrlForMode(ConfigService::gatewayBaseUrl(), $mode),
            'merchant_id' => (string)$merchantId,
            'merchant_md5' => $merchantMd5,
            'platform_public_key' => trim((string)ConfigService::platformPublicKey()),
            'merchant_private_key' => trim((string)($credential['rsa_private_key'] ?? '')),
            'source' => 'merchant_channel',
            'carrier_merchant_id' => $merchantId,
            'legacy_channel_snapshot' => $channel,
        ];
    }

    private static function syntheticLocalGatewayConfig(int $merchantId): ?array
    {
        if ($merchantId <= 0 || !self::merchantActiveOrPending($merchantId)) {
            return null;
        }

        $credential = AccountService::merchantCredentialById($merchantId) ?? [];
        $userId = (int)($credential['id'] ?? $merchantId);
        $merchantMd5 = MerchantApiService::ensureMd5Key($merchantId, $userId, (string)($credential['mch_key'] ?? ''));
        if ($merchantMd5 === '') {
            return null;
        }

        return [
            'provider' => self::PROVIDER_EPAY,
            'mode' => self::MODE_V1,
            'appswitch' => '0',
            'payment_url' => self::paymentUrlForMode(ConfigService::gatewayBaseUrl(), self::MODE_V1),
            'merchant_id' => (string)$merchantId,
            'merchant_md5' => $merchantMd5,
            'platform_public_key' => trim((string)ConfigService::platformPublicKey()),
            'merchant_private_key' => trim((string)($credential['rsa_private_key'] ?? '')),
            'source' => 'synthetic_local',
            'carrier_merchant_id' => $merchantId,
        ];
    }

    private static function merchantActive(int $merchantId): bool
    {
        $credential = AccountService::merchantCredentialById($merchantId);
        if (!is_array($credential)) {
            return false;
        }

        return (int)($credential['status'] ?? 0) === 1;
    }

    private static function merchantActiveOrPending(int $merchantId): bool
    {
        $credential = AccountService::merchantCredentialById($merchantId);
        if (!is_array($credential)) {
            return false;
        }

        return in_array((int)($credential['status'] ?? 0), [0, 1], true);
    }

    private static function firstAvailableChannelSnapshot(int $merchantId, string $requestedMethod, float $amount): ?array
    {
        return self::resolveMerchantChannelSnapshot($merchantId, 0, $requestedMethod, '', $amount);
    }

    private static function resolveMerchantChannelSnapshot(
        int $merchantId,
        int $preferredChannelId,
        string $requestedMethod,
        string $preferredChannelCode,
        float $amount
    ): ?array
    {
        $payload = MerchantChannelService::all($merchantId);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $methodStatusMap = self::statusCodeMap(is_array($payload['methods'] ?? null) ? $payload['methods'] : []);
        $pluginStatusMap = self::statusCodeMap(is_array($payload['plugins'] ?? null) ? $payload['plugins'] : []);
        $acceptedMethods = self::acceptedMethodCodes($requestedMethod);
        $preferredMethods = self::acceptedMethodCodes($preferredChannelCode);

        foreach ($items as $item) {
            if (!is_array($item) || (int)($item['status_code'] ?? 0) !== 1) {
                continue;
            }

            if ($preferredChannelId > 0 && (int)($item['id'] ?? 0) !== $preferredChannelId) {
                continue;
            }

            $methodCode = PaymentMetaService::normalizeMethodCode((string)($item['method_code'] ?? ''));
            if ($methodCode === '') {
                continue;
            }

            if ($acceptedMethods !== [] && !in_array($methodCode, $acceptedMethods, true)) {
                continue;
            }

            if ($preferredMethods !== [] && !in_array($methodCode, $preferredMethods, true)) {
                continue;
            }

            if (isset($methodStatusMap[$methodCode]) && $methodStatusMap[$methodCode] !== 1) {
                continue;
            }

            $pluginCode = PluginCodeService::normalize((string)($item['plugin_code'] ?? ''));
            if ($pluginCode !== '' && isset($pluginStatusMap[$pluginCode]) && $pluginStatusMap[$pluginCode] !== 1) {
                continue;
            }

            if (!self::amountWithinChannelLimits($item, $amount)) {
                continue;
            }

            $config = is_array($item['config'] ?? null) ? $item['config'] : [];

            return [
                'merchant_channel_id' => (int)($item['id'] ?? 0),
                'merchant_id' => $merchantId,
                'channel_type_id' => 0,
                'channel_code' => $methodCode,
                'channel_category' => PaymentMetaService::normalizeCategory((string)($item['category'] ?? ''), $methodCode),
                'config' => $config,
                'rate' => (float)str_replace('%', '', (string)($item['rate'] ?? '0')),
                'remark' => (string)($item['remark'] ?? ''),
            ];
        }

        return null;
    }

    private static function acceptedMethodCodes(string $methodCode): array
    {
        $normalized = PaymentMetaService::normalizeMethodCode($methodCode);
        if ($normalized === '') {
            return [];
        }

        $accepted = PaymentMetaService::acceptedMethodCodes($normalized);
        if ($accepted === []) {
            $accepted = [$normalized];
        }

        $accepted = array_map(
            static fn(string $code): string => PaymentMetaService::normalizeMethodCode($code),
            $accepted
        );

        return array_values(array_unique(array_filter($accepted, static fn(string $code): bool => $code !== '')));
    }

    private static function amountWithinChannelLimits(array $item, float $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }

        $min = (float)($item['single_min_amount'] ?? 0);
        if ($min > 0 && $amount < $min) {
            return false;
        }

        $max = (float)($item['single_max_amount'] ?? 0);
        if ($max > 0 && $amount > $max) {
            return false;
        }

        return true;
    }

    private static function statusCodeMap(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code = trim((string)($item['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $normalized = PaymentMetaService::normalizeMethodCode($code);
            if ($normalized === '') {
                $normalized = PluginCodeService::normalize($code);
            }

            if ($normalized === '') {
                continue;
            }

            $map[$normalized] = (int)($item['status_code'] ?? 0) === 1 ? 1 : 0;
        }

        return $map;
    }

    private static function requestedMethodCode(array $payload): string
    {
        $requestPayload = is_array($payload['request_payload'] ?? null) ? $payload['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];

        return PaymentMetaService::normalizeMethodCode((string)(
            $payload['channel_code']
            ?? $payload['method_code']
            ?? $payload['method']
            ?? $payload['type']
            ?? $meta['requested_method']
            ?? ''
        ));
    }

    private static function paymentUrlForMode(string $siteUrl, string $mode): string
    {
        $baseUrl = rtrim(trim($siteUrl), '/');

        return self::mode($mode) === self::MODE_V1
            ? $baseUrl . '/mapi.php'
            : $baseUrl . '/api/pay/create';
    }

    private static function pluginCode(string $provider, string $mode): string
    {
        if ($provider === self::PROVIDER_EPAY || $provider === self::PROVIDER_SYSTEM) {
            return $mode === self::MODE_V1 ? 'epay' : 'epayn';
        }

        return $mode === self::MODE_V1 ? 'epay' : 'epayn';
    }

    private static function pluginName(string $pluginCode): string
    {
        return $pluginCode === 'epay' ? '易支付 V1' : '易支付 V2';
    }

    private static function normalizeGatewayBaseUrl(string $value, string $mode): string
    {
        $url = rtrim(trim($value), '/');
        if ($url === '') {
            return '';
        }

        $suffixes = $mode === self::MODE_V1
            ? ['/submit.php', '/mapi.php', '/api.php']
            : ['/api/pay/create', '/api/pay/query', '/api/pay/refund', '/api/pay/refundquery', '/api/pay/close'];

        $lowerUrl = strtolower($url);
        foreach ($suffixes as $suffix) {
            if (str_ends_with($lowerUrl, $suffix)) {
                return substr($url, 0, -strlen($suffix));
            }
        }

        return $url;
    }

    private static function provider(string $value): string
    {
        return trim(strtolower($value)) === self::PROVIDER_EPAY ? self::PROVIDER_EPAY : self::PROVIDER_SYSTEM;
    }

    private static function mode(string $value): string
    {
        return trim(strtolower($value)) === self::MODE_V1 ? self::MODE_V1 : self::MODE_V2;
    }

    private static function appswitch(string $value, string $mode): string
    {
        $normalized = trim($value);

        if ($normalized === '0' || $normalized === '1') {
            return $normalized;
        }

        return self::mode($mode) === self::MODE_V1 ? '0' : '1';
    }

    private static function tradeNo(): string
    {
        return date('YmdHis') . random_int(100000, 999999);
    }

    private static function outTradeNo(string $value, string $tradeNo): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9._-]+/', '', trim($value));
        if (is_string($cleaned) && $cleaned !== '') {
            return substr($cleaned, 0, 64);
        }

        return $tradeNo;
    }
}
