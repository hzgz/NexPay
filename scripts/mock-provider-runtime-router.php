<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/success') {
    respondText(200, 'success');
    return;
}

if ($path === '/sms') {
    handleSms();
    return;
}

if ($path === '/geetest/validate') {
    handleGeetestValidate();
    return;
}

if ($path === '/geetest/unreachable') {
    respondText(500, 'provider_down');
    return;
}

respondJson(404, [
    'code' => 404,
    'message' => 'not_found',
]);

function handleSms(): void
{
    $params = $_POST;
    writeLog('sms', $params);

    $phone = trim((string)($params['PhoneNumbers'] ?? ''));
    $templateParam = json_decode((string)($params['TemplateParam'] ?? '{}'), true);
    $code = is_array($templateParam) ? (string)($templateParam['code'] ?? '') : '';

    if ($phone === '' || $code === '') {
        respondJson(422, [
            'Code' => 'InvalidParameters',
            'Message' => 'missing phone or code',
        ]);
        return;
    }

    if (str_ends_with($phone, '9999')) {
        respondJson(200, [
            'Code' => 'isv.BUSINESS_LIMIT_CONTROL',
            'Message' => 'limited by mock server',
        ]);
        return;
    }

    respondJson(200, [
        'Code' => 'OK',
        'Message' => 'OK',
        'BizId' => 'mock-biz-' . date('YmdHis'),
        'RequestId' => 'mock-request-' . date('YmdHis'),
    ]);
}

function handleGeetestValidate(): void
{
    $params = $_POST;
    $params['captcha_id'] = (string)($_GET['captcha_id'] ?? '');
    writeLog('geetest', $params);

    $captchaKey = envValue('NEXPAY_MOCK_GEETEST_KEY', 'mock-geetest-key');
    $lotNumber = trim((string)($params['lot_number'] ?? ''));
    $captchaOutput = trim((string)($params['captcha_output'] ?? ''));
    $passToken = trim((string)($params['pass_token'] ?? ''));
    $genTime = trim((string)($params['gen_time'] ?? ''));
    $signToken = trim((string)($params['sign_token'] ?? ''));

    if ($lotNumber === '' || $captchaOutput === '' || $passToken === '' || $genTime === '' || $signToken === '') {
        respondJson(422, [
            'result' => 'fail',
            'reason' => 'missing_fields',
        ]);
        return;
    }

    $expectedSignToken = hash_hmac('sha256', $lotNumber, $captchaKey);
    if (!hash_equals($expectedSignToken, $signToken)) {
        respondJson(200, [
            'result' => 'fail',
            'reason' => 'bad_sign_token',
        ]);
        return;
    }

    if ($captchaOutput === 'pass' && $passToken === 'token-pass') {
        respondJson(200, [
            'result' => 'success',
            'reason' => 'mock_pass',
        ]);
        return;
    }

    respondJson(200, [
        'result' => 'fail',
        'reason' => 'mock_reject',
    ]);
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
    $path = envValue('NEXPAY_MOCK_PROVIDER_RUNTIME_LOG');
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
