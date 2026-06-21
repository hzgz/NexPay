<?php

namespace app\service\system;

/**
 * Grouped admin settings read/write service.
 */
class SettingsService
{
    private const STORE_KEY = 'settings';
    private const QR_PROVIDER_DOMESTIC = 'cliim';
    private const QR_PROVIDER_INTERNATIONAL = 'goqr';
    private const PAYMENT_PROVIDER_SYSTEM = 'system';
    private const PAYMENT_PROVIDER_EPAY = 'epay';
    private const PAYMENT_MODE_V1 = 'v1';
    private const PAYMENT_MODE_V2 = 'v2';
    private const PAYMENT_APPSWITCH_V1_DEFAULT = '0';
    private const PAYMENT_APPSWITCH_V2_DEFAULT = '1';
    private const PAYMENT_METHOD_PRESETS = [
        ['key' => 'alipay', 'code' => 'alipay', 'name' => '支付宝', 'icon' => 'payment-icons/alipay.png'],
        ['key' => 'wxpay', 'code' => 'wxpay', 'name' => '微信支付', 'icon' => 'payment-icons/wechat.png'],
        ['key' => 'qqpay', 'code' => 'qqpay', 'name' => 'QQ钱包', 'icon' => 'payment-icons/qqpay.png'],
    ];
    private const RUNTIME_PROVIDER_GROUP_MAP = [
        'mail' => ['setting_group' => 'mail', 'provider_bucket' => 'mail', 'provider_key' => 'provider_code'],
        'sms' => ['setting_group' => 'sms', 'provider_bucket' => 'sms', 'provider_key' => 'provider_code'],
        'oauth' => ['setting_group' => 'oauth', 'provider_bucket' => 'oauth', 'provider_key' => 'provider_code'],
        'verify' => ['setting_group' => 'verify', 'provider_bucket' => 'geetest', 'provider_key' => 'provider_code'],
        'auth' => ['setting_group' => 'auth', 'provider_bucket' => 'captcha', 'provider_key' => 'captcha_provider_code'],
        'realname' => ['setting_group' => 'realname', 'provider_bucket' => 'realname', 'provider_key' => 'provider'],
    ];
    private const AUTH_TO_MERCHANT_MAP = [
        'register_enabled' => 'register_enabled',
        'register_auto_audit' => 'register_auto_audit',
        'merchant_register_fee_enabled' => 'register_fee_enabled',
        'merchant_register_fee' => 'register_fee',
        'require_realname_after_register' => 'require_realname',
    ];

    private const AUTH_DEFAULTS = [
        'register_enabled' => true,
        'register_auto_audit' => false,
        'merchant_register_fee_enabled' => false,
        'merchant_register_fee' => '0.00',
        'merchant_login_captcha' => false,
        'merchant_register_captcha' => false,
        'merchant_forgot_captcha' => false,
        'admin_login_captcha' => false,
        'require_realname_after_register' => false,
        'captcha_enabled' => false,
    ];

    private const VERIFY_DEFAULTS = [
        'geetest_enabled' => false,
        'geetest_scene_login' => false,
        'geetest_scene_register' => false,
        'geetest_scene_forgot' => false,
        'geetest_scene_admin' => false,
        'captcha_id' => '',
        'captcha_key' => '',
        'failback' => true,
    ];

    public static function all(bool $includePaymentPlugins = true): array
    {
        $defaults = self::defaults();
        $stored = JsonStoreService::load(self::STORE_KEY, $defaults);
        $data = array_replace_recursive($defaults, $stored);
        if ($includePaymentPlugins && RuntimeToggleService::pluginRuntimeEnabled()) {
            PluginRuntimeService::ensureSettingsStorage();
        }

        $flatDefaults = [
            'app_name' => $data['basic']['site_name'] ?? 'NexPay 聚合支付系统',
            'app_url' => $data['basic']['site_url'] ?? env('APP_URL', 'http://127.0.0.1:5174'),
            'platform_public_key' => $data['payment']['platform_public_key'] ?? env('PLATFORM_PUBLIC_KEY', ''),
            'platform_private_key' => $data['payment']['platform_private_key'] ?? env('PLATFORM_PRIVATE_KEY', ''),
            'internal_refund_secret' => $data['payment']['internal_refund_secret'] ?? ConfigService::internalRefundSecret(),
        ];
        $flat = ConfigService::all($flatDefaults);

        $data['basic']['site_name'] = $flat['app_name'] ?? ($data['basic']['site_name'] ?? 'NexPay 聚合支付系统');
        $data['basic']['site_url'] = $flat['app_url'] ?? ($data['basic']['site_url'] ?? env('APP_URL', 'http://127.0.0.1:5174'));
        $data['basic']['gateway_base_url'] = $data['basic']['site_url'];
        $data['payment']['platform_public_key'] = $flat['platform_public_key'] ?? ($data['payment']['platform_public_key'] ?? '');
        $data['payment']['platform_private_key'] = $flat['platform_private_key'] ?? ($data['payment']['platform_private_key'] ?? '');
        $data['payment']['internal_refund_secret'] = $flat['internal_refund_secret'] ?? ($data['payment']['internal_refund_secret'] ?? ConfigService::internalRefundSecret());
        if ($includePaymentPlugins) {
            $data['plugins']['enabled_codes'] = self::enabledPluginCodes();
        }
        self::normalizeApiSettings($data);
        self::normalizePaymentSettings($data);
        self::normalizeCrossGroupSettings($data);
        self::mergeRuntimeProviderSettings($data);
        $data = SystemProviderService::attachSelections($data);
        $data['cleanup_workspace'] = SystemCleanupService::catalog();
        unset($data['plugin_runtime_settings']);

        return $data;
    }

