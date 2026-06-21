<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\exception\BusinessException;
use app\service\system\AccountService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantAuthService;
use app\service\system\OAuthRuntimeService;
use app\service\system\ProviderRuntimeService;

$stores = [
    'settings',
    'merchant_auth_users',
    'merchant_accounts',
    'oauth_runtime_states',
    'provider_test_logs',
    'realname_audit_logs',
];

$backups = [];
foreach ($stores as $store) {
    $backups[$store] = JsonStoreService::load($store, []);
}

$mockServer = null;
$mockLogPath = '';
$mockStdout = '';
$mockStderr = '';
$externalMockBaseUrl = rtrim((string)($argv[1] ?? ''), '/');
$externalMockLogPath = trim((string)($argv[2] ?? ''));
$result = [];
$ok = false;

try {
    debugStep('reset stores');
    foreach ([
        'merchant_auth_users',
        'merchant_accounts',
        'oauth_runtime_states',
        'provider_test_logs',
        'realname_audit_logs',
    ] as $store) {
        JsonStoreService::save($store, []);
    }

    $mockBaseUrl = $externalMockBaseUrl;
    if ($mockBaseUrl === '') {
        throw new RuntimeException('please pass mock provider base url as argv[1], for example: php scripts/verify-oauth-realname-provider-closure.php http://127.0.0.1:50123');
    }
    $mockLogPath = $externalMockLogPath;

    debugStep('save oauth and realname settings');
    $settings = is_array($backups['settings']) ? $backups['settings'] : [];
    $settings['oauth'] = is_array($settings['oauth'] ?? null) ? $settings['oauth'] : [];
    $settings['realname'] = is_array($settings['realname'] ?? null) ? $settings['realname'] : [];

    $settings['oauth']['enabled'] = true;
    $settings['oauth']['provider'] = '聚合登录接口';
    $settings['oauth']['provider_code'] = 'oauth-aggregate';
    $settings['oauth']['api_url'] = $mockBaseUrl . '/oauth';
    $settings['oauth']['app_id'] = 'mock-oauth-app';
    $settings['oauth']['app_key'] = 'mock-oauth-key';
    $settings['oauth']['callback_url'] = 'https://merchant.nexpay.local/oauth/callback';
    $settings['oauth']['response_sign_required'] = true;
    $settings['oauth']['qq_enabled'] = true;
    $settings['oauth']['wechat_enabled'] = false;
    $settings['oauth']['alipay_enabled'] = false;
    $settings['oauth']['google_enabled'] = false;
    $settings['oauth']['telegram_enabled'] = false;

    $settings['realname']['enabled'] = true;
    $settings['realname']['provider'] = 'api';
    $settings['realname']['api_url'] = $mockBaseUrl . '/realname';
    $settings['realname']['app_id'] = 'mock-realname-app';
    $settings['realname']['app_key'] = 'mock-realname-key';
    $settings['realname']['app_secret'] = 'mock-realname-secret';
    $settings['realname']['daily_limit'] = 5;

    JsonStoreService::save('settings', $settings);

    debugStep('create merchants');
    $mainMerchant = createMerchant('oauthmain');
    $otherMerchant = createMerchant('oauthdup');
    $realnameApprovedMerchant = createMerchant('realok');
    $realnameFailedMerchant = createMerchant('realfail');
    $realnamePendingMerchant = createMerchant('realpending');
    $realnameLimitedMerchant = createMerchant('reallimit');

    debugStep('oauth bind main');
    $bindStart = OAuthRuntimeService::start('qq', 'bind', (int)$mainMerchant['id'], (int)$mainMerchant['merchant_id']);
    $bindAuthorize = fetchAuthorizeRedirect((string)$bindStart['auth_url'], 'oauth-bound-main');
    $bindCallbackPayload = extractQueryFromLocation($bindAuthorize['location']);
    $bindResult = OAuthRuntimeService::callback($bindCallbackPayload);
    $bindDuplicateError = captureBusinessException(static function () use ($otherMerchant): void {
        $duplicateStart = OAuthRuntimeService::start('qq', 'bind', (int)$otherMerchant['id'], (int)$otherMerchant['merchant_id']);
        $duplicateAuthorize = fetchAuthorizeRedirect((string)$duplicateStart['auth_url'], 'oauth-bound-main');
        OAuthRuntimeService::callback(extractQueryFromLocation($duplicateAuthorize['location']));
    });
    $bindStateReuseError = captureBusinessException(static function () use ($bindCallbackPayload): void {
        OAuthRuntimeService::callback($bindCallbackPayload);
    });

    debugStep('oauth login main');
    $loginStart = OAuthRuntimeService::start('qq', 'login');
    $loginAuthorize = fetchAuthorizeRedirect((string)$loginStart['auth_url'], 'oauth-login-main');
    $loginResult = OAuthRuntimeService::callback(extractQueryFromLocation($loginAuthorize['location']));

    $unboundLoginError = captureBusinessException(static function (): void {
        $start = OAuthRuntimeService::start('qq', 'login');
        $authorize = fetchAuthorizeRedirect((string)$start['auth_url'], 'oauth-unbound');
        OAuthRuntimeService::callback(extractQueryFromLocation($authorize['location']));
    });

    debugStep('oauth root sign and bad sign');
    $rootSignBindResult = null;
    $rootSignStart = OAuthRuntimeService::start('qq', 'bind', (int)$otherMerchant['id'], (int)$otherMerchant['merchant_id']);
    $rootSignAuthorize = fetchAuthorizeRedirect((string)$rootSignStart['auth_url'], 'oauth-root-sign');
    $rootSignBindResult = OAuthRuntimeService::callback(extractQueryFromLocation($rootSignAuthorize['location']));

    $badSignError = captureBusinessException(static function (): void {
        $start = OAuthRuntimeService::start('qq', 'login');
        $authorize = fetchAuthorizeRedirect((string)$start['auth_url'], 'oauth-bad-sign');
        OAuthRuntimeService::callback(extractQueryFromLocation($authorize['location']));
    });

    $mainMerchantAccount = merchantAccountById((int)$mainMerchant['merchant_id']);
    $otherMerchantAccount = merchantAccountById((int)$otherMerchant['merchant_id']);
    $oauthLogs = array_values(array_filter(
        ProviderRuntimeService::testLogs(200),
        static fn(array $item): bool => (string)($item['type'] ?? '') === 'oauth'
    ));

    debugStep('realname approved');
    $approvedProfile = AccountService::saveMerchantProfile((int)$realnameApprovedMerchant['id'], [
        'realname' => [
            'real_name' => 'Zhang San',
            'id_card' => '110101199003070011',
        ],
    ]);
    $approvedAccount = merchantAccountById((int)$realnameApprovedMerchant['merchant_id']);
    $approvedAuth = merchantAuthById((int)$realnameApprovedMerchant['merchant_id']);

    $failedProfile = AccountService::saveMerchantProfile((int)$realnameFailedMerchant['id'], [
        'realname' => [
            'real_name' => 'Li Si',
            'id_card' => '110101199003070022',
        ],
    ]);
    $failedAccount = merchantAccountById((int)$realnameFailedMerchant['merchant_id']);
    $failedAuth = merchantAuthById((int)$realnameFailedMerchant['merchant_id']);

    debugStep('realname pending');
    $pendingProfile = AccountService::saveMerchantProfile((int)$realnamePendingMerchant['id'], [
        'realname' => [
            'real_name' => 'Wang Wu',
            'id_card' => '110101199003070033',
        ],
    ]);
    $pendingAccount = merchantAccountById((int)$realnamePendingMerchant['merchant_id']);
    $pendingAuth = merchantAuthById((int)$realnamePendingMerchant['merchant_id']);

    debugStep('realname daily limit');
    $settings['realname']['daily_limit'] = 1;
    JsonStoreService::save('settings', $settings);
    $limitFirstProfile = AccountService::saveMerchantProfile((int)$realnameLimitedMerchant['id'], [
        'realname' => [
            'real_name' => 'Zhao Liu',
            'id_card' => '110101199003070022',
        ],
    ]);
    $dailyLimitError = captureBusinessException(static function () use ($realnameLimitedMerchant): void {
        AccountService::saveMerchantProfile((int)$realnameLimitedMerchant['id'], [
            'realname' => [
                'real_name' => 'Zhao Liu',
                'id_card' => '110101199003070033',
            ],
        ]);
    });

    $realnameLogs = JsonStoreService::load('realname_audit_logs', []);
    $mockRequests = readMockRequests($mockLogPath);

    debugStep('build result');
    $checks = [
        'oauth_bind_authorize_redirect_ok' => $bindAuthorize['status'] === 302
            && str_contains($bindAuthorize['location'], 'https://merchant.nexpay.local/oauth/callback')
            && (string)($bindCallbackPayload['state'] ?? '') === (string)($bindStart['state'] ?? ''),
        'oauth_bind_success' => (string)($bindResult['channel'] ?? '') === 'qq'
            && isset($mainMerchantAccount['bindings']['qq'])
            && is_array($mainMerchantAccount['bindings']['qq'])
            && (string)($mainMerchantAccount['bindings']['qq']['status'] ?? '') === '已绑定'
            && (string)($mainMerchantAccount['bindings']['qq']['openid'] ?? '') === 'openid-main-qq',
        'oauth_duplicate_bind_blocked' => str_contains((string)($bindDuplicateError['message'] ?? ''), '已绑定其他商户'),
        'oauth_state_single_use' => str_contains((string)($bindStateReuseError['message'] ?? ''), 'state 不存在或已过期'),
        'oauth_login_success' => (string)($loginResult['mode'] ?? '') === 'login'
            && (int)($loginResult['user']['id'] ?? 0) === (int)$mainMerchant['id']
            && (int)($loginResult['user']['merchant_id'] ?? 0) === (int)$mainMerchant['merchant_id'],
        'oauth_unbound_login_blocked' => str_contains((string)($unboundLoginError['message'] ?? ''), '尚未绑定商户'),
        'oauth_root_sign_success' => (string)($rootSignBindResult['channel'] ?? '') === 'qq'
            && isset($otherMerchantAccount['bindings']['qq'])
            && is_array($otherMerchantAccount['bindings']['qq'])
            && (string)($otherMerchantAccount['bindings']['qq']['openid'] ?? '') === 'openid-root-qq',
        'oauth_bad_sign_blocked' => str_contains((string)($badSignError['message'] ?? ''), '响应签名校验失败'),
        'oauth_logs_written' => hasLogSceneStatus($oauthLogs, 'oauth_start', 'success')
            && hasLogSceneStatus($oauthLogs, 'oauth_callback', 'success')
            && hasLogSceneStatus($oauthLogs, 'oauth_callback', 'failed'),
        'realname_approved' => (string)($approvedProfile['realname']['status'] ?? '') === 'approved'
            && (string)($approvedAccount['realname']['result'] ?? '') === 'provider_approved'
            && (string)($approvedAuth['realname_status'] ?? '') === 'approved'
            && !array_key_exists('id_card', (array)($approvedAccount['realname']['raw_response'] ?? []))
            && !array_key_exists('real_name', (array)($approvedAccount['realname']['raw_response'] ?? [])),
        'realname_failed' => (string)($failedProfile['realname']['status'] ?? '') === 'failed'
            && (string)($failedAccount['realname']['result'] ?? '') === 'provider_rejected'
            && (string)($failedAuth['realname_status'] ?? '') === 'failed',
        'realname_pending' => (string)($pendingProfile['realname']['status'] ?? '') === 'pending'
            && (string)($pendingAccount['realname']['result'] ?? '') === 'provider_pending'
            && (string)($pendingAuth['realname_status'] ?? '') === 'pending',
        'realname_daily_limit_enforced' => (string)($limitFirstProfile['realname']['status'] ?? '') === 'failed'
            && str_contains((string)($dailyLimitError['message'] ?? ''), '今日实名认证提交次数已达上限'),
        'realname_logs_written' => hasRealnameLogStatus($realnameLogs, (int)$realnameApprovedMerchant['merchant_id'], 'success')
            && hasRealnameLogStatus($realnameLogs, (int)$realnameFailedMerchant['merchant_id'], 'failed')
            && hasRealnameLogStatus($realnameLogs, (int)$realnamePendingMerchant['merchant_id'], 'pending'),
        'mock_provider_requests_seen' => hasMockScene($mockRequests, 'oauth_authorize')
            && hasMockScene($mockRequests, 'oauth_token')
            && hasMockScene($mockRequests, 'realname'),
    ];

    $result = [
        'mock_provider' => [
            'base_url' => $mockBaseUrl,
            'request_count' => count($mockRequests),
        ],
        'oauth' => [
            'main_merchant' => [
                'user_id' => (int)$mainMerchant['id'],
                'merchant_id' => (int)$mainMerchant['merchant_id'],
                'binding' => $mainMerchantAccount['bindings']['qq'] ?? null,
            ],
            'other_merchant' => [
                'user_id' => (int)$otherMerchant['id'],
                'merchant_id' => (int)$otherMerchant['merchant_id'],
                'binding' => $otherMerchantAccount['bindings']['qq'] ?? null,
            ],
            'bind_start' => $bindStart,
            'bind_authorize' => $bindAuthorize,
            'bind_result' => $bindResult,
            'duplicate_bind_error' => $bindDuplicateError,
            'state_reuse_error' => $bindStateReuseError,
            'login_result' => $loginResult,
            'unbound_login_error' => $unboundLoginError,
            'root_sign_bind_result' => $rootSignBindResult,
            'bad_sign_error' => $badSignError,
            'log_count' => count($oauthLogs),
        ],
        'realname' => [
            'approved' => [
                'merchant_id' => (int)$realnameApprovedMerchant['merchant_id'],
                'profile' => $approvedProfile['realname'] ?? [],
                'auth_summary' => $approvedAuth,
            ],
            'failed' => [
                'merchant_id' => (int)$realnameFailedMerchant['merchant_id'],
                'profile' => $failedProfile['realname'] ?? [],
                'auth_summary' => $failedAuth,
            ],
            'pending' => [
                'merchant_id' => (int)$realnamePendingMerchant['merchant_id'],
                'profile' => $pendingProfile['realname'] ?? [],
                'auth_summary' => $pendingAuth,
            ],
            'daily_limit_error' => $dailyLimitError,
            'log_count' => count($realnameLogs),
        ],
        'checks' => $checks,
    ];

    $ok = !in_array(false, $checks, true);
} catch (Throwable $exception) {
    $result = array_merge($result, [
        'error' => $exception->getMessage(),
        'exception' => get_class($exception),
    ]);
} finally {
    foreach ($stores as $store) {
        JsonStoreService::save($store, $backups[$store]);
    }

    if ($mockServer !== null) {
        stopMockProviderServer($mockServer);
    }
    if ($externalMockBaseUrl === '') {
        cleanupServerLogs(array_filter([$mockStdout, $mockStderr, $mockLogPath]));
    }

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($ok ? 0 : 1);

function createMerchant(string $prefix): array
{
    $suffix = $prefix . date('His') . random_int(1000, 9999);
    $payload = MerchantAuthService::registerByAdmin([
        'merchant_name' => 'Verify ' . $prefix,
        'contact_name' => 'Verifier',
        'username' => $suffix,
        'email' => $suffix . '@example.com',
        'phone' => '139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'password' => 'Passw0rd!123',
        'status_code' => 1,
        'rate' => '0.80',
    ]);

    return [
        'id' => (int)($payload['id'] ?? 0),
        'merchant_id' => (int)($payload['merchant_id'] ?? 0),
        'username' => (string)($payload['username'] ?? ''),
    ];
}

function startMockProviderServer(string $routerPath, string $logPath = ''): array
{
    debugStep('mock server: enter');
    if (!is_file($routerPath)) {
        throw new RuntimeException('mock provider router not found: ' . $routerPath);
    }

    $lastError = '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        debugStep('mock server: attempt ' . ($attempt + 1));
        $port = random_int(20000, 45000);
        $stdout = tempnam(sys_get_temp_dir(), 'nexpay-provider-out-');
        $stderr = tempnam(sys_get_temp_dir(), 'nexpay-provider-err-');
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('failed to create mock provider server log files');
        }
        debugStep('mock server: try port ' . $port);

        $command = escapeshellarg(PHP_BINARY)
            . ' -S 127.0.0.1:' . $port
            . ' ' . escapeshellarg(basename($routerPath));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdout, 'a'],
            2 => ['file', $stderr, 'a'],
        ];

        $env = array_merge($_ENV, [
            'NEXPAY_MOCK_OAUTH_APP_ID' => 'mock-oauth-app',
            'NEXPAY_MOCK_OAUTH_APP_KEY' => 'mock-oauth-key',
            'NEXPAY_MOCK_REALNAME_APP_ID' => 'mock-realname-app',
            'NEXPAY_MOCK_REALNAME_APP_KEY' => 'mock-realname-key',
            'NEXPAY_MOCK_REALNAME_SECRET' => 'mock-realname-secret',
            'NEXPAY_MOCK_PROVIDER_LOG' => $logPath,
        ]);

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, dirname($routerPath), $env);
        if (!is_resource($process)) {
            cleanupServerLogs([$stdout, $stderr]);
            throw new RuntimeException('failed to start mock provider server');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $baseUrl = 'http://127.0.0.1:' . $port;

        try {
            waitForHttpReady($baseUrl . '/success', $stderr);
            debugStep('mock server: ready on ' . $port);
            return [
                'process' => $process,
                'base_url' => $baseUrl,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (Throwable $exception) {
            $lastError = $exception->getMessage();
            debugStep('mock server: failed attempt ' . ($attempt + 1) . ' - ' . $lastError);
            @proc_terminate($process);
            @proc_close($process);
            cleanupServerLogs([$stdout, $stderr]);
            usleep(150000);
        }
    }

    throw new RuntimeException($lastError !== '' ? $lastError : 'mock provider server did not become ready');
}

function stopMockProviderServer(?array $server): void
{
    if (!$server || !is_resource($server['process'] ?? null)) {
        return;
    }

    @proc_terminate($server['process']);
    @proc_close($server['process']);
}

function cleanupServerLogs(array $paths): void
{
    foreach ($paths as $path) {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

function waitForHttpReady(string $url, string $stderrPath): void
{
    for ($i = 0; $i < 40; $i++) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (trim((string)$response) === 'success') {
            return;
        }

        usleep(150000);
    }

    $stderr = is_file($stderrPath) ? trim((string)file_get_contents($stderrPath)) : '';
    throw new RuntimeException('mock provider server did not become ready' . ($stderr !== '' ? ': ' . $stderr : ''));
}

function fetchAuthorizeRedirect(string $url, string $mockCode): array
{
    $separator = str_contains($url, '?') ? '&' : '?';
    $requestUrl = $url . $separator . 'mock_code=' . rawurlencode($mockCode);
    $response = rawHttpGet($requestUrl);
    $status = $response['status'];
    $location = '';
    foreach ($response['headers'] as $header) {
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
            break;
        }
    }

    return [
        'request_url' => $requestUrl,
        'status' => $status,
        'location' => $location,
        'body' => $response['body'],
    ];
}

function extractQueryFromLocation(string $location): array
{
    if ($location === '') {
        throw new RuntimeException('oauth authorize location header missing');
    }

    $query = parse_url($location, PHP_URL_QUERY);
    $payload = [];
    parse_str((string)$query, $payload);
    return $payload;
}

function captureBusinessException(callable $callback): array
{
    try {
        $callback();
    } catch (BusinessException $exception) {
        return [
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode(),
        ];
    }

    throw new RuntimeException('expected BusinessException was not thrown');
}

function merchantAccountById(int $merchantId): array
{
    foreach (JsonStoreService::load('merchant_accounts', []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((int)($row['merchant_id'] ?? $row['id'] ?? 0) === $merchantId) {
            return $row;
        }
    }

    return [];
}

function merchantAuthById(int $merchantId): array
{
    foreach (JsonStoreService::load('merchant_auth_users', []) as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((int)($row['merchant_id'] ?? $row['id'] ?? 0) === $merchantId) {
            return $row + ['username' => (string)($row['username'] ?? $key)];
        }
    }

    return [];
}

function hasLogSceneStatus(array $logs, string $scene, string $status): bool
{
    foreach ($logs as $log) {
        if ((string)($log['scene'] ?? '') === $scene && (string)($log['status'] ?? '') === $status) {
            return true;
        }
    }

    return false;
}

function hasRealnameLogStatus(array $logs, int $merchantId, string $status): bool
{
    foreach ($logs as $log) {
        if (!is_array($log)) {
            continue;
        }
        if ((int)($log['merchant_id'] ?? 0) === $merchantId && (string)($log['status'] ?? '') === $status) {
            return true;
        }
    }

    return false;
}

function readMockRequests(string $path): array
{
    if ($path === '' || !is_file($path)) {
        return [];
    }

    $items = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $items[] = $decoded;
        }
    }

    return $items;
}

