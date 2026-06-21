<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\model\AdminUser;
use Throwable;

class AccountService
{
    private const ADMIN_STORE = 'admin_accounts';
    private const MERCHANT_STORE = 'merchant_accounts';
    private const MERCHANT_AUTH_STORE = 'merchant_auth_users';

    public static function adminLogin(string $username, string $password): array
    {
        if (database_available()) {
            try {
                $admin = AdminUser::where('username', $username)->where('status', 1)->find();
                if ($admin && password_verify($password, (string)$admin->password_hash)) {
                    return self::sanitize(self::row($admin));
                }
            } catch (Throwable) {
            }
        }

        foreach (self::admins() as $admin) {
            if (($admin['username'] ?? '') === $username && password_verify($password, (string)$admin['password_hash'])) {
                return self::sanitize($admin);
            }
        }

        throw new BusinessException('管理员账号或密码错误', StatusCode::UNAUTHORIZED);
    }

    public static function merchantLogin(string $username, string $password): array
    {
        foreach (self::merchants() as $merchant) {
            if (($merchant['username'] ?? '') === $username && password_verify($password, (string)$merchant['password_hash'])) {
                return self::sanitize($merchant);
            }
        }

        throw new BusinessException('商户账号或密码错误', StatusCode::UNAUTHORIZED);
    }

    public static function adminProfile(int $id): array
    {
        if (database_available()) {
            try {
                $admin = AdminUser::find($id);
                if ($admin) {
                    return self::sanitize(self::row($admin));
                }
            } catch (Throwable) {
            }
        }

        foreach (self::admins() as $admin) {
            if ((int)$admin['id'] === $id) {
                return self::sanitize($admin);
            }
        }

        throw new BusinessException('管理员资料不存在', StatusCode::NOT_FOUND);
    }

    public static function merchantProfile(int $id): array
    {
        foreach (self::merchants() as $merchant) {
            if ((int)$merchant['id'] === $id) {
                return self::sanitize($merchant);
            }
        }

        throw new BusinessException('商户资料不存在', StatusCode::NOT_FOUND);
    }

    public static function merchantCredentialByPid(string $pid): ?array
    {
        $pid = trim($pid);
        if ($pid === '' || !ctype_digit($pid)) {
            return null;
        }

        return self::merchantCredentialById((int)$pid);
    }

    public static function merchantCredentialById(int $merchantId): ?array
    {
        if ($merchantId <= 0) {
            return null;
        }

        $record = null;
        $authUsers = JsonStoreService::load(self::MERCHANT_AUTH_STORE, []);
        if (is_array($authUsers)) {
            foreach ($authUsers as $username => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemMerchantId = (int)($item['merchant_id'] ?? $item['id'] ?? 0);
                if ($itemMerchantId === $merchantId) {
                    $record = $item + ['username' => trim((string)($item['username'] ?? $username))];
                    break;
                }
            }
        }

        $profile = null;
        foreach (self::merchants() as $merchant) {
            $profileMerchantId = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0);
            if ($profileMerchantId === $merchantId) {
                $profile = $merchant;
                break;
            }
        }

        if ($record === null && $profile === null) {
            return null;
        }

        $merged = array_replace($profile ?? [], $record ?? []);
        $username = trim((string)($merged['username'] ?? ''));

