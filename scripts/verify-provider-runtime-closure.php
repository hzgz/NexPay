<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\exception\BusinessException;
use app\service\system\AuthPolicyService;
use app\service\system\JsonStoreService;
use app\service\system\ProviderRuntimeService;
use app\service\system\SettingsService;

$httpBaseUrl = rtrim(trim((string)($argv[1] ?? '')), '/');
$smtpPort = (int)($argv[2] ?? 0);
$smtpLogPath = trim((string)($argv[3] ?? ''));
$httpLogPath = trim((string)($argv[4] ?? ''));

if ($httpBaseUrl === '' || $smtpPort <= 0 || $smtpLogPath === '' || $httpLogPath === '') {
    fwrite(STDERR, "usage: php scripts/verify-provider-runtime-closure.php <http_base_url> <smtp_port> <smtp_log_path> <http_log_path>\n");
    exit(1);
}

$stores = [
    'settings',
    'provider_verify_codes',
    'provider_verify_send_attempts',
    'provider_test_logs',
    'auth_captcha_runtime',
];

$backups = [];
foreach ($stores as $store) {
    $backups[$store] = JsonStoreService::load($store, []);
}

$result = [];
$ok = false;

try {
    foreach ($stores as $store) {
        JsonStoreService::save($store, []);
    }

    $settings = is_array($backups['settings']) ? $backups['settings'] : [];
    $settings['mail'] = is_array($settings['mail'] ?? null) ? $settings['mail'] : [];
    $settings['sms'] = is_array($settings['sms'] ?? null) ? $settings['sms'] : [];
    $settings['verify'] = is_array($settings['verify'] ?? null) ? $settings['verify'] : [];
    $settings['auth'] = is_array($settings['auth'] ?? null) ? $settings['auth'] : [];
    $settings['merchant'] = is_array($settings['merchant'] ?? null) ? $settings['merchant'] : [];

    $settings['mail'] = array_replace($settings['mail'], [
        'enabled' => true,
        'provider_code' => 'mail-smtp',
        'smtp_host' => '127.0.0.1',
        'smtp_port' => (string)$smtpPort,
        'smtp_user' => 'mock-user',
        'smtp_pass' => 'mock-pass',
        'smtp_secure' => 'tcp',
        'sender_name' => 'NexPay Verify',
        'from_email' => 'noreply@nexpay.local',
    ]);

    $settings['sms'] = array_replace($settings['sms'], [
        'enabled' => true,
        'provider_code' => 'sms-aliyun',
        'api_url' => $httpBaseUrl . '/sms',
        'sign_name' => 'NexPay',
        'template_code' => 'SMS_VERIFY',
        'access_key_id' => 'mock-ak',
        'access_key_secret' => 'mock-sk',
    ]);

    $settings['verify'] = array_replace($settings['verify'], [
        'provider_code' => 'geetest',
        'enabled' => true,
        'geetest_enabled' => true,
        'geetest_scene_login' => true,
        'geetest_scene_register' => false,
        'geetest_scene_forgot' => false,
        'geetest_scene_admin' => false,
        'captcha_enabled' => true,
        'captcha_id' => 'mock-geetest-id',
        'captcha_key' => 'mock-geetest-key',
        'validate_url' => $httpBaseUrl . '/geetest/validate?captcha_id={captcha_id}',
        'failback' => true,
    ]);

    $settings['auth'] = array_replace($settings['auth'], [
        'captcha_enabled' => true,
        'merchant_login_captcha' => true,
    ]);

    $settings['merchant'] = array_replace($settings['merchant'], [
        'register_enabled' => true,
    ]);

    JsonStoreService::save('settings', $settings);

    $mailTarget = 'merchant@example.com';
    $mailSend = ProviderRuntimeService::sendVerifyCode('merchant_forgot', 'mail', $mailTarget);
    $mailLogs = waitForSmtpMessages($smtpLogPath, 1);
    $mailCode = extractCodeFromText((string)($mailLogs[0]['body'] ?? ''));
    $mailWrongCodeError = captureBusinessException(static function () use ($mailTarget): void {
        ProviderRuntimeService::verifyCode('merchant_forgot', 'mail', $mailTarget, '000000');
    });
    ProviderRuntimeService::verifyCode('merchant_forgot', 'mail', $mailTarget, $mailCode);

    $smsTarget = '13800138000';
    $smsSend = ProviderRuntimeService::sendVerifyCode('merchant_forgot', 'sms', $smsTarget);
    $httpLogs = waitForHttpLogScenes($httpLogPath, ['sms']);
    $smsCode = extractSmsCode($httpLogs, $smsTarget);
    $smsWrongCodeError = captureBusinessException(static function () use ($smsTarget): void {
        ProviderRuntimeService::verifyCode('merchant_forgot', 'sms', $smsTarget, '000000');
    });
    ProviderRuntimeService::verifyCode('merchant_forgot', 'sms', $smsTarget, $smsCode);

    $mailTest = ProviderRuntimeService::testProvider('mail', 'admin@example.com', 'verify-script', '127.0.0.1');
    $smsTest = ProviderRuntimeService::testProvider('sms', '13800138001', 'verify-script', '127.0.0.1');

    $verifySettings = SettingsService::all(false)['verify'] ?? [];
    $geetestSuccess = ProviderRuntimeService::verifyGeetest((array)$verifySettings, [
        'lot_number' => 'lot-success',
        'captcha_output' => 'pass',
        'pass_token' => 'token-pass',
        'gen_time' => (string)time(),
    ]);
    $geetestFail = ProviderRuntimeService::verifyGeetest((array)$verifySettings, [
        'lot_number' => 'lot-fail',
        'captcha_output' => 'deny',
        'pass_token' => 'token-pass',
        'gen_time' => (string)time(),
    ]);

    $captchaChallenge = AuthPolicyService::issueCaptchaChallenge('merchant_login');
    $captchaStorage = JsonStoreService::load('auth_captcha_runtime', []);
    $captchaKey = (string)($captchaChallenge['captcha_key'] ?? '');
    $captchaCode = (string)($captchaStorage[$captchaKey]['code'] ?? '');
    if ($captchaKey === '' || $captchaCode === '') {
        throw new RuntimeException('captcha challenge code not found');
    }

    $settings['verify']['validate_url'] = $httpBaseUrl . '/geetest/unreachable?captcha_id={captcha_id}';
    JsonStoreService::save('settings', $settings);
    AuthPolicyService::ensureMerchantLoginAllowed([
        'lot_number' => 'lot-fallback',
        'captcha_output' => 'pass',
        'pass_token' => 'token-pass',
        'gen_time' => (string)time(),
        'captcha_key' => $captchaKey,
        'captcha_code' => $captchaCode,
    ]);

    $providerLogs = ProviderRuntimeService::testLogs(200);
    $httpLogs = readJsonLines($httpLogPath);
    $smtpLogs = readJsonLines($smtpLogPath);

    $checks = [
        'mail_verify_send_success' => (string)($mailSend['target_type'] ?? '') === 'mail'
            && $mailCode !== ''
            && (string)($smtpLogs[0]['to'] ?? '') === $mailTarget,
        'mail_verify_code_chain' => str_contains((string)($mailWrongCodeError['message'] ?? ''), '验证码错误'),
        'sms_verify_send_success' => (string)($smsSend['target_type'] ?? '') === 'sms'
            && $smsCode !== ''
            && hasHttpSceneTarget($httpLogs, 'sms', $smsTarget),
        'sms_verify_code_chain' => str_contains((string)($smsWrongCodeError['message'] ?? ''), '验证码错误'),
        'admin_provider_test_success' => (string)($mailTest['status'] ?? '') === 'success'
            && (string)($smsTest['status'] ?? '') === 'success',
        'geetest_success_and_fail_paths' => $geetestSuccess === true && $geetestFail === false,
        'geetest_failback_to_captcha' => true,
        'provider_logs_written' => hasProviderLog($providerLogs, 'mail', 'success')
            && hasProviderLog($providerLogs, 'sms', 'success')
            && hasProviderLog($providerLogs, 'geetest', 'success')
            && hasProviderLog($providerLogs, 'geetest', 'failed'),
        'mock_requests_seen' => hasHttpScene($httpLogs, 'sms')
            && hasHttpScene($httpLogs, 'geetest')
            && count($smtpLogs) >= 2,
    ];

    $result = [
        'mail' => [
            'send' => $mailSend,
            'captured_code' => $mailCode,
            'wrong_code_error' => $mailWrongCodeError,
            'smtp_messages' => $smtpLogs,
        ],
        'sms' => [
            'send' => $smsSend,
            'captured_code' => $smsCode,
            'wrong_code_error' => $smsWrongCodeError,
            'http_logs' => $httpLogs,
        ],
        'provider_tests' => [
            'mail' => $mailTest,
            'sms' => $smsTest,
        ],
        'geetest' => [
            'success' => $geetestSuccess,
            'fail' => $geetestFail,
            'fallback' => 'captcha_passed_after_provider_exception',
        ],
        'checks' => $checks,
    ];

    $ok = !in_array(false, $checks, true);
} catch (Throwable $exception) {
    $result = [
        'error' => $exception->getMessage(),
        'exception' => get_class($exception),
    ];
} finally {
    foreach ($stores as $store) {
        JsonStoreService::save($store, $backups[$store]);
    }

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($ok ? 0 : 1);

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

function waitForSmtpMessages(string $path, int $count): array
{
    for ($i = 0; $i < 50; $i++) {
        $items = readJsonLines($path);
        if (count($items) >= $count) {
            return $items;
        }

        usleep(200000);
    }

    return readJsonLines($path);
}

function waitForHttpLogScenes(string $path, array $scenes): array
{
    for ($i = 0; $i < 50; $i++) {
        $items = readJsonLines($path);
        $remaining = $scenes;
        foreach ($items as $item) {
            $scene = (string)($item['scene'] ?? '');
            $index = array_search($scene, $remaining, true);
            if ($index !== false) {
                unset($remaining[$index]);
            }
        }

        if ($remaining === []) {
            return $items;
        }

        usleep(200000);
    }

    return readJsonLines($path);
}

function readJsonLines(string $path): array
{
    if (!is_file($path)) {
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

function extractCodeFromText(string $text): string
{
    if (preg_match('/(\d{6})/', $text, $matches) === 1) {
        return (string)$matches[1];
    }

    return '';
}

function extractSmsCode(array $logs, string $targetPhone): string
{
    foreach ($logs as $item) {
        if ((string)($item['scene'] ?? '') !== 'sms') {
            continue;
        }

        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        if ((string)($payload['PhoneNumbers'] ?? '') !== $targetPhone) {
            continue;
        }

        $templateParam = json_decode((string)($payload['TemplateParam'] ?? '{}'), true);
        if (is_array($templateParam) && preg_match('/^\d{6}$/', (string)($templateParam['code'] ?? '')) === 1) {
            return (string)$templateParam['code'];
        }
    }

    return '';
}

function hasProviderLog(array $logs, string $type, string $status): bool
{
    foreach ($logs as $log) {
        if ((string)($log['type'] ?? '') === $type && (string)($log['status'] ?? '') === $status) {
            return true;
        }
    }

    return false;
}

function hasHttpScene(array $logs, string $scene): bool
{
    foreach ($logs as $log) {
        if ((string)($log['scene'] ?? '') === $scene) {
            return true;
        }
    }

    return false;
}

function hasHttpSceneTarget(array $logs, string $scene, string $target): bool
{
    foreach ($logs as $log) {
        if ((string)($log['scene'] ?? '') !== $scene) {
            continue;
        }

        $payload = is_array($log['payload'] ?? null) ? $log['payload'] : [];
        if ((string)($payload['PhoneNumbers'] ?? '') === $target) {
            return true;
        }
    }

    return false;
}
