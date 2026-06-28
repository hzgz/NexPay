<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\LegacyPaymentGatewayService;
use app\service\payment\OrderStatusService;
use app\service\payment\OrderService;
use app\service\system\MerchantAuthService;
use app\service\system\PackageService;
use Throwable;

class SystemBusinessPaymentService
{
    private const PROVIDER_SYSTEM = 'system';
    private const PROVIDER_EPAY = 'epay';
    private const MODE_V1 = 'v1';
    private const MODE_V2 = 'v2';
    private const MIN_AMOUNT_DEFAULT = '0.10';
    private const IDEMPOTENT_BUSINESSES = [
        'merchant_recharge',
        'merchant_package_purchase',
        'merchant_register_fee',
        'homepage_payment_test',
        'channel_test',
    ];

    private const FUND_BUSINESSES = [
        'merchant_recharge',
        'merchant_package_purchase',
        'merchant_register_fee',
    ];

    private const TEST_SYNC_BUSINESSES = [
        'homepage_payment_test',
        'channel_test',
    ];

    private const FUND_BUSINESS_REF_TYPES = [
        'merchant_recharge' => 'recharge',
        'merchant_register_fee' => 'register_fee',
        'merchant_package_purchase' => 'package_purchase',
    ];

    private const FUND_ORDER_TYPE_LABELS = [
        'merchant_recharge' => '在线充值订单',
        'merchant_register_fee' => '商户注册收费订单',
        'merchant_package_purchase' => '套餐购买订单',
    ];

    private const BUSINESS_META = [
        'merchant_recharge' => [
            'type' => '商户充值订单',
            'source' => '在线充值',
        ],
        'merchant_package_purchase' => [
            'type' => '套餐购买订单',
            'source' => '套餐购买',
        ],
        'merchant_register_fee' => [
            'type' => '商户注册收费订单',
            'source' => '注册收费',
        ],
        'homepage_payment_test' => [
            'type' => '首页测试订单',
            'source' => '首页测试',
        ],
        'channel_test' => [
            'type' => '通道测试订单',
            'source' => '通道测试',
        ],
    ];

    public static function create(string $configKey, array $payload): object
    {
        $config = self::gatewayConfig($configKey, $payload);
        return self::createResolvedOrder($config, $payload);
    }

    public static function createBusinessOrder(
        string $configKey,
        int $merchantId,
        string $business,
        array $payload,
        array $extraMeta = []
    ): object {
        return self::create($configKey, self::composeBusinessCreatePayload(
            $merchantId,
            $business,
            $payload,
            $extraMeta
        ));
    }

    public static function createBusinessOrderFromLegacyChannelSnapshot(
        array $snapshot,
        int $merchantId,
        string $business,
        array $payload,
        array $extraMeta = []
    ): object {
        $resolvedMerchantId = $merchantId > 0 ? $merchantId : (int)($snapshot['merchant_id'] ?? 0);
        if ($resolvedMerchantId <= 0) {
            throw new BusinessException('业务订单缺少商户信息', StatusCode::VALIDATION_ERROR);
        }

        return self::createFromLegacyChannelSnapshot($snapshot, self::composeBusinessCreatePayload(
            $resolvedMerchantId,
            $business,
            $payload,
            $extraMeta
        ));
    }

    public static function createFromLegacyChannelSnapshot(array $snapshot, array $payload): object
    {
        $merchantId = (int)($payload['merchant_id'] ?? $snapshot['merchant_id'] ?? 0);
        if ($merchantId <= 0) {
            throw new BusinessException('业务订单缺少商户信息', StatusCode::VALIDATION_ERROR);
        }

        $payload['merchant_id'] = $merchantId;

        return self::createResolvedOrder([
            'provider' => self::PROVIDER_SYSTEM,
            'mode' => self::MODE_V2,
            'source_protocol' => trim((string)($payload['source_protocol'] ?? 'channel_test')) ?: 'channel_test',
            'source' => trim((string)($payload['gateway_source'] ?? 'merchant_channel_snapshot')) ?: 'merchant_channel_snapshot',
            'min_amount' => self::normalizeMinimumAmount((string)($payload['min_amount'] ?? self::MIN_AMOUNT_DEFAULT)),
            'carrier_merchant_id' => (int)($snapshot['merchant_id'] ?? 0),
            'legacy_channel_snapshot' => $snapshot,
        ], $payload);
    }

