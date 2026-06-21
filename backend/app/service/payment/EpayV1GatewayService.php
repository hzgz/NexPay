<?php

namespace app\service\payment;

use app\exception\BusinessException;

class EpayV1GatewayService
{
    public function __construct(
        protected string $apiUrl,
        protected string $pid,
        protected string $key,
    ) {
    }

    public function buildPagePayUrl(array $payload): string
    {
        $signed = $this->buildRequest($payload);
        return rtrim($this->apiUrl, '/') . '/submit.php?' . http_build_query($signed);
    }

    public function create(array $payload): array
    {
        return $this->request('/mapi.php', $this->buildRequest($payload));
    }

    public function query(array $query): array
    {
        $query['pid'] = $this->pid;
        $query['key'] = $this->key;
        return $this->request('/api.php', $query, 'GET');
    }

    protected function buildRequest(array $payload): array
    {
        $payload['pid'] = $this->pid;
        $payload['sign_type'] = 'MD5';
        $payload['sign'] = SignService::md5Sign($payload, $this->key);
        return $payload;
    }

    protected function request(string $path, array $payload, string $method = 'POST'): array
    {
        $url = rtrim($this->apiUrl, '/') . $path;
        $options = [
            'http' => [
                'method' => $method,
                'timeout' => 10,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload),
            ],
        ];
        if ($method === 'GET') {
            $url .= '?' . http_build_query($payload);
            unset($options['http']['content']);
        }
        $response = @file_get_contents($url, false, stream_context_create($options));
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new BusinessException('上游易支付 V1 响应异常');
        }
        return $decoded;
    }
}
