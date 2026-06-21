<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;

class ProviderRuntimeService
{
    private const VERIFY_CODE_STORE = 'provider_verify_codes';
    private const VERIFY_SEND_STORE = 'provider_verify_send_attempts';
    private const TEST_LOG_STORE = 'provider_test_logs';
    private const VERIFY_CODE_TTL = 300;
    private const VERIFY_SEND_INTERVAL = 60;
    private const VERIFY_SEND_WINDOW = 600;
    private const VERIFY_SEND_WINDOW_LIMIT = 5;
    private const VERIFY_MAX_ATTEMPTS = 5;

    public static function sendVerifyCode(string $scene, string $targetType, string $target): array
    {
        $scene = trim($scene) !== '' ? trim($scene) : 'merchant_forgot';
        $targetType = strtolower(trim($targetType));
        $target = trim($target);

        if (!in_array($targetType, ['mail', 'sms'], true)) {
            throw new BusinessException('验证码类型仅支持 mail 或 sms', StatusCode::VALIDATION_ERROR);
        }

        if ($target === '') {
            throw new BusinessException('验证码接收目标不能为空', StatusCode::VALIDATION_ERROR);
        }

        if ($targetType === 'mail' && !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('邮箱格式不正确', StatusCode::VALIDATION_ERROR);
        }

        $sendAttempts = self::pruneSendAttempts(JsonStoreService::load(self::VERIFY_SEND_STORE, []));
        self::assertSendRateLimit($sendAttempts, $scene, $targetType, $target);

        $code = (string)random_int(100000, 999999);
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + self::VERIFY_CODE_TTL);

        $sendAttempts[] = [
            'id' => self::nextId($sendAttempts),
            'scene' => $scene,
            'target_type' => $targetType,
            'target' => $target,
            'created_at' => $now,
        ];
        JsonStoreService::save(self::VERIFY_SEND_STORE, $sendAttempts);

        if ($targetType === 'mail') {
            try {
                self::sendMail($target, 'NexPay 验证码', '您的验证码是：' . $code . '，5 分钟内有效。');
            } catch (BusinessException $exception) {
                self::writeRuntimeLog([
                    'type' => 'mail',
                    'scene' => $scene,
                    'target' => self::maskTarget($target, $targetType),
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'operator' => 'system',
                ]);
                throw $exception;
            }
        } else {
            try {
                self::sendSms($target, $code);
            } catch (BusinessException $exception) {
                self::writeRuntimeLog([
                    'type' => 'sms',
                    'scene' => $scene,
                    'target' => self::maskTarget($target, $targetType),
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'operator' => 'system',
                ]);
                throw $exception;
            }
        }

        $items = self::pruneCodes(JsonStoreService::load(self::VERIFY_CODE_STORE, []));
        $items[] = [
            'id' => self::nextId($items),
            'scene' => $scene,
            'target_type' => $targetType,
            'target' => $target,
            'code_hash' => password_hash($code, PASSWORD_BCRYPT),
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ];
        JsonStoreService::save(self::VERIFY_CODE_STORE, $items);
        self::writeRuntimeLog([
            'type' => $targetType,
            'scene' => $scene,
            'target' => self::maskTarget($target, $targetType),
            'status' => 'success',
            'message' => $targetType === 'mail' ? '验证码邮件发送成功' : '验证码短信发送成功',
            'operator' => 'system',
        ]);