    public static function save(array $payload): array
    {
        $current = self::all();
        $next = array_replace_recursive($current, $payload);
        $pluginSettings = is_array($payload['plugins'] ?? null) ? $payload['plugins'] : null;

        $flat = [];
        if (isset($next['basic']['site_name'])) {
            $flat['app_name'] = $next['basic']['site_name'];
        }
        if (isset($next['basic']['site_url'])) {
            $flat['app_url'] = $next['basic']['site_url'];
        } elseif (isset($next['basic']['gateway_base_url'])) {
            $flat['app_url'] = $next['basic']['gateway_base_url'];
        }
        if (isset($next['payment']['platform_public_key'])) {
            $flat['platform_public_key'] = $next['payment']['platform_public_key'];
        }
        if (isset($next['payment']['platform_private_key'])) {
            $flat['platform_private_key'] = $next['payment']['platform_private_key'];
        }
        if (isset($next['payment']['internal_refund_secret'])) {
            $flat['internal_refund_secret'] = $next['payment']['internal_refund_secret'];
        }

        if ($flat !== []) {
            ConfigService::save($flat);
        }

        if ($pluginSettings !== null) {
            self::syncEnabledPluginCodes($pluginSettings);
        }

        self::normalizeApiSettings($next);
        self::normalizePaymentSettings($next);
        self::normalizeCrossGroupSettings($next, $payload);
        self::persistRuntimeProviderSettings($next, $payload);
        $next = SystemProviderService::attachSelections($next);
        unset($next['plugin_runtime_settings']);
        unset($next['provider_options']);
        unset($next['cleanup_workspace']);
        JsonStoreService::save(self::STORE_KEY, $next);
        return self::all();
    }

    public static function clearCache(): array
    {
        return SystemCleanupService::clearRuntimeCache();
    }

