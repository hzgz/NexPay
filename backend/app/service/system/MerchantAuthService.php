<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\model\Merchant;
use app\model\MerchantBalance;
use app\model\MerchantUser;
use app\model\Order;
use app\service\payment\LocalOrderStore;
use app\service\payment\OrderService;
use Throwable;
use think\facade\Db;

class MerchantAuthService
{
    private const STORE_KEY = 'merchant_auth_users';

    public static function authSettings(): array
    {
        return AuthPolicyService::merchantConfig();
    }

    public static function login(array $payload, string $ip = ''): array
    {
        $username = trim((string)($payload['username'] ?? ''));
        $password = (string)($payload['password'] ?? '');

        AuthPolicyService::ensureMerchantLoginAllowed($payload);

        if ($username === '' || $password === '') {
            throw new BusinessException('请输入商户账号和登录密码', StatusCode::VALIDATION_ERROR);
        }

        if (database_available()) {
            try {
                $user = MerchantUser::where('username', $username)->where('status', 1)->find();
                if ($user && password_verify($password, (string)$user->password_hash)) {
                    $merchant = Merchant::find((int)$user->merchant_id);
                    if (!$merchant) {
                        throw new BusinessException('商户资料不存在', StatusCode::NOT_FOUND);
                    }
                    $runtimeRecord = self::storage()[$username] ?? null;
                    if (is_array($runtimeRecord) && (
                        (string)($runtimeRecord['audit_status'] ?? '') === 'pending_payment'
                        || (string)($runtimeRecord['register_fee_status'] ?? '') === 'pending'
                    )) {
                        throw new BusinessException('商户注册费待支付，请完成支付后再登录', StatusCode::BUSINESS_ERROR);
                    }

                    self::assertMerchantActive((int)($merchant->status ?? 0));

                    $merchant->last_login_ip = $ip;
                    $merchant->last_login_time = date('Y-m-d H:i:s');
                    $merchant->save();

                    return self::shapeUser([
                        'id' => (int)$user->id,
                        'merchant_id' => (int)$user->merchant_id,
                        'username' => (string)$user->username,
                        'nickname' => (string)$user->nickname,
                        'email' => (string)($user->email ?? ''),
                        'phone' => (string)($user->phone ?? ''),
                        'merchant_name' => (string)($merchant->name ?? ''),
                        'status' => (int)($merchant->status ?? 0),
                    ]);
                }
            } catch (Throwable) {
            }
        }

        $users = self::storage();
        if (!isset($users[$username]) || !password_verify($password, (string)$users[$username]['password_hash'])) {
            throw new BusinessException('商户账号或密码错误', StatusCode::UNAUTHORIZED);
        }
        if ((string)($users[$username]['audit_status'] ?? '') === 'pending_payment') {
            throw new BusinessException('商户注册费待支付，请完成支付后再登录', StatusCode::BUSINESS_ERROR);
        }
        self::assertMerchantActive((int)($users[$username]['status'] ?? 0));

        $users[$username]['last_login_ip'] = $ip;
        $users[$username]['last_login_time'] = date('Y-m-d H:i:s');
        self::saveStorage($users);

        return self::shapeUser([
            'id' => (int)$users[$username]['id'],
            'merchant_id' => (int)$users[$username]['merchant_id'],
            'username' => (string)$users[$username]['username'],
            'nickname' => (string)$users[$username]['nickname'],
            'email' => (string)$users[$username]['email'],
            'phone' => (string)$users[$username]['phone'],
            'merchant_name' => (string)$users[$username]['merchant_name'],
            'status' => 1,
        ]);
    }