        return [
            'scene' => $scene,
            'target_type' => $targetType,
            'target' => self::maskTarget($target, $targetType),
            'expires_at' => $expiresAt,
        ];
    }

    public static function verifyCode(string $scene, string $targetType, string $target, string $code): void
    {
        $items = self::pruneCodes(JsonStoreService::load(self::VERIFY_CODE_STORE, []));
        $matched = false;
        $attemptsExceeded = false;

        foreach ($items as $index => $item) {
            if ((string)($item['scene'] ?? '') !== $scene
                || (string)($item['target_type'] ?? '') !== $targetType
                || (string)($item['target'] ?? '') !== $target
            ) {
                continue;
            }

            $matched = true;
            if (!password_verify($code, (string)($item['code_hash'] ?? ''))) {
                $attempts = (int)($item['attempts'] ?? 0) + 1;
                if ($attempts >= self::VERIFY_MAX_ATTEMPTS) {
                    unset($items[$index]);
                    $attemptsExceeded = true;
                } else {
                    $items[$index]['attempts'] = $attempts;
                }
                JsonStoreService::save(self::VERIFY_CODE_STORE, array_values($items));
                continue;
            }

            unset($items[$index]);
            JsonStoreService::save(self::VERIFY_CODE_STORE, array_values($items));
            return;
        }

        if ($attemptsExceeded) {
            throw new BusinessException('验证码错误次数过多，请重新获取', StatusCode::VALIDATION_ERROR);
        }

        throw new BusinessException($matched ? '验证码错误' : '验证码不存在或已过期', StatusCode::VALIDATION_ERROR);
    }

    public static function sendMail(string $to, string $subject, string $body): void
    {
        $settings = self::settings('mail');
        if (!(bool)($settings['enabled'] ?? false)) {
            throw new BusinessException('邮件 provider 未启用，邮件未发送', StatusCode::BUSINESS_ERROR);
        }

        $host = trim((string)($settings['smtp_host'] ?? ''));
        $port = (int)($settings['smtp_port'] ?? 465);
        $user = trim((string)($settings['smtp_user'] ?? ''));
        $pass = (string)($settings['smtp_pass'] ?? '');
        $secure = strtolower(trim((string)($settings['smtp_secure'] ?? 'ssl')));
        $from = trim((string)($settings['from_email'] ?? $user));
        $senderName = trim((string)($settings['sender_name'] ?? 'NexPay'));

        if ($host === '' || $port <= 0 || $user === '' || $pass === '' || $from === '') {
            throw new BusinessException('SMTP 配置不完整，邮件未发送', StatusCode::BUSINESS_ERROR);
        }

        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $error, 12, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new BusinessException('SMTP 连接失败：' . $error, StatusCode::BUSINESS_ERROR);
        }

        stream_set_timeout($socket, 12);
        self::smtpExpect($socket, [220]);
        self::smtpCommand($socket, 'EHLO nexpay.local', [250]);
        if ($secure === 'tls') {
            self::smtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                throw new BusinessException('SMTP STARTTLS 握手失败', StatusCode::BUSINESS_ERROR);
            }
            self::smtpCommand($socket, 'EHLO nexpay.local', [250]);
        }
        self::smtpCommand($socket, 'AUTH LOGIN', [334]);
        self::smtpCommand($socket, base64_encode($user), [334]);
        self::smtpCommand($socket, base64_encode($pass), [235]);
        self::smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
        self::smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        self::smtpCommand($socket, 'DATA', [354]);

        $headers = [
            'From: ' . self::mailboxHeader($senderName, $from),
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $body) . "\r\n.\r\n");
        self::smtpExpect($socket, [250]);
        self::smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
    }

    public static function sendSms(string $phone, string $code): void
    {
        $settings = self::settings('sms');
        if (!(bool)($settings['enabled'] ?? false)) {
            throw new BusinessException('短信 provider 未启用，短信未发送', StatusCode::BUSINESS_ERROR);
        }

        $accessKeyId = trim((string)($settings['access_key_id'] ?? ''));
        $accessKeySecret = trim((string)($settings['access_key_secret'] ?? ''));
        $signName = trim((string)($settings['sign_name'] ?? ''));
        $templateCode = trim((string)($settings['template_code'] ?? ''));
        $apiUrl = trim((string)($settings['api_url'] ?? 'https://dysmsapi.aliyuncs.com/'));
        if ($accessKeyId === '' || $accessKeySecret === '' || $signName === '' || $templateCode === '') {
            throw new BusinessException('阿里云短信配置不完整，短信未发送', StatusCode::BUSINESS_ERROR);
        }
        if ($apiUrl === '') {
            throw new BusinessException('短信发送地址未配置，短信未发送', StatusCode::BUSINESS_ERROR);
        }

        $params = [
            'AccessKeyId' => $accessKeyId,
            'Action' => 'SendSms',
            'Format' => 'JSON',
            'PhoneNumbers' => $phone,
            'RegionId' => 'cn-hangzhou',
            'SignName' => $signName,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'TemplateCode' => $templateCode,
            'TemplateParam' => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2017-05-25',
        ];
        ksort($params);
        $canonicalized = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $stringToSign = 'POST&%2F&' . rawurlencode($canonicalized);
        $params['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 12,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            ],
        ]);
        $response = @file_get_contents($apiUrl, false, $context);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        $success = is_array($decoded) && (
            (string)($decoded['Code'] ?? '') === 'OK'
            || (int)($decoded['code'] ?? -1) === 0
            || (bool)($decoded['success'] ?? false) === true
        );
        if (!$success) {
            throw new BusinessException('阿里云短信发送失败：' . (string)($decoded['Message'] ?? $decoded['message'] ?? $decoded['msg'] ?? 'unknown'), StatusCode::BUSINESS_ERROR);
        }
    }

    public static function verifyGeetest(array $verifySettings, array $payload): bool
    {
        $captchaId = trim((string)($verifySettings['captcha_id'] ?? ''));
        $captchaKey = trim((string)($verifySettings['captcha_key'] ?? ''));
        if ($captchaId === '' || $captchaKey === '') {
            self::writeRuntimeLog([
                'type' => 'geetest',
                'scene' => 'server_validate',
                'target' => '',
                'status' => 'failed',
                'message' => '极验配置不完整，无法执行服务端校验',
                'operator' => 'system',
            ]);
            throw new BusinessException('极验配置不完整，无法执行服务端校验', StatusCode::BUSINESS_ERROR);
        }

        $lotNumber = trim((string)($payload['lot_number'] ?? $payload['geetest_lot_number'] ?? ''));
        $captchaOutput = trim((string)($payload['captcha_output'] ?? $payload['geetest_captcha_output'] ?? ''));
        $passToken = trim((string)($payload['pass_token'] ?? $payload['geetest_pass_token'] ?? ''));
        $genTime = trim((string)($payload['gen_time'] ?? $payload['geetest_gen_time'] ?? ''));
        if ($lotNumber === '' || $captchaOutput === '' || $passToken === '' || $genTime === '') {
            self::writeRuntimeLog([
                'type' => 'geetest',
                'scene' => 'server_validate',
                'target' => '',
                'status' => 'failed',
                'message' => '极验服务端校验参数不完整',
                'operator' => 'system',
            ]);
            throw new BusinessException('极验服务端校验参数不完整', StatusCode::VALIDATION_ERROR);
        }

        $params = [
            'lot_number' => $lotNumber,
            'captcha_output' => $captchaOutput,
            'pass_token' => $passToken,
            'gen_time' => $genTime,
            'sign_token' => hash_hmac('sha256', $lotNumber, $captchaKey),
        ];
        $validateUrl = trim((string)($verifySettings['validate_url'] ?? ''));
        if ($validateUrl === '') {
            $validateUrl = 'https://gcaptcha4.geetest.com/validate?captcha_id={captcha_id}';
        }
        $validateUrl = str_replace('{captcha_id}', rawurlencode($captchaId), $validateUrl);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 12,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
            ],
        ]);
        $response = @file_get_contents($validateUrl, false, $context);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            self::writeRuntimeLog([
                'type' => 'geetest',
                'scene' => 'server_validate',
                'target' => self::maskToken($lotNumber),
                'status' => 'failed',
                'message' => '极验服务端响应异常',
                'operator' => 'system',
            ]);
            throw new BusinessException('极验服务端响应异常', StatusCode::BUSINESS_ERROR);
        }

        $passed = (string)($decoded['result'] ?? '') === 'success'
            || (int)($decoded['code'] ?? -1) === 0
            || (bool)($decoded['success'] ?? false) === true;
        self::writeRuntimeLog([
            'type' => 'geetest',
            'scene' => 'server_validate',
            'target' => self::maskToken($lotNumber),
            'status' => $passed ? 'success' : 'failed',
            'message' => $passed ? '极验服务端校验通过' : '极验服务端校验未通过',
            'operator' => 'system',
        ]);

        return $passed;
    }

    public static function testProvider(string $type, string $target, string $operator = 'admin', string $ip = ''): array
    {
        $type = strtolower(trim($type));
        $target = trim($target);
        if (!in_array($type, ['mail', 'sms'], true)) {
            throw new BusinessException('provider 测试类型仅支持 mail 或 sms', StatusCode::VALIDATION_ERROR);
        }

        if ($type === 'mail' && !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('测试邮箱格式不正确', StatusCode::VALIDATION_ERROR);
        }
        if ($type === 'sms' && !preg_match('/^\d{6,20}$/', preg_replace('/\D+/', '', $target))) {
            throw new BusinessException('测试手机号格式不正确', StatusCode::VALIDATION_ERROR);
        }

        $settings = self::settings($type);
        $providerCode = trim((string)($settings['provider_code'] ?? $settings['provider'] ?? ''));

        try {
            if ($type === 'mail') {
                self::sendMail($target, 'NexPay 邮件 provider 测试', '这是一封 NexPay 邮件 provider 测试消息，用于确认 SMTP 配置可以真实发送。');
            } else {
                self::sendSms($target, (string)random_int(100000, 999999));
            }

            return self::writeRuntimeLog([
                'type' => $type,
                'scene' => 'admin_provider_test',
                'provider_code' => $providerCode,
                'target' => self::maskTarget($target, $type),
                'status' => 'success',
                'message' => $type === 'mail' ? '测试邮件发送成功' : '测试短信发送成功',
                'operator' => $operator,
                'ip' => $ip,
            ]);
        } catch (BusinessException $exception) {
            self::writeRuntimeLog([
                'type' => $type,
                'scene' => 'admin_provider_test',
                'provider_code' => $providerCode,
                'target' => self::maskTarget($target, $type),
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'operator' => $operator,
                'ip' => $ip,
            ]);
            throw $exception;
        }
    }

    public static function testLogs(int $limit = 100): array
    {
        $items = JsonStoreService::load(self::TEST_LOG_STORE, []);
        $items = array_values(array_filter($items, static fn($item): bool => is_array($item)));
        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return array_slice($items, 0, max(1, min(500, $limit)));
    }

    public static function recordEvent(array $payload): array
    {
        return self::writeRuntimeLog($payload);
    }

    private static function settings(string $group): array
    {
        $settings = SettingsService::all(false);
        return is_array($settings[$group] ?? null) ? $settings[$group] : [];
    }

    private static function smtpCommand($socket, string $command, array $expected): void
    {
        fwrite($socket, $command . "\r\n");
        self::smtpExpect($socket, $expected);
    }

    private static function smtpExpect($socket, array $expected): void
    {
        $lines = '';
        while (($line = fgets($socket, 515)) !== false) {
            $lines .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $code = (int)substr($lines, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new BusinessException('SMTP 响应异常：' . trim($lines), StatusCode::BUSINESS_ERROR);
        }
    }

    private static function mailboxHeader(string $name, string $email): string
    {
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    private static function pruneCodes(array $items): array
    {
        $now = time();
        return array_values(array_filter($items, static function (array $item) use ($now): bool {
            $expiresAt = strtotime((string)($item['expires_at'] ?? ''));
            return $expiresAt !== false && $expiresAt >= $now;
        }));
    }

    private static function pruneSendAttempts(array $items): array
    {
        $now = time();
        return array_values(array_filter($items, static function (array $item) use ($now): bool {
            $createdAt = strtotime((string)($item['created_at'] ?? ''));
            return $createdAt !== false && $now - $createdAt <= self::VERIFY_SEND_WINDOW;
        }));
    }

    private static function assertSendRateLimit(array $items, string $scene, string $targetType, string $target): void
    {
        $now = time();
        $recent = 0;
        foreach ($items as $item) {
            if ((string)($item['scene'] ?? '') !== $scene
                || (string)($item['target_type'] ?? '') !== $targetType
                || (string)($item['target'] ?? '') !== $target
            ) {
                continue;
            }

            $createdAt = strtotime((string)($item['created_at'] ?? ''));
            if ($createdAt === false) {
                continue;
            }

            if ($now - $createdAt < self::VERIFY_SEND_INTERVAL) {
                self::writeRuntimeLog([
                    'type' => $targetType,
                    'scene' => $scene,
                    'target' => self::maskTarget($target, $targetType),
                    'status' => 'failed',
                    'message' => '验证码发送过于频繁，请稍后再试',
                    'operator' => 'system',
                ]);
                throw new BusinessException('验证码发送过于频繁，请稍后再试', StatusCode::BUSINESS_ERROR);
            }

            if ($now - $createdAt <= self::VERIFY_SEND_WINDOW) {
                $recent++;
            }
        }

        if ($recent >= self::VERIFY_SEND_WINDOW_LIMIT) {
            self::writeRuntimeLog([
                'type' => $targetType,
                'scene' => $scene,
                'target' => self::maskTarget($target, $targetType),
                'status' => 'failed',
                'message' => '验证码发送次数过多，请稍后再试',
                'operator' => 'system',
            ]);
            throw new BusinessException('验证码发送次数过多，请稍后再试', StatusCode::BUSINESS_ERROR);
        }
    }

    private static function nextId(array $items): int
    {
        $ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function maskTarget(string $target, string $targetType): string
    {
        if ($targetType === 'mail' && str_contains($target, '@')) {
            [$name, $domain] = explode('@', $target, 2);
            return substr($name, 0, 2) . '***@' . $domain;
        }

        return substr($target, 0, 3) . '****' . substr($target, -4);
    }

    private static function writeRuntimeLog(array $payload): array
    {
        $items = JsonStoreService::load(self::TEST_LOG_STORE, []);
        $items[] = [
            'id' => self::nextId($items),
            'type' => (string)($payload['type'] ?? ''),
            'scene' => (string)($payload['scene'] ?? ''),
            'provider_code' => (string)($payload['provider_code'] ?? ''),
            'target' => (string)($payload['target'] ?? ''),
            'status' => (string)($payload['status'] ?? ''),
            'message' => (string)($payload['message'] ?? ''),
            'operator' => trim((string)($payload['operator'] ?? 'admin')) ?: 'admin',
            'ip' => (string)($payload['ip'] ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $items = array_slice($items, -300);
        JsonStoreService::save(self::TEST_LOG_STORE, $items);

        return end($items) ?: [];
    }

    private static function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        return substr($token, 0, 4) . '***' . substr($token, -4);
    }
}
