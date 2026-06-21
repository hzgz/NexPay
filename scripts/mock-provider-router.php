<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/success') {
    respondText(200, 'success');
    return;
}

if ($path === '/oauth/authorize') {
    handleOAuthAuthorize();
    return;
}

if ($path === '/oauth/token') {
    handleOAuthToken();
    return;
}

if ($path === '/realname') {
    handleRealname();
    return;
}

respondJson(404, [
    'code' => 404,
    'message' => 'not_found',
]);

function handleOAuthAuthorize(): void
{
    $appId = trim((string)($_GET['app_id'] ?? ''));
    $channel = trim((string)($_GET['channel'] ?? ''));
    $redirectUri = trim((string)($_GET['redirect_uri'] ?? ''));
    $state = trim((string)($_GET['state'] ?? ''));
    $sign = trim((string)($_GET['sign'] ?? ''));
    $expectedAppId = envValue('NEXPAY_MOCK_OAUTH_APP_ID', 'mock-oauth-app');
    $appKey = envValue('NEXPAY_MOCK_OAUTH_APP_KEY', 'mock-oauth-key');

    $request = [
        'app_id' => $appId,
        'channel' => $channel,
        'redirect_uri' => $redirectUri,
        'state' => $state,
    ];

    if (
        $appId === ''
        || $channel === ''
        || $redirectUri === ''
        || $state === ''
        || $sign === ''
        || $appId !== $expectedAppId
        || !hash_equals(oauthSign($request, $appKey), $sign)
    ) {
        respondJson(422, [
            'code' => 422,
            'message' => 'invalid_oauth_authorize_request',
        ]);
        return;
    }

    writeLog('oauth_authorize', [
        'channel' => $channel,
        'state' => $state,
        'redirect_uri' => $redirectUri,
    ]);

    $mockCode = trim((string)($_GET['mock_code'] ?? 'oauth-bound-main'));
    $query = http_build_query([
        'code' => $mockCode,
        'state' => $state,
        'channel' => $channel,
    ]);

    http_response_code(302);
    header('Location: ' . $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . $query);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'redirect';
}

