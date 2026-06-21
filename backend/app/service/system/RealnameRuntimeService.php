<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;

class RealnameRuntimeService
{
    private const LOG_STORE = 'realname_audit_logs';

    public static function submit(int $userId, int $merchantId, array $current, array $payload, string $operator = 'merchant'): array
    {
        $realName = trim((string)($payload['real_name'] ?? $payload['name'] ?? ''));
        $idCard = strtoupper(trim((string)($payload['id_card'] ?? $payload['idcard'] ?? $payload['cert_no'] ?? '')));

        if ($realName === '' || $idCard === '') {
            throw new BusinessException('请填写真实姓名和证件号', StatusCode::VALIDATION_ERROR);
        }

        if (!self::validRealName($realName)) {
            throw new BusinessException('真实姓名格式不正确', StatusCode::VALIDATION_ERROR);
        }

        if (!self::validIdCard($idCard)) {
            throw new BusinessException('证件号格式不正确', StatusCode::VALIDATION_ERROR);
        }

        $currentStatus = strtolower(trim((string)($current['status'] ?? '')));
        $currentHash = trim((string)($current['id_card_hash'] ?? ''));
        $newHash = hash('sha256', $idCard);
        if (in_array($currentStatus, ['approved', 'success', '已认证'], true)
            && $currentHash !== ''
            && !hash_equals($currentHash, $newHash)
        ) {
            throw new BusinessException('已认证资料不能直接变更，请联系管理员重新审核', StatusCode::BUSINESS_ERROR);
        }

        $settings = SettingsService::all(false);
        $realname = is_array($settings['realname'] ?? null) ? $settings['realname'] : [];
        $provider = strtolower(trim((string)($realname['provider'] ?? 'manual')));
        $enabled = (bool)($realname['enabled'] ?? false);

        self::ensureDailyLimit($merchantId, (int)($realname['daily_limit'] ?? 3));

        $record = [
            'status' => 'pending',
            'real_name' => $realName,
            'id_card' => self::maskIdCard($idCard),
            'id_card_hash' => $newHash,
            'provider' => $provider !== '' ? $provider : 'manual',
            'result' => 'pending_manual_review',
            'last_error' => '',
            'submitted_at' => date('Y-m-d H:i:s'),
            'reviewed_at' => '',
            'raw_response' => [],
        ];

        if (!$enabled) {
            $record['result'] = 'provider_disabled';
            $record['last_error'] = '实名认证 provider 未启用，已保存为待审核资料';
            self::writeLog($merchantId, $userId, $record, 'pending', $record['last_error'], $operator);
            return $record;
        }

        if ($provider !== 'api') {
            self::writeLog($merchantId, $userId, $record, 'pending', '实名资料已进入人工审核', $operator);
            return $record;
        }

        $apiUrl = rtrim(trim((string)($realname['api_url'] ?? '')), '/');
        $appId = trim((string)($realname['app_id'] ?? ''));
        $appKey = trim((string)($realname['app_key'] ?? ''));
        $appSecret = trim((string)($realname['app_secret'] ?? ''));
        if ($apiUrl === '' || $appId === '' || $appKey === '' || $appSecret === '') {
            $record['result'] = 'provider_config_missing';
            $record['last_error'] = '实名接口配置不完整，已保存为待审核资料';
            self::writeLog($merchantId, $userId, $record, 'failed', $record['last_error'], $operator);
            return $record;
        }

        try {
            $providerResult = self::callApiProvider($apiUrl, $appId, $appKey, $appSecret, $realName, $idCard, $merchantId);
        } catch (BusinessException $exception) {
            $record['result'] = 'provider_request_failed';
            $record['last_error'] = $exception->getMessage();
            self::writeLog($merchantId, $userId, $record, 'failed', $record['last_error'], $operator);
            return $record;
        }

        $record['raw_response'] = $providerResult['safe_response'];
        $record['last_error'] = (string)$providerResult['message'];
        if ($providerResult['status'] === 'approved') {
            $record['status'] = 'approved';
            $record['result'] = 'provider_approved';
            $record['last_error'] = '';
            $record['reviewed_at'] = date('Y-m-d H:i:s');
            self::writeLog($merchantId, $userId, $record, 'success', '实名接口核验通过', $operator);
            return $record;
        }

        if ($providerResult['status'] === 'failed') {
            $record['status'] = 'failed';
            $record['result'] = 'provider_rejected';
            $record['reviewed_at'] = date('Y-m-d H:i:s');
            self::writeLog($merchantId, $userId, $record, 'failed', $record['last_error'] ?: '实名接口核验不通过', $operator);
            return $record;
        }

        $record['result'] = 'provider_pending';
        self::writeLog($merchantId, $userId, $record, 'pending', $record['last_error'] ?: '实名接口返回处理中', $operator);
        return $record;
    }