function hasMockScene(array $items, string $scene): bool
{
    foreach ($items as $item) {
        if ((string)($item['scene'] ?? '') === $scene) {
            return true;
        }
    }

    return false;
}

function rawHttpGet(string $url): array
{
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
    $host = (string)($parts['host'] ?? '');
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $path = (string)($parts['path'] ?? '/');
    $query = (string)($parts['query'] ?? '');
    if ($host === '') {
        throw new RuntimeException('invalid url: ' . $url);
    }

    $target = $path . ($query !== '' ? '?' . $query : '');
    $transport = $scheme === 'https' ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $error, 10);
    if ($socket === false) {
        throw new RuntimeException('oauth authorize socket connect failed: ' . $error);
    }

    stream_set_timeout($socket, 10);
    $request = "GET {$target} HTTP/1.1\r\n"
        . "Host: {$host}:{$port}\r\n"
        . "Connection: close\r\n\r\n";
    fwrite($socket, $request);

    $raw = '';
    while (!feof($socket)) {
        $chunk = fread($socket, 8192);
        if ($chunk === false) {
            break;
        }
        $raw .= $chunk;
    }
    fclose($socket);

    [$rawHeaders, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $headers = preg_split("/\r\n/", $rawHeaders) ?: [];
    $status = 0;
    if ($headers !== []) {
        preg_match('/\s(\d{3})\s/', (string)$headers[0], $matches);
        $status = isset($matches[1]) ? (int)$matches[1] : 0;
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
    ];
}

function debugStep(string $message): void
{
    $enabled = getenv('NEXPAY_VERIFY_DEBUG');
    if (!is_string($enabled) || !in_array(strtolower($enabled), ['1', 'true', 'yes'], true)) {
        return;
    }

    fwrite(STDERR, '[verify-oauth-realname] ' . $message . PHP_EOL);
}
