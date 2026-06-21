<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\exception\BusinessException;
use app\model\CallbackQueue;
use app\model\Merchant;
use app\model\MerchantBalance;
use app\model\MerchantUser;
use app\model\Order;
use app\service\payment\LocalFundStore;
use app\service\payment\OrderService;
use app\service\system\AccountService;
use app\service\system\AdminMerchantService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantAuthService;
use app\service\system\MerchantChannelService;
use app\service\system\MerchantFundService;
use app\service\system\PackageService;
use app\service\system\PaymentMetaService;
use app\service\system\PluginCodeService;
use app\service\system\PluginSchemaService;
use app\service\system\PluginService;
use app\service\system\ResourceDataService;
use app\service\system\SettlementService;
use think\facade\Db;

$stores = [
    'settings',
    'merchant_auth_users',
    'merchant_accounts',
    'merchant_channels',
    'orders_local',
    'callback_queue_local',
    'fund_flows_local',
    'settlements_local',
    'merchant_packages',
    'packages',
    'realname_audit_logs',
    'merchant_operation_logs',
];

$backups = [];
foreach ($stores as $store) {
    $backups[$store] = JsonStoreService::load($store, []);
}

$merchantId = 0;
$userId = 0;
$packageName = 'Business Growth Pack ' . date('YmdHis') . random_int(100000, 999999);
$result = [];
$ok = false;