    private static function syncEnabledPluginCodes(array $pluginSettings): void
    {
        $rawCodes = $pluginSettings['enabled_codes'] ?? [];
        $enabledCodes = [];

        if (is_array($rawCodes)) {
            $enabledCodes = PluginCodeService::normalizeList($rawCodes);
        } elseif (is_string($rawCodes)) {
            $enabledCodes = PluginCodeService::normalizeList(
                preg_split('/[\s,，\n]+/', $rawCodes, -1, PREG_SPLIT_NO_EMPTY) ?: []
            );
        }

        $pluginItems = PluginService::plugins();
        $enabledMap = array_fill_keys($enabledCodes, true);

        foreach ($pluginItems as $plugin) {
            $code = PluginCodeService::normalize((string)($plugin['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            PluginService::toggle($code, isset($enabledMap[$code]) ? 1 : 0);
        }
    }

    private static function enabledPluginCodes(): array
    {
        $enabled = [];

        if (!database_available()) {
            $plugins = JsonStoreService::load('plugins', []);
            foreach ($plugins as $plugin) {
                if ((int)($plugin['status_code'] ?? 0) !== 1) {
                    continue;
                }

                $code = PluginCodeService::normalize((string)($plugin['code'] ?? ''));
                if ($code !== '') {
                    $enabled[$code] = $code;
                }
            }

            return array_values($enabled);
        }

        foreach (PluginService::plugins() as $plugin) {
            if ((int)($plugin['status_code'] ?? 0) !== 1) {
                continue;
            }

            $code = PluginCodeService::normalize((string)($plugin['code'] ?? ''));
            if ($code !== '') {
                $enabled[$code] = $code;
            }
        }

        return array_values($enabled);
    }

    public static function defaults(): array
    {
        $siteUrl = env('APP_URL', 'http://127.0.0.1:5174');

        return [
            'basic' => [
                'site_name' => 'NexPay 聚合支付系统',
                'site_title' => 'NexPay 聚合支付系统',
                'site_subtitle' => 'NexPay 聚合支付系统',
                'site_url' => $siteUrl,
                'gateway_base_url' => $siteUrl,
                'site_logo_home' => '/assets/logo-home.png',
                'site_logo_global' => '/assets/logo-admin.png',
                'icp_no' => '',
                'member_start_id' => '10000',
                'allow_avatar_upload' => true,
                'allow_account_cancel' => false,
            ],
            'payment' => [
                'system_checkout' => self::paymentGatewayDefaults($siteUrl, self::PAYMENT_MODE_V2),
                'frontend_test' => self::paymentFrontendDefaults($siteUrl, self::PAYMENT_MODE_V2),
                'epay_version' => self::PAYMENT_MODE_V2,
                'epay_v1_api' => rtrim($siteUrl, '/') . '/mapi.php',
                'epay_v2_api' => rtrim($siteUrl, '/') . '/api/pay/create',
                'callback_mode' => '异步回调 + 重试',
                'default_confirmations' => '2',
                'payment_test_enabled' => false,
                'test_default_amount' => '',
                'test_auto_complete' => false,
                'platform_public_key' => env('PLATFORM_PUBLIC_KEY', ''),
                'platform_private_key' => env('PLATFORM_PRIVATE_KEY', ''),
                'internal_refund_secret' => ConfigService::internalRefundSecret(),
            ],
            'oauth' => [
                'enabled' => false,
                'provider' => '聚合登录接口',
                'provider_code' => 'oauth-aggregate',
                'api_url' => '',
                'app_id' => '',
                'app_key' => '',
                'callback_url' => '',
                'callback_path' => '/user/login',
                'qq_enabled' => false,
                'wechat_enabled' => false,
                'alipay_enabled' => false,
                'google_enabled' => false,
                'failure_fallback' => true,
                'bind_auto_create' => false,
            ],
            'mail' => [
                'enabled' => false,
                'provider_code' => 'mail-smtp',
                'smtp_host' => '',
                'smtp_port' => '465',
                'smtp_user' => '',
                'smtp_pass' => '',
                'smtp_secure' => 'ssl',
                'sender_name' => 'NexPay',
                'from_email' => '',
            ],
            'telegram' => [
                'bot_token' => '',
                'chat_id' => '',
                'notify_enabled' => false,
            ],
            'sms' => [
                'enabled' => false,
                'provider' => 'aliyun',
                'provider_code' => 'sms-aliyun',
                'api_url' => 'https://dysmsapi.aliyuncs.com/',
                'app_id' => '',
                'app_key' => '',
                'sign_name' => '',
                'template_code' => '',
                'template_code_login' => '',
                'template_code_register' => '',
                'template_code_forgot' => '',
                'access_key_id' => '',
                'access_key_secret' => '',
            ],
            'realname' => [
                'enabled' => false,
                'provider' => 'manual',
                'api_url' => '',
                'app_id' => '',
                'app_key' => '',
                'app_secret' => '',
                'daily_limit' => '3',
                'auto_audit' => false,
                'require_before_api' => false,
            ],
            'upload' => [
                'max_size_mb' => '5',
                'allowed_ext' => 'jpg,png,pdf,zip',
                'storage_driver' => 'local',
            ],
            'api' => [
                'encode_provider' => self::QR_PROVIDER_DOMESTIC,
                'decode_provider' => self::QR_PROVIDER_DOMESTIC,
                'notify_retry' => '5',
            ],
            'auth' => [
                'register_enabled' => true,
                'register_type' => '邮箱',
                'login_type' => '账号密码',
                'captcha_enabled' => false,
                'captcha_provider_code' => 'captcha-slider',
                'behavior_verify' => false,
                'recover_type' => '邮箱',
                'merchant_register_fee_enabled' => false,
                'merchant_register_fee' => '0.00',
                'merchant_trial_days' => '0',
                'register_auto_audit' => false,
                'require_realname_after_register' => false,
                'merchant_login_captcha' => false,
                'merchant_register_captcha' => false,
                'merchant_forgot_captcha' => false,
                'admin_login_captcha' => false,
            ],
            'verify' => [
                'enabled' => false,
                'provider_code' => 'geetest',
                'geetest_enabled' => false,
                'geetest_scene_login' => false,
                'geetest_scene_register' => false,
                'geetest_scene_forgot' => false,
                'geetest_scene_admin' => false,
                'captcha_enabled' => false,
                'captcha_id' => '',
                'captcha_key' => '',
                'validate_url' => 'https://gcaptcha4.geetest.com/validate?captcha_id={captcha_id}',
                'failback' => true,
            ],
            'merchant' => [
                'register_enabled' => true,
                'register_mode' => '免费注册',
                'register_fee_enabled' => false,
                'register_fee' => '0.00',
                'register_auto_audit' => false,
                'require_realname' => false,
                'require_realname_before_withdraw' => false,
                'default_group' => '基础组',
                'trial_package' => '',
            ],
            'plugins' => [
                'auto_scan' => true,
                'sync_on_boot' => true,
                'enabled_codes' => [],
            ],
        ];
    }

    private static function normalizeCrossGroupSettings(array &$settings, array $payload = []): void
    {
        self::syncAuthMerchantSettings($settings, $payload);
        self::syncVerifySettings($settings, $payload);
    }

    private static function normalizeApiSettings(array &$settings): void
    {
        $api = is_array($settings['api'] ?? null) ? $settings['api'] : [];
        $api['encode_provider'] = self::normalizeQrProvider((string)($api['encode_provider'] ?? ''));
        $api['decode_provider'] = self::normalizeQrProvider((string)($api['decode_provider'] ?? ''));
        $api['notify_retry'] = trim((string)($api['notify_retry'] ?? '5'));

        if ($api['notify_retry'] === '') {
            $api['notify_retry'] = '5';
        }

        $settings['api'] = $api;
    }

    private static function normalizePaymentSettings(array &$settings): void
    {
        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
        $siteUrl = trim((string)($settings['basic']['site_url'] ?? env('APP_URL', 'http://127.0.0.1:5174')));
        $legacyMode = self::normalizePaymentMode((string)($payment['epay_version'] ?? self::PAYMENT_MODE_V2));
        $systemDefaults = self::paymentGatewayDefaults($siteUrl, $legacyMode);
        $frontendDefaults = self::paymentFrontendDefaults($siteUrl, $legacyMode);
        $legacyV1Url = trim((string)($payment['epay_v1_api'] ?? ''));
        $legacyV2Url = trim((string)($payment['epay_v2_api'] ?? ''));
        $legacyActiveUrl = $legacyMode === self::PAYMENT_MODE_V1
            ? ($legacyV1Url !== '' ? $legacyV1Url : $legacyV2Url)
            : ($legacyV2Url !== '' ? $legacyV2Url : $legacyV1Url);
        $systemPayload = is_array($payment['system_checkout'] ?? null) ? $payment['system_checkout'] : [];
        $frontendPayload = is_array($payment['frontend_test'] ?? null) ? $payment['frontend_test'] : [];

        $systemPayloadMode = self::normalizePaymentMode((string)($systemPayload['mode'] ?? $legacyMode));
        $systemPayloadLooksDefault = trim((string)($systemPayload['merchant_id'] ?? '')) === ''
            && trim((string)($systemPayload['merchant_md5'] ?? '')) === ''
            && trim((string)($systemPayload['platform_public_key'] ?? '')) === ''
            && trim((string)($systemPayload['merchant_private_key'] ?? '')) === ''
            && trim((string)($systemPayload['payment_url'] ?? '')) === self::paymentUrlForMode($siteUrl, $systemPayloadMode);

        if ($systemPayloadLooksDefault && $legacyActiveUrl !== '') {
            $systemPayload['mode'] = $legacyMode;
            $systemPayload['payment_url'] = $legacyActiveUrl;
        }

        $frontendPayloadMode = self::normalizePaymentMode((string)($frontendPayload['mode'] ?? $legacyMode));
        $frontendPayloadLooksDefault = trim((string)($frontendPayload['merchant_id'] ?? '')) === ''
            && trim((string)($frontendPayload['merchant_md5'] ?? '')) === ''
            && trim((string)($frontendPayload['platform_public_key'] ?? '')) === ''
            && trim((string)($frontendPayload['merchant_private_key'] ?? '')) === ''
            && trim((string)($frontendPayload['payment_url'] ?? '')) === self::paymentUrlForMode($siteUrl, $frontendPayloadMode)
            && empty($frontendPayload['enabled'])
            && trim((string)($frontendPayload['amount'] ?? '')) === ''
            && empty($frontendPayload['auto_complete']);

        if ($frontendPayloadLooksDefault) {
            $frontendPayload = array_merge($frontendPayload, [
                'provider' => self::PAYMENT_PROVIDER_SYSTEM,
                'mode' => $legacyMode,
                'payment_url' => $legacyActiveUrl !== '' ? $legacyActiveUrl : ($systemPayload['payment_url'] ?? ''),
                'enabled' => $payment['payment_test_enabled'] ?? false,
                'amount' => $payment['test_default_amount'] ?? '',
                'auto_complete' => $payment['test_auto_complete'] ?? false,
            ]);
        }

        $systemCheckout = self::normalizePaymentGatewayConfig(
            $systemPayload,
            $systemDefaults,
            [
                'mode' => $legacyMode,
                'payment_url' => $legacyActiveUrl,
            ]
        );

        $frontendSeed = array_merge(
            [
                'provider' => $systemCheckout['provider'],
                'mode' => $systemCheckout['mode'],
                'payment_url' => $systemCheckout['payment_url'],
                'merchant_id' => $systemCheckout['merchant_id'],
                'merchant_md5' => $systemCheckout['merchant_md5'],
                'platform_public_key' => $systemCheckout['platform_public_key'],
                'merchant_private_key' => $systemCheckout['merchant_private_key'],
            ],
            $frontendPayload,
            [
                'enabled' => $frontendPayload['enabled'] ?? ($payment['payment_test_enabled'] ?? false),
                'amount' => $frontendPayload['amount'] ?? ($payment['test_default_amount'] ?? ''),
                'auto_complete' => $frontendPayload['auto_complete'] ?? ($payment['test_auto_complete'] ?? false),
            ]
        );

        $frontendTest = self::normalizePaymentGatewayConfig($frontendSeed, $frontendDefaults, [
            'enabled' => $payment['payment_test_enabled'] ?? false,
            'amount' => $payment['test_default_amount'] ?? '',
            'auto_complete' => $payment['test_auto_complete'] ?? false,
        ]);

        $payment['system_checkout'] = $systemCheckout;
        $payment['frontend_test'] = $frontendTest;
        $payment['epay_version'] = $systemCheckout['mode'];
        $payment['epay_v1_api'] = $systemCheckout['mode'] === self::PAYMENT_MODE_V1
            ? $systemCheckout['payment_url']
            : ($legacyV1Url !== '' ? $legacyV1Url : self::paymentUrlForMode($siteUrl, self::PAYMENT_MODE_V1));
        $payment['epay_v2_api'] = $systemCheckout['mode'] === self::PAYMENT_MODE_V2
            ? $systemCheckout['payment_url']
            : ($legacyV2Url !== '' ? $legacyV2Url : self::paymentUrlForMode($siteUrl, self::PAYMENT_MODE_V2));
        $payment['payment_test_enabled'] = (bool)($frontendTest['enabled'] ?? false);
        $payment['test_default_amount'] = trim((string)($frontendTest['amount'] ?? ''));
        $payment['test_auto_complete'] = (bool)($frontendTest['auto_complete'] ?? false);

        $settings['payment'] = $payment;
    }

    private static function normalizeQrProvider(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '',
            'cliim',
            'goqr' => self::QR_PROVIDER_INTERNATIONAL,
            default => self::QR_PROVIDER_DOMESTIC,
        };
    }

    private static function paymentGatewayDefaults(string $siteUrl, string $mode = self::PAYMENT_MODE_V2): array
    {
        $normalizedMode = self::normalizePaymentMode($mode);

        return [
            'provider' => self::PAYMENT_PROVIDER_SYSTEM,
            'mode' => $normalizedMode,
            'appswitch' => self::paymentAppswitchDefault($normalizedMode),
            'payment_url' => self::paymentUrlForMode($siteUrl, $normalizedMode),
            'merchant_id' => '',
            'merchant_md5' => '',
            'platform_public_key' => '',
            'merchant_private_key' => '',
            'carrier_merchant_id' => '',
            'carrier_channel_id' => '',
            'carrier_channel_code' => '',
            'methods' => self::paymentMethodDefaults(),
        ];
    }

    private static function paymentFrontendDefaults(string $siteUrl, string $mode = self::PAYMENT_MODE_V2): array
    {
        return array_merge(self::paymentGatewayDefaults($siteUrl, $mode), [
            'enabled' => false,
            'amount' => '',
            'auto_complete' => false,
        ]);
    }

    private static function normalizePaymentGatewayConfig(array $source, array $defaults, array $legacy = []): array
    {
        $config = array_replace($defaults, $legacy, $source);
        $config['provider'] = self::normalizePaymentProvider((string)($config['provider'] ?? self::PAYMENT_PROVIDER_SYSTEM));
        $config['mode'] = self::normalizePaymentMode((string)($config['mode'] ?? self::PAYMENT_MODE_V2));
        $config['appswitch'] = self::normalizePaymentAppswitch((string)($config['appswitch'] ?? ''), $config['mode']);
        $config['payment_url'] = trim((string)($config['payment_url'] ?? ''));
        $config['merchant_id'] = trim((string)($config['merchant_id'] ?? ''));
        $config['merchant_md5'] = trim((string)($config['merchant_md5'] ?? ''));
        $config['platform_public_key'] = trim((string)($config['platform_public_key'] ?? ''));
        $config['merchant_private_key'] = trim((string)($config['merchant_private_key'] ?? ''));
        $config['carrier_merchant_id'] = trim((string)($config['carrier_merchant_id'] ?? ''));
        $config['carrier_channel_id'] = trim((string)($config['carrier_channel_id'] ?? ''));
        $config['carrier_channel_code'] = PaymentMetaService::normalizeMethodCode((string)($config['carrier_channel_code'] ?? ''));
        $config['methods'] = self::normalizePaymentMethods($config['methods'] ?? []);

        if (array_key_exists('enabled', $config)) {
            $config['enabled'] = (bool)$config['enabled'];
        }
        if (array_key_exists('amount', $config)) {
            $config['amount'] = trim((string)$config['amount']);
        }
        if (array_key_exists('auto_complete', $config)) {
            $config['auto_complete'] = (bool)$config['auto_complete'];
        }

        return $config;
    }

    public static function paymentMethodConfigs(string $configKey): array
    {
        $settings = self::all(false);
        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
        $config = is_array($payment[$configKey] ?? null) ? $payment[$configKey] : [];

        return self::normalizePaymentMethods($config['methods'] ?? []);
    }

    public static function enabledPaymentMethodConfigs(string $configKey): array
    {
        return array_values(array_filter(
            self::paymentMethodConfigs($configKey),
            static fn(array $item): bool => (bool)($item['enabled'] ?? false)
        ));
    }

    public static function frontendPaymentMethodOptions(string $configKey): array
    {
        return array_map(static function (array $item): array {
            $code = trim((string)($item['code'] ?? ''));

            return [
                'key' => trim((string)($item['key'] ?? '')),
                'code' => $code,
                'method_code' => $code,
                'name' => trim((string)($item['name'] ?? '')),
                'icon' => trim((string)($item['icon'] ?? '')),
                'enabled' => (bool)($item['enabled'] ?? false),
                'builtin' => (bool)($item['builtin'] ?? false),
            ];
        }, self::enabledPaymentMethodConfigs($configKey));
    }

    public static function resolveEnabledPaymentMethodCode(string $configKey, string $requested = ''): string
    {
        $options = self::frontendPaymentMethodOptions($configKey);
        if ($options === []) {
            return '';
        }

        $normalizedRequested = trim(PaymentMetaService::normalizeMethodCode($requested));
        $fallback = trim((string)($options[0]['method_code'] ?? $options[0]['code'] ?? ''));

        if ($normalizedRequested === '') {
            return $fallback;
        }

        foreach ($options as $item) {
            $optionCode = trim((string)($item['method_code'] ?? $item['code'] ?? ''));
            if ($optionCode !== '' && $optionCode === $normalizedRequested) {
                return $optionCode;
            }
        }

        return '';
    }

    private static function paymentMethodDefaults(): array
    {
        return array_map(static function (array $item): array {
            return [
                'key' => $item['key'],
                'enabled' => true,
                'builtin' => true,
                'code' => $item['code'],
                'name' => $item['name'],
                'icon' => $item['icon'],
            ];
        }, self::PAYMENT_METHOD_PRESETS);
    }

    private static function normalizePaymentMethods(mixed $methods): array
    {
        $items = is_array($methods) ? $methods : [];
        $normalized = [];
        $builtinSeen = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $presetKey = strtolower(trim((string)($item['key'] ?? '')));
            $sourceCode = trim((string)($item['code'] ?? ''));
            $methodCode = PaymentMetaService::normalizeMethodCode($sourceCode);
            if ($methodCode === '') {
                $methodCode = $presetKey !== '' ? $presetKey : strtolower(trim((string)($item['code'] ?? '')));
            }
            if ($methodCode === '') {
                continue;
            }

            $preset = self::paymentMethodPreset($presetKey, $methodCode);
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                $name = $preset['name'] ?? PaymentMetaService::friendlyMethodName($methodCode);
            }

            $icon = trim((string)($item['icon'] ?? ''));
            if ($icon === '') {
                $icon = $preset['icon'] ?? PaymentMetaService::friendlyMethodIcon($methodCode);
            }

            $normalizedItem = [
                'key' => $preset['key'] ?? ($presetKey !== '' ? $presetKey : ('custom_' . ($index + 1))),
                'enabled' => (bool)($item['enabled'] ?? true),
                'builtin' => (bool)($preset['builtin'] ?? false),
                'code' => $methodCode,
                'name' => $name,
                'icon' => $icon,
            ];

            if ((bool)$normalizedItem['builtin']) {
                $builtinSeen[$normalizedItem['key']] = true;
            }

            $normalized[] = $normalizedItem;
        }

        foreach (self::PAYMENT_METHOD_PRESETS as $preset) {
            if (isset($builtinSeen[$preset['key']])) {
                continue;
            }

            $normalized[] = [
                'key' => $preset['key'],
                'enabled' => true,
                'builtin' => true,
                'code' => $preset['code'],
                'name' => $preset['name'],
                'icon' => $preset['icon'],
            ];
        }

        return array_values($normalized);
    }