    public static function composeBusinessCreatePayload(
        int $merchantId,
        string $business,
        array $payload,
        array $extraMeta = []
    ): array {
        $requestPayload = is_array($payload['request_payload'] ?? null) ? $payload['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $requestedMethod = trim((string)(
            $payload['channel_code']
            ?? $payload['type']
            ?? $payload['method_code']
            ?? $extraMeta['requested_method']
            ?? $meta['requested_method']
            ?? ''
        ));

        $mergedMeta = array_replace($meta, $extraMeta);
        $mergedMeta['business'] = trim((string)($mergedMeta['business'] ?? $business)) ?: $business;
        $mergedMeta['merchant_id'] = $merchantId;
        if ($requestedMethod !== '') {
            $mergedMeta['requested_method'] = $requestedMethod;
        }

        $requestPayload['_meta'] = $mergedMeta;

        $mergedPayload = array_replace([
            'merchant_id' => $merchantId,
            'channel_category' => 2,
            'notify_url' => '',
        ], $payload);
        $mergedPayload['merchant_id'] = $merchantId;
        $mergedPayload['channel_category'] = (int)($mergedPayload['channel_category'] ?? 2);
        $mergedPayload['notify_url'] = trim((string)($mergedPayload['notify_url'] ?? ''));
        $mergedPayload['request_payload'] = $requestPayload;

