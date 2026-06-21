<?php

namespace app\service\payment;

use app\exception\BusinessException;

class EpayV2GatewayService
{
    public function __construct(
        protected string $apiUrl,
        protected string $pid,
        protected string $platformPublicKey,
        protected string $merchantPrivateKey,
    ) {
    }

    public function create(array $payload): array
    {
        return $this->request('/api/pay/create', $payload);
    }

    public function query(array $payload): array
    {
        return $this->request('/api/pay/query', $payload);
    }

    public function refund(array $payload): array
    {
        return $this->request('/api/pay/refund', $payload);
    }

    public function refundQuery(array $payload): array
    {
        return $this->request('/api/pay/refundquery', $payload);
    }

    public function close(array $payload): array
    {
        return $this->request('/api/pay/close', $payload);
    }

    public function merchantInfo(array $payload = []): array
    {
        return $this->request('/api/merchant/info', $payload);
    }

    public function merchantOrders(array $payload = []): array
    {
        return $this->request('/api/merchant/orders', $payload);
    }

    public function transferSubmit(array $payload): array
    {
        return $this->request('/api/transfer/submit', $payload);
    }

    public function transferQuery(array $payload): array
    {
        return $this->request('/api/transfer/query', $payload);
    }

    public function transferBalance(array $payload = []): array
    {
        return $this->request('/api/transfer/balance', $payload);
    }

    protected function request(string $path, array $payload): array
    {
        $payload['pid'] = $this->pid;
        $payload['timestamp'] = (string)time();
        $payload['sign_type'] = 'RSA';
        $payload['sign'] = SignService::rsaSign($payload, $this->merchantPrivateKey);

        $url = rtrim($this->apiUrl, '/') . $path;
        $options = [
            'http' => [
                'method' => 'POST',
                'timeout' => 10,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload),
            ],
        ];
        $response = @file_get_contents($url, false, stream_context_create($options));
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new BusinessException('上游易支付 V2 响应异常');
        }
        if (isset($decoded['sign']) && !SignService::verifyRsa($decoded, $this->platformPublicKey, (string)$decoded['sign'])) {
            throw new BusinessException('上游易支付 V2 验签失败');
        }
        return $decoded;
    }
}
