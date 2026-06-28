<?php

declare(strict_types=1);

namespace plugins\payment\xingyifuyhkpro;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class XingyifuyhkproPlugin extends BasePayment
{
    private const XM_DEFAULT_PROXY_TIMEOUT = 8;
    private const XM_REQUEST_TIMEOUT = 10;
    private const XM_CREATE_RECEIPT_TIMEOUT = 15;
    private const XM_CONNECT_TIMEOUT = 6;
    private const XM_PROXY_FETCH_RETRY = 3;
    private const XM_PROXY_RETRY_WAIT_US = 200000;
    private const XM_REQUEST_RETRY = 3;
    private ?array $runtimeProxyContext = null;
    private mixed $sharedCurlHandle = null;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
    }

    public function __destruct()
    {
        if ($this->sharedCurlHandle !== null) {
            curl_close($this->sharedCurlHandle);
            $this->sharedCurlHandle = null;
        }
    }

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . $ctx->order['trade_no'] . '/?type=' . $ctx->order['typename']];
    }

    public function mapi(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => request()->siteurl . 'pay/qrcode/' . $ctx->order['trade_no'] . '/?type=' . $ctx->order['typename']];
    }

    public function qrcode(PaymentContext $ctx): mixed
    {
        try {
            $data = self::lockPayData($ctx->order['trade_no'], function () use ($ctx) {
                return $this->createQrcode($ctx);
            });
            $codeUrl = $data['codeUrl'];
        } catch (\Throwable $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        $type = (string)request()->get('type');
        if ($type === 'alipay') {
            if ($ctx->mdevice === 'alipay') {
                $url = 'alipays://platformapi/startapp?saId=10000007&qrcode=' . urlencode($codeUrl);
                return ['type' => 'jump', 'url' => $url];
            }
            $expiresIn = (string)(strtotime($ctx->order['addtime']) + 360 - time());
            return view($this->payRoot . 'view/alipay_qrcode.html', [
                'code_url' => $codeUrl,
                'order' => $ctx->order,
                'expires_in' => $expiresIn,
            ]);
        }

        if ($type === 'wxpay') {
            if ($ctx->isMobile) {
                $query = urlencode('q=?codePlate=' . explode('?codePlate=', $codeUrl)[1]);
                $url = 'weixin://dl/business/?appid=wx7cd05626d476f4cc&path=pages/scene/index&query=' . $query;
                return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $url];
            }
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $codeUrl, 'expire' => strtotime($ctx->order['addtime']) + 360];
        }

        if ($type === 'bank') {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $codeUrl, 'expire' => strtotime($ctx->order['addtime']) + 360];
        }

        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function query(array $order): array
    {
        $startTime = date('Y-m-d H:i:s', strtotime($order['addtime']) - 60);
        $endTime = date('Y-m-d H:i:s', strtotime($order['addtime']) + 360);
        $params = [
            'pageNum' => 1,
            'pageSize' => 1000,
            'payState' => '1',
            'queryStartTime' => $startTime,
            'queryEndTime' => $endTime,
        ];

        $data = $this->request('POST', '/v1/payfly/pf2/mina/merch/order/page', $params);
        $orderList = $data['records'] ?? [];
        if (empty($orderList)) {
            throw new \Exception('时间范围内未查询到订单');
        }

        foreach ($orderList as $item) {
            if (!isset($item['payState']) || !isset($item['renPageName']) || (int)$item['payState'] !== 1) {
                continue;
            }

            $tradeNo = trim((string)$item['renPageName']);
            $money = isset($item['amount']) ? round($item['amount'] / 100, 2) : 0;
            if ($tradeNo === (string)$order['trade_no']) {
                return [
                    'api_trade_no' => $item['orderId'] ?? null,
                    'status' => 1,
                    'money' => $money,
                    'buyer' => $item['userOfficialId'] ?? null,
                    'bill_trade_no' => $item['edenOrderId'] ?? null,
                    'endtime' => $item['updateTime'] ?? null,
                ];
            }
        }

        throw new \Exception('时间范围内未查询到该订单');
    }

    public function refund(array $order): array
    {
        $params = [
            'orderId' => $order['api_trade_no'],
            'refundAmt' => (int)round($order['refundmoney'] * 100),
            'billDataIds' => [],
        ];

        try {
            $this->request('POST', '/v1/payfly/pf2/mina/merch/order/refund', $params);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0];
    }

    private function createQrcode(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $money = (int)round($ctx->order['realmoney'] * 100);

        $params = [
            'deptId' => '',
            'copInfos' => [[
                'childCops' => [[
                    'composite' => 1,
                    'copType' => '1',
                    'copLabel' => $tradeNo,
                    'copPrompt' => '',
                    'copDefaultValue' => $money,
                    'copValue' => $money,
                    'copValueType' => 1,
                    'copRequire' => 0,
                    'copQuery' => 0,
                    'copLikeQuery' => 1,
                    'copReadonly' => 0,
                    'copSize' => null,
                    'copAboutAmt' => 1,
                    'copResourceShared' => 0,
                    'copId' => '',
                    'copChecklist' => 1,
                    'copCustom' => (object)[],
                ]],
                'composite' => '2',
                'copType' => '105',
                'copHtmlClass' => null,
                'copLabel' => $tradeNo,
                'copPrompt' => '',
                'copDefaultValue' => $money,
                'copValue' => $money,
                'copValueType' => 1,
                'copRequire' => 0,
                'copQuery' => 0,
                'copLikeQuery' => 1,
                'copReadonly' => 0,
                'copSize' => null,
                'copAboutAmt' => 1,
                'copResourceShared' => 0,
                'copChecklist' => 1,
                'copCustom' => (object)[],
            ]],
            'renId' => '',
            'renTitle' => $tradeNo,
            'renEffectiveDate' => date('Y-m-d H:i:s'),
            'renExpirationDate' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
            'managerId' => '',
            'snNoList' => [],
            'useTemplate' => 0,
            'confirmTwice' => 0,
            'managerIdList' => [],
        ];

        try {
            $renId = $this->request('POST', '/v1/payfly/pf2/mina/merch/renovation/createReceipt', $params);
        } catch (\Throwable $ex) {
            throw new \Exception('创建收款单失败：' . $ex->getMessage());
        }

        try {
            $this->request('POST', '/v1/payfly/pf2/mina/merch/renovation/changeStatus', [
                'renId' => $renId,
                'status' => 2,
            ]);
        } catch (\Throwable $ex) {
            throw new \Exception('发布收款单失败：' . $ex->getMessage());
        }

        try {
            $data = $this->request('POST', '/v1/payfly/pf2/mina/merch/renovation/getReceipt?renId=' . $renId);
        } catch (\Throwable $ex) {
            throw new \Exception('查询收款单失败：' . $ex->getMessage());
        }

        if (empty($data['qrCodeEncodeNoMgrStr'])) {
            throw new \Exception('未返回有效收款链接');
        }

        return [
            'renId' => $data['renId'],
            'deptId' => $data['deptId'],
            'codeUrl' => $data['qrCodeEncodeNoMgrStr'],
        ];
    }

    public function getOrderList(): array
    {
        $params = [
            'pageNum' => 1,
            'pageSize' => 1000,
            'payState' => '1',
            'queryStartTime' => date('Y-m-d H:i:s', strtotime('-6 minutes')),
        ];
        $data = $this->request('POST', '/v1/payfly/pf2/mina/merch/order/page', $params);
        return $data['records'] ?? [];
    }

    public function closeOrder(int $renId): void
    {
        $this->request('POST', '/v1/payfly/pf2/mina/merch/renovation/changeStatus', [
            'renId' => $renId,
            'status' => 0,
        ]);
    }

    private function request(string $method, string $path, ?array $params = null): mixed
    {
        $url = 'https://yhk.postar.cn' . $path;
        $ua = 'Mozilla/5.0 (Linux; Android 15; NX769J Build/AQ3A.240812.002; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/142.0.7444.173 Mobile Safari/537.36 XWEB/1420283 MMWEBSDK/20250904 MMWEBID/1468 MicroMessenger/8.0.64.2940(0x28004050) WeChat/arm64 Weixin NetType/WIFI Language/zh_CN ABI/arm64 MiniProgramEnv/android';
        $referer = 'https://servicewechat.com/wxaceada211a82dcdd/34/page-frame.html';
        $headers = [
            'appsession: ' . $this->channel['appsession'],
            'client-type: C',
            'charset: utf-8',
            'Content-Type: application/json;charset=utf-8',
        ];

        $body = null;
        if ($params !== null) {
            $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $lastErr = null;
        for ($attempt = 1; $attempt <= self::XM_REQUEST_RETRY; $attempt++) {
            try {
                $proxy = $this->buildProxyContextFromApi();
                $timeout = $this->getRequestTimeoutByPath($path);
                $response = $this->curl($method, $url, $headers, $body, $ua, $referer, $timeout, $proxy);

                $result = $this->decodeJsonPayload($response);
                if (is_array($result) && isset($result['code']) && (int)$result['code'] === 200) {
                    return $result['data'];
                }
                if (is_array($result) && isset($result['msg'])) {
                    throw new \Exception((string)$result['msg']);
                }
                throw new \Exception($response);
            } catch (\Throwable $e) {
                $lastErr = $e;
                if ($attempt >= self::XM_REQUEST_RETRY || !$this->isRetryableNetworkError($e->getMessage())) {
                    throw $e;
                }
                // Network-level retry: drop cached proxy and fetch a fresh one.
                $this->runtimeProxyContext = null;
                usleep(self::XM_PROXY_RETRY_WAIT_US);
            }
        }

        throw new \Exception($lastErr ? $lastErr->getMessage() : 'request failed');
    }

    private function getRequestTimeoutByPath(string $path): int
    {
        if (strpos($path, '/v1/payfly/pf2/mina/merch/renovation/createReceipt') !== false) {
            return self::XM_CREATE_RECEIPT_TIMEOUT;
        }
        return self::XM_REQUEST_TIMEOUT;
    }

    private function curl(string $method, string $url, array $headers, mixed $body = null, mixed $ua = null, mixed $referer = null, int $timeout = self::XM_REQUEST_TIMEOUT, ?array $proxy = null): string
    {
        $ch = $this->getReusableCurlHandle();
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(self::XM_CONNECT_TIMEOUT, $timeout));
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, (string)$ua);
        curl_setopt($ch, CURLOPT_REFERER, (string)$referer);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if (!empty($proxy)) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, (int)$proxy['proxy_type']);
            curl_setopt($ch, CURLOPT_PROXY, $proxy['host'] . ':' . $proxy['port']);
            $proxyUser = trim((string)($proxy['user'] ?? ''));
            $proxyPass = trim((string)($proxy['pass'] ?? ''));
            if ($proxyUser !== '' || $proxyPass !== '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyUser . ':' . $proxyPass);
            }
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $data = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            throw new \Exception($errmsg, 0);
        }

        return is_string($data) ? $data : '';
    }

    private function getReusableCurlHandle(): mixed
    {
        if ($this->sharedCurlHandle === null) {
            $this->sharedCurlHandle = curl_init();
            return $this->sharedCurlHandle;
        }

        if (function_exists('curl_reset')) {
            curl_reset($this->sharedCurlHandle);
            return $this->sharedCurlHandle;
        }

        curl_close($this->sharedCurlHandle);
        $this->sharedCurlHandle = curl_init();
        return $this->sharedCurlHandle;
    }

    private function buildProxyContextFromApi(): ?array
    {
        $proxyApi = trim((string)($this->channel['proxy_api'] ?? ''));
        if ($proxyApi === '') {
            return null;
        }
        if ($this->runtimeProxyContext !== null) {
            return $this->runtimeProxyContext;
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= self::XM_PROXY_FETCH_RETRY; $attempt++) {
            try {
                $proxyType = $this->getXmProxyType();
                $response = $this->fetchProxyApiResponse($proxyApi);
                $payload = $this->decodeJsonPayload($response);

                if (is_array($payload)) {
                    $proxy = $this->extractProxyFromJsonPayload($payload, $proxyType, $response);
                    $this->runtimeProxyContext = $proxy;
                    return $proxy;
                }

                $proxy = $this->extractProxyFromTxtPayload($response, $proxyType);
                $this->runtimeProxyContext = $proxy;
                return $proxy;
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < self::XM_PROXY_FETCH_RETRY) {
                    usleep(self::XM_PROXY_RETRY_WAIT_US);
                }
            }
        }

        $msg = $lastError ? $lastError->getMessage() : 'unknown error';
        throw new \Exception('远程API提取代理失败，重试3次后仍失败：' . $msg);
    }

    private function fetchProxyApiResponse(string $proxyApi): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $proxyApi);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(self::XM_CONNECT_TIMEOUT, self::XM_DEFAULT_PROXY_TIMEOUT));
        curl_setopt($ch, CURLOPT_TIMEOUT, self::XM_DEFAULT_PROXY_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new \Exception('代理API请求失败：' . $errmsg);
        }
        curl_close($ch);

        $response = trim((string)$response);
        if ($response === '') {
            throw new \Exception('代理API返回为空');
        }

        if (stripos($response, 'ERROR(') === 0) {
            throw new \Exception('代理API返回错误：' . $response);
        }

        return $response;
    }

    private function extractProxyFromJsonPayload(array $payload, int $proxyType, string $raw): array
    {
        if (array_key_exists('success', $payload) && $payload['success'] === false) {
            $msg = (string)($payload['msg'] ?? $payload['message'] ?? 'unknown error');
            throw new \Exception('代理API返回错误：' . $msg);
        }

        if (isset($payload['code']) && !in_array((int)$payload['code'], [0, 1, 200], true)) {
            $msg = (string)($payload['msg'] ?? $payload['message'] ?? 'unknown error');
            throw new \Exception('代理API返回错误：' . $msg);
        }

        $candidates = [];
        $this->collectProxyCandidates($payload, $candidates);

        foreach ($candidates as $item) {
            $topUser = $this->pickString($payload, ['account', 'username', 'user', 'proxyUser', 'proxyAccount']);
            $topPass = $this->pickString($payload, ['password', 'pass', 'pwd', 'proxyPass']);

            $host = $this->pickString($item, ['ip', 'host', 'proxyIp', 'proxyIP', 'addr']);
            $portRaw = $this->pickString($item, ['port', 'proxyPort']);
            $user = $this->pickString($item, ['account', 'username', 'user', 'proxyUser', 'proxyAccount']);
            $pass = $this->pickString($item, ['password', 'pass', 'pwd', 'proxyPass']);

            if (($host === '' || $portRaw === '') && isset($item['server'])) {
                [$host2, $port2, $user2, $pass2] = $this->parseProxyServerAddress((string)$item['server']);
                if ($host === '') {
                    $host = $host2;
                }
                if ($portRaw === '') {
                    $portRaw = $port2;
                }
                if ($user === '') {
                    $user = $user2;
                }
                if ($pass === '') {
                    $pass = $pass2;
                }
            }

            if ($host === '' || $portRaw === '') {
                continue;
            }

            $port = (int)$portRaw;
            if ($port <= 0 || $port > 65535) {
                continue;
            }

            if ($user === '') {
                $user = $topUser;
            }
            if ($pass === '') {
                $pass = $topPass;
            }
            if ($user === '') {
                $user = $this->getProxyUserFallback();
            }
            if ($pass === '') {
                $pass = $this->getProxyPassFallback();
            }

            return [
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'pass' => $pass,
                'proxy_type' => $proxyType,
            ];
        }

        $stringCandidate = $this->findProxyServerString($payload);
        if ($stringCandidate !== '') {
            [$host, $portRaw, $user, $pass] = $this->parseProxyServerAddress($stringCandidate);
            if ($host !== '' && $portRaw !== '') {
                $port = (int)$portRaw;
                if ($port > 0 && $port <= 65535) {
                    if ($user === '') {
                        $user = $this->getProxyUserFallback();
                    }
                    if ($pass === '') {
                        $pass = $this->getProxyPassFallback();
                    }
                    return [
                        'host' => $host,
                        'port' => $port,
                        'user' => $user,
                        'pass' => $pass,
                        'proxy_type' => $proxyType,
                    ];
                }
            }
        }

        throw new \Exception('代理API未返回可用代理地址：' . $this->clipForError($raw));
    }

    private function extractProxyFromTxtPayload(string $response, int $proxyType): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $response) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$host, $portRaw, $user, $pass] = $this->parseProxyServerAddress($line);
            if ($host === '' || $portRaw === '') {
                continue;
            }

            $port = (int)$portRaw;
            if ($port <= 0 || $port > 65535) {
                continue;
            }

            if ($user === '') {
                $user = $this->getProxyUserFallback();
            }
            if ($pass === '') {
                $pass = $this->getProxyPassFallback();
            }

            return [
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'pass' => $pass,
                'proxy_type' => $proxyType,
            ];
        }

        throw new \Exception('代理API未返回可用代理地址：' . $this->clipForError($response));
    }

    private function collectProxyCandidates(mixed $node, array &$candidates): void
    {
        if (!is_array($node)) {
            return;
        }

        $hasHostLikeKey = isset($node['ip']) || isset($node['host']) || isset($node['server']) || isset($node['proxyIp']) || isset($node['proxyIP']);
        $hasPortLikeKey = isset($node['port']) || isset($node['proxyPort']) || isset($node['server']);
        if ($hasHostLikeKey && $hasPortLikeKey) {
            $candidates[] = $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectProxyCandidates($value, $candidates);
            }
        }
    }

    private function parseProxyServerAddress(string $server): array
    {
        $server = trim($server);
        if ($server === '') {
            return ['', '', '', ''];
        }

        $server = (string)preg_replace('#^[a-z0-9]+://#i', '', $server);
        $server = ltrim($server, '/');

        $user = '';
        $pass = '';
        if (strpos($server, '@') !== false) {
            [$auth, $server] = explode('@', $server, 2);
            if (strpos($auth, ':') !== false) {
                [$user, $pass] = explode(':', $auth, 2);
            } else {
                $user = $auth;
            }
        }

        if (str_contains($server, '/')) {
            $server = explode('/', $server, 2)[0];
        }

        if (!str_contains($server, ':')) {
            return ['', '', $user, $pass];
        }

        $pos = strrpos($server, ':');
        if ($pos === false) {
            return ['', '', $user, $pass];
        }

        $host = trim(substr($server, 0, $pos));
        $port = trim(substr($server, $pos + 1));
        return [$host, $port, $user, $pass];
    }

    private function findProxyServerString(array $payload): string
    {
        $keys = ['proxy', 'server', 'ipPort', 'ip_port', 'proxyAddr', 'proxy_addr'];
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $value = trim($payload[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach (['data', 'obj', 'result'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $value = trim($payload[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function pickString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key])) {
                $value = trim((string)$source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function getXmProxyType(): int
    {
        $value = trim((string)($this->channel['xm_proxy_type'] ?? '1'));
        return $value === '2' ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP;
    }

    private function getProxyUserFallback(): string
    {
        return trim((string)($this->channel['xm_proxy_user'] ?? ''));
    }

    private function getProxyPassFallback(): string
    {
        return trim((string)($this->channel['xm_proxy_pass'] ?? ''));
    }

    private function clipForError(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $max = 180;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($raw, 'UTF-8') > $max) {
                return mb_substr($raw, 0, $max, 'UTF-8') . '...';
            }
            return $raw;
        }

        if (strlen($raw) > $max) {
            return substr($raw, 0, $max) . '...';
        }

        return $raw;
    }

    private function decodeJsonPayload(string $raw): ?array
    {
        $flags = 0;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $decoded = json_decode($raw, true, 512, $flags);
        return is_array($decoded) ? $decoded : null;
    }

    private function isRetryableNetworkError(string $msg): bool
    {
        $m = strtolower($msg);
        $needles = [
            'operation timed out',
            'timed out',
            'connection timed out',
            'failed to connect',
            'could not resolve host',
            'empty reply from server',
            'proxy connect aborted',
            'recv failure',
            'connection reset by peer',
            'connection refused',
        ];
        foreach ($needles as $needle) {
            if (strpos($m, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