    private static function paymentMethodPreset(string $presetKey, string $methodCode): array
    {
        foreach (self::PAYMENT_METHOD_PRESETS as $preset) {
            if ($preset['key'] === $presetKey || $preset['code'] === $methodCode) {
                return $preset + ['builtin' => true];
            }
        }

        return [];
    }

    private static function normalizePaymentProvider(string $value): string
    {
        $normalized = trim(strtolower($value));

        return match ($normalized) {
            self::PAYMENT_PROVIDER_EPAY => self::PAYMENT_PROVIDER_EPAY,
            self::PAYMENT_PROVIDER_SYSTEM => self::PAYMENT_PROVIDER_SYSTEM,
            default => self::PAYMENT_PROVIDER_SYSTEM,
        };
    }

    private static function normalizePaymentMode(string $value): string
    {
        return trim(strtolower($value)) === self::PAYMENT_MODE_V1
            ? self::PAYMENT_MODE_V1
            : self::PAYMENT_MODE_V2;
    }

    private static function normalizePaymentAppswitch(string $value, string $mode): string
    {
        $normalized = trim($value);

        if ($normalized === '0' || $normalized === '1') {
            return $normalized;
        }

        return self::paymentAppswitchDefault($mode);
    }

    private static function paymentAppswitchDefault(string $mode): string
    {
        return self::normalizePaymentMode($mode) === self::PAYMENT_MODE_V1
            ? self::PAYMENT_APPSWITCH_V1_DEFAULT
            : self::PAYMENT_APPSWITCH_V2_DEFAULT;
    }

