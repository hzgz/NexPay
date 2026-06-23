<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\model\Merchant;
use app\model\MerchantBalance;
use app\model\MerchantUser;
use Throwable;
use think\facade\Db;

class AdminMerchantService
{
    public static function create(array $payload): array
    {
        $merchantName = trim((string)($payload['merchant_name'] ?? ''));
        $contactName = trim((string)($payload['contact_name'] ?? ''));
        $username = trim((string)($payload['username'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $phone = trim((string)($payload['phone'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        $groupName = trim((string)($payload['group_name'] ?? ''));
        $rate = self::normalizeRate($payload['rate'] ?? '0.80');
        $statusCode = self::normalizeStatusCode((int)($payload['status_code'] ?? 1));

        self::validate($merchantName, $contactName, $username, $email, $phone, $password);
        if ($groupName !== '' && !MerchantGroupService::existsName($groupName)) {
            throw new BusinessException('所选用户组不存在', StatusCode::VALIDATION_ERROR);
        }

        if (database_available()) {
            try {
                return Db::transaction(function () use (
                    $merchantName,
                    $contactName,
                    $username,
                    $email,
                    $phone,
                    $password,
                    $rate,
                    $statusCode,
                    $groupName
                ) {
                    if (MerchantUser::where('username', $username)->find()) {
                        throw new BusinessException('商户账号已存在', StatusCode::VALIDATION_ERROR);
                    }

                    if ($email !== '' && MerchantUser::where('email', $email)->find()) {
                        throw new BusinessException('邮箱已被使用', StatusCode::VALIDATION_ERROR);
                    }

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
                    $merchant->status = $statusCode;
                    $merchant->platform_rate = $rate;
                    $merchant->daily_limit = 0;
                    $merchant->white_ip = '';
                    $merchant->notify_url = '';
                    $merchant->return_url = '';
                    $merchant->registered_ip = '127.0.0.1';
                    $merchant->last_login_ip = '';
                    $merchant->last_login_time = null;
                    $merchant->save();
                    self::syncDatabaseMerchantAppId($merchant);

                    $user = new MerchantUser();
                    $user->merchant_id = (int)$merchant->id;
                    $user->username = $username;
                    $user->nickname = $merchantName;
                    $user->email = $email;
                    $user->phone = $phone;
                    $user->password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $user->status = $statusCode === 1 ? 1 : 0;
                    $user->save();

                    $balance = new MerchantBalance();
                    $balance->merchant_id = (int)$merchant->id;
                    $balance->balance = 0;
                    $balance->frozen_balance = 0;
                    $balance->total_recharge = 0;
                    $balance->total_consumption = 0;
                    $balance->save();

                    $account = [
                        'id' => (int)$user->id,
                        'merchant_id' => (int)$merchant->id,
                        'username' => $username,
                        'nickname' => $merchantName,
                        'merchant_name' => $merchantName,
                        'contact_name' => $contactName,
                        'email' => $email,
                        'phone' => $phone,
                        'appid' => (string)$merchant->id,
                        'mch_key' => (string)$merchant->mch_key,
                        'password_hash' => (string)$user->password_hash,
                        'group_name' => $groupName,
                        'platform_rate' => number_format($rate, 2, '.', ''),
                        'status' => $statusCode,
                        'audit_status' => self::statusAuditCode($statusCode),
                        'register_fee_status' => 'none',
                        'register_fee_amount' => '0.00',
                    ];
                    self::syncAuthStore($account);
                    self::syncAccountStore($account);

                    return [
                        'merchant_id' => (int)$merchant->id,
                        'user_id' => (int)$user->id,
                    ];
                });
            } catch (BusinessException $exception) {
                throw $exception;
            } catch (Throwable) {
            }
        }

        $account = MerchantAuthService::registerByAdmin([
            'merchant_name' => $merchantName,
            'contact_name' => $contactName,
            'username' => $username,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
            'group_name' => $groupName,
            'rate' => $rate,
            'status_code' => $statusCode,
        ]);

        return [
            'merchant_id' => (int)($account['merchant_id'] ?? 0),
            'user_id' => (int)($account['id'] ?? 0),
        ];
    }

    public static function review(array $payload, string $operator = 'admin'): array
    {
        $merchantId = (int)($payload['merchant_id'] ?? $payload['id'] ?? 0);
        $username = trim((string)($payload['username'] ?? ''));
        $action = strtolower(trim((string)($payload['action'] ?? '')));
        $reason = trim((string)($payload['reason'] ?? $payload['remark'] ?? ''));

        if ($merchantId <= 0 && $username === '') {
            throw new BusinessException('缺少商户编号或账号', StatusCode::VALIDATION_ERROR);
        }

        if (!in_array($action, ['approve', 'reject', 'disable'], true)) {
            throw new BusinessException('审核动作仅支持 approve、reject 或 disable', StatusCode::VALIDATION_ERROR);
        }

        [$merchantId, $username] = self::resolveMerchantIdentity($merchantId, $username);

        $nextStatus = $action === 'approve' ? 1 : 2;
        $auditStatus = match ($action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            default => 'disabled',
        };

        self::ensureLocalMerchantRecord($merchantId, $username);

        if (database_available() && $merchantId > 0) {
            try {
                $merchant = Merchant::find($merchantId);
                if ($merchant) {
                    $merchant->status = $nextStatus;
                    $merchant->save();

                    MerchantUser::where('merchant_id', $merchantId)->update([
                        'status' => $nextStatus === 1 ? 1 : 0,
                    ]);
                }
            } catch (Throwable) {
            }
        }

        $record = self::updateLocalAuthUser($merchantId, $username, [
            'status' => $nextStatus,
            'audit_status' => $auditStatus,
            'audit_reason' => $reason,
            'audited_at' => date('Y-m-d H:i:s'),
            'audited_by' => trim($operator) !== '' ? trim($operator) : 'admin',
        ]);
        self::updateLocalAccount((int)($record['merchant_id'] ?? $merchantId), (string)($record['username'] ?? $username), [
            'status' => $nextStatus,
            'audit_status' => $auditStatus,
            'audit_reason' => $reason,
            'audited_at' => (string)($record['audited_at'] ?? date('Y-m-d H:i:s')),
            'audited_by' => trim($operator) !== '' ? trim($operator) : 'admin',
        ]);
        self::appendAuditLog($record, $action, $operator, $reason);

        return [
            'merchant' => $record,
            'items' => ResourceDataService::adminMerchants(),
        ];
    }

    private static function validate(
        string $merchantName,
        string $contactName,
        string $username,
        string $email,
        string $phone,
        string $password
    ): void {
        if ($merchantName === '' || $contactName === '' || $username === '' || $password === '') {
            throw new BusinessException('请完整填写商户信息', StatusCode::VALIDATION_ERROR);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('邮箱格式不正确', StatusCode::VALIDATION_ERROR);
        }

        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{3,31}$/', $username)) {
            throw new BusinessException('商户账号需为 4-32 位字母、数字、下划线或中划线', StatusCode::VALIDATION_ERROR);
        }

        if ($phone !== '' && !preg_match('/^\d{11,20}$/', preg_replace('/\D+/', '', $phone))) {
            throw new BusinessException('手机号格式不正确', StatusCode::VALIDATION_ERROR);
        }

        if (mb_strlen($password) < 8) {
            throw new BusinessException('登录密码至少 8 位', StatusCode::VALIDATION_ERROR);
        }
    }

    private static function normalizeRate(mixed $rate): float
    {
        $raw = str_replace('%', '', trim((string)$rate));
        if ($raw === '') {
            return 0.80;
        }

        return round((float)$raw, 2);
    }

    private static function normalizeStatusCode(int $statusCode): int
    {
        return $statusCode === 2 ? 2 : ($statusCode === 0 ? 0 : 1);
    }

    private static function syncAccountStore(array $account): void
    {
        $store = JsonStoreService::load('merchant_accounts', []);
        $store[] = AccountService::normalizeMerchantRuntimeRow($account);
        JsonStoreService::save('merchant_accounts', self::uniqueAccounts($store));
    }

    private static function syncAuthStore(array $record): void
    {
        $username = trim((string)($record['username'] ?? ''));
        if ($username === '') {
            return;
        }

        $store = JsonStoreService::load('merchant_auth_users', []);
        $existing = is_array($store[$username] ?? null) ? $store[$username] : [];
        $store[$username] = AccountService::normalizeMerchantRuntimeRow(array_replace($existing, $record));
        JsonStoreService::save('merchant_auth_users', $store);
    }

    private static function resolveMerchantIdentity(int $merchantId, string $username): array
    {
        if (!database_available()) {
            return [$merchantId, $username];
        }

        try {
            if ($merchantId <= 0 && $username !== '') {
                $user = MerchantUser::where('username', $username)->find();
                if ($user) {
                    $merchantId = (int)$user->merchant_id;
                }
            }

            if ($merchantId > 0 && $username === '') {
                $user = MerchantUser::where('merchant_id', $merchantId)->find();
                if ($user) {
                    $username = (string)$user->username;
                }
            }
        } catch (Throwable) {
        }

        return [$merchantId, $username];
    }

    private static function ensureLocalMerchantRecord(int $merchantId, string $username): void
    {
        if ($merchantId <= 0 && $username === '') {
            return;
        }

        foreach (JsonStoreService::load('merchant_auth_users', []) as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $matched = ($merchantId > 0 && (int)($item['merchant_id'] ?? $item['id'] ?? 0) === $merchantId)
                || ($username !== '' && (string)($item['username'] ?? $key) === $username);
            if ($matched) {
                return;
            }
        }

        foreach (JsonStoreService::load('merchant_accounts', []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $matched = ($merchantId > 0 && (int)($item['merchant_id'] ?? $item['id'] ?? 0) === $merchantId)
                || ($username !== '' && (string)($item['username'] ?? '') === $username);
            if ($matched) {
                self::syncAuthStore($item);
                return;
            }
        }

        $record = self::databaseMerchantRecord($merchantId, $username);
        if ($record !== null) {
            self::syncAuthStore($record);
            self::syncAccountStore($record);
        }
    }

    private static function databaseMerchantRecord(int $merchantId, string $username): ?array
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

            $merchant = null;
            if ($merchantId > 0) {
                $merchant = Merchant::find($merchantId);
            }
            if (!$merchant && $user) {
                $merchant = Merchant::find((int)$user->merchant_id);
            }
            if (!$merchant || !$user) {
                return null;
            }

            $status = (int)($merchant->status ?? 0);

            return [
                'id' => (int)$user->id,
                'merchant_id' => (int)$merchant->id,
                'username' => (string)$user->username,
                'nickname' => (string)$user->nickname,
                'merchant_name' => (string)$merchant->name,
                'contact_name' => (string)($merchant->contact_name ?? ''),
                'email' => (string)($user->email ?? $merchant->email ?? ''),
                'phone' => (string)($user->phone ?? $merchant->phone ?? ''),
                'appid' => (string)$merchant->id,
                'mch_key' => (string)$merchant->mch_key,
                'password_hash' => (string)$user->password_hash,
                'platform_rate' => number_format((float)($merchant->platform_rate ?? 0.80), 2, '.', ''),
                'status' => $status,
                'audit_status' => self::statusAuditCode($status),
                'register_fee_status' => 'none',
                'register_fee_amount' => '0.00',
                'registered_ip' => (string)($merchant->registered_ip ?? ''),
                'last_login_ip' => (string)($merchant->last_login_ip ?? ''),
                'last_login_time' => (string)($merchant->last_login_time ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private static function statusAuditCode(int $status): string
    {
        return match ($status) {
            1 => 'approved',
            2 => 'rejected',
            default => 'pending',
        };
    }

    private static function updateLocalAuthUser(int $merchantId, string $username, array $changes): array
    {
        $users = JsonStoreService::load('merchant_auth_users', []);
        foreach ($users as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $matched = ($merchantId > 0 && (int)($item['merchant_id'] ?? $item['id'] ?? 0) === $merchantId)
                || ($username !== '' && (string)($item['username'] ?? $key) === $username);
            if (!$matched) {
                continue;
            }

            $users[$key] = AccountService::normalizeMerchantRuntimeRow(array_replace($item, $changes));
            JsonStoreService::save('merchant_auth_users', $users);
            return $users[$key] + ['username' => (string)($item['username'] ?? $key)];
        }

        throw new BusinessException('商户账号不存在', StatusCode::NOT_FOUND);
    }

    private static function updateLocalAccount(int $merchantId, string $username, array $changes): void
    {
        $accounts = JsonStoreService::load('merchant_accounts', []);
        foreach ($accounts as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $matched = ($merchantId > 0 && (int)($item['merchant_id'] ?? $item['id'] ?? 0) === $merchantId)
                || ($username !== '' && (string)($item['username'] ?? '') === $username);
            if (!$matched) {
                continue;
            }

            $accounts[$index] = AccountService::normalizeMerchantRuntimeRow(array_replace($item, $changes));
            JsonStoreService::save('merchant_accounts', $accounts);
            return;
        }
    }

    private static function appendAuditLog(array $merchant, string $action, string $operator, string $reason): void
    {
        $logs = JsonStoreService::load('merchant_operation_logs', []);
        $logs[] = [
            'operator' => trim($operator) !== '' ? trim($operator) : 'admin',
            'merchant_id' => (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0),
            'action' => match ($action) {
                'approve' => '商户审核通过：',
                'reject' => '商户审核驳回：',
                default => '商户停用：',
            } . (string)($merchant['username'] ?? ''),
            'ip' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => [
                'username' => (string)($merchant['username'] ?? ''),
                'merchant_id' => (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0),
                'reason' => $reason,
            ],
        ];

        JsonStoreService::save('merchant_operation_logs', $logs);
    }

    private static function uniqueAccounts(array $accounts): array
    {
        $map = [];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $account = AccountService::normalizeMerchantRuntimeRow($account);

            $username = trim((string)($account['username'] ?? ''));
            if ($username === '') {
                continue;
            }

            $map[$username] = $account;
        }

        return array_values($map);
    }

    private static function generateUid(): int
    {
        do {
            $uid = (int)('10' . random_int(10000, 99999));
        } while (Merchant::where('uid', $uid)->find());

        return $uid;
    }

    private static function generateAppId(): string
    {
        do {
            $appid = (string)random_int(1000000, 9999999);
        } while (Merchant::where('appid', $appid)->find());

        return $appid;
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

    private static function generateMerchantKey(): string
    {
        do {
            $key = bin2hex(random_bytes(16));
        } while (Merchant::where('mch_key', $key)->find());

        return $key;
    }
}