    public static function logs(int $limit = 100): array
    {
        $items = JsonStoreService::load(self::LOG_STORE, []);
        $items = array_values(array_filter($items, static fn(mixed $item): bool => is_array($item)));
        usort($items, static fn(array $left, array $right): int => strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? '')));

        return array_slice($items, 0, max(1, min(500, $limit)));
    }

    public static function review(int $userId, int $merchantId, array $current, string $action, string $reason = '', string $operator = 'admin'): array
    {
        $action = strtolower(trim($action));
        $reason = trim($reason);
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new BusinessException('实名审核动作仅支持 approve 或 reject', StatusCode::VALIDATION_ERROR);
        }

        $realName = trim((string)($current['real_name'] ?? ''));
        $idCard = trim((string)($current['id_card'] ?? ''));
        if ($realName === '' || $idCard === '') {
            throw new BusinessException('商户尚未提交实名认证资料', StatusCode::BUSINESS_ERROR);
        }

        if ($action === 'reject' && $reason === '') {
            throw new BusinessException('请填写实名审核驳回原因', StatusCode::VALIDATION_ERROR);
        }

        $record = $current;
        $record['provider'] = (string)($record['provider'] ?? 'manual');
        $record['submitted_at'] = (string)($record['submitted_at'] ?? date('Y-m-d H:i:s'));
        $record['reviewed_at'] = date('Y-m-d H:i:s');
        $record['reviewed_by'] = trim($operator) !== '' ? trim($operator) : 'admin';
        $record['review_reason'] = $reason;
        $record['raw_response'] = is_array($record['raw_response'] ?? null) ? $record['raw_response'] : [];

        if ($action === 'approve') {
            $record['status'] = 'approved';
            $record['result'] = 'manual_approved';
            $record['last_error'] = '';
            self::writeLog($merchantId, $userId, $record, 'success', '后台人工审核通过', 'admin:' . $record['reviewed_by']);
            return $record;
        }

        $record['status'] = 'failed';
        $record['result'] = 'manual_rejected';
        $record['last_error'] = $reason;
        self::writeLog($merchantId, $userId, $record, 'failed', '后台人工审核驳回：' . $reason, 'admin:' . $record['reviewed_by']);

        return $record;
    }

    private static function callApiProvider(
        string $apiUrl,
        string $appId,
        string $appKey,
        string $appSecret,
        string $realName,
        string $idCard,
        int $merchantId
    ): array {
        $request = [
            'app_id' => $appId,
            'app_key' => $appKey,
            'merchant_id' => $merchantId,
            'real_name' => $realName,
            'id_card' => $idCard,
            'request_id' => 'RN' . date('YmdHis') . random_int(100000, 999999),
            'timestamp' => time(),
        ];
        $request['sign'] = self::sign($request, $appSecret);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 12,
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ]);
        $response = @file_get_contents($apiUrl, false, $context);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            throw new BusinessException('实名接口响应异常', StatusCode::BUSINESS_ERROR);
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $statusText = strtoupper(trim((string)($data['status'] ?? $data['result'] ?? $data['verify_status'] ?? '')));
        $passed = (bool)($data['passed'] ?? $data['success'] ?? false);
        $code = (string)($decoded['code'] ?? $data['code'] ?? '');
        $message = (string)($decoded['message'] ?? $decoded['msg'] ?? $data['message'] ?? $data['msg'] ?? '');

        $status = 'pending';
        if ($passed || in_array($statusText, ['APPROVED', 'SUCCESS', 'PASS', 'PASSED', 'VERIFIED'], true)) {
            $status = 'approved';
        } elseif (in_array($statusText, ['FAILED', 'FAIL', 'REJECTED', 'DENIED'], true) || ($code !== '' && !in_array($code, ['0', '200', 'OK'], true))) {
            $status = 'failed';
        }

        return [
            'status' => $status,
            'message' => $message,
            'safe_response' => self::safeResponse($decoded),
        ];
    }

    private static function ensureDailyLimit(int $merchantId, int $limit): void
    {
        $limit = max(1, $limit);
        $today = date('Y-m-d');
        $count = 0;
        foreach (self::logs(500) as $log) {
            if ((int)($log['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }
            $operator = strtolower(trim((string)($log['operator'] ?? '')));
            if ($operator !== 'merchant' && !str_starts_with($operator, 'merchant:')) {
                continue;
            }
            if (str_starts_with((string)($log['created_at'] ?? ''), $today)) {
                $count++;
            }
        }

        if ($count >= $limit) {
            throw new BusinessException('今日实名认证提交次数已达上限', StatusCode::BUSINESS_ERROR);
        }
    }

    private static function writeLog(int $merchantId, int $userId, array $record, string $status, string $message, string $operator): void
    {
        $items = JsonStoreService::load(self::LOG_STORE, []);
        $items[] = [
            'id' => self::nextId($items),
            'merchant_id' => $merchantId,
            'user_id' => $userId,
            'operator' => trim($operator) !== '' ? trim($operator) : 'merchant',
            'provider' => (string)($record['provider'] ?? ''),
            'status' => $status,
            'result' => (string)($record['result'] ?? ''),
            'message' => $message,
            'real_name' => (string)($record['real_name'] ?? ''),
            'id_card' => (string)($record['id_card'] ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        JsonStoreService::save(self::LOG_STORE, array_slice($items, -500));
    }

    private static function validRealName(string $name): bool
    {
        return mb_strlen($name) >= 2 && mb_strlen($name) <= 64;
    }

    private static function validIdCard(string $idCard): bool
    {
        return preg_match('/^[0-9A-Z][0-9A-Z\-]{5,31}$/', $idCard) === 1;
    }

    private static function maskIdCard(string $idCard): string
    {
        $length = strlen($idCard);
        if ($length <= 8) {
            return substr($idCard, 0, 2) . '****' . substr($idCard, -2);
        }

        return substr($idCard, 0, 4) . str_repeat('*', max(4, $length - 8)) . substr($idCard, -4);
    }

    private static function sign(array $payload, string $secret): string
    {
        unset($payload['sign']);
        ksort($payload);
        $pairs = [];
        foreach ($payload as $key => $value) {
            $text = is_scalar($value) ? trim((string)$value) : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($text !== '') {
                $pairs[] = $key . '=' . $text;
            }
        }

        return hash_hmac('sha256', implode('&', $pairs), $secret);
    }

    private static function safeResponse(array $payload): array
    {
        unset($payload['id_card'], $payload['cert_no'], $payload['real_name']);
        return $payload;
    }

    private static function nextId(array $items): int
    {
        $ids = array_map(static fn(mixed $item): int => is_array($item) ? (int)($item['id'] ?? 0) : 0, $items);
        return ($ids ? max($ids) : 0) + 1;
    }
}