    private static function paymentUrlForMode(string $siteUrl, string $mode): string
    {
        $baseUrl = rtrim($siteUrl, '/');

        return self::normalizePaymentMode($mode) === self::PAYMENT_MODE_V1
            ? $baseUrl . '/mapi.php'
            : $baseUrl . '/api/pay/create';
    }

    private static function mergeRuntimeProviderSettings(array &$settings): void
    {
        $providerOptions = SystemProviderService::settingOptions();

        foreach (self::RUNTIME_PROVIDER_GROUP_MAP as $config) {
            $groupKey = $config['setting_group'];
            $bucketKey = $config['provider_bucket'];
            $providerKey = $config['provider_key'];

            $group = is_array($settings[$groupKey] ?? null) ? $settings[$groupKey] : [];
            $providers = is_array($providerOptions[$bucketKey] ?? null) ? $providerOptions[$bucketKey] : [];
            $selectedCode = trim((string)($group[$providerKey] ?? ''));

            if ($selectedCode === '' && $providers !== []) {
                $selectedCode = trim((string)($providers[0]['code'] ?? ''));
            }

            if ($selectedCode === '') {
                $settings[$groupKey] = $group;
                continue;
            }

            $selectedProvider = null;
            foreach ($providers as $provider) {
                if (($provider['code'] ?? '') === $selectedCode) {
                    $selectedProvider = $provider;
                    break;
                }
            }

            if (!$selectedProvider) {
                $settings[$groupKey] = $group;
                continue;
            }

            $defaults = is_array($selectedProvider['default_settings'] ?? null) ? $selectedProvider['default_settings'] : [];
            $group = array_replace($defaults, $group);
            $group[$providerKey] = $selectedCode;

            $settings[$groupKey] = $group;
        }
    }

