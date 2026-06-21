<?php

declare(strict_types=1);

namespace plugins\payment\airwallex;

class AirwallexClient
{
    private string $clientId;
    private string $apiKey;
    private string $webhookSecret;
    private string $baseUrl;
    private ?string $accessToken = null;

    const BASE_URL_PRODUCTION = 'https://api.airwallex.com/api/v1';
    const BASE_URL_SANDBOX = 'https://api-demo.airwallex.com/api/v1';

    public function __construct(string $clientId, string $apiKey, string $webhookSecret, bool $sandbox = false)
    {
        $this->clientId = $clientId;
        $this->apiKey = $apiKey;
        $this->webhookSecret = $webhookSecret;
        $this->baseUrl = $sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $cacheKey = 'airwallex_token_' . md5($this->clientId);
        $cached = cache($cacheKey);
        if ($cached) {
            $this->accessToken = $cached;
            return $this->accessToken;
        }

        $url = $this->baseUrl . '/authentication/login';
        $headers = [
            'x-client-id: ' . $this->clientId,
            'x-api-key: ' . $this->apiKey,
            'Content-Type: application/json',
        ];

        $response = get_curl($url, '{}', null, null, null, null, null, $headers);
        if (!$response) {
            throw new \Exception('Airwallex认证请求失败');
        }

        $result = json_decode($response, true);
        if (!isset($result['token'])) {
            $msg = $result['message'] ?? '获取AccessToken失败';
            throw new \Exception($msg);
        }

        $this->accessToken = $result['token'];
        $ttl = 1500;
        if (!empty($result['expires_at'])) {
            $expiresAt = strtotime($result['expires_at']);
            if ($expiresAt) {
                $ttl = max($expiresAt - time() - 60, 60);
            }
        }
        cache($cacheKey, $this->accessToken, $ttl);

        return $this->accessToken;
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $token = $this->getAccessToken();
        $url = $this->baseUrl . $path;

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $postData = $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        if ($method === 'GET' && $body) {
            $url .= '?' . http_build_query($body);
            $postData = null;
        }

        $response = get_curl($url, $postData, null, null, null, null, null, $headers);
        if (!$response) {
            throw new \Exception('Airwallex接口请求失败');
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new \Exception('Airwallex返回数据解析失败');
        }

        if (isset($result['code']) && $result['code'] !== 'success') {
            $msg = $result['message'] ?? '接口请求异常';
            throw new \Exception($msg);
        }

        return $result;
    }

    public function verifyWebhookSignature(string $payload, string $signature, string $timestamp): bool
    {
        $signedPayload = $timestamp . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $this->webhookSecret);
        return hash_equals($expectedSignature, $signature);
    }
}
