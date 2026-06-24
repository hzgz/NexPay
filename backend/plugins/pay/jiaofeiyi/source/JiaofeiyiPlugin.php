<?php

declare(strict_types=1);

namespace plugins\payment\jiaofeiyi;

use app\common\BasePayment;
use app\common\PaymentContext;
use app\service\payment\LocalOrderStore;
use app\service\payment\OrderService;
use Exception;

class JiaofeiyiPlugin extends BasePayment
{
    private const PAY_API_URL = 'https://jfyconsole.lakala.com/order/api/cashier/pay';
    private const QUERY_API_URL = 'https://payment.lakala.com/m/ccss/counter/order/query';
    private const DEFAULT_CHANNEL_ID = '95';
    private const PROXY_CONNECT_TIMEOUT = 15;
    private const PROXY_TIMEOUT = 35;
    private const PROXY_RETRY_TIMES = 3;
    private const PROXY_RETRY_DELAY_US = 300000;

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $ctx->order['trade_no'] . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = $ctx->order['typename'] ?? '';
        if (!$typename || !method_exists($this, $typename)) {
            return ['type' => 'error', 'msg' => 'Unsupported pay type'];
        }
        return $this->$typename($ctx);
    }

    public function alipay(PaymentContext $ctx): array
    {
        try {
            $codeUrl = $this->createQrcode($ctx);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => 'Alipay order create failed: ' . $e->getMessage()];
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $codeUrl];
        }
        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $codeUrl, 'expire' => strtotime($ctx->order['addtime']) + 300];
    }

    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $codeUrl = $this->createQrcode($ctx);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => 'WeChat order create failed: ' . $e->getMessage()];
        }

        $wxpayType = isset($this->channel['wxpay_type']) ? (string)$this->channel['wxpay_type'] : '1';
        if ($wxpayType === '3') {
            if ($ctx->mdevice === 'wechat' || $ctx->isMobile) {
                return ['type' => 'jump', 'url' => $codeUrl];
            }
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $codeUrl, 'expire' => strtotime($ctx->order['addtime']) + 300];
        }

        if ($wxpayType === '1') {
            if ($ctx->mdevice === 'wechat' || $ctx->isMobile) {
                return ['type' => 'jump', 'url' => $codeUrl];
            }
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $codeUrl, 'expire' => strtotime($ctx->order['addtime']) + 300];
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $codeUrl];
        }
        if ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $codeUrl, 'expire' => strtotime($ctx->order['addtime']) + 300];
        }
        return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $codeUrl, 'expire' => strtotime($ctx->order['addtime']) + 300];
    }

    public function bank(PaymentContext $ctx): array
    {
        try {
            $codeUrl = $this->createQrcode($ctx);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => 'UnionPay order create failed: ' . $e->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $codeUrl, 'expire' => strtotime($ctx->order['addtime']) + 300];
    }

    public function query(array $order): array
    {
        $targets = $this->buildQueryTargets($order);
        if ($targets === []) {
            throw new Exception('缴费易订单缺少可查询的上游单号');
        }

        $lastError = null;
        $lastResult = null;
        $pendingResult = null;

        foreach ($targets as $target) {
            try {
                $result = $this->queryOrder((string)$target);
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }

            $lastResult = $result;
            $normalized = $this->normalizeGatewayQueryResult($order, $result, (string)$target);
            if ((int)($normalized['status'] ?? 0) === 1) {
                return $normalized;
            }

            if ($pendingResult === null) {
                $pendingResult = $normalized;
            }
        }

        if (is_array($pendingResult)) {
            return $pendingResult;
        }

        if (is_array($lastResult)) {
            return $this->normalizeGatewayQueryResult($order, $lastResult, (string)$targets[0]);
        }

        if ($lastError instanceof \Throwable) {
            throw new Exception($lastError->getMessage());
        }

        throw new Exception('query order failed');
    }

    public function cron(array $channel): int
    {
        $channelId = (int)($channel['id'] ?? 0);
        if ($channelId <= 0) {
            return 0;
        }

        $processed = 0;
        foreach ($this->recentPendingTradeNos($channelId) as $tradeNo) {
            $order = $this->getOrder($tradeNo);
            if (!$order) {
                continue;
            }

            try {
                $result = $this->query($order);
            } catch (\Throwable) {
                continue;
            }

            if ((int)($result['status'] ?? 0) !== 1) {
                continue;
            }

            try {
                ($this->markTrustedQueryResult('notify', 'provider-order-query'))(function () use ($order, $result): void {
                    $this->processNotify(
                        $order,
                        $result['api_trade_no'] ?? ($order['api_trade_no'] ?? ''),
                        $result['buyer'] ?? null,
                        $result['bill_trade_no'] ?? null,
                        $result['bill_mch_trade_no'] ?? null,
                        $result['endtime'] ?? null
                    );
                });
                $processed++;
            } catch (\Throwable) {
                continue;
            }
        }

        return $processed;
    }

    public function queryOrder(string $outOrderNo): array
    {
        $outOrderNo = trim($outOrderNo);
        if ($outOrderNo === '') {
            throw new Exception('outOrderNo cannot be empty');
        }

        $lastError = null;
        $results = [];
        $preferredFields = ['payOrderNo', 'outOrderNo', 'channelTradeNo'];
        foreach ($preferredFields as $field) {
            try {
                $result = $this->queryOrderOnce($outOrderNo, $field);
                $results[] = $result;
                $status = $result['orderStatus'] ?? null;
                if ($status !== null && $status !== '' && $this->isPaidStatus($status)) {
                    return $result;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        foreach ($results as $result) {
            $status = $result['orderStatus'] ?? null;
            if ($status !== null && $status !== '' && $this->isUnpaidStatus($status)) {
                return $result;
            }
        }

        if (!empty($results)) {
            return $results[0];
        }

        if ($lastError instanceof \Throwable) {
            throw new Exception($lastError->getMessage());
        }

        throw new Exception('query order failed');
    }

    public function buildQueryTargets(array $order): array
    {
        $targets = [];
        $seen = [];

        $push = function (?string $value) use (&$targets, &$seen): void {
            $v = trim((string)$value);
            if ($v === '' || isset($seen[$v])) {
                return;
            }
            $seen[$v] = true;
            $targets[] = $v;
        };

        $meta = $this->readOrderExtMeta($order['ext'] ?? '');
        $payurl = trim((string)($order['payurl'] ?? ''));
        $payOrderNoByUrl = $this->extractPayOrderNo($payurl);

        $push((string)($order['api_trade_no'] ?? ''));
        $push((string)($order['bill_mch_trade_no'] ?? ''));
        $push((string)($order['bill_trade_no'] ?? ''));
        $push($payOrderNoByUrl);
        $push($meta['payOrderNo'] ?? '');
        $push($meta['sysTradeNo'] ?? '');
        $push($meta['channelTradeNo'] ?? '');

        return $targets;
    }

    public function isPaidStatus($status): bool
    {
        $status = strtoupper((string)$status);
        return in_array($status, ['2', 'PAID', 'SUCCESS', 'TRADE_SUCCESS', 'PAY_SUCCESS'], true);
    }

    public function isUnpaidStatus($status): bool
    {
        $status = strtoupper((string)$status);
        return in_array($status, ['0', '1', 'UNPAID', 'WAIT_PAY', 'PAYING', 'CREATE', 'PENDING'], true);
    }

    private function createQrcode(PaymentContext $ctx): string
    {
        $tradeNo = trim((string)($ctx->order['trade_no'] ?? ''));
        if ($tradeNo === '') {
            throw new Exception('tradeNo cannot be empty');
        }

        $cachedMeta = $this->loadCachedGatewayMeta($tradeNo);
        $cachedPayUrl = trim((string)($cachedMeta['payUrl'] ?? ''));
        if ($cachedPayUrl !== '') {
            $this->syncGatewayMetaToOrder($tradeNo, $cachedMeta);
            return $cachedPayUrl;
        }

        $channel = $this->channel;
        $merchId = trim((string)($channel['appurl'] ?? ''));
        if ($merchId === '') {
            throw new Exception('merchId cannot be empty');
        }

        $amount = sprintf('%.2f', (float)$ctx->order['realmoney']);
        $cashierTemplateName = trim((string)($channel['appid'] ?? ''));
        if ($cashierTemplateName === '') {
            $cashierTemplateName = 'cashier';
        }

        $orderData = [
            'merchId' => $merchId,
            'tradeAmount' => $amount,
            'remark' => trim((string)($channel['appmchid'] ?? '')),
            'orderTemplateData' => [
                [
                    'key' => 1747822239290,
                    'type' => 'number',
                    'index' => 0,
                    'label' => 'Pay Amount',
                    'value' => $amount,
                    'origin' => 'number17478222392900',
                    'options' => [
                        'label' => 'Pay Amount',
                        'content' => $amount,
                        'required' => true,
                        'labelAlign' => '',
                    ],
                    'displayName' => 'Amount',
                    'formItemFlag' => false,
                    'settingsTitle' => 'Amount Setting',
                    'marginLeftRight' => 10,
                    'marginTopBottom' => 5,
                    'cashierTemplateName' => $cashierTemplateName,
                    'state' => true,
                ],
            ],
        ];
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Content-Type: application/json;charset=utf-8',
        ];

        $response = $this->requestWithFallback(
            self::PAY_API_URL,
            $orderData,
            $headers,
            'pay'
        );

        $payUrl = $this->extractValue($response, ['payUrl', 'pay_url', 'url', 'codeUrl', 'counterUrl']);
        if ($payUrl === null || trim((string)$payUrl) === '') {
            throw new Exception('No payUrl returned');
        }

        $sysTradeNo = $this->extractValue($response, ['sysTradeNo', 'sys_trade_no', 'tradeNo', 'trade_no', 'orderNo', 'outOrderNo']);
        $payOrderNo = $this->extractPayOrderNo((string)$payUrl, $response);
        $channelTradeNo = (string)$this->extractValue($response, ['channelTradeNo', 'channel_trade_no']);
        if ($payOrderNo === '' && $channelTradeNo !== '') {
            $payOrderNo = trim($channelTradeNo);
        }
        $apiTradeNo = trim((string)($sysTradeNo ?: $payOrderNo));
        if ($apiTradeNo === '') {
            throw new Exception('No trade number returned');
        }

        $this->updateOrder($tradeNo, $apiTradeNo);
        $orderUpdate = ['payurl' => substr((string)$payUrl, 0, 500)];
        if ($payOrderNo !== '') {
            $orderUpdate['bill_mch_trade_no'] = $payOrderNo;
        }
        $this->saveOrderChanges($tradeNo, $orderUpdate);
        $this->mergeOrderExtMeta($tradeNo, [
            'sysTradeNo' => (string)$sysTradeNo,
            'payOrderNo' => (string)$payOrderNo,
            'channelTradeNo' => trim((string)$channelTradeNo),
            'payUrl' => (string)$payUrl,
            'updatedAt' => date('Y-m-d H:i:s'),
        ]);

        return (string)$payUrl;
    }

    private function requestWithFallback(
        string $url,
        array $payload,
        array $headers,
        string $scene
    ): array {
        $proxyApi = $this->getProxyApiUrl($this->channel);
        if ($proxyApi !== '') {
            return $this->requestViaProxyApi($proxyApi, $url, $payload, $headers);
        }

        $remoteApi = $this->getRemoteApiUrl($this->channel);
        if ($remoteApi !== '') {
            return $this->requestViaRemoteApi($remoteApi, $url, $payload, $headers, $scene);
        }

        return $this->doHttpJson($url, $payload, $headers);
    }

    private function requestViaRemoteApi(
        string $remoteApi,
        string $targetUrl,
        array $payload,
        array $headers,
        string $scene
    ): array {
        $proxyPayload = [
            'scene' => $scene,
            'target_url' => $targetUrl,
            'method' => 'POST',
            'data' => $payload,
            'headers' => array_values($headers),
            'timestamp' => time(),
        ];
        $proxyResponse = $this->doHttpJson(
            $remoteApi,
            $proxyPayload,
            [
                'Content-Type: application/json;charset=utf-8',
                'Accept: application/json,text/plain,*/*',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            ]
        );

        if (isset($proxyResponse['code']) && (int)$proxyResponse['code'] !== 0) {
            throw new Exception((string)($proxyResponse['msg'] ?? 'Remote API returned failure'));
        }
        if (isset($proxyResponse['data']) && is_array($proxyResponse['data'])) {
            return $proxyResponse['data'];
        }
        if (isset($proxyResponse['result']) && is_array($proxyResponse['result'])) {
            return $proxyResponse['result'];
        }
        if (is_array($proxyResponse)) {
            return $proxyResponse;
        }
        throw new Exception('Remote API returned invalid response');
    }

    private function requestViaProxyApi(string $proxyApi, string $targetUrl, array $payload, array $headers): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::PROXY_RETRY_TIMES; $attempt++) {
            try {
                $proxy = $this->fetchProxyConfig($proxyApi);
                return $this->doHttpJson($targetUrl, $payload, $headers, $proxy);
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < self::PROXY_RETRY_TIMES) {
                    usleep(self::PROXY_RETRY_DELAY_US);
                }
            }
        }

        if ($lastError instanceof \Throwable) {
            throw new Exception('Proxy request failed after ' . self::PROXY_RETRY_TIMES . ' attempts: ' . $lastError->getMessage());
        }
        throw new Exception('Proxy request failed');
    }

    private function doHttpJson(string $url, array $payload, array $headers, ?array $proxy = null): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new Exception('Unable to encode request payload');
        }

        if ($proxy === null) {
            $response = \get_curl($url, $body, 0, 0, 0, 0, 0, $headers);
        } else {
            $response = $this->postJsonViaCurlWithProxy($url, $body, $headers, $proxy);
        }

        if (!$response) {
            throw new Exception('HTTP request failed');
        }
        $decoded = $this->decodeJsonResponse((string)$response);
        if (!is_array($decoded)) {
            throw new Exception('Unable to parse JSON response');
        }
        return $decoded;
    }

    private function queryOrderOnce(string $identifier, string $fieldName): array
    {
        $channel = $this->channel;
        $requestBody = [
            'reqTime' => date('YmdHis'),
            'version' => '1.0',
            'reqData' => [
                'channelId' => self::DEFAULT_CHANNEL_ID,
                $fieldName => $identifier,
                'merchantNo' => (string)($channel['appkey'] ?? ''),
            ],
        ];
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Content-Type: application/json;charset=utf-8',
        ];
        $clientIp = trim((string)($channel['appsecret'] ?? ''));
        if ($clientIp !== '' && !$this->isHttpUrl($clientIp)) {
            $headers[] = 'X-FORWARDED-FOR: ' . $clientIp;
            $headers[] = 'CLIENT-IP: ' . $clientIp;
        }

        $response = $this->requestWithFallback(
            self::QUERY_API_URL,
            $requestBody,
            $headers,
            'query'
        );

        $status = $this->extractValue($response, ['orderStatus', 'order_status', 'tradeStatus', 'status']);
        $status = $status === null && isset($response['respData']) && is_array($response['respData'])
            ? $this->extractValue($response['respData'], ['orderStatus', 'order_status', 'tradeStatus', 'status'])
            : $status;

        $sysTradeNo = $this->extractValue($response, ['sysTradeNo', 'sys_trade_no', 'tradeNo', 'trade_no', 'orderNo', 'outOrderNo']);
        if ($sysTradeNo === null && isset($response['respData']) && is_array($response['respData'])) {
            $sysTradeNo = $this->extractValue($response['respData'], ['sysTradeNo', 'sys_trade_no', 'tradeNo', 'trade_no', 'orderNo', 'outOrderNo']);
        }
        $payOrderNo = $this->extractPayOrderNo('', $response);
        if ($payOrderNo === '' && isset($response['respData']) && is_array($response['respData'])) {
            $payOrderNo = $this->extractPayOrderNo('', $response['respData']);
        }
        $channelTradeNo = $this->extractValue($response, ['channelTradeNo', 'channel_trade_no']);
        if ($channelTradeNo === null && isset($response['respData']) && is_array($response['respData'])) {
            $channelTradeNo = $this->extractValue($response['respData'], ['channelTradeNo', 'channel_trade_no']);
        }
        $buyer = $this->extractValue($response, ['userId2', 'buyer', 'buyer_id', 'buyerId']);
        if ($buyer === null && isset($response['respData']) && is_array($response['respData'])) {
            $buyer = $this->extractValue($response['respData'], ['userId2', 'buyer', 'buyer_id', 'buyerId']);
        }
        $billTradeNo = $this->extractValue($response, ['tradeNo', 'billTradeNo', 'bill_trade_no']);
        if ($billTradeNo === null && isset($response['respData']) && is_array($response['respData'])) {
            $billTradeNo = $this->extractValue($response['respData'], ['tradeNo', 'billTradeNo', 'bill_trade_no']);
        }
        $billMchTradeNo = $this->extractValue($response, ['accTradeNo', 'billMchTradeNo', 'bill_mch_trade_no']);
        if ($billMchTradeNo === null && isset($response['respData']) && is_array($response['respData'])) {
            $billMchTradeNo = $this->extractValue($response['respData'], ['accTradeNo', 'billMchTradeNo', 'bill_mch_trade_no']);
        }

        return [
            'raw' => $response,
            'queryField' => $fieldName,
            'orderStatus' => $status,
            'sysTradeNo' => $sysTradeNo,
            'payOrderNo' => $payOrderNo,
            'channelTradeNo' => $channelTradeNo,
            'buyer' => $buyer,
            'billTradeNo' => $billTradeNo,
            'billMchTradeNo' => $billMchTradeNo,
        ];
    }

    private function decodeJsonResponse(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/^[\w$]+\((.*)\)\s*;?\s*$/s', $text, $m)) {
            $decoded = json_decode(trim((string)$m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private function getRemoteApiUrl(array $channel): string
    {
        foreach (['remote_api', 'remoteApi', 'remoteapi', 'request_api', 'requestApi', 'apiurl', 'api_url'] as $key) {
            $value = trim((string)($channel[$key] ?? ''));
            if ($this->isHttpUrl($value)) {
                return $value;
            }
        }
        return '';
    }

    private function getProxyApiUrl(array $channel): string
    {
        foreach (['proxy_api', 'proxyApi', 'proxyapi', 'proxy_url', 'proxyUrl', 'proxyip_api', 'proxy_ip_api'] as $key) {
            $value = trim((string)($channel[$key] ?? ''));
            if ($this->isHttpUrl($value)) {
                return $value;
            }
        }
        return '';
    }

    private function isHttpUrl(string $url): bool
    {
        return (bool)preg_match('/^https?:\/\/.+/i', trim($url));
    }

    private function fetchProxyConfig(string $proxyApi): array
    {
        $raw = $this->httpGetText($proxyApi);
        $decoded = $this->decodeJsonResponse($raw);

        if (is_array($decoded)) {
            $code = $decoded['code'] ?? null;
            if ($code !== null) {
                $codeText = strtoupper(trim((string)$code));
                if (!in_array($codeText, ['0', '200', 'SUCCESS', 'TRUE', 'OK'], true)) {
                    $msg = trim((string)($decoded['msg'] ?? $decoded['message'] ?? 'Proxy API returned failure'));
                    throw new Exception($msg === '' ? 'Proxy API returned failure' : $msg);
                }
            }

            $proxyNode = $this->findProxyNode($decoded);
            if ($proxyNode !== null) {
                return $proxyNode;
            }
        }

        if (preg_match('/(\d{1,3}(?:\.\d{1,3}){3})\s*[:]\s*(\d{2,5})/', $raw, $m)) {
            return [
                'host' => trim((string)$m[1]),
                'port' => (int)$m[2],
            ];
        }

        throw new Exception('Proxy API response does not contain available proxy');
    }

    private function findProxyNode(array $data): ?array
    {
        $candidates = [];
        $obj = $data['obj'] ?? null;
        $rows = $data['rows'] ?? null;
        $list = $data['list'] ?? null;
        $result = $data['result'] ?? null;
        $innerData = $data['data'] ?? null;

        foreach ([$obj, $rows, $list, $result, $innerData] as $candidate) {
            if (is_array($candidate)) {
                $candidates[] = $candidate;
                if (isset($candidate[0]) && is_array($candidate[0])) {
                    $candidates[] = $candidate[0];
                }
            }
        }

        $candidates[] = $data;

        foreach ($candidates as $item) {
            $ip = $this->extractValue($item, ['ip', 'proxyIp', 'proxy_ip', 'host', 'proxyHost', 'proxy_host']);
            $port = $this->extractValue($item, ['port', 'proxyPort', 'proxy_port']);
            if ($ip === null || trim((string)$ip) === '') {
                continue;
            }
            $portInt = (int)$port;
            if ($portInt <= 0) {
                continue;
            }

            $username = trim((string)($this->extractValue($item, ['username', 'userName', 'user', 'account', 'proxyUser', 'proxy_user']) ?? ''));
            $password = trim((string)($this->extractValue($item, ['password', 'pass', 'passwd', 'pwd', 'proxyPass', 'proxy_pass']) ?? ''));

            return [
                'host' => trim((string)$ip),
                'port' => $portInt,
                'username' => $username,
                'password' => $password,
            ];
        }

        return null;
    }

    private function httpGetText(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new Exception('cURL extension not installed');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::PROXY_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::PROXY_TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json,text/plain,*/*',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Proxy API request failed: ' . $err);
        }
        curl_close($ch);

        return (string)$raw;
    }

    private function postJsonViaCurlWithProxy(string $url, string $body, array $headers, array $proxy): string
    {
        if (!function_exists('curl_init')) {
            throw new Exception('cURL extension not installed');
        }

        $host = trim((string)($proxy['host'] ?? ''));
        $port = (int)($proxy['port'] ?? 0);
        if ($host === '' || $port <= 0) {
            throw new Exception('Invalid proxy endpoint');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::PROXY_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::PROXY_TIMEOUT);
        curl_setopt($ch, CURLOPT_PROXY, $host);
        curl_setopt($ch, CURLOPT_PROXYPORT, $port);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);

        $username = trim((string)($proxy['username'] ?? ''));
        $password = trim((string)($proxy['password'] ?? ''));
        if ($username !== '') {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username . ':' . $password);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Proxy request failed: ' . $err);
        }
        curl_close($ch);

        return (string)$raw;
    }

    private function mergeOrderExtMeta(string $tradeNo, array $meta): void
    {
        try {
            $order = OrderService::findByTradeNoOrNull($tradeNo);
            if (!$order) {
                return;
            }

            $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
            $legacyExt = $notifyPayload['legacy_ext'] ?? ($order->legacy_ext ?? $order->ext ?? []);
            $current = $this->readOrderExtMeta($legacyExt);
            $this->updateOrderExt($tradeNo, [
                'jiaofeiyi' => array_merge($current, $meta),
            ]);
        } catch (\Throwable $e) {
            // Ignore ext update failure, do not block pay flow.
        }
    }

    private function readOrderExtMeta(mixed $ext): array
    {
        if (is_array($ext)) {
            $meta = $ext['jiaofeiyi'] ?? $ext;
            if (!is_array($meta)) {
                return [];
            }

            $result = [];
            foreach ([
                'sysTradeNo',
                'payOrderNo',
                'channelTradeNo',
                'payUrl',
                'updatedAt',
                'buyer',
                'billTradeNo',
                'billMchTradeNo',
            ] as $key) {
                if (!array_key_exists($key, $meta) || is_array($meta[$key]) || is_object($meta[$key])) {
                    continue;
                }

                $result[$key] = trim((string)$meta[$key]);
            }

            return $result;
        }

        $ext = trim((string)$ext);
        if ($ext === '' || $ext === 'Array') {
            return [];
        }

        $decoded = @unserialize($ext);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->readOrderExtMeta($decoded);
    }

    private function normalizeGatewayQueryResult(array $order, array $result, string $fallbackTradeNo): array
    {
        $status = $result['orderStatus'] ?? null;
        $normalizedStatus = $this->isPaidStatus($status)
            ? 1
            : ($this->isUnpaidStatus($status) ? 0 : 2);

        $apiTradeNo = trim((string)($result['sysTradeNo'] ?? $result['payOrderNo'] ?? $result['channelTradeNo'] ?? $fallbackTradeNo));
        $billTradeNo = trim((string)($result['billTradeNo'] ?? ''));
        $billMchTradeNo = trim((string)($result['billMchTradeNo'] ?? $result['payOrderNo'] ?? $result['channelTradeNo'] ?? ''));

        return [
            'api_trade_no' => $apiTradeNo,
            'status' => $normalizedStatus,
            'money' => number_format((float)($order['realmoney'] ?? $order['money'] ?? 0), 2, '.', ''),
            'buyer' => trim((string)($result['buyer'] ?? '')),
            'bill_trade_no' => $billTradeNo,
            'bill_mch_trade_no' => $billMchTradeNo,
            'endtime' => $this->normalizeQueryPaidAt($result),
            'raw' => $result['raw'] ?? [],
        ];
    }

    private function normalizeQueryPaidAt(array $result): string
    {
        $value = trim((string)(
            $result['paidAt']
            ?? $result['endTime']
            ?? $result['payTime']
            ?? $result['finishTime']
            ?? ($result['raw']['payTime'] ?? $result['raw']['endTime'] ?? '')
        ));

        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : $value;
    }

    private function loadCachedGatewayMeta(string $tradeNo): array
    {
        $order = OrderService::findByTradeNoOrNull($tradeNo);
        if (!$order) {
            return [];
        }

        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $legacyExt = $notifyPayload['legacy_ext'] ?? ($order->legacy_ext ?? $order->ext ?? []);
        $meta = $this->readOrderExtMeta($legacyExt);

        $payUrl = trim((string)($order->payurl ?? ''));
        if ($payUrl !== '') {
            $meta['payUrl'] = $payUrl;
        }

        $payOrderNo = trim((string)($order->bill_mch_trade_no ?? ''));
        if ($payOrderNo === '') {
            $payOrderNo = $this->extractPayOrderNo($payUrl);
        }
        if ($payOrderNo !== '') {
            $meta['payOrderNo'] = $payOrderNo;
        }

        $sysTradeNo = trim((string)($notifyPayload['api_trade_no'] ?? $order->api_trade_no ?? $order->txid ?? ''));
        if ($sysTradeNo !== '') {
            $meta['sysTradeNo'] = $sysTradeNo;
        }

        return $meta;
    }

    private function syncGatewayMetaToOrder(string $tradeNo, array $meta): void
    {
        $changes = [];

        $payUrl = trim((string)($meta['payUrl'] ?? ''));
        if ($payUrl !== '') {
            $changes['payurl'] = substr($payUrl, 0, 500);
        }

        $payOrderNo = trim((string)($meta['payOrderNo'] ?? ''));
        if ($payOrderNo !== '') {
            $changes['bill_mch_trade_no'] = $payOrderNo;
        }

        if ($changes !== []) {
            $this->saveOrderChanges($tradeNo, $changes);
        }

        $sysTradeNo = trim((string)($meta['sysTradeNo'] ?? ''));
        if ($sysTradeNo !== '') {
            $this->updateOrder($tradeNo, $sysTradeNo);
        }
    }

    private function recentPendingTradeNos(int $channelId): array
    {
        $sinceTs = time() - 300;
        $tradeNos = [];

        if (\database_available()) {
            try {
                foreach (\app\model\Order::where('merchant_channel_id', $channelId)->where('status', 0)->select()->all() as $order) {
                    $tradeNo = trim((string)($order->trade_no ?? ''));
                    if ($tradeNo === '') {
                        continue;
                    }

                    $createdAt = (string)($order->created_at ?? $order->addtime ?? '');
                    if (!$this->isRecentOrderTime($createdAt, $sinceTs)) {
                        continue;
                    }

                    $tradeNos[$tradeNo] = $tradeNo;
                }
            } catch (\Throwable) {
            }
        }

        foreach (LocalOrderStore::allOrders() as $order) {
            if ((int)($order->status ?? -1) !== 0) {
                continue;
            }

            if ((int)($order->merchant_channel_id ?? 0) !== $channelId) {
                continue;
            }

            $tradeNo = trim((string)($order->trade_no ?? ''));
            if ($tradeNo === '') {
                continue;
            }

            $createdAt = (string)($order->created_at ?? $order->addtime ?? '');
            if (!$this->isRecentOrderTime($createdAt, $sinceTs)) {
                continue;
            }

            $tradeNos[$tradeNo] = $tradeNo;
        }

        return array_values($tradeNos);
    }

    private function isRecentOrderTime(string $value, int $sinceTs): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return false;
        }

        return $timestamp >= $sinceTs;
    }

    private function saveOrderChanges(string $tradeNo, array $changes): void
    {
        $order = OrderService::findByTradeNoOrNull($tradeNo);
        if (!$order) {
            return;
        }

        OrderService::saveOrder($order, $changes);
    }

    private function extractPayOrderNo(?string $payUrl = null, ?array $response = null): string
    {
        if (is_array($response)) {
            $payOrderNo = $this->extractValue($response, ['payOrderNo', 'pay_order_no', 'channelTradeNo', 'channel_trade_no']);
            if ($payOrderNo !== null && trim((string)$payOrderNo) !== '') {
                return trim((string)$payOrderNo);
            }
        }

        $payUrl = trim((string)$payUrl);
        if ($payUrl === '') {
            return '';
        }
        $payUrl = html_entity_decode($payUrl, ENT_QUOTES);

        for ($i = 0; $i < 3; $i++) {
            $query = parse_url($payUrl, PHP_URL_QUERY);
            if (!empty($query)) {
                parse_str($query, $queryData);
                if (!empty($queryData['payOrderNo'])) {
                    return trim((string)$queryData['payOrderNo']);
                }
                if (!empty($queryData['pay_order_no'])) {
                    return trim((string)$queryData['pay_order_no']);
                }
                if (!empty($queryData['channelTradeNo'])) {
                    return trim((string)$queryData['channelTradeNo']);
                }
                if (!empty($queryData['channel_trade_no'])) {
                    return trim((string)$queryData['channel_trade_no']);
                }
            }

            if (preg_match('/(?:^|[?&])payOrderNo=([^&#]+)/i', $payUrl, $m)) {
                return trim(rawurldecode($m[1]));
            }
            if (preg_match('/(?:^|[?&])pay_order_no=([^&#]+)/i', $payUrl, $m)) {
                return trim(rawurldecode($m[1]));
            }
            if (preg_match('/(?:^|[?&])channelTradeNo=([^&#]+)/i', $payUrl, $m)) {
                return trim(rawurldecode($m[1]));
            }
            if (preg_match('/(?:^|[?&])channel_trade_no=([^&#]+)/i', $payUrl, $m)) {
                return trim(rawurldecode($m[1]));
            }

            $decoded = rawurldecode($payUrl);
            if ($decoded === $payUrl) {
                break;
            }
            $payUrl = $decoded;
        }
        return '';
    }

    private function extractValue(array $data, array $keys)
    {
        foreach ($keys as $key) {
            $value = $this->findValueRecursive($data, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function findValueRecursive($data, string $targetKey)
    {
        if (!is_array($data)) {
            return null;
        }
        foreach ($data as $k => $v) {
            if ((string)$k === $targetKey && !is_array($v)) {
                return $v;
            }
            if (is_array($v)) {
                $found = $this->findValueRecursive($v, $targetKey);
                if ($found !== null && $found !== '') {
                    return $found;
                }
            }
        }
        return null;
    }
}