    public static function register(array $payload, string $ip = ''): array
    {
        $merchantName = trim((string)($payload['merchant_name'] ?? ''));
        $contactName = trim((string)($payload['contact_name'] ?? ''));
        $username = trim((string)($payload['username'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        $confirmPassword = (string)($payload['confirm_password'] ?? '');
        $registerFeeMethodCode = SettingsService::resolveEnabledPaymentMethodCode(
            'system_checkout',
            (string)($payload['register_fee_method_code'] ?? '')
        );

        AuthPolicyService::ensureMerchantRegisterAllowed($payload);
        self::validateRegistration($merchantName, $contactName, $username, $email, $phone, $password, $confirmPassword);
        $autoAudit = self::registerAutoAuditEnabled();
        $registerFee = self::registrationFeeAmount();
        $feeEnabled = (float)$registerFee > 0;
        $merchantStatus = $feeEnabled ? 0 : ($autoAudit ? 1 : 0);
        $auditStatus = $feeEnabled ? 'pending_payment' : ($merchantStatus === 1 ? 'approved' : 'pending');

        if (database_available()) {
            try {
                return Db::transaction(function () use ($merchantName, $contactName, $username, $email, $phone, $password, $ip, $merchantStatus, $auditStatus, $feeEnabled, $registerFee, $autoAudit, $registerFeeMethodCode) {
                    $merchant = new Merchant();
                    $merchant->uid = self::generateUid();
                    $merchant->appid = self::generateAppId();
                    $merchant->mch_key = self::generateMerchantKey();
                    $merchant->rsa_private_key = '';
                    $merchant->rsa_public_key = '';
                    $merchant->name = $merchantName;
                    $merchant->contact_name = $contactName;
                    $merchant->email = $email;
                    $merchant->phone = $phone;
                    $merchant->status = $merchantStatus;
                    $merchant->platform_rate = 0.80;
                    $merchant->daily_limit = 0;
                    $merchant->white_ip = '';
                    $merchant->notify_url = '';
                    $merchant->return_url = '';
                    $merchant->registered_ip = $ip;
                    $merchant->last_login_ip = $ip;
                    $merchant->last_login_time = date('Y-m-d H:i:s');
                    $merchant->save();
                    self::syncDatabaseMerchantAppId($merchant);

                    $user = new MerchantUser();
                    $user->merchant_id = (int)$merchant->id;
                    $user->username = $username;
                    $user->nickname = $merchantName;
                    $user->email = $email;
                    $user->phone = $phone;
                    $user->password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $user->status = 1;
                    $user->save();

                    $balance = new MerchantBalance();
                    $balance->merchant_id = (int)$merchant->id;
                    $balance->balance = 0;
                    $balance->frozen_balance = 0;
                    $balance->total_recharge = 0;
                    $balance->total_consumption = 0;
                    $balance->save();

                    $userPayload = [
                        'id' => (int)$user->id,
                        'merchant_id' => (int)$merchant->id,
                        'username' => (string)$user->username,
                        'nickname' => (string)$user->nickname,
                        'email' => (string)$user->email,
                        'phone' => (string)$user->phone,
                        'merchant_name' => (string)$merchant->name,
                        'contact_name' => $contactName,
                        'password_hash' => (string)$user->password_hash,
                        'status' => (int)$merchant->status,
                        'audit_status' => $auditStatus,
                        'appid' => (string)$merchant->id,
                        'mch_key' => (string)$merchant->mch_key,
                        'register_fee_status' => $feeEnabled ? 'pending' : 'none',
                        'register_fee_amount' => $registerFee,
                        'register_fee_method_code' => $registerFeeMethodCode,
                    ];

                    if ($feeEnabled) {
                        $order = self::createRegistrationFeeOrder($userPayload, $registerFee, $autoAudit, $ip);
                        $userPayload['register_fee_trade_no'] = (string)$order->trade_no;
                        $userPayload['register_fee_pay_url'] = self::paymentUrl((string)$order->trade_no);
                        self::syncAuthStore($userPayload);
                        self::syncAccountStore($userPayload);
                        return self::attachRegistrationPayment($userPayload, $order, $registerFee);
                    }

                    self::syncAuthStore($userPayload);
                    self::syncAccountStore($userPayload);
                    return self::shapeUser($userPayload);
                });
            } catch (Throwable) {
            }
        }

        $storage = self::storage();
        if (isset($storage[$username])) {
            throw new BusinessException('该商户账号已存在', StatusCode::VALIDATION_ERROR);
        }

        $id = self::nextStorageId($storage);
        $storage[$username] = [
            'id' => $id,
            'merchant_id' => $id,
            'username' => $username,
            'nickname' => $merchantName,
            'email' => $email,
            'phone' => $phone,
            'merchant_name' => $merchantName,
            'contact_name' => $contactName,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'status' => $merchantStatus,
            'audit_status' => $auditStatus,
            'appid' => (string)$id,
            'mch_key' => self::generateLocalMerchantKey($storage),
            'registered_ip' => $ip,
            'last_login_ip' => $ip,
            'last_login_time' => date('Y-m-d H:i:s'),
            'register_fee_status' => $feeEnabled ? 'pending' : 'none',
            'register_fee_amount' => $registerFee,
            'register_fee_method_code' => $registerFeeMethodCode,
        ];
        self::saveStorage($storage);

        $paymentOrder = null;
        if ($feeEnabled) {
            $paymentOrder = self::createRegistrationFeeOrder($storage[$username], $registerFee, $autoAudit, $ip);
            $storage[$username]['register_fee_trade_no'] = (string)$paymentOrder->trade_no;
            $storage[$username]['register_fee_pay_url'] = self::paymentUrl((string)$paymentOrder->trade_no);
            self::saveStorage($storage);
        }

        self::syncAccountStore($storage[$username]);

        if ($paymentOrder !== null) {
            return self::attachRegistrationPayment($storage[$username], $paymentOrder, $registerFee);
        }

        return self::shapeUser($storage[$username]);
    }

    public static function registerByAdmin(array $payload): array
    {
        $merchantName = trim((string)($payload['merchant_name'] ?? ''));
        $contactName = trim((string)($payload['contact_name'] ?? ''));
        $username = trim((string)($payload['username'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        $groupName = trim((string)($payload['group_name'] ?? ''));
        $statusCode = (int)($payload['status_code'] ?? 1);
        $rate = number_format((float)($payload['rate'] ?? 0.80), 2, '.', '');

        if ($merchantName === '' || $contactName === '' || $username === '' || $password === '') {
            throw new BusinessException('请完整填写商户信息', StatusCode::VALIDATION_ERROR);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('邮箱格式不正确', StatusCode::VALIDATION_ERROR);
        }
        if ($phone !== '' && !preg_match('/^\d{11,20}$/', preg_replace('/\D+/', '', $phone))) {
            throw new BusinessException('手机号格式不正确', StatusCode::VALIDATION_ERROR);
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{3,31}$/', $username)) {
            throw new BusinessException('商户账号需为 4-32 位字母、数字、下划线或中划线', StatusCode::VALIDATION_ERROR);
        }
        if (mb_strlen($password) < 8) {
            throw new BusinessException('登录密码至少 8 位', StatusCode::VALIDATION_ERROR);
        }

        $storage = self::storage();
        if (isset($storage[$username])) {
            throw new BusinessException('该商户账号已存在', StatusCode::VALIDATION_ERROR);
        }

        $id = self::nextStorageId($storage);
        $record = [
            'id' => $id,
            'merchant_id' => $id,
            'username' => $username,
            'nickname' => $merchantName,
            'email' => $email,
            'phone' => $phone,
            'merchant_name' => $merchantName,
            'contact_name' => $contactName,
            'group_name' => $groupName,
            'platform_rate' => $rate,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'status' => $statusCode,
            'appid' => (string)$id,
            'mch_key' => self::generateLocalMerchantKey($storage),
            'registered_ip' => '127.0.0.1',
            'last_login_ip' => '',
            'last_login_time' => '',
        ];

        $storage[$username] = $record;
        self::saveStorage($storage);
        self::syncAccountStore($record);

        return self::shapeUser($record);
    }

    public static function resetPassword(array $payload): void
    {
        $username = trim((string)($payload['username'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $verifyCode = trim((string)($payload['verify_code'] ?? ''));
        $newPassword = (string)($payload['new_password'] ?? '');
        $confirmPassword = (string)($payload['confirm_password'] ?? '');

        AuthPolicyService::ensureMerchantForgotAllowed($payload);

        if ($username === '' || $email === '' || $newPassword === '' || $confirmPassword === '') {
            throw new BusinessException('请完整填写找回信息', StatusCode::VALIDATION_ERROR);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('邮箱格式不正确', StatusCode::VALIDATION_ERROR);
        }
        if ($newPassword !== $confirmPassword) {
            throw new BusinessException('两次输入的密码不一致', StatusCode::VALIDATION_ERROR);
        }
        if (mb_strlen($newPassword) < 8) {
            throw new BusinessException('新密码至少 8 位', StatusCode::VALIDATION_ERROR);
        }
        if ($verifyCode === '') {
            throw new BusinessException('请先获取并填写邮箱验证码', StatusCode::VALIDATION_ERROR);
        }

        ProviderRuntimeService::verifyCode('merchant_forgot', 'mail', $email, $verifyCode);

        if (database_available()) {
            try {
                $user = MerchantUser::where('username', $username)->where('email', $email)->find();
                if ($user) {
                    $user->password_hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $user->save();
                    return;
                }
            } catch (Throwable) {
            }
        }

        $storage = self::storage();
        if (!isset($storage[$username]) || (string)$storage[$username]['email'] !== $email) {
            throw new BusinessException('未找到匹配的商户账号', StatusCode::NOT_FOUND);
        }

        $storage[$username]['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
        self::saveStorage($storage);
    }

    public static function sendForgotCode(array $payload): array
    {
        $username = trim((string)($payload['username'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        AuthPolicyService::ensureMerchantForgotAllowed($payload);

        if ($username === '' || $email === '') {
            throw new BusinessException('请填写商户账号和注册邮箱', StatusCode::VALIDATION_ERROR);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('邮箱格式不正确', StatusCode::VALIDATION_ERROR);
        }

        if (database_available()) {
            try {
                $user = MerchantUser::where('username', $username)->where('email', $email)->find();
                if ($user) {
                    return ProviderRuntimeService::sendVerifyCode('merchant_forgot', 'mail', $email);
                }
            } catch (Throwable) {
            }
        }

        $storage = self::storage();
        if (!isset($storage[$username]) || (string)$storage[$username]['email'] !== $email) {
            throw new BusinessException('未找到匹配的商户账号', StatusCode::NOT_FOUND);
        }

        return ProviderRuntimeService::sendVerifyCode('merchant_forgot', 'mail', $email);
    }

    public static function completeRegistrationPayment(object $order): void
    {
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        if (($meta['business'] ?? '') !== 'merchant_register_fee') {
            return;
        }

        $merchantId = (int)($meta['merchant_id'] ?? $order->merchant_id ?? 0);
        $username = trim((string)($meta['username'] ?? ''));
        $nextStatus = (int)($meta['next_status'] ?? 0);
        $nextAuditStatus = $nextStatus === 1 ? 'approved' : 'pending';

        if (database_available() && $merchantId > 0) {
            try {
                $merchant = Merchant::find($merchantId);
                if ($merchant) {
                    $merchant->status = $nextStatus;
                    $merchant->save();
                }
            } catch (Throwable) {
            }
        }

        $storage = self::storage();
        foreach ($storage as $key => $record) {
            if (!is_array($record)) {
                continue;
            }

            $matched = ($username !== '' && $key === $username)
                || ($merchantId > 0 && (int)($record['merchant_id'] ?? $record['id'] ?? 0) === $merchantId);
            if (!$matched) {
                continue;
            }

            $storage[$key]['status'] = $nextStatus;
            $storage[$key]['audit_status'] = $nextAuditStatus;
            $storage[$key]['register_fee_status'] = 'paid';
            $storage[$key]['register_fee_paid_at'] = (string)($order->pay_time ?? date('Y-m-d H:i:s'));
            $storage[$key]['register_fee_trade_no'] = (string)($order->trade_no ?? '');
            self::saveStorage($storage);
            self::syncAccountStore($storage[$key]);
            return;
        }

        $record = self::registrationRecordFromDatabase($merchantId, $username);
        if ($record === null) {
            $record = [
                'id' => (int)($meta['user_id'] ?? 0),
                'merchant_id' => $merchantId,
                'username' => $username,
                'nickname' => $username,
                'merchant_name' => $username,
                'email' => '',
                'phone' => '',
                'password_hash' => '',
            ];
        }

        $record = array_replace($record, [
            'status' => $nextStatus,
            'audit_status' => $nextAuditStatus,
            'register_fee_status' => 'paid',
            'register_fee_paid_at' => (string)($order->pay_time ?? date('Y-m-d H:i:s')),
            'register_fee_trade_no' => (string)($order->trade_no ?? ''),
            'register_fee_pay_url' => self::paymentUrl((string)($order->trade_no ?? '')),
            'register_fee_amount' => number_format((float)($order->amount ?? 0), 2, '.', ''),
        ]);
        self::syncAuthStore($record);
        self::syncAccountStore($record);
    }

    private static function validateRegistration(
        string $merchantName,
        string $contactName,
        string $username,
        string $email,
        string $phone,
        string $password,
        string $confirmPassword
    ): void {
        if ($merchantName === '' || $contactName === '' || $username === '' || $email === '' || $phone === '' || $password === '') {
            throw new BusinessException('请完整填写注册信息', StatusCode::VALIDATION_ERROR);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('邮箱格式不正确', StatusCode::VALIDATION_ERROR);
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{3,31}$/', $username)) {
            throw new BusinessException('账号需为 4-32 位字母、数字、下划线或中划线', StatusCode::VALIDATION_ERROR);
        }
        if (!preg_match('/^\d{11,20}$/', preg_replace('/\D+/', '', $phone))) {
            throw new BusinessException('手机号格式不正确', StatusCode::VALIDATION_ERROR);
        }
        if (mb_strlen($password) < 8) {
            throw new BusinessException('密码至少 8 位', StatusCode::VALIDATION_ERROR);
        }
        if ($password !== $confirmPassword) {
            throw new BusinessException('两次输入的密码不一致', StatusCode::VALIDATION_ERROR);
        }
    }

    private static function registerAutoAuditEnabled(): bool
    {
        $settings = SettingsService::all(false);
        $auth = is_array($settings['auth'] ?? null) ? $settings['auth'] : [];
        $merchant = is_array($settings['merchant'] ?? null) ? $settings['merchant'] : [];

        return (bool)($auth['register_auto_audit'] ?? $merchant['register_auto_audit'] ?? false);
    }

    private static function registrationFeeAmount(): string
    {
        $settings = SettingsService::all(false);
        $auth = is_array($settings['auth'] ?? null) ? $settings['auth'] : [];
        $merchant = is_array($settings['merchant'] ?? null) ? $settings['merchant'] : [];
        $enabled = (bool)($auth['merchant_register_fee_enabled'] ?? $merchant['register_fee_enabled'] ?? false);
        $amount = number_format((float)($auth['merchant_register_fee'] ?? $merchant['register_fee'] ?? '0.00'), 2, '.', '');

        return $enabled && (float)$amount > 0 ? $amount : '0.00';
    }

    private static function createRegistrationFeeOrder(array $record, string $amount, bool $autoAudit, string $ip = ''): object
    {
        $username = trim((string)($record['username'] ?? ''));
        $merchantId = (int)($record['merchant_id'] ?? $record['id'] ?? 0);
        $methodCode = SettingsService::resolveEnabledPaymentMethodCode(
            'system_checkout',
            (string)($record['register_fee_method_code'] ?? '')
        );
        if ($methodCode === '') {
            throw new BusinessException('请先在后台系统业务支付配置中启用至少一种支付方式', StatusCode::BUSINESS_ERROR);
        }
        $outTradeNo = OrderService::normalizeGatewayOutTradeNo(
            'REG-' . date('YmdHis') . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $username)
        );

        return SystemBusinessPaymentService::createBusinessOrder(
            'system_checkout',
            $merchantId,
            'merchant_register_fee',
            [
            'merchant_id' => $merchantId,
            'out_trade_no' => $outTradeNo !== '' ? $outTradeNo : SystemBusinessPaymentService::fallbackBusinessOutTradeNo('REG', $merchantId, [$username]),
            'channel_code' => $methodCode,
            'channel_category' => 2,
            'force_configured_gateway' => true,
            'subject' => '商户注册费',
            'amount' => $amount,
            'notify_url' => '',
            'return_url' => '/user/login',
            'client_ip' => $ip,
            'param' => 'merchant-register-fee',
            'expire_time' => date('Y-m-d H:i:s', time() + \app\service\payment\OrderService::DEFAULT_EXPIRE_SECONDS),
            'request_payload' => [
                '_meta' => [
                    'business' => 'merchant_register_fee',
                    'username' => $username,
                    'merchant_id' => $merchantId,
                    'user_id' => (int)($record['id'] ?? 0),
                    'requested_method' => $methodCode,
                    'next_status' => $autoAudit ? 1 : 0,
                    'next_audit_status' => $autoAudit ? 'approved' : 'pending',
                ],
            ],
            ]
        );
    }

    private static function attachRegistrationPayment(array $user, object $order, string $amount): array
    {
        $user['payment_required'] = true;
        $user['payment_order'] = [
            'trade_no' => (string)$order->trade_no,
            'amount' => $amount,
            'channel_code' => (string)($order->channel_code ?? ''),
            'pay_url' => self::paymentUrl((string)$order->trade_no),
            'submit_url' => self::paymentUrl((string)$order->trade_no),
            'checkout_url' => SystemBusinessPaymentService::checkoutUrl((string)$order->trade_no),
            'expire_time' => (string)$order->expire_time,
        ];

        return self::shapeUser($user);
    }

    private static function paymentUrl(string $tradeNo): string
    {
        return SystemBusinessPaymentService::submitUrl($tradeNo);
    }

    private static function assertMerchantActive(int $status): void
    {
        if ($status === 1) {
            return;
        }

        if ($status === 0) {
            throw new BusinessException('商户账号待审核，请等待管理员审核通过', StatusCode::BUSINESS_ERROR);
        }

        throw new BusinessException('商户账号已停用，请联系管理员', StatusCode::BUSINESS_ERROR);
    }

    private static function storage(): array
    {
        $storage = JsonStoreService::load(self::STORE_KEY, []);

        return self::normalizeAuthStorage($storage, true);
    }

    private static function saveStorage(array $storage): void
    {
        JsonStoreService::save(self::STORE_KEY, self::normalizeAuthStorage($storage));
    }

    private static function syncAuthStore(array $record): void
    {
        $username = trim((string)($record['username'] ?? ''));
        if ($username === '') {
            return;
        }

        $storage = self::storage();
        $existing = is_array($storage[$username] ?? null) ? $storage[$username] : [];
        $storage[$username] = AccountService::normalizeMerchantRuntimeRow(array_replace($existing, $record));
        self::saveStorage($storage);
    }

    private static function syncAccountStore(array $record): void
    {
        $accounts = JsonStoreService::load('merchant_accounts', []);
        $map = [];

        foreach ($accounts as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item = AccountService::normalizeMerchantRuntimeRow($item);

            $username = trim((string)($item['username'] ?? ''));
            if ($username !== '') {
                $map[$username] = $item;
            }
        }

        $username = trim((string)($record['username'] ?? ''));
        if ($username === '') {
            return;
        }

        $existing = is_array($map[$username] ?? null) ? $map[$username] : [];
        $map[$username] = AccountService::normalizeMerchantRuntimeRow(array_replace($existing, [
            'id' => (int)($record['id'] ?? 0),
            'merchant_id' => (int)($record['merchant_id'] ?? 0),
            'username' => $username,
            'nickname' => (string)($record['nickname'] ?? ''),
            'avatar' => (string)($existing['avatar'] ?? ''),
            'merchant_name' => (string)($record['merchant_name'] ?? ''),
            'contact_name' => (string)($record['contact_name'] ?? ''),
            'email' => (string)($record['email'] ?? ''),
            'phone' => (string)($record['phone'] ?? ''),
            'notify_url' => (string)($existing['notify_url'] ?? ''),
            'return_url' => (string)($existing['return_url'] ?? ''),
            'white_ip' => is_array($existing['white_ip'] ?? null) ? $existing['white_ip'] : [],
            'realname' => is_array($existing['realname'] ?? null) ? $existing['realname'] : [
                'status' => '未认证',
                'real_name' => '',
                'id_card' => '',
                'submitted_at' => '',
            ],
            'security' => is_array($existing['security'] ?? null) ? $existing['security'] : [
                'email_bound' => false,
                'password_updated_at' => date('Y-m-d H:i:s'),
            ],
            'notifications' => is_array($existing['notifications'] ?? null) ? $existing['notifications'] : [
                'email' => false,
                'sms' => false,
                'telegram' => false,
                'order_paid' => true,
                'ticket_reply' => true,
            ],
            'bindings' => is_array($existing['bindings'] ?? null) ? $existing['bindings'] : [
                'qq' => '未绑定',
                'wechat' => '未绑定',
                'alipay' => '未绑定',
                'google' => '未绑定',
                'telegram' => '未绑定',
            ],
            'login_logs' => is_array($existing['login_logs'] ?? null) ? $existing['login_logs'] : [],
            'password_hash' => (string)($record['password_hash'] ?? ''),
            'group_name' => (string)($record['group_name'] ?? ''),
            'platform_rate' => (string)($record['platform_rate'] ?? '0.80'),
            'appid' => self::canonicalAppId((int)($record['merchant_id'] ?? $record['id'] ?? 0), $record['appid'] ?? ''),
            'mch_key' => (string)($record['mch_key'] ?? ''),
            'status' => (int)($record['status'] ?? $existing['status'] ?? 1),
            'audit_status' => (string)($record['audit_status'] ?? $existing['audit_status'] ?? ''),
            'audit_reason' => (string)($record['audit_reason'] ?? $existing['audit_reason'] ?? ''),
            'audited_at' => (string)($record['audited_at'] ?? $existing['audited_at'] ?? ''),
            'audited_by' => (string)($record['audited_by'] ?? $existing['audited_by'] ?? ''),
            'register_fee_status' => (string)($record['register_fee_status'] ?? $existing['register_fee_status'] ?? 'none'),
            'register_fee_amount' => (string)($record['register_fee_amount'] ?? $existing['register_fee_amount'] ?? '0.00'),
            'register_fee_trade_no' => (string)($record['register_fee_trade_no'] ?? $existing['register_fee_trade_no'] ?? ''),
            'register_fee_pay_url' => (string)($record['register_fee_pay_url'] ?? $existing['register_fee_pay_url'] ?? ''),
            'register_fee_paid_at' => (string)($record['register_fee_paid_at'] ?? $existing['register_fee_paid_at'] ?? ''),
        ]));

        JsonStoreService::save('merchant_accounts', array_values($map));
    }

    private static function normalizeAuthStorage(array $storage, bool $persistIfChanged = false): array
    {
        $normalized = [];
        $changed = false;

        foreach ($storage as $key => $record) {
            if (!is_array($record)) {
                $changed = true;
                continue;
            }

            $record['username'] = trim((string)($record['username'] ?? $key));
            if ($record['username'] === '') {
                $changed = true;
                continue;
            }

            $normalizedRow = AccountService::normalizeMerchantRuntimeRow($record);

            if ($normalizedRow !== $record) {
                $changed = true;
            }

            $normalized[$record['username']] = $normalizedRow;
        }

        if ($persistIfChanged && ($changed || count($normalized) !== count($storage))) {
            JsonStoreService::save(self::STORE_KEY, $normalized);
        }

        return $normalized;
    }

    private static function registrationRecordFromDatabase(int $merchantId, string $username): ?array
    {
        if (!database_available()) {
            return null;
        }

        try {
            $user = null;
            if ($username !== '') {
                $user = MerchantUser::where('username', $username)->find();
            }
            if (!$user && $merchantId > 0) {
                $user = MerchantUser::where('merchant_id', $merchantId)->find();
            }

            $merchant = $merchantId > 0 ? Merchant::find($merchantId) : null;
            if (!$merchant && $user) {
                $merchant = Merchant::find((int)$user->merchant_id);
            }
            if (!$merchant || !$user) {
                return null;
            }

            return [
                'id' => (int)$user->id,
                'merchant_id' => (int)$merchant->id,
                'username' => (string)$user->username,
                'nickname' => (string)$user->nickname,
                'email' => (string)($user->email ?? $merchant->email ?? ''),
                'phone' => (string)($user->phone ?? $merchant->phone ?? ''),
                'merchant_name' => (string)$merchant->name,
                'contact_name' => (string)($merchant->contact_name ?? ''),
                'password_hash' => (string)$user->password_hash,
                'appid' => (string)$merchant->id,
                'mch_key' => (string)$merchant->mch_key,
                'platform_rate' => number_format((float)($merchant->platform_rate ?? 0.80), 2, '.', ''),
                'registered_ip' => (string)($merchant->registered_ip ?? ''),
                'last_login_ip' => (string)($merchant->last_login_ip ?? ''),
                'last_login_time' => (string)($merchant->last_login_time ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private static function nextStorageId(array $storage): int
    {
        $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $storage);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function shapeUser(array $user): array
    {
        unset($user['password_hash']);
        $username = trim((string)($user['username'] ?? ''));
        $merchantId = (int)($user['merchant_id'] ?? $user['id'] ?? 0);
        if (isset($user['nickname'])) {
            $user['nickname'] = AccountService::cleanMerchantDisplayName((string)$user['nickname'], $username, $merchantId);
        }
        if (isset($user['merchant_name'])) {
            $user['merchant_name'] = AccountService::cleanMerchantDisplayName((string)$user['merchant_name'], $username, $merchantId);
        }
        return $user;
    }

    private static function generateUid(): int
    {
        if (!database_available()) {
            return (int)('10' . random_int(10000, 99999));
        }

        do {
            $uid = (int)('10' . random_int(10000, 99999));
        } while (Merchant::where('uid', $uid)->find());

        return $uid;
    }

    private static function generateAppId(): string
    {
        if (!database_available()) {
            return (string)random_int(1000000, 9999999);
        }

        do {
            $appid = (string)random_int(1000000, 9999999);
        } while (Merchant::where('appid', $appid)->find());

        return $appid;
    }

    private static function generateMerchantKey(): string
    {
        if (!database_available()) {
            return bin2hex(random_bytes(16));
        }

        do {
            $key = bin2hex(random_bytes(16));
        } while (Merchant::where('mch_key', $key)->find());

        return $key;
    }

    private static function syncDatabaseMerchantAppId(Merchant $merchant): void
    {
        $merchantId = (int)($merchant->id ?? 0);
        if ($merchantId <= 0) {
            return;
        }

        $merchant->appid = (string)$merchantId;
        $merchant->save();
    }

    private static function canonicalAppId(int $merchantId, mixed $fallback = ''): string
    {
        if ($merchantId > 0) {
            return (string)$merchantId;
        }

        return trim((string)$fallback);
    }

    private static function generateLocalMerchantKey(array $storage): string
    {
        do {
            $key = bin2hex(random_bytes(16));
            $exists = false;
            foreach ($storage as $row) {
                if ((string)($row['mch_key'] ?? '') === $key) {
                    $exists = true;
                    break;
                }
            }
        } while ($exists);

        return $key;
    }

}
