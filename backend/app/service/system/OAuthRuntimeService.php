<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;

class OAuthRuntimeService
{
    private const STATE_STORE = 'oauth_runtime_states';
    private const STATE_TTL = 600;
    private const SUPPORTED_CHANNELS = ['qq', 'wechat', 'alipay', 'google', 'telegram'];

    public static function start(string $channel, string $mode, int $merchantUserId = 0, int $merchantId = 0): array
    {
        $rawChannel = $channel;
        $mode = in_array($mode, ['login', 'bind'], true) ? $mode : 'login';

        try {
            $channel = self::normalizeChannel($channel);
            if ($mode === 'bind' && ($merchantUserId <= 0 || $merchantId <= 0)) {
                throw new BusinessException('绑定第三方账号需要先登录商户中心', StatusCode::UNAUTHORIZED);
            }

            $settings = self::oauthSettings();
            if (!(bool)($settings['enabled'] ?? false)) {
                throw new BusinessException('聚合登录未启用', StatusCode::BUSINESS_ERROR);
            }

            if (!self::channelEnabled($settings, $channel)) {
                throw new BusinessException('当前第三方登录渠道未启用：' . $channel, StatusCode::BUSINESS_ERROR);
            }

            $apiUrl = rtrim(trim((string)($settings['api_url'] ?? '')), '/');
            $appId = trim((string)($settings['app_id'] ?? ''));
            $appKey = trim((string)($settings['app_key'] ?? ''));
            if ($apiUrl === '' || $appId === '' || $appKey === '') {
                throw new BusinessException('聚合登录 provider 配置不完整，无法发起授权', StatusCode::BUSINESS_ERROR);
            }

            $state = bin2hex(random_bytes(18));
            $callbackUrl = self::callbackUrl($settings);
            $payload = [
                'app_id' => $appId,
                'channel' => $channel,
                'redirect_uri' => $callbackUrl,
                'state' => $state,
            ];
            $payload['sign'] = self::sign($payload, $appKey);

            self::saveState($state, [
                'state' => $state,
                'channel' => $channel,
                'mode' => $mode,
                'merchant_user_id' => $merchantUserId,
                'merchant_id' => $merchantId,
                'expires_at' => date('Y-m-d H:i:s', time() + self::STATE_TTL),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            self::writeOAuthLog('oauth_start', $channel, $mode, 'success', '聚合登录授权地址已生成', [
                'merchant_id' => $merchantId,
                'user_id' => $merchantUserId,
                'state' => self::maskToken($state),
            ]);

            return [
                'auth_url' => $apiUrl . '/authorize?' . http_build_query($payload),
                'state' => $state,
                'channel' => $channel,
                'mode' => $mode,
                'expires_at' => date('Y-m-d H:i:s', time() + self::STATE_TTL),
            ];
        } catch (BusinessException $exception) {
            self::writeOAuthLog('oauth_start', self::safeChannel($rawChannel), $mode, 'failed', $exception->getMessage(), [
                'merchant_id' => $merchantId,
                'user_id' => $merchantUserId,
            ]);
            throw $exception;
        }
    }

    public static function callback(array $payload): array
    {
        $state = trim((string)($payload['state'] ?? ''));
        $code = trim((string)($payload['code'] ?? ''));
        $stateRecord = null;

        try {
            if ($state === '' || $code === '') {
                throw new BusinessException('聚合登录回调参数不完整', StatusCode::VALIDATION_ERROR);
            }

            $stateRecord = self::pullState($state);
            if (!$stateRecord) {
                throw new BusinessException('聚合登录 state 不存在或已过期', StatusCode::VALIDATION_ERROR);
            }

            $settings = self::oauthSettings();
            $identity = self::fetchIdentity($settings, $code, $stateRecord);
            if ((string)($stateRecord['mode'] ?? 'login') === 'bind') {
                $result = self::bindIdentity(
                    (int)($stateRecord['merchant_user_id'] ?? 0),
                    (int)($stateRecord['merchant_id'] ?? 0),
                    $identity
                ) + ['mode' => 'bind'];
                self::writeOAuthLog('oauth_callback', (string)($stateRecord['channel'] ?? ''), 'bind', 'success', '第三方账号绑定成功', [
                    'merchant_id' => (int)($stateRecord['merchant_id'] ?? 0),
                    'user_id' => (int)($stateRecord['merchant_user_id'] ?? 0),
                    'openid' => self::maskOpenid((string)($identity['openid'] ?? '')),
                    'state' => self::maskToken($state),
                ]);
                return $result;
            }

            $result = self::loginByIdentity($identity) + ['mode' => 'login'];
            $user = is_array($result['user'] ?? null) ? $result['user'] : [];
            self::writeOAuthLog('oauth_callback', (string)($stateRecord['channel'] ?? ''), 'login', 'success', '聚合登录成功', [
                'merchant_id' => (int)($user['merchant_id'] ?? 0),
                'user_id' => (int)($user['id'] ?? 0),
                'openid' => self::maskOpenid((string)($identity['openid'] ?? '')),
                'state' => self::maskToken($state),
            ]);
            return $result;
        } catch (BusinessException $exception) {
            $record = is_array($stateRecord) ? $stateRecord : [];
            self::writeOAuthLog('oauth_callback', (string)($record['channel'] ?? self::safeChannel((string)($payload['channel'] ?? ''))), (string)($record['mode'] ?? $payload['mode'] ?? 'login'), 'failed', $exception->getMessage(), [
                'merchant_id' => (int)($record['merchant_id'] ?? 0),
                'user_id' => (int)($record['merchant_user_id'] ?? 0),
                'state' => self::maskToken($state),
            ]);
            throw $exception;
        }
    }

    public static function bindIdentity(int $merchantUserId, int $merchantId, array $identity): array
    {
        if ($merchantUserId <= 0 || $merchantId <= 0) {
            throw new BusinessException('商户身份无效，无法绑定第三方账号', StatusCode::UNAUTHORIZED);
        }

        $channel = self::normalizeChannel((string)($identity['channel'] ?? ''));
        $openid = trim((string)($identity['openid'] ?? ''));
        if ($openid === '') {
            throw new BusinessException('第三方账号缺少 openid，无法绑定', StatusCode::VALIDATION_ERROR);
        }

        $merchants = self::merchantAccounts();
        foreach ($merchants as $merchant) {
            if (!is_array($merchant)) {
                continue;
            }

            $sameUser = (int)($merchant['id'] ?? 0) === $merchantUserId;
            $sameMerchant = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0) === $merchantId;
            $bindings = is_array($merchant['bindings'] ?? null) ? $merchant['bindings'] : [];
            $binding = $bindings[$channel] ?? null;
            if (
                is_array($binding)
                && (string)($binding['openid'] ?? '') === $openid
                && !$sameUser
                && !$sameMerchant
            ) {
                throw new BusinessException('该第三方账号已绑定其他商户，无法重复绑定', StatusCode::BUSINESS_ERROR);
            }
        }

        $changed = false;
        foreach ($merchants as &$merchant) {
            $sameUser = (int)($merchant['id'] ?? 0) === $merchantUserId;
            $sameMerchant = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0) === $merchantId;
            if (!$sameUser && !$sameMerchant) {
                continue;
            }

            $bindings = is_array($merchant['bindings'] ?? null) ? $merchant['bindings'] : [];
            $bindings[$channel] = [
                'status' => '已绑定',
                'openid' => $openid,
                'unionid' => (string)($identity['unionid'] ?? ''),
                'nickname' => (string)($identity['nickname'] ?? ''),
                'bound_at' => date('Y-m-d H:i:s'),
            ];
            $merchant['bindings'] = $bindings;
            $changed = true;
            break;
        }
        unset($merchant);

        if (!$changed) {
            throw new BusinessException('商户资料不存在，无法绑定第三方账号', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save('merchant_accounts', $merchants);

        return [
            'channel' => $channel,
            'openid' => self::maskOpenid($openid),
            'nickname' => (string)($identity['nickname'] ?? ''),
        ];
    }

    private static function loginByIdentity(array $identity): array
    {
        $channel = self::normalizeChannel((string)($identity['channel'] ?? ''));
        $openid = trim((string)($identity['openid'] ?? ''));
        if ($openid === '') {
            throw new BusinessException('第三方账号缺少 openid，无法登录', StatusCode::VALIDATION_ERROR);
        }

        foreach (self::merchantAccounts() as $merchant) {
            $bindings = is_array($merchant['bindings'] ?? null) ? $merchant['bindings'] : [];
            $binding = $bindings[$channel] ?? null;
            if (!is_array($binding) || (string)($binding['openid'] ?? '') !== $openid) {
                continue;
            }

            $credential = AccountService::merchantCredentialById((int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0));
            if ($credential === null || (int)($credential['status'] ?? 0) !== 1) {
                throw new BusinessException('绑定商户不存在或已停用', StatusCode::BUSINESS_ERROR);
            }

            return [
                'user' => [
                    'id' => (int)($credential['id'] ?? 0),
                    'merchant_id' => (int)($credential['merchant_id'] ?? $credential['id'] ?? 0),
                    'username' => (string)($credential['username'] ?? ''),
                    'nickname' => (string)($credential['name'] ?? $credential['username'] ?? ''),
                    'email' => (string)($credential['email'] ?? ''),
                    'phone' => (string)($credential['phone'] ?? ''),
                    'merchant_name' => (string)($credential['name'] ?? ''),
                    'status' => (int)($credential['status'] ?? 0),
                ],
            ];
        }

        throw new BusinessException('第三方账号尚未绑定商户，请先登录后绑定', StatusCode::BUSINESS_ERROR);
    }

    private static function fetchIdentity(array $settings, string $code, array $stateRecord): array
    {
        $apiUrl = rtrim(trim((string)($settings['api_url'] ?? '')), '/');
        $appId = trim((string)($settings['app_id'] ?? ''));
        $appKey = trim((string)($settings['app_key'] ?? ''));
        if ($apiUrl === '' || $appId === '' || $appKey === '') {
            throw new BusinessException('聚合登录 provider 配置不完整，无法完成回调', StatusCode::BUSINESS_ERROR);
        }

        $request = [
            'app_id' => $appId,
            'code' => $code,
            'channel' => (string)$stateRecord['channel'],
            'redirect_uri' => self::callbackUrl($settings),
            'state' => (string)$stateRecord['state'],
        ];
        $request['sign'] = self::sign($request, $appKey);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 12,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($request),
            ],
        ]);
        $response = @file_get_contents($apiUrl . '/token', false, $context);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded) || (int)($decoded['code'] ?? 0) !== 0) {
            throw new BusinessException('聚合登录 provider 回调换取失败：' . (string)($decoded['message'] ?? $decoded['msg'] ?? 'unknown'), StatusCode::BUSINESS_ERROR);
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        self::verifyProviderResponseSignature($decoded, $data, $appKey, (bool)($settings['response_sign_required'] ?? true));

        $openid = trim((string)($data['openid'] ?? $data['open_id'] ?? ''));
        if ($openid === '') {
            throw new BusinessException('聚合登录 provider 未返回 openid', StatusCode::BUSINESS_ERROR);
        }

        return [
            'channel' => (string)$stateRecord['channel'],
            'openid' => $openid,
            'unionid' => (string)($data['unionid'] ?? $data['union_id'] ?? ''),
            'nickname' => (string)($data['nickname'] ?? $data['name'] ?? ''),
            'avatar' => (string)($data['avatar'] ?? ''),
            'raw' => $data,
        ];
    }

    private static function oauthSettings(): array
    {
        $settings = SettingsService::all(false);
        return is_array($settings['oauth'] ?? null) ? $settings['oauth'] : [];
    }

    private static function callbackUrl(array $settings): string
    {
        $configured = trim((string)($settings['callback_url'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $all = SettingsService::all(false);
        $basic = is_array($all['basic'] ?? null) ? $all['basic'] : [];
        $base = trim((string)($basic['gateway_base_url'] ?? $basic['site_url'] ?? ''));
        return ($base !== '' ? rtrim($base, '/') : '') . '/api/merchant/auth/oauth/callback';
    }

    private static function channelEnabled(array $settings, string $channel): bool
    {
        $key = $channel . '_enabled';
        if (array_key_exists($key, $settings)) {
            return (bool)$settings[$key];
        }

        return false;
    }

    private static function verifyProviderResponseSignature(array $decoded, array $data, string $appKey, bool $required): void
    {
        $dataSign = trim((string)($data['sign'] ?? ''));
        $rootSign = trim((string)($decoded['sign'] ?? ''));
        $sign = $dataSign !== '' ? $dataSign : $rootSign;

        if ($sign === '') {
            if ($required) {
                throw new BusinessException('聚合登录 provider 未返回响应签名，已拒绝本次回调', StatusCode::BUSINESS_ERROR);
            }

            return;
        }

        $payload = $dataSign !== '' ? $data : $decoded;
        unset($payload['sign']);

        if (!hash_equals(self::sign($payload, $appKey), $sign)) {
            throw new BusinessException('聚合登录 provider 响应签名校验失败', StatusCode::BUSINESS_ERROR);
        }
    }

    private static function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, self::SUPPORTED_CHANNELS, true)) {
            throw new BusinessException('不支持的第三方登录渠道', StatusCode::VALIDATION_ERROR);
        }

        return $channel;
    }

    private static function safeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        return $channel !== '' ? preg_replace('/[^a-z0-9_-]/', '', $channel) ?? '' : '';
    }

    private static function sign(array $payload, string $appKey): string
    {
        unset($payload['sign']);
        ksort($payload);
        $pairs = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $text = (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $text = trim((string)$value);
            }

            if ($text !== '') {
                $pairs[] = $key . '=' . $text;
            }
        }

        return hash_hmac('sha256', implode('&', $pairs), $appKey);
    }

    private static function saveState(string $state, array $record): void
    {
        $states = self::pruneStates(JsonStoreService::load(self::STATE_STORE, []));
        $states[$state] = $record;
        JsonStoreService::save(self::STATE_STORE, $states);
    }

    private static function pullState(string $state): ?array
    {
        $states = self::pruneStates(JsonStoreService::load(self::STATE_STORE, []));
        $record = is_array($states[$state] ?? null) ? $states[$state] : null;
        unset($states[$state]);
        JsonStoreService::save(self::STATE_STORE, $states);
        return $record;
    }

    private static function pruneStates(array $states): array
    {
        $now = time();
        return array_filter($states, static function (mixed $record) use ($now): bool {
            if (!is_array($record)) {
                return false;
            }

            $expiresAt = strtotime((string)($record['expires_at'] ?? ''));
            return $expiresAt !== false && $expiresAt >= $now;
        });
    }

    private static function merchantAccounts(): array
    {
        $accounts = JsonStoreService::load('merchant_accounts', []);
        $normalized = [];

        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $normalized[] = AccountService::normalizeMerchantRuntimeRow($account);
        }

        if ($normalized !== $accounts) {
            JsonStoreService::save('merchant_accounts', $normalized);
        }

        return $normalized;
    }

    private static function maskOpenid(string $openid): string
    {
        if (strlen($openid) <= 8) {
            return substr($openid, 0, 2) . '***';
        }

        return substr($openid, 0, 4) . '****' . substr($openid, -4);
    }

    private static function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        if (strlen($token) <= 10) {
            return substr($token, 0, 3) . '***';
        }

        return substr($token, 0, 6) . '****' . substr($token, -4);
    }

    private static function writeOAuthLog(string $scene, string $channel, string $mode, string $status, string $message, array $context = []): void
    {
        $targetParts = array_filter([
            trim($channel) !== '' ? trim($channel) : null,
            trim($mode) !== '' ? trim($mode) : null,
            trim((string)($context['state'] ?? '')) !== '' ? 'state=' . (string)$context['state'] : null,
        ]);

        ProviderRuntimeService::recordEvent([
            'type' => 'oauth',
            'scene' => $scene,
            'provider_code' => (string)(self::oauthSettings()['provider_code'] ?? ''),
            'target' => implode(' / ', $targetParts),
            'status' => $status,
            'message' => $message,
            'operator' => $mode === 'bind' ? 'merchant' : 'system',
            'ip' => '',
        ]);
    }
}