    private static function persistRuntimeProviderSettings(array &$settings, array $payload = []): void
    {
        $providerOptions = SystemProviderService::settingOptions();

        foreach (self::RUNTIME_PROVIDER_GROUP_MAP as $config) {
            $groupKey = $config['setting_group'];
            $bucketKey = $config['provider_bucket'];
            $providerKey = $config['provider_key'];

            $providers = is_array($providerOptions[$bucketKey] ?? null) ? $providerOptions[$bucketKey] : [];
            if ($providers === []) {
                continue;
            }

            $group = is_array($settings[$groupKey] ?? null) ? $settings[$groupKey] : [];
            $payloadGroup = is_array($payload[$groupKey] ?? null) ? $payload[$groupKey] : [];

            $selectedCode = trim((string)($payloadGroup[$providerKey] ?? $group[$providerKey] ?? ''));
            if ($selectedCode === '') {
                $selectedCode = trim((string)($providers[0]['code'] ?? ''));
            }
            if ($selectedCode === '') {
                $settings[$groupKey] = $group;
                continue;
            }

            $selectedProvider = null;
            foreach ($providers as $provider) {
                if (($provider['code'] ?? '') === $selectedCode) {
                    $selectedProvider = $provider;
                    break;
                }
            }

            if (!$selectedProvider) {
                $settings[$groupKey] = $group;
                continue;
            }

            $group[$providerKey] = $selectedCode;
            $settings[$groupKey] = $group;
        }
    }