try {
    $settings = is_array($backups['settings']) ? $backups['settings'] : [];
    $settings['auth'] = is_array($settings['auth'] ?? null) ? $settings['auth'] : [];
    $settings['merchant'] = is_array($settings['merchant'] ?? null) ? $settings['merchant'] : [];
    $settings['verify'] = is_array($settings['verify'] ?? null) ? $settings['verify'] : [];
    $settings['realname'] = is_array($settings['realname'] ?? null) ? $settings['realname'] : [];

    $settings['auth']['register_enabled'] = true;
    $settings['auth']['captcha_enabled'] = false;
    $settings['auth']['merchant_login_captcha'] = false;
    $settings['auth']['merchant_register_captcha'] = false;
    $settings['auth']['merchant_forgot_captcha'] = false;
    $settings['auth']['merchant_register_fee_enabled'] = true;
    $settings['auth']['merchant_register_fee'] = '9.90';
    $settings['auth']['register_auto_audit'] = false;

    $settings['merchant']['register_enabled'] = true;
    $settings['merchant']['register_fee_enabled'] = true;
    $settings['merchant']['register_fee'] = '9.90';
    $settings['merchant']['register_auto_audit'] = false;
    $settings['merchant']['require_realname_before_withdraw'] = true;

    $settings['verify']['geetest_enabled'] = false;
    $settings['verify']['geetest_scene_login'] = false;
    $settings['verify']['geetest_scene_register'] = false;
    $settings['verify']['geetest_scene_forgot'] = false;
    $settings['verify']['geetest_scene_admin'] = false;

    $settings['realname']['enabled'] = false;
    $settings['realname']['provider'] = 'manual';
    $settings['realname']['daily_limit'] = 5;

    JsonStoreService::save('settings', $settings);

    $suffix = date('His') . random_int(1000, 9999);
    $username = 'bizverify' . $suffix;
    $email = $username . '@example.com';
    $phone = '139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $password = 'Passw0rd!123';

    $registered = MerchantAuthService::register([
        'merchant_name' => 'Verify Merchant ' . $suffix,
        'contact_name' => 'Verify User',
        'username' => $username,
        'email' => $email,
        'phone' => $phone,
        'password' => $password,
        'confirm_password' => $password,
    ], '127.0.0.1');

    $merchantId = (int)($registered['merchant_id'] ?? 0);
    $userId = (int)($registered['id'] ?? 0);
    $registerTradeNo = (string)($registered['payment_order']['trade_no'] ?? '');

    $loginBlockedBeforePayment = false;
    $loginBlockedMessage = '';
    try {
        MerchantAuthService::login([
            'username' => $username,
            'password' => $password,
        ], '127.0.0.1');
    } catch (BusinessException $exception) {
        $loginBlockedBeforePayment = true;
        $loginBlockedMessage = $exception->getMessage();
    }

    OrderService::completeByTradeNo($registerTradeNo, [
        'source' => 'verify-merchant-auth-fund-closure',
        'txid' => 'REG' . date('YmdHis') . random_int(100000, 999999),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);

    $authUsers = JsonStoreService::load('merchant_auth_users', []);
    $authRow = is_array($authUsers[$username] ?? null) ? $authUsers[$username] : [];
    $merchantModel = database_available() ? Merchant::find($merchantId) : null;
    $registerPaidPendingAudit = $merchantId > 0
        && $registerTradeNo !== ''
        && (string)($authRow['register_fee_status'] ?? '') === 'paid'
        && (string)($authRow['audit_status'] ?? '') === 'pending'
        && (int)($merchantModel?->status ?? 0) === 0;

    $review = AdminMerchantService::review([
        'merchant_id' => $merchantId,
        'action' => 'approve',
        'reason' => 'verify merchant activation',
    ], 'verify-script');

    $loginAfterApprove = MerchantAuthService::login([
        'username' => $username,
        'password' => $password,
    ], '127.0.0.1');

    $profileAfterSubmit = AccountService::saveMerchantProfile($userId, [
        'realname' => [
            'real_name' => 'Lisi',
            'id_card' => '110101199003070011',
        ],
    ]);

    $withdrawBlockedBeforeRealname = false;
    $withdrawBlockedMessage = '';
    try {
        SettlementService::requestWithdraw($merchantId, $userId, [
            'money' => '10.00',
            'account_type' => 'alipay',
            'account' => 'verify-before-review@example.com',
            'account_name' => 'Verify User',
            'out_settle_no' => 'WITHDRAW-BLOCK-' . random_int(100000, 999999),
        ]);
    } catch (BusinessException $exception) {
        $withdrawBlockedBeforeRealname = true;
        $withdrawBlockedMessage = $exception->getMessage();
    }

    $profileAfterReview = AccountService::reviewMerchantRealname([
        'merchant_id' => $merchantId,
        'action' => 'approve',
        'reason' => 'verify realname approval',
    ], 'verify-script');

    [$methodCode, $pluginCode, $pluginConfig] = resolveUsableChannelDefinition();
    MerchantChannelService::saveItem($merchantId, [
        'channel_name' => 'Recharge Verify Channel',
        'method_code' => $methodCode,
        'plugin_code' => $pluginCode,
        'daily_limit' => '100000.00',
        'daily_count_limit' => '100',
        'single_min_amount' => '1.00',
        'single_max_amount' => '100000.00',
        'rate' => '0.88',
        'status_code' => 1,
        'plugin_config' => $pluginConfig,
        'validate_plugin_config' => true,
    ]);

    $recharge = MerchantFundService::createRechargeOrder($merchantId, [
        'amount' => '120.00',
        'client_ip' => '127.0.0.1',
        'type' => $methodCode,
    ]);
    $rechargeTradeNo = (string)($recharge['trade_no'] ?? '');

    OrderService::completeByTradeNo($rechargeTradeNo, [
        'source' => 'verify-merchant-auth-fund-closure',
        'txid' => 'RCH' . date('YmdHis') . random_int(100000, 999999),
        'paid_at' => date('Y-m-d H:i:s'),
    ]);

    $fundsAfterRecharge = ResourceDataService::merchantFunds($merchantId);
    $merchantAfterRecharge = OrderService::gatewayMerchantById($merchantId);
    $gatewayBalanceAfterRecharge = $merchantAfterRecharge ? OrderService::gatewayMerchantBalance($merchantAfterRecharge) : '';

    PackageService::save([
        'name' => $packageName,
        'price' => '20.00',
        'duration_days' => 30,
        'benefits' => ['priority settlement', 'business support'],
        'status_code' => 1,
    ]);

    $packageId = 0;
    foreach (PackageService::all()['items'] as $package) {
        if ((string)($package['name'] ?? '') === $packageName) {
            $packageId = (int)($package['id'] ?? 0);
            break;
        }
    }

    $packagePurchase = PackageService::buy($merchantId, ['package_id' => $packageId]);
    $fundsAfterPackage = ResourceDataService::merchantFunds($merchantId);

    $withdrawReject = SettlementService::requestWithdraw($merchantId, $userId, [
        'money' => '30.00',
        'account_type' => 'alipay',
        'account' => 'verify-reject@example.com',
        'account_name' => 'Verify User',
        'out_settle_no' => 'WITHDRAW-REJECT-' . random_int(100000, 999999),
    ]);
    $rejectSettleNo = (string)($withdrawReject['settlement']['settle_no'] ?? '');
    $rejectReview = SettlementService::review($rejectSettleNo, 'reject', 'verify-script', 'reject path verify');
    $fundsAfterReject = ResourceDataService::merchantFunds($merchantId);

    $withdrawApprove = SettlementService::requestWithdraw($merchantId, $userId, [
        'money' => '40.00',
        'account_type' => 'bank',
        'account' => '6222020000001234567',
        'account_name' => 'Verify User',
        'out_settle_no' => 'WITHDRAW-APPROVE-' . random_int(100000, 999999),
    ]);
    $approveSettleNo = (string)($withdrawApprove['settlement']['settle_no'] ?? '');
    $approveReview = SettlementService::review($approveSettleNo, 'approve', 'verify-script', '');
    $fundsAfterApprove = ResourceDataService::merchantFunds($merchantId);

    $dbBalance = null;
    if (database_available()) {
        $dbBalance = MerchantBalance::where('merchant_id', $merchantId)->find();
    }

    $result = [
        'merchant_id' => $merchantId,
        'user_id' => $userId,
        'register_trade_no' => $registerTradeNo,
        'login_blocked_before_payment' => $loginBlockedBeforePayment,
        'login_blocked_before_payment_message' => $loginBlockedMessage,
        'register_paid_pending_audit' => $registerPaidPendingAudit,
        'merchant_review_status' => (string)($review['merchant']['audit_status'] ?? ''),
        'login_after_approve_user_id' => (int)($loginAfterApprove['id'] ?? 0),
        'realname_submit_status' => (string)($profileAfterSubmit['realname']['status'] ?? ''),
        'withdraw_blocked_before_realname' => $withdrawBlockedBeforeRealname,
        'withdraw_blocked_before_realname_message' => $withdrawBlockedMessage,
        'realname_review_status' => (string)($profileAfterReview['realname']['status'] ?? ''),
        'recharge_method_code' => $methodCode,
        'recharge_plugin_code' => $pluginCode,
        'recharge_trade_no' => $rechargeTradeNo,
        'balance_after_recharge' => (string)($fundsAfterRecharge['balance']['available'] ?? ''),
        'total_recharge_after_recharge' => (string)($fundsAfterRecharge['balance']['total_recharge'] ?? ''),
        'gateway_balance_after_recharge' => $gatewayBalanceAfterRecharge,
        'package_id' => $packageId,
        'package_order_no' => (string)($packagePurchase['order_no'] ?? ''),
        'balance_after_package' => (string)($fundsAfterPackage['balance']['available'] ?? ''),
        'balance_after_reject' => (string)($fundsAfterReject['balance']['available'] ?? ''),
        'balance_after_approve' => (string)($fundsAfterApprove['balance']['available'] ?? ''),
        'reject_settlement_status' => (string)($rejectReview['settlement']['status'] ?? ''),
        'approve_settlement_status' => (string)($approveReview['settlement']['status'] ?? ''),
        'db_balance' => $dbBalance ? [
            'balance' => number_format((float)($dbBalance->balance ?? 0), 2, '.', ''),
            'total_recharge' => number_format((float)($dbBalance->total_recharge ?? 0), 2, '.', ''),
            'total_consumption' => number_format((float)($dbBalance->total_consumption ?? 0), 2, '.', ''),
        ] : null,
    ];

    $ok = !empty($registered['payment_required'])
        && $loginBlockedBeforePayment
        && $registerPaidPendingAudit
        && (string)($review['merchant']['audit_status'] ?? '') === 'approved'
        && (int)($loginAfterApprove['id'] ?? 0) === $userId
        && (string)($profileAfterSubmit['realname']['status'] ?? '') === 'pending'
        && $withdrawBlockedBeforeRealname
        && (string)($profileAfterReview['realname']['status'] ?? '') === 'approved'
        && $rechargeTradeNo !== ''
        && (string)($fundsAfterRecharge['balance']['available'] ?? '') === '120.00'
        && (string)($fundsAfterRecharge['balance']['total_recharge'] ?? '') === '120.00'
        && $gatewayBalanceAfterRecharge === '120.00'
        && $packageId > 0
        && (string)($packagePurchase['order_no'] ?? '') !== ''
        && (string)($fundsAfterPackage['balance']['available'] ?? '') === '100.00'
        && (string)($rejectReview['settlement']['status_code'] ?? '') === '2'
        && (string)($fundsAfterReject['balance']['available'] ?? '') === '100.00'
        && (string)($approveReview['settlement']['status_code'] ?? '') === '1'
        && (string)($fundsAfterApprove['balance']['available'] ?? '') === '60.00'
        && (
            !$dbBalance
            || (
                number_format((float)($dbBalance->balance ?? 0), 2, '.', '') === '60.00'
                && number_format((float)($dbBalance->total_recharge ?? 0), 2, '.', '') === '120.00'
                && number_format((float)($dbBalance->total_consumption ?? 0), 2, '.', '') === '60.00'
            )
        );

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    foreach ($stores as $store) {
        JsonStoreService::save($store, $backups[$store]);
    }

    if (database_available()) {
        try {
            if ($packageName !== '') {
                Db::table('packages')->where('name', $packageName)->delete();
            }
        } catch (Throwable) {
        }

        try {
            if ($merchantId > 0) {
                CallbackQueue::where('merchant_id', $merchantId)->delete();
            }
        } catch (Throwable) {
        }

        try {
            if ($merchantId > 0) {
                Order::where('merchant_id', $merchantId)->delete();
            }
        } catch (Throwable) {
        }

        try {
            if ($merchantId > 0) {
                MerchantBalance::where('merchant_id', $merchantId)->delete();
            }
        } catch (Throwable) {
        }

        try {
            if ($merchantId > 0) {
                MerchantUser::where('merchant_id', $merchantId)->delete();
            }
        } catch (Throwable) {
        }

        try {
            if ($merchantId > 0) {
                Merchant::where('id', $merchantId)->delete();
            }
        } catch (Throwable) {
        }
    }
}

exit($ok ? 0 : 1);

function resolveUsableChannelDefinition(): array
{
    $methods = PluginService::methods();
    $plugins = PluginService::plugins();

    foreach ($methods as $method) {
        if ((int)($method['status_code'] ?? 0) !== 1) {
            continue;
        }

        $methodCode = PaymentMetaService::normalizeMethodCode((string)($method['code'] ?? ''));
        foreach ($plugins as $plugin) {
            if ((int)($plugin['status_code'] ?? 0) !== 1) {
                continue;
            }
            if (($plugin['group'] ?? 'pay') !== 'pay') {
                continue;
            }

            $supported = false;
            foreach ((array)($plugin['payment_methods'] ?? []) as $pluginMethod) {
                if (PaymentMetaService::normalizeMethodCode((string)$pluginMethod) === $methodCode) {
                    $supported = true;
                    break;
                }
            }

            if (!$supported) {
                continue;
            }

            $pluginCode = PluginCodeService::normalize((string)($plugin['code'] ?? ''));
            if ($pluginCode === '') {
                continue;
            }

            return [$methodCode, $pluginCode, buildPluginConfig($plugin, $methodCode)];
        }
    }

    throw new RuntimeException('no active method/plugin pair available for recharge closure verification');
}

function buildPluginConfig(array $plugin, string $methodCode): array
{
    $config = [];

    foreach ((array)($plugin['settings_schema'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !PluginSchemaService::isFieldVisible($field, $methodCode, $config)) {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $default = $plugin['default_settings'][$key] ?? $field['default'] ?? null;
        if ($default !== null && trim((string)$default) !== '') {
            $config[$key] = (string)$default;
            continue;
        }

        $config[$key] = match ($type) {
            'number' => '1',
            'select', 'radio' => firstOptionValue($field['options'] ?? null),
            'checkbox', 'switch' => '1',
            default => 'verify-placeholder',
        };
    }

    return $config;
}

function firstOptionValue(mixed $options): string
{
    if (is_array($options)) {
        $first = reset($options);
        if (is_array($first)) {
            return (string)($first['value'] ?? $first['key'] ?? $first['id'] ?? '1');
        }

        if ($first !== false) {
            return (string)$first;
        }
    }

    return '1';
}