        return $mergedPayload;
    }

    public static function fallbackBusinessOutTradeNo(string $prefix, int $merchantId, array $segments = []): string
    {
        $parts = [strtoupper(trim($prefix)), date('YmdHis'), (string)$merchantId];
        foreach ($segments as $segment) {
            $clean = OrderService::normalizeGatewayOutTradeNo((string)$segment);
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }

        $parts[] = (string)random_int(100000, 999999);

        return OrderService::normalizeGatewayOutTradeNo(implode('-', $parts));
    }

    private static function createResolvedOrder(array $config, array $payload): object
    {
        $normalized = OrderService::normalizeGatewayCreateInput($payload, [
            'subject' => '系统业务订单',
        ]);

        $merchantId = (int)($normalized['merchant_id'] ?? 0);
        if ($merchantId <= 0) {
            throw new BusinessException('业务订单缺少商户信息', StatusCode::VALIDATION_ERROR);
        }

        $amount = number_format((float)($normalized['amount'] ?? 0), 2, '.', '');
        OrderService::assertPositiveOrderAmount($amount);
        self::assertMinimumAmount($amount, (string)($config['min_amount'] ?? self::MIN_AMOUNT_DEFAULT));

        $tradeNo = self::resolvedTradeNo((string)($normalized['trade_no'] ?? ''));
        $createdAt = date('Y-m-d H:i:s');
        $returnUrl = trim((string)($normalized['return_url'] ?? ''));
        $requestPayload = is_array($normalized['request_payload'] ?? null) ? $normalized['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $sourceProtocol = strtolower(trim((string)($normalized['source_protocol'] ?? $meta['source_protocol'] ?? $config['source_protocol'] ?? $config['mode'] ?? '')));
        if ($sourceProtocol !== '') {
            $meta['source_protocol'] = $sourceProtocol;
        }
        $gatewaySource = trim((string)($normalized['gateway_source'] ?? $config['source'] ?? 'configured')) ?: 'configured';
        $meta['gateway_source'] = $gatewaySource;
        $meta['order_origin'] = trim((string)($normalized['order_origin'] ?? $meta['order_origin'] ?? 'system_business')) ?: 'system_business';
        $meta['order_scene'] = trim((string)($normalized['order_scene'] ?? $meta['order_scene'] ?? $meta['business'] ?? 'system_business')) ?: 'system_business';
        $meta['business'] = trim((string)($normalized['business'] ?? $meta['business'] ?? 'system_business')) ?: 'system_business';
        $meta['config_key'] = trim((string)($normalized['config_key'] ?? $meta['config_key'] ?? ($config['config_key'] ?? '')));
        $meta['requested_provider'] = trim((string)($normalized['requested_provider'] ?? $meta['requested_provider'] ?? ($normalized['provider'] ?? '')));
        $meta['gateway_provider'] = trim((string)($normalized['gateway_provider'] ?? $meta['gateway_provider'] ?? ($config['provider'] ?? '')));
        $meta['gateway_mode'] = trim((string)($normalized['gateway_mode'] ?? $meta['gateway_mode'] ?? ($config['mode'] ?? '')));
        $meta['route_type'] = trim((string)($normalized['route_type'] ?? $meta['route_type'] ?? self::routeType($config)));
        $requestedMethod = trim((string)($normalized['channel_code'] ?? $normalized['type'] ?? $meta['requested_method'] ?? ''));
        if ($requestedMethod !== '') {
            $meta['requested_method'] = $requestedMethod;
        }
        $credential = AccountService::merchantCredentialById($merchantId) ?? [];
        $merchantName = trim((string)($normalized['merchant_name'] ?? $credential['merchant_name'] ?? $credential['nickname'] ?? $credential['username'] ?? ''));
        if ($merchantName !== '') {
            $meta['merchant_name'] = $merchantName;
        }
        $meta['merchant_pid'] = trim((string)($normalized['merchant_pid'] ?? $credential['id'] ?? $merchantId));
        $meta['merchant_id'] = $merchantId;
        $carrierMerchantId = (int)($normalized['carrier_merchant_id'] ?? $config['carrier_merchant_id'] ?? 0);
        if ($carrierMerchantId > 0) {
            $meta['carrier_merchant_id'] = $carrierMerchantId;
        }
        $localMerchantId = (int)($normalized['local_merchant_id'] ?? $config['local_merchant_id'] ?? 0);
        if ($localMerchantId > 0) {
            $meta['local_merchant_id'] = $localMerchantId;
        }

        $outTradeNo = self::outTradeNo((string)($normalized['out_trade_no'] ?? ''), '');
        if ($outTradeNo === '') {
            $outTradeNo = self::resolvedTradeNo((string)($normalized['trade_no'] ?? ''));
        }

        $existing = self::findExistingBusinessOrder($merchantId, $outTradeNo, (string)($meta['business'] ?? ''));
        if ($existing !== null) {
            if ((int)($existing->status ?? OrderService::STATUS_PENDING) === OrderService::STATUS_SUCCESS) {
                return OrderService::completeOrder($existing, [
                    'source' => 'system-business-idempotent-reuse',
                ]);
            }

            return $existing;
        }

        $legacyChannel = self::legacyChannelSnapshot($config, $normalized);
        $creationContext = OrderService::buildOrderCreationContext([
            'request_payload' => $requestPayload,
            'source_protocol' => $sourceProtocol,
            'business' => (string)($meta['business'] ?? 'system_business'),
            'gateway_source' => $gatewaySource,
            'order_origin' => (string)($meta['order_origin'] ?? 'system_business'),
            'order_scene' => (string)($meta['order_scene'] ?? 'system_business'),
            'requested_provider' => (string)($meta['requested_provider'] ?? ''),
            'gateway_provider' => (string)($meta['gateway_provider'] ?? ''),
            'gateway_mode' => (string)($meta['gateway_mode'] ?? ''),
            'config_key' => (string)($meta['config_key'] ?? ''),
            'route_type' => (string)($meta['route_type'] ?? ''),
            'merchant_name' => $merchantName,
            'merchant_pid' => (string)($meta['merchant_pid'] ?? $merchantId),
            'merchant_id' => $merchantId,
            'merchant_channel_id' => (int)($normalized['merchant_channel_id'] ?? $legacyChannel['merchant_channel_id'] ?? 0),
            'carrier_merchant_id' => $carrierMerchantId,
            'local_merchant_id' => $localMerchantId,
            'type' => $requestedMethod,
            'legacy_channel_snapshot' => $legacyChannel,
        ], [
            'scene' => 'system_business',
            'business' => 'system_business',
            'order_origin' => 'system_business',
            'order_scene' => 'system_business',
        ]);
        $requestPayload = $creationContext['request_payload'] ?? [];
        $orderPayload = OrderService::buildPendingOrderPayload([
            'trade_no' => $tradeNo,
            'out_trade_no' => $outTradeNo !== '' ? $outTradeNo : $tradeNo,
            'merchant_id' => $merchantId,
            'merchant_channel_id' => (int)($normalized['merchant_channel_id'] ?? $legacyChannel['merchant_channel_id'] ?? 0),
            'channel_code' => (string)($normalized['channel_code'] ?? $legacyChannel['channel_code'] ?? ''),
            'channel_category' => (int)($normalized['channel_category'] ?? $legacyChannel['channel_category'] ?? 2),
            'subject' => trim((string)($normalized['subject'] ?? '系统业务订单')) ?: '系统业务订单',
            'amount' => $amount,
            'status' => OrderService::STATUS_PENDING,
            'notify_url' => trim((string)($normalized['notify_url'] ?? '')),
            'return_url' => $returnUrl,
            'client_ip' => trim((string)($normalized['client_ip'] ?? '')),
            'param' => trim((string)($normalized['param'] ?? 'business-payment')),
            'expire_time' => trim((string)($normalized['expire_time'] ?? date('Y-m-d H:i:s', time() + OrderService::DEFAULT_EXPIRE_SECONDS))),
            'request_payload' => $requestPayload,
            'notify_payload' => [],
            'legacy_channel_snapshot' => $legacyChannel,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return self::persistOrder($orderPayload);
    }

    public static function inspectContext(string $configKey, array $payload = []): array
    {
        try {
            $context = self::gatewayConfig($configKey, $payload);
            $amount = number_format((float)($payload['amount'] ?? 0), 2, '.', '');
            if ((float)$amount > 0) {
                self::assertMinimumAmount($amount, (string)($context['min_amount'] ?? self::MIN_AMOUNT_DEFAULT));
            }

            return $context + ['ok' => true];
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

    public static function resolveFrontendTestLocalMerchantId(array $payload = []): int
    {
        $settings = SettingsService::all(false);
        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
        $config = is_array($payment['frontend_test'] ?? null) ? $payment['frontend_test'] : [];

        return self::resolveConfiguredLocalMerchantId(
            $config,
            $payload,
            self::requestedMethodCode($payload),
            (float)($payload['amount'] ?? 0)
        );
    }

    public static function submitUrl(string $tradeNo): string
    {
        return LegacyPaymentGatewayService::entryUrl($tradeNo, 'submit');
    }

    public static function checkoutUrl(string $tradeNo): string
    {
        return rtrim((string)ConfigService::gatewayBaseUrl(), '/') . '/pay/checkout/' . rawurlencode($tradeNo);
    }

    public static function isBusinessIdempotent(string $business): bool
    {
        return in_array(strtolower(trim($business)), self::IDEMPOTENT_BUSINESSES, true);
    }

    public static function isFundBusiness(string $business): bool
    {
        return in_array(strtolower(trim($business)), self::FUND_BUSINESSES, true);
    }

    public static function isTestSyncBusiness(string $business): bool
    {
        return in_array(strtolower(trim($business)), self::TEST_SYNC_BUSINESSES, true);
    }

    public static function fundRefType(string $business): string
    {
        return self::FUND_BUSINESS_REF_TYPES[strtolower(trim($business))] ?? '';
    }

    public static function businessByFundRefType(string $refType): string
    {
        $normalized = strtolower(trim($refType));
        foreach (self::FUND_BUSINESS_REF_TYPES as $business => $mappedRefType) {
            if ($mappedRefType === $normalized) {
                return $business;
            }
        }

        return '';
    }

    public static function fundOrderTypeLabel(string $business): string
    {
        return self::FUND_ORDER_TYPE_LABELS[strtolower(trim($business))] ?? '系统订单';
    }

    public static function businessMeta(string $business): array
    {
        return self::BUSINESS_META[strtolower(trim($business))] ?? [];
    }

    public static function businessSourceLabel(string $business): string
    {
        return (string)(self::businessMeta($business)['source'] ?? '');
    }

    public static function businessTypeLabel(string $business): string
    {
        return (string)(self::businessMeta($business)['type'] ?? '');
    }

    public static function paymentResult(string $tradeNo): array
    {
        $order = OrderService::findByTradeNoForRead($tradeNo, [
            'source' => 'system-business-payment-result-read',
        ]);
        $statusInfo = OrderStatusService::forCheckout($order);
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $status = (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING);

        return [
            'trade_no' => (string)($order->trade_no ?? ''),
            'out_trade_no' => (string)($order->out_trade_no ?? ''),
            'subject' => (string)($order->subject ?? ''),
            'amount' => number_format((float)($order->amount ?? 0), 2, '.', ''),
            'status' => $status,
            'status_key' => (string)($statusInfo['key'] ?? 'pending'),
            'status_text' => (string)($statusInfo['label'] ?? '等待支付'),
            'pay_time' => (string)($order->pay_time ?? ''),
            'expire_time' => (string)($order->expire_time ?? ''),
            'channel_code' => (string)($order->channel_code ?? ''),
            'business' => (string)($meta['business'] ?? ''),
            'return_url' => (string)($order->return_url ?? ''),
            'txid' => trim((string)($order->txid ?? $notifyPayload['api_trade_no'] ?? '')),
        ];
    }

    public static function completeBusinessOrder(object $order): void
    {
        $business = self::businessKeyFromOrder($order);
        if ($business === '') {
            return;
        }

        if ($business === 'merchant_register_fee') {
            MerchantAuthService::completeRegistrationPayment($order);
            return;
        }

        if ($business === 'merchant_package_purchase') {
            PackageService::completePurchasePayment($order);
        }
    }

    private static function persistOrder(array $orderPayload): object
    {
        $requestPayload = is_array($orderPayload['request_payload'] ?? null) ? $orderPayload['request_payload'] : [];

        return OrderService::persistCreatedOrder(
            $orderPayload,
            OrderService::buildOrderCreationEventMeta($requestPayload, [
                'scene' => 'system_business',
                'business' => 'system_business',
                'order_origin' => 'system_business',
                'order_scene' => 'system_business',
            ])
        );
    }

    public static function businessKeyFromOrder(object|array $order): string
    {
        $requestPayload = is_array($order)
            ? (is_array($order['request_payload'] ?? null) ? $order['request_payload'] : [])
            : (is_array($order->request_payload ?? null) ? $order->request_payload : []);
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];

        return strtolower(trim((string)($meta['business'] ?? '')));
    }

    private static function resolvedTradeNo(string $candidate): string
    {
        $tradeNo = preg_replace('/[^A-Za-z0-9_-]+/', '', trim($candidate));
        if (is_string($tradeNo) && $tradeNo !== '') {
            return substr($tradeNo, 0, 64);
        }

        return OrderService::nextTradeNo();
    }

    private static function gatewayConfig(string $configKey, array $payload = []): array
    {
        $settings = SettingsService::all(false);
        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
        $config = is_array($payment[$configKey] ?? null) ? $payment[$configKey] : [];
        if ($configKey === 'system_checkout') {
            $config = self::inheritSystemCheckoutGatewayConfig($config, $payment);
        }

        $provider = self::provider((string)($payload['provider'] ?? $config['provider'] ?? self::PROVIDER_SYSTEM));
        $mode = self::mode((string)($payload['mode'] ?? $config['mode'] ?? self::MODE_V2));
        $minAmount = self::normalizeMinimumAmount((string)($config['min_amount'] ?? self::MIN_AMOUNT_DEFAULT));
        $paymentUrl = self::normalizeGatewayBaseUrl((string)($config['payment_url'] ?? ''), $mode);
        $merchantId = trim((string)($config['merchant_id'] ?? ''));
        $merchantMd5 = trim((string)($config['merchant_md5'] ?? ''));
        $platformPublicKey = trim((string)($config['platform_public_key'] ?? ''));
        $merchantPrivateKey = trim((string)($config['merchant_private_key'] ?? ''));
        $requestedMethod = self::requestedMethodCode($payload);
        $amount = (float)($payload['amount'] ?? 0);

        if ($configKey === 'system_checkout') {
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
                return self::configuredGatewayConfig(
                    $config,
                    $provider,
                    $mode,
                    $minAmount,
                    $paymentUrl,
                    $merchantId,
                    $merchantMd5,
                    $platformPublicKey,
                    $merchantPrivateKey
                );
            }

            if (self::hasConfiguredCarrierRoute($config, $payload)) {
                $resolved = self::configuredCarrierGatewayConfig(
                    $config,
                    $payload,
                    $provider,
                    $mode,
                    $requestedMethod,
                    $amount,
                    $minAmount
                );
                if ($resolved !== null) {
                    return $resolved;
                }

                throw new BusinessException('当前系统业务支付配置无效，请检查后台支付参数或指定的后台通道', StatusCode::BUSINESS_ERROR);
            }

            throw new BusinessException('请先在后台系统业务支付配置中填写可用支付参数或指定后台通道', StatusCode::BUSINESS_ERROR);
        }

        if (
            $configKey === 'frontend_test'
            && self::configuredGatewayReady(
                $paymentUrl,
                $merchantId,
                $merchantMd5,
                $platformPublicKey,
                $merchantPrivateKey,
                $mode
            )
        ) {
            $resolved = self::configuredGatewayConfig(
                $config,
                $provider,
                $mode,
                $minAmount,
                $paymentUrl,
                $merchantId,
                $merchantMd5,
                $platformPublicKey,
                $merchantPrivateKey
            );

            $resolved['local_merchant_id'] = self::resolveConfiguredLocalMerchantId($config, $payload, $requestedMethod, $amount);
            if ((int)($resolved['local_merchant_id'] ?? 0) <= 0) {
                throw new BusinessException('首页测试支付缺少可用的本地商户，请先启用至少一个本地商户。', StatusCode::BUSINESS_ERROR);
            }

            return $resolved;
        }

        if (self::configuredGatewayExplicitlyRequested($config, $payload)) {
            if (
                !self::configuredGatewayReady(
                    $paymentUrl,
                    $merchantId,
                    $merchantMd5,
                    $platformPublicKey,
                    $merchantPrivateKey,
                    $mode
                )
            ) {
                throw new BusinessException('系统业务支付上游接口配置不完整，请检查支付 URL、商户号和密钥', StatusCode::BUSINESS_ERROR);
            }

            $resolved = self::configuredGatewayConfig(
                $config,
                $provider,
                $mode,
                $minAmount,
                $paymentUrl,
                $merchantId,
                $merchantMd5,
                $platformPublicKey,
                $merchantPrivateKey
            );

            if ($configKey === 'frontend_test') {
                $resolved['local_merchant_id'] = self::resolveConfiguredLocalMerchantId($config, $payload, $requestedMethod, $amount);
                if ((int)($resolved['local_merchant_id'] ?? 0) <= 0) {
                    throw new BusinessException('首页测试支付缺少可用的本地商户，请先启用至少一个本地商户。', StatusCode::BUSINESS_ERROR);
                }
            }

            return $resolved;
        }

        if (self::hasConfiguredCarrierRoute($config, $payload)) {
            $resolved = self::configuredCarrierGatewayConfig($config, $payload, $provider, $mode, $requestedMethod, $amount, $minAmount);
            if ($resolved !== null) {
                return $resolved;
            }

            throw new BusinessException('当前系统业务收款通道配置无效，请检查公共收款商户或通道状态', StatusCode::BUSINESS_ERROR);
        }

        foreach (self::candidateMerchantIds($payload, $config) as $candidateMerchantId) {
            $resolved = self::autoBoundGatewayConfig($candidateMerchantId, $provider, $mode, $requestedMethod, $amount, $minAmount);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $message = $configKey === 'frontend_test'
            ? '请先为可用商户添加已启用的支付通道'
            : '当前没有可自动绑定的商户支付通道';

        throw new BusinessException($message, StatusCode::BUSINESS_ERROR);
    }

    private static function inheritSystemCheckoutGatewayConfig(array $config, array $payment): array
    {
        if (self::configuredGatewayReadyFromConfig($config) || self::hasConfiguredCarrierRoute($config, [])) {
            return $config;
        }

        $frontend = is_array($payment['frontend_test'] ?? null) ? $payment['frontend_test'] : [];
        if (!self::configuredGatewayReadyFromConfig($frontend)) {
            return $config;
        }

        foreach ([
            'provider',
            'mode',
            'appswitch',
            'payment_url',
            'merchant_id',
            'merchant_md5',
            'platform_public_key',
            'merchant_private_key',
        ] as $field) {
            $config[$field] = $frontend[$field] ?? ($config[$field] ?? '');
        }

        return $config;
    }

    private static function configuredGatewayConfig(
        array $config,
        string $provider,
        string $mode,
        string $minAmount,
        string $paymentUrl,
        string $merchantId,
        string $merchantMd5,
        string $platformPublicKey,
        string $merchantPrivateKey
    ): array {
        return [
            'provider' => $provider,
            'mode' => $mode,
            'appswitch' => self::appswitch((string)($config['appswitch'] ?? ''), $mode),
            'min_amount' => $minAmount,
            'payment_url' => $paymentUrl,
            'merchant_id' => $merchantId,
            'merchant_md5' => $merchantMd5,
            'platform_public_key' => $platformPublicKey,
            'merchant_private_key' => $merchantPrivateKey,
            'carrier_merchant_id' => trim((string)($config['carrier_merchant_id'] ?? '')),
            'carrier_channel_id' => trim((string)($config['carrier_channel_id'] ?? '')),
            'carrier_channel_code' => PaymentMetaService::normalizeMethodCode((string)($config['carrier_channel_code'] ?? '')),
            'source' => 'configured',
        ];
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
            'merchant_id' => trim((string)($config['carrier_merchant_id'] ?? '')),
            'channel_id' => trim((string)($config['carrier_channel_id'] ?? '')),
            'carrier_channel_code' => trim((string)($config['carrier_channel_code'] ?? '')),
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

    private static function configuredGatewayReadyFromConfig(array $config): bool
    {
        $mode = self::mode((string)($config['mode'] ?? self::MODE_V2));

        return self::configuredGatewayReady(
            self::normalizeGatewayBaseUrl((string)($config['payment_url'] ?? ''), $mode),
            trim((string)($config['merchant_id'] ?? '')),
            trim((string)($config['merchant_md5'] ?? '')),
            trim((string)($config['platform_public_key'] ?? '')),
            trim((string)($config['merchant_private_key'] ?? '')),
            $mode
        );
    }

    private static function configuredGatewayExplicitlyRequested(array $config, array $payload): bool
    {
        foreach ([
            $payload['use_configured_gateway'] ?? null,
            $payload['force_configured_gateway'] ?? null,
            $config['use_configured_gateway'] ?? null,
            $config['force_configured_gateway'] ?? null,
        ] as $value) {
            if (self::truthy($value)) {
                return true;
            }
        }

        return false;
    }

    private static function candidateMerchantIds(array $payload, array $config = []): array
    {
        $candidates = [];
        $requestPayload = is_array($payload['request_payload'] ?? null) ? $payload['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];

        foreach ([
            (int)($payload['merchant_id'] ?? 0),
            (int)($payload['carrier_merchant_id'] ?? 0),
            (int)($config['carrier_merchant_id'] ?? 0),
            (int)($meta['merchant_id'] ?? 0),
            (int)($meta['carrier_merchant_id'] ?? 0),
        ] as $merchantId) {
            if ($merchantId > 0) {
                $candidates[$merchantId] = $merchantId;
            }
        }

        return array_values($candidates);
    }

    private static function resolveConfiguredLocalMerchantId(
        array $config,
        array $payload,
        string $requestedMethod,
        float $amount
    ): int {
        $requestPayload = is_array($payload['request_payload'] ?? null) ? $payload['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];

        foreach ([
            (int)($payload['carrier_merchant_id'] ?? 0),
            (int)($config['carrier_merchant_id'] ?? 0),
            (int)($meta['carrier_merchant_id'] ?? 0),
            (int)($payload['local_merchant_id'] ?? 0),
            (int)($meta['local_merchant_id'] ?? 0),
            (int)($payload['merchant_id'] ?? 0),
            (int)($meta['merchant_id'] ?? 0),
        ] as $candidate) {
            if ($candidate > 0 && self::merchantActive($candidate)) {
                return $candidate;
            }
        }

        foreach (self::availableLocalMerchantIds() as $merchantId) {
            if (!self::merchantActive($merchantId)) {
                continue;
            }

            if (self::firstAvailableChannelSnapshot($merchantId, $requestedMethod, $amount) !== null) {
                return $merchantId;
            }
        }

        foreach (self::availableLocalMerchantIds() as $merchantId) {
            if (!self::merchantActive($merchantId)) {
                continue;
            }

            if (self::firstAvailableChannelSnapshot($merchantId, '', $amount) !== null) {
                return $merchantId;
            }
        }

        foreach (self::availableLocalMerchantIds() as $merchantId) {
            if (self::merchantActive($merchantId)) {
                return $merchantId;
            }
        }

        return 0;
    }

    private static function availableLocalMerchantIds(): array
    {
        $ids = [];

        foreach (JsonStoreService::load('merchant_channels', []) as $row) {
            if (is_array($row) && (int)($row['merchant_id'] ?? 0) > 0) {
                $ids[] = (int)$row['merchant_id'];
            }
        }

        foreach (JsonStoreService::load('merchant_auth_users', []) as $row) {
            if (is_array($row)) {
                $merchantId = (int)($row['merchant_id'] ?? $row['id'] ?? 0);
                if ($merchantId > 0) {
                    $ids[] = $merchantId;
                }
            }
        }

        foreach (JsonStoreService::load('merchant_accounts', []) as $row) {
            if (is_array($row)) {
                $merchantId = (int)($row['merchant_id'] ?? $row['id'] ?? 0);
                if ($merchantId > 0) {
                    $ids[] = $merchantId;
                }
            }
        }

        return array_values(array_unique($ids));
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
        float $amount,
        string $minAmount
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
            'min_amount' => $minAmount,
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
        float $amount,
        string $minAmount
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
            'appswitch' => self::appswitch('', $mode),
            'min_amount' => $minAmount,
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

    private static function merchantActive(int $merchantId): bool
    {
        $credential = AccountService::merchantCredentialById($merchantId);
        if (!is_array($credential)) {
            return false;
        }

        return (int)($credential['status'] ?? 0) === 1;
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

            if (!self::channelRuntimeAvailable($item)) {
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

    private static function channelRuntimeAvailable(array $item): bool
    {
        $runtimeState = MerchantChannelService::runtimeStateForPayload($item);
        if (!($runtimeState['runtime_requires_online'] ?? false)) {
            return true;
        }

        return (bool)($runtimeState['runtime_online'] ?? false);
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

    private static function findExistingBusinessOrder(int $merchantId, string $outTradeNo, string $business): ?object
    {
        if ($merchantId <= 0 || $outTradeNo === '') {
            return null;
        }

        if (!self::isBusinessIdempotent($business)) {
            return null;
        }

        return OrderService::gatewayMerchantOrderOrNull($merchantId, null, $outTradeNo);
    }

    private static function routeType(array $config): string
    {
        $source = trim((string)($config['source'] ?? ''));
        if ($source === 'collector_channel') {
            return 'collector_channel';
        }

        if ($source === 'merchant_channel') {
            return 'merchant_channel';
        }

        if ($source === 'merchant_channel_snapshot') {
            return 'merchant_channel_snapshot';
        }

        return 'configured_gateway';
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

    private static function normalizeMinimumAmount(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return self::MIN_AMOUNT_DEFAULT;
        }

        $amount = (float)$trimmed;
        if ($amount <= 0) {
            return self::MIN_AMOUNT_DEFAULT;
        }

        return number_format($amount, 2, '.', '');
    }

    private static function assertMinimumAmount(string $amount, string $minimumAmount): void
    {
        $minimum = (float)self::normalizeMinimumAmount($minimumAmount);
        if ($minimum > 0 && (float)$amount < $minimum) {
            throw new BusinessException(
                '当前支付配置最低起付金额为 ' . number_format($minimum, 2, '.', '') . ' 元',
                StatusCode::VALIDATION_ERROR
            );
        }
    }

    private static function outTradeNo(string $value, string $tradeNo): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9._-]+/', '', trim($value));
        if (is_string($cleaned) && $cleaned !== '') {
            return substr($cleaned, 0, 64);
        }

        return $tradeNo;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