function handleOAuthToken(): void
{
    $appId = trim((string)($_POST['app_id'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    $channel = trim((string)($_POST['channel'] ?? ''));
    $redirectUri = trim((string)($_POST['redirect_uri'] ?? ''));
    $state = trim((string)($_POST['state'] ?? ''));
    $sign = trim((string)($_POST['sign'] ?? ''));
    $expectedAppId = envValue('NEXPAY_MOCK_OAUTH_APP_ID', 'mock-oauth-app');
    $appKey = envValue('NEXPAY_MOCK_OAUTH_APP_KEY', 'mock-oauth-key');

    $request = [
        'app_id' => $appId,
        'code' => $code,
        'channel' => $channel,
        'redirect_uri' => $redirectUri,
        'state' => $state,
    ];

    if (
        $appId === ''
        || $code === ''
        || $channel === ''
        || $redirectUri === ''
        || $state === ''
        || $sign === ''
        || $appId !== $expectedAppId
        || !hash_equals(oauthSign($request, $appKey), $sign)
    ) {
        respondJson(422, [
            'code' => 422,
            'message' => 'invalid_oauth_token_request',
        ]);
        return;
    }

    $identity = oauthIdentityForCode($code, $channel);
    $signMode = oauthSignModeForCode($code);

    writeLog('oauth_token', [
        'code' => $code,
        'channel' => $channel,
        'openid' => $identity['openid'],
        'sign_mode' => $signMode,
    ]);

    if ($signMode === 'bad') {
        respondJson(200, [
            'code' => 0,
            'message' => 'ok',
            'data' => $identity + ['sign' => 'broken-signature'],
        ]);
        return;
    }

    if ($signMode === 'root') {
        $payload = [
            'code' => 0,
            'message' => 'ok',
            'data' => $identity,
        ];
        $payload['sign'] = oauthSign($payload, $appKey);
        respondJson(200, $payload);
        return;
    }

    $payload = $identity;
    $payload['sign'] = oauthSign($payload, $appKey);

    respondJson(200, [
        'code' => 0,
        'message' => 'ok',
        'data' => $payload,
    ]);
}

function handleRealname(): void
{
    $body = file_get_contents('php://input');
    $payload = json_decode((string)$body, true);
    if (!is_array($payload)) {
        respondJson(400, [
            'code' => 400,
            'message' => 'invalid_json',
        ]);
        return;
    }

    $appId = trim((string)($payload['app_id'] ?? ''));
    $appKey = trim((string)($payload['app_key'] ?? ''));
    $merchantId = (int)($payload['merchant_id'] ?? 0);
    $realName = trim((string)($payload['real_name'] ?? ''));
    $idCard = strtoupper(trim((string)($payload['id_card'] ?? '')));
    $requestId = trim((string)($payload['request_id'] ?? ''));
    $timestamp = (string)($payload['timestamp'] ?? '');
    $sign = trim((string)($payload['sign'] ?? ''));

    $expectedAppId = envValue('NEXPAY_MOCK_REALNAME_APP_ID', 'mock-realname-app');
    $expectedAppKey = envValue('NEXPAY_MOCK_REALNAME_APP_KEY', 'mock-realname-key');
    $appSecret = envValue('NEXPAY_MOCK_REALNAME_SECRET', 'mock-realname-secret');

    $request = [
        'app_id' => $appId,
        'app_key' => $appKey,
        'merchant_id' => $merchantId,
        'real_name' => $realName,
        'id_card' => $idCard,
        'request_id' => $requestId,
        'timestamp' => $timestamp,
    ];

    if (
        $appId === ''
        || $appKey === ''
        || $merchantId <= 0
        || $realName === ''
        || $idCard === ''
        || $requestId === ''
        || $timestamp === ''
        || $sign === ''
        || $appId !== $expectedAppId
        || $appKey !== $expectedAppKey
        || !hash_equals(oauthSign($request, $appSecret), $sign)
    ) {
        respondJson(422, [
            'code' => 422,
            'message' => 'invalid_realname_request',
        ]);
        return;
    }

    writeLog('realname', [
        'merchant_id' => $merchantId,
        'real_name' => $realName,
        'id_card' => $idCard,
        'request_id' => $requestId,
    ]);

    $status = 'PENDING';
    $message = 'manual_review';
    if (str_ends_with($idCard, '0011')) {
        $status = 'APPROVED';
        $message = 'verified';
    } elseif (str_ends_with($idCard, '0022')) {
        $status = 'FAILED';
        $message = 'name_mismatch';
    } elseif (str_ends_with($idCard, '0033')) {
        $status = 'PENDING';
        $message = 'provider_pending';
    }

    $data = [
        'status' => $status,
        'verify_status' => $status,
        'passed' => $status === 'APPROVED',
        'message' => $message,
        'request_id' => $requestId,
        'merchant_id' => $merchantId,
        'real_name' => $realName,
        'id_card' => $idCard,
    ];

    respondJson(200, [
        'code' => 0,
        'message' => $message,
        'data' => $data,
    ]);
}

function oauthIdentityForCode(string $code, string $channel): array
{
    return match ($code) {
        'oauth-bound-main', 'oauth-login-main' => [
            'openid' => 'openid-main-' . $channel,
            'unionid' => 'union-main-' . $channel,
            'nickname' => 'Main OAuth User',
            'avatar' => 'https://example.com/avatar-main.png',
        ],
        'oauth-unbound' => [
            'openid' => 'openid-unbound-' . $channel,
            'unionid' => 'union-unbound-' . $channel,
            'nickname' => 'Unbound OAuth User',
            'avatar' => 'https://example.com/avatar-unbound.png',
        ],
        'oauth-root-sign' => [
            'openid' => 'openid-root-' . $channel,
            'unionid' => 'union-root-' . $channel,
            'nickname' => 'Root Sign User',
            'avatar' => 'https://example.com/avatar-root.png',
        ],
        'oauth-bad-sign' => [
            'openid' => 'openid-bad-' . $channel,
            'unionid' => 'union-bad-' . $channel,
            'nickname' => 'Bad Sign User',
            'avatar' => 'https://example.com/avatar-bad.png',
        ],
        default => [
            'openid' => 'openid-' . preg_replace('/[^a-z0-9_-]/i', '-', strtolower($code)),
            'unionid' => 'union-' . preg_replace('/[^a-z0-9_-]/i', '-', strtolower($code)),
            'nickname' => 'Generic OAuth User',
            'avatar' => 'https://example.com/avatar-generic.png',
        ],
    };
}

function oauthSignModeForCode(string $code): string
{
    return match ($code) {
        'oauth-bad-sign' => 'bad',
        'oauth-root-sign' => 'root',
        default => 'data',
    };
}

function oauthSign(array $payload, string $appKey): string
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

function respondText(int $status, string $body): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $body;
}

function respondJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
}

function writeLog(string $scene, array $payload): void
{
    $path = envValue('NEXPAY_MOCK_PROVIDER_LOG');
    if ($path === '') {
        return;
    }

    $line = json_encode([
        'scene' => $scene,
        'payload' => $payload,
        'time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($line === false) {
        return;
    }

    file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
}