    private static function syncAuthMerchantSettings(array &$settings, array $payload = []): void
    {
        $auth = is_array($settings['auth'] ?? null) ? $settings['auth'] : [];
        $merchant = is_array($settings['merchant'] ?? null) ? $settings['merchant'] : [];
        $payloadAuth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
        $payloadMerchant = is_array($payload['merchant'] ?? null) ? $payload['merchant'] : [];

        foreach (self::AUTH_TO_MERCHANT_MAP as $authKey => $merchantKey) {
            $default = self::AUTH_DEFAULTS[$authKey] ?? null;
            $value = self::resolveMappedValue(
                $authKey,
                $merchantKey,
                $default,
                $auth,
                $merchant,
                $payloadAuth,
                $payloadMerchant
            );

            $auth[$authKey] = self::normalizeScalar($value, $default);
            $merchant[$merchantKey] = self::normalizeScalar($value, $default);
        }

        foreach (self::AUTH_DEFAULTS as $key => $default) {
            $auth[$key] = self::normalizeScalar($auth[$key] ?? $default, $default);
        }

        $settings['auth'] = $auth;
        $settings['merchant'] = $merchant;
    }

    private static function syncVerifySettings(array &$settings, array $payload = []): void
    {
        $verify = is_array($settings['verify'] ?? null) ? $settings['verify'] : [];
        $auth = is_array($settings['auth'] ?? null) ? $settings['auth'] : [];
        $payloadVerify = is_array($payload['verify'] ?? null) ? $payload['verify'] : [];
        $payloadAuth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];