        return self::normalizeMerchantCredential($merged, $username);
    }

    public static function cleanMerchantDisplayName(string $name, string $fallbackUsername = '', int $merchantId = 0): string
    {
        $name = trim($name);
        $fallbackUsername = trim($fallbackUsername);

        $repaired = EncodingRepairService::repair($name);
        if (is_string($repaired)) {
            $name = trim($repaired);
        }

        if ($name !== '' && !self::looksLikeBrokenDisplayName($name)) {
            return $name;
        }

        if ($fallbackUsername !== '') {
            return $fallbackUsername;
        }

        return $merchantId > 0 ? '商户' . $merchantId : '';
    }

    private static function canonicalAppId(int $merchantId, mixed $fallback = ''): string
    {
        if ($merchantId > 0) {
            return (string)$merchantId;
        }

        return trim((string)$fallback);
    }

    public static function normalizeMerchantRuntimeRow(array $row): array
    {
        $merchantId = (int)($row['merchant_id'] ?? $row['id'] ?? 0);
        $userId = (int)($row['id'] ?? $merchantId);
        $username = trim((string)($row['username'] ?? ''));

        if ($merchantId > 0) {
            $row['merchant_id'] = $merchantId;
            $row['appid'] = self::canonicalAppId($merchantId, $row['appid'] ?? '');
        }

        if ($userId > 0) {
            $row['id'] = $userId;
        }

        if ($username !== '') {
            $row['username'] = $username;
        }

        return $row;
    }

    private static function normalizeMerchantCredential(array $merged, string $username = ''): array
    {
        $merchantId = (int)($merged['merchant_id'] ?? $merged['id'] ?? 0);
        $username = trim((string)($merged['username'] ?? $username));
        $name = self::cleanMerchantDisplayName(
            (string)($merged['merchant_name'] ?? $merged['nickname'] ?? ''),
            $username,
            $merchantId
        );

        return [
            'id' => (int)($merged['id'] ?? $merchantId),
            'uid' => (int)($merged['uid'] ?? $merchantId),
            'merchant_id' => $merchantId,
            'appid' => self::canonicalAppId($merchantId, $merged['appid'] ?? ''),
            'mch_key' => (string)($merged['mch_key'] ?? ''),
            'rsa_private_key' => (string)($merged['rsa_private_key'] ?? ''),
            'rsa_public_key' => (string)($merged['rsa_public_key'] ?? ''),
            'name' => $name,
            'username' => $username,
            'email' => (string)($merged['email'] ?? ''),
            'status' => (int)($merged['status'] ?? 0),
            'platform_rate' => (float)($merged['platform_rate'] ?? 0.8),
            'daily_limit' => (float)($merged['daily_limit'] ?? 0),
            'balance' => (string)($merged['balance'] ?? '0.00'),
        ];
    }

    public static function merchantRealnameApproved(int $userId = 0, int $merchantId = 0): bool
    {
        foreach (self::merchants() as $merchant) {
            $rowUserId = (int)($merchant['id'] ?? 0);
            $rowMerchantId = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0);
            $sameUser = $userId > 0 && ($rowUserId === $userId || $rowMerchantId === $userId);
            $sameMerchant = $merchantId > 0 && ($rowMerchantId === $merchantId || $rowUserId === $merchantId);
            if (!$sameUser && !$sameMerchant) {
                continue;
            }

            $realname = is_array($merchant['realname'] ?? null) ? $merchant['realname'] : [];
            return in_array((string)($realname['status'] ?? ''), ['已认证', 'approved', 'success'], true);
        }

        return false;
    }

    public static function saveAdminProfile(int $id, array $payload): array
    {
        if (database_available()) {
            try {
                $admin = AdminUser::find($id);
                if ($admin) {
                    $admin->nickname = trim((string)($payload['nickname'] ?? $admin->nickname));
                    $admin->email = trim((string)($payload['email'] ?? ($admin->email ?? '')));
                    $admin->save();
                    return self::sanitize(self::row($admin));
                }
            } catch (Throwable) {
            }
        }

        $admins = self::admins();
        foreach ($admins as &$admin) {
            if ((int)$admin['id'] === $id) {
                $admin['nickname'] = trim((string)($payload['nickname'] ?? $admin['nickname']));
                $admin['email'] = trim((string)($payload['email'] ?? $admin['email']));
                $admin['phone'] = trim((string)($payload['phone'] ?? ($admin['phone'] ?? '')));
                $admin['avatar'] = trim((string)($payload['avatar'] ?? ($admin['avatar'] ?? '')));
                self::saveAdmins($admins);
                return self::sanitize($admin);
            }
        }
        unset($admin);

        throw new BusinessException('管理员资料不存在', StatusCode::NOT_FOUND);
    }

    public static function saveMerchantProfile(int $id, array $payload): array
    {
        $merchants = self::merchants();
        foreach ($merchants as &$merchant) {
            if ((int)$merchant['id'] === $id) {
                $merchant['avatar'] = trim((string)($payload['avatar'] ?? ($merchant['avatar'] ?? '')));
                $merchant['nickname'] = trim((string)($payload['nickname'] ?? $merchant['nickname']));
                $merchant['merchant_name'] = trim((string)($payload['merchant_name'] ?? $merchant['merchant_name']));
                $merchant['contact_name'] = trim((string)($payload['contact_name'] ?? $merchant['contact_name']));
                $merchant['email'] = trim((string)($payload['email'] ?? $merchant['email']));
                $merchant['phone'] = trim((string)($payload['phone'] ?? $merchant['phone']));
                $merchant['notify_url'] = trim((string)($payload['notify_url'] ?? $merchant['notify_url']));
                $merchant['return_url'] = trim((string)($payload['return_url'] ?? $merchant['return_url']));
                $whiteIp = $payload['white_ip'] ?? $merchant['white_ip'];
                $merchant['white_ip'] = is_array($whiteIp)
                    ? array_values(array_filter($whiteIp, static fn(mixed $item): bool => trim((string)$item) !== ''))
                    : preg_split('/[\r\n,]+/', (string)$whiteIp, -1, PREG_SPLIT_NO_EMPTY);
                if (array_key_exists('realname', $payload)) {
                    $merchant['realname'] = RealnameRuntimeService::submit(
                        (int)($merchant['id'] ?? 0),
                        (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0),
                        is_array($merchant['realname'] ?? null) ? $merchant['realname'] : [],
                        (array)$payload['realname'],
                        'merchant:' . (string)($merchant['username'] ?? $id)
                    );
                    self::syncMerchantAuthRealnameSummary($merchant, $merchant['realname']);
                }
                $merchant['notifications'] = self::mergeArray($merchant['notifications'] ?? [], (array)($payload['notifications'] ?? []));
                self::saveMerchants($merchants);
                return self::sanitize($merchant);
            }
        }
        unset($merchant);

        throw new BusinessException('商户资料不存在', StatusCode::NOT_FOUND);
    }

    public static function reviewMerchantRealname(array $payload, string $operator = 'admin'): array
    {
        $merchantId = (int)($payload['merchant_id'] ?? $payload['id'] ?? 0);
        $userId = (int)($payload['user_id'] ?? 0);
        $username = trim((string)($payload['username'] ?? ''));
        $action = strtolower(trim((string)($payload['action'] ?? '')));
        $reason = trim((string)($payload['reason'] ?? $payload['remark'] ?? ''));

        if ($merchantId <= 0 && $userId <= 0 && $username === '') {
            throw new BusinessException('缺少商户编号或账号', StatusCode::VALIDATION_ERROR);
        }

        $operator = trim($operator) !== '' ? trim($operator) : 'admin';
        $merchants = self::merchants();
        foreach ($merchants as &$merchant) {
            $rowMerchantId = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0);
            $rowUserId = (int)($merchant['id'] ?? 0);
            $rowUsername = trim((string)($merchant['username'] ?? ''));
            $matched = ($merchantId > 0 && $rowMerchantId === $merchantId)
                || ($userId > 0 && $rowUserId === $userId)
                || ($username !== '' && $rowUsername === $username);

            if (!$matched) {
                continue;
            }

            $reviewed = RealnameRuntimeService::review(
                $rowUserId,
                $rowMerchantId,
                is_array($merchant['realname'] ?? null) ? $merchant['realname'] : [],
                $action,
                $reason,
                $operator
            );
            $merchant['realname'] = $reviewed;
            self::saveMerchants($merchants);
            self::syncMerchantAuthRealnameSummary($merchant, $reviewed);

            return self::sanitize($merchant);
        }
        unset($merchant);

        throw new BusinessException('商户资料不存在', StatusCode::NOT_FOUND);
    }

    public static function changeAdminPassword(int $id, string $oldPassword, string $newPassword): void
    {
        if (database_available()) {
            try {
                $admin = AdminUser::find($id);
                if ($admin) {
                    if (!password_verify($oldPassword, (string)$admin->password_hash)) {
                        throw new BusinessException('原密码错误', StatusCode::UNAUTHORIZED);
                    }

                    $admin->password_hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $admin->save();
                    return;
                }
            } catch (BusinessException $exception) {
                throw $exception;
            } catch (Throwable) {
            }
        }

        $admins = self::admins();
        foreach ($admins as &$admin) {
            if ((int)$admin['id'] === $id) {
                if (!password_verify($oldPassword, (string)$admin['password_hash'])) {
                    throw new BusinessException('原密码错误', StatusCode::UNAUTHORIZED);
                }

                $admin['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
                self::saveAdmins($admins);
                return;
            }
        }
        unset($admin);

        throw new BusinessException('管理员资料不存在', StatusCode::NOT_FOUND);
    }

    public static function changeMerchantPassword(int $id, string $oldPassword, string $newPassword): void
    {
        $merchants = self::merchants();
        foreach ($merchants as &$merchant) {
            if ((int)$merchant['id'] === $id) {
                if (!password_verify($oldPassword, (string)$merchant['password_hash'])) {
                    throw new BusinessException('原密码错误', StatusCode::UNAUTHORIZED);
                }

                $merchant['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
                $merchant['security']['password_updated_at'] = date('Y-m-d H:i:s');
                self::saveMerchants($merchants);
                return;
            }
        }
        unset($merchant);

        throw new BusinessException('商户资料不存在', StatusCode::NOT_FOUND);
    }

    private static function admins(): array
    {
        return JsonStoreService::load(self::ADMIN_STORE, []);
    }

    private static function merchants(): array
    {
        $merchants = JsonStoreService::load(self::MERCHANT_STORE, []);

        $normalized = [];
        $changed = false;
        foreach ($merchants as $merchant) {
            if (!is_array($merchant)) {
                $changed = true;
                continue;
            }

            $normalizedRow = self::normalizeMerchantRuntimeRow($merchant);

            if ($normalizedRow !== $merchant) {
                $changed = true;
            }

            $normalized[] = $normalizedRow;
        }

        if ($changed || count($normalized) !== count($merchants)) {
            JsonStoreService::save(self::MERCHANT_STORE, $normalized);
        }

        return $normalized;
    }

    private static function saveAdmins(array $admins): void
    {
        JsonStoreService::save(self::ADMIN_STORE, $admins);
    }

    private static function saveMerchants(array $merchants): void
    {
        $normalized = [];
        foreach ($merchants as $merchant) {
            if (!is_array($merchant)) {
                continue;
            }

            $normalized[] = self::normalizeMerchantRuntimeRow($merchant);
        }

        JsonStoreService::save(self::MERCHANT_STORE, $normalized);
    }

    private static function syncMerchantAuthRealnameSummary(array $merchant, array $realname): void
    {
        $merchantId = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0);
        $username = trim((string)($merchant['username'] ?? ''));
        $users = JsonStoreService::load(self::MERCHANT_AUTH_STORE, []);

        foreach ($users as $key => $record) {
            if (!is_array($record)) {
                continue;
            }

            $matched = ($merchantId > 0 && (int)($record['merchant_id'] ?? $record['id'] ?? 0) === $merchantId)
                || ($username !== '' && (string)($record['username'] ?? $key) === $username);
            if (!$matched) {
                continue;
            }

            $users[$key] = array_replace($record, [
                'realname_status' => (string)($realname['status'] ?? ''),
                'realname_result' => (string)($realname['result'] ?? ''),
                'realname_submitted_at' => (string)($realname['submitted_at'] ?? ''),
                'realname_reviewed_at' => (string)($realname['reviewed_at'] ?? ''),
                'realname_reviewed_by' => (string)($realname['reviewed_by'] ?? ''),
            ]);
            JsonStoreService::save(self::MERCHANT_AUTH_STORE, $users);
            return;
        }

        if ($username === '') {
            return;
        }

        $users[$username] = [
            'id' => (int)($merchant['id'] ?? 0),
            'merchant_id' => $merchantId,
            'username' => $username,
            'nickname' => (string)($merchant['nickname'] ?? $merchant['merchant_name'] ?? $username),
            'email' => (string)($merchant['email'] ?? ''),
            'phone' => (string)($merchant['phone'] ?? ''),
            'merchant_name' => (string)($merchant['merchant_name'] ?? $merchant['nickname'] ?? $username),
            'password_hash' => (string)($merchant['password_hash'] ?? ''),
            'status' => (int)($merchant['status'] ?? 0),
            'appid' => self::canonicalAppId($merchantId, $merchant['appid'] ?? ''),
            'mch_key' => (string)($merchant['mch_key'] ?? ''),
            'realname_status' => (string)($realname['status'] ?? ''),
            'realname_result' => (string)($realname['result'] ?? ''),
            'realname_submitted_at' => (string)($realname['submitted_at'] ?? ''),
            'realname_reviewed_at' => (string)($realname['reviewed_at'] ?? ''),
            'realname_reviewed_by' => (string)($realname['reviewed_by'] ?? ''),
        ];
        JsonStoreService::save(self::MERCHANT_AUTH_STORE, $users);
    }

    private static function sanitize(array $account): array
    {
        unset($account['password_hash']);
        if (is_array($account['realname'] ?? null)) {
            unset($account['realname']['id_card_hash'], $account['realname']['raw_response']);
        }
        $username = trim((string)($account['username'] ?? ''));
        $merchantId = (int)($account['merchant_id'] ?? $account['id'] ?? 0);
        if (isset($account['nickname'])) {
            $account['nickname'] = self::cleanMerchantDisplayName((string)$account['nickname'], $username, $merchantId);
        }
        if (isset($account['merchant_name'])) {
            $account['merchant_name'] = self::cleanMerchantDisplayName((string)$account['merchant_name'], $username, $merchantId);
        }
        return $account;
    }

    private static function looksLikeBrokenDisplayName(string $value): bool
    {
        if (str_contains($value, "\xEF\xBF\xBD")) {
            return true;
        }

        if (preg_match('/^\?{2,}[0-9a-zA-Z_-]*$/u', $value) === 1) {
            return true;
        }

        return preg_match('/[ÃÂâæçèéåäöüï¼]/u', $value) === 1
            && preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $value) !== 1;
    }

    private static function mergeArray(array $current, array $payload): array
    {
        return array_replace_recursive(
            $current,
            array_filter($payload, static fn(mixed $value): bool => $value !== null)
        );
    }
}