        foreach (self::VERIFY_DEFAULTS as $key => $default) {
            if ($key === 'captcha_enabled') {
                continue;
            }

            if (array_key_exists($key, $payloadVerify)) {
                $verify[$key] = self::normalizeScalar($payloadVerify[$key], $default);
                continue;
            }

            $verify[$key] = self::normalizeScalar($verify[$key] ?? $default, $default);
        }

        $captchaEnabled = false;
        if (array_key_exists('captcha_enabled', $payloadAuth)) {
            $captchaEnabled = (bool)$payloadAuth['captcha_enabled'];
        } elseif (array_key_exists('captcha_enabled', $payloadVerify)) {
            $captchaEnabled = (bool)$payloadVerify['captcha_enabled'];
        } elseif (array_key_exists('captcha_enabled', $auth)) {
            $captchaEnabled = (bool)$auth['captcha_enabled'];
        } elseif (array_key_exists('captcha_enabled', $verify)) {
            $captchaEnabled = (bool)$verify['captcha_enabled'];
        }

        $auth['captcha_enabled'] = $captchaEnabled;
        $verify['captcha_enabled'] = $captchaEnabled;

        $settings['verify'] = $verify;
        $settings['auth'] = $auth;
    }

    private static function resolveMappedValue(
        string $authKey,
        string $merchantKey,
        mixed $default,
        array $auth,
        array $merchant,
        array $payloadAuth,
        array $payloadMerchant
    ): mixed {
        if (array_key_exists($authKey, $payloadAuth)) {
            return $payloadAuth[$authKey];
        }

        if (array_key_exists($merchantKey, $payloadMerchant)) {
            return $payloadMerchant[$merchantKey];
        }

        $hasAuth = array_key_exists($authKey, $auth);
        $hasMerchant = array_key_exists($merchantKey, $merchant);
        if ($hasAuth && $hasMerchant) {
            $authValue = self::normalizeScalar($auth[$authKey], $default);
            $merchantValue = self::normalizeScalar($merchant[$merchantKey], $default);

            if ($authValue !== $merchantValue) {
                $authIsDefault = $authValue === self::normalizeScalar($default, $default);
                $merchantIsDefault = $merchantValue === self::normalizeScalar($default, $default);

                if ($authIsDefault && !$merchantIsDefault) {
                    return $merchantValue;
                }

                return $authValue;
            }

            return $authValue;
        }

        if ($hasAuth) {
            return $auth[$authKey];
        }

        if ($hasMerchant) {
            return $merchant[$merchantKey];
        }

        return $default;
    }

    private static function normalizeScalar(mixed $value, mixed $default): mixed
    {
        if (is_bool($default)) {
            return (bool)$value;
        }

        if (is_int($default)) {
            return (int)$value;
        }

        if (is_float($default)) {
            return (float)$value;
        }

        if (is_string($default)) {
            return trim((string)$value);
        }

        return $value;
    }
}
