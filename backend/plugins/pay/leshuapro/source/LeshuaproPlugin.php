<?php

declare(strict_types=1);

namespace plugins\payment\leshuapro;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;
use think\facade\Db;

class LeshuaproPlugin extends BasePayment
{
    private const DEFAULT_GATEWAY = 'https://paygate.leshuazf.com/cgi-bin/lepos_pay_gateway.cgi';

    private const PAY_URL_KEYS = [
        'jspay_url', 'pay_url', 'payUrl', 'code_url', 'codeUrl',
        'qrcode_url', 'qrcodeUrl', 'cashier_url', 'cashierUrl', 'url',
    ];

    private const QR_CODE_KEYS = [
        'td_code', 'qrcode', 'qr_code', 'qrCode', 'code',
    ];

    private const JSAPI_URL_KEYS = [
        'paymentUrl', 'payUrl', 'pay_url', 'url', 'cashierUrl', 'cashier_url',
        'codeUrl', 'code_url',
    ];

    private const PAID_STATUS_VALUES = ['2', '5'];

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $ctx->order['trade_no'] . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = trim((string)($ctx->order['typename'] ?? ''));
        if ($typename === '' || !method_exists($this, $typename)) {
            return ['type' => 'error', 'msg' => 'Unsupported pay type'];
        }
        return $this->$typename($ctx);
    }

    public function alipay(PaymentContext $ctx): array
    {
        try {
            return $this->createOrder($ctx, 'ZFBZF', $ctx->mdevice === 'alipay');
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => 'Leshua alipay order create failed: ' . $e->getMessage()];
        }
    }

    public function wxpay(PaymentContext $ctx): array
    {
        try {
            return $this->createOrder($ctx, 'WXZF', $ctx->mdevice === 'wechat');
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => 'Leshua wechat order create failed: ' . $e->getMessage()];
        }
    }

    public function notify(PaymentContext $ctx): array
    {
        try {
            $data = $this->parseNotifyData();
            if (empty($data)) {
                return ['type' => 'html', 'data' => 'FAIL'];
            }

            $status = strtoupper(trim((string)($data['status'] ?? $data['state'] ?? '')));
            $tradeNo = trim((string)($data['third_order_id'] ?? $data['merchant_order_no'] ?? $data['order_id'] ?? $data['out_trade_no'] ?? ''));
            $tradeNoExpect = (string)($ctx->order['trade_no'] ?? '');

            $notifyKey = trim((string)($this->channel['appsecret'] ?? ''));
            $tradeKey = trim((string)($this->channel['appkey'] ?? ''));
            if ($notifyKey === '' && $tradeKey === '') {
                return ['type' => 'html', 'data' => 'FAIL'];
            }

            $excludeFieldsPrimary = ['sign', 'leshua', 'error_code'];
            $excludeFieldsFallback = ['sign', 'leshua', 'error_code', 'sign_type'];
            $verifyMatched = false;
            $verifyCandidates = [];

            if ($notifyKey !== '') {
                $verifyCandidates[] = ['key_name' => 'appsecret', 'key_value' => $notifyKey];
            }
            if ($tradeKey !== '' && $tradeKey !== $notifyKey) {
                $verifyCandidates[] = ['key_name' => 'appkey', 'key_value' => $tradeKey];
            }

            foreach ($verifyCandidates as $candidate) {
                $kname = (string)$candidate['key_name'];
                $kvalue = (string)$candidate['key_value'];

                if ($this->verifySign($data, $kvalue, $excludeFieldsPrimary, true, false)) {
                    $verifyMatched = true;
                    break;
                }

                if ($this->verifySign($data, $kvalue, $excludeFieldsFallback, true, false)) {
                    $verifyMatched = true;
                    break;
                }
            }

            if (!$verifyMatched) {
                return ['type' => 'html', 'data' => 'FAIL'];
            }

            $isPaid = in_array($status, self::PAID_STATUS_VALUES, true);
            if (!$isPaid) {
                return ['type' => 'html', 'data' => 'FAIL'];
            }

            if ($tradeNo === '' || $tradeNo !== $tradeNoExpect) {
                return ['type' => 'html', 'data' => 'FAIL'];
            }

            $apiTradeNo = trim((string)($data['leshua_order_id'] ?? $data['transaction_id'] ?? ''));
            $billTradeNo = trim((string)($data['out_transaction_id'] ?? $data['channel_order_id'] ?? ''));
            $buyer = trim((string)($data['openid'] ?? $data['sub_openid'] ?? ''));
            $endTime = trim((string)($data['pay_time'] ?? $data['channel_datetime'] ?? ''));

            $this->processNotify(
                $ctx->order,
                $apiTradeNo !== '' ? $apiTradeNo : $tradeNo,
                $buyer !== '' ? $buyer : null,
                $billTradeNo !== '' ? $billTradeNo : null,
                $tradeNo,
                $endTime !== '' ? $endTime : null
            );

            return ['type' => 'html', 'data' => '000000'];
        } catch (\Throwable $e) {
            return ['type' => 'html', 'data' => 'FAIL'];
        }
    }

    public function queryOrder(string $tradeNo): array
    {
        $tradeNo = trim($tradeNo);
        if ($tradeNo === '') {
            throw new Exception('trade no cannot be empty');
        }

        $result = $this->queryOrderByField('third_order_id', $tradeNo);
        $respCode = trim((string)($result['resp_code'] ?? ''));
        if ($respCode !== '' && $respCode !== '0') {
            $result2 = $this->queryOrderByField('merchant_order_no', $tradeNo);
            $respCode2 = trim((string)($result2['resp_code'] ?? ''));
            if ($respCode2 !== '' && $respCode2 !== '0') {
                throw new Exception((string)($result2['error_msg'] ?? $result['error_msg'] ?? ('query failed, resp_code=' . $respCode2)));
            }
            $result = $result2;
        }

        if (!$this->verifySign($result, $this->getTradeKey(), ['sign', 'leshua', 'resp_code'], true, true)) {
            throw new Exception('query sign verify failed');
        }

        return $result;
    }

    private function queryOrderByField(string $field, string $tradeNo): array
    {
        $payload = [
            'service' => 'query_status',
            'merchant_id' => $this->getMerchantId(),
            $field => $tradeNo,
            'nonce_str' => $this->makeNonce(),
        ];
        $payload['sign'] = $this->buildSign($payload, $this->getTradeKey(), true, false);

        return $this->requestGateway($payload);
    }

    private function createOrder(PaymentContext $ctx, string $payWay, bool $isInApp): array
    {
        $tradeNo = (string)($ctx->order['trade_no'] ?? '');
        if ($tradeNo === '') {
            throw new Exception('trade no cannot be empty');
        }

        $amount = (float)($ctx->order['realmoney'] ?? 0);
        if ($amount <= 0) {
            throw new Exception('invalid amount');
        }

        $notifyUrl = rtrim((string)config_get('localurl'), '/') . '/pay/notify/' . $tradeNo . '/';
        $payload = [
            'service' => 'get_tdcode',
            'merchant_id' => $this->getMerchantId(),
            'third_order_id' => $tradeNo,
            'amount' => $this->formatAmount($amount),
            'nonce_str' => $this->makeNonce(),
            'pay_way' => $payWay,
            'order_expiration' => $this->getOrderExpiration(),
            'jspay_flag' => $isInApp ? '1' : '2',
            'notify_url' => $notifyUrl,
            'client_ip' => (string)request()->clientip,
        ];

        $appid = trim((string)($this->channel['appid'] ?? ''));
        if ($appid !== '') {
            $payload['appid'] = $appid;
        }

        if ($isInApp) {
            $openid = trim((string)($ctx->order['sub_openid'] ?? ''));
            if ($openid !== '') {
                $payload['sub_openid'] = $openid;
            }
        }

        $payload['sign'] = $this->buildSign($payload, $this->getTradeKey(), true, false);
        $result = $this->requestGateway($payload);

        $respCode = trim((string)($result['resp_code'] ?? ''));
        if ($respCode !== '' && $respCode !== '0') {
            throw new Exception((string)($result['error_msg'] ?? ('order create failed, resp_code=' . $respCode)));
        }

        if (!$this->verifySign($result, $this->getTradeKey(), ['sign', 'leshua', 'resp_code'], true, true)) {
            throw new Exception('order result sign verify failed');
        }

        $apiTradeNo = trim((string)($result['leshua_order_id'] ?? $result['transaction_id'] ?? ''));
        if ($apiTradeNo !== '') {
            $this->updateOrder($tradeNo, $apiTradeNo);
        }

        $payUrl = $this->extractPayUrl($result);
        if ($payUrl !== '') {
            Db::name('order')->where('trade_no', $tradeNo)->update(['payurl' => substr($payUrl, 0, 500)]);
        }

        if ($isInApp) {
            $jsapiInfo = $this->parseJsapiInfo($result['jspay_info'] ?? null);
            if (empty($jsapiInfo)) {
                throw new Exception('missing JSAPI parameters');
            }

            if ($payWay === 'ZFBZF') {
                $tradeNoValue = trim((string)($jsapiInfo['tradeNO'] ?? $jsapiInfo['trade_no'] ?? $jsapiInfo['tradeNo'] ?? ''));
                if ($tradeNoValue === '') {
                    $tradeNoValue = trim((string)($result['trade_no'] ?? $result['channel_order_id'] ?? $result['leshua_order_id'] ?? ''));
                }
                if ($tradeNoValue === '') {
                    throw new Exception('missing alipay tradeNO');
                }
                return ['type' => 'jsapi', 'data' => $tradeNoValue];
            }

            return ['type' => 'jsapi', 'data' => $jsapiInfo];
        }

        $codeValue = $this->extractQrcodeValue($result);
        if ($payUrl !== '') {
            if ($this->shouldPreferQrcode($payUrl)) {
                $page = $payWay === 'ZFBZF' ? 'alipay_qrcode' : 'wxpay_qrcode';
                return ['type' => 'qrcode', 'page' => $page, 'url' => $payUrl, 'expire' => strtotime((string)($ctx->order['addtime'] ?? '')) + 300];
            }
            return ['type' => 'jump', 'url' => $payUrl];
        }

        if ($codeValue === '') {
            throw new Exception('missing pay url or pay code');
        }

        $page = $payWay === 'ZFBZF' ? 'alipay_qrcode' : 'wxpay_qrcode';
        return ['type' => 'qrcode', 'page' => $page, 'url' => $codeValue, 'expire' => strtotime((string)($ctx->order['addtime'] ?? '')) + 300];
    }

    private function parseJsapiInfo($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $raw = $this->normalizeUrl($value);
        if ($raw === '') {
            return [];
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        parse_str($raw, $arr);
        if (is_array($arr) && !empty($arr)) {
            return $arr;
        }

        return [];
    }

    private function extractPayUrl(array $result): string
    {
        $candidates = [];

        foreach (self::PAY_URL_KEYS as $key) {
            $this->appendCandidate($candidates, $result[$key] ?? null);
            $this->appendCandidate($candidates, $this->findValueRecursive($result, $key));
        }

        $jsapiInfo = $this->parseJsapiInfo($result['jspay_info'] ?? null);
        foreach (self::JSAPI_URL_KEYS as $key) {
            $this->appendCandidate($candidates, $jsapiInfo[$key] ?? null);
            $this->appendCandidate($candidates, $this->findValueRecursive($jsapiInfo, $key));
        }

        foreach ($candidates as $candidate) {
            $url = $this->normalizeUrl($candidate);
            if ($this->isAllowedPayUrl($url)) {
                return $url;
            }
        }

        return '';
    }

    private function extractQrcodeValue(array $result): string
    {
        $candidates = [];

        foreach (self::QR_CODE_KEYS as $key) {
            $this->appendCandidate($candidates, $result[$key] ?? null);
            $this->appendCandidate($candidates, $this->findValueRecursive($result, $key));
        }

        foreach ($candidates as $candidate) {
            $value = $this->normalizeUrl($candidate);
            if ($value === '') {
                continue;
            }
            if (preg_match('/^(javascript|data):/i', $value)) {
                continue;
            }
            return $value;
        }

        return '';
    }

    private function shouldPreferQrcode(string $payUrl): bool
    {
        $url = strtolower(trim($payUrl));
        if ($url === '') {
            return false;
        }
        return str_contains($url, '/cgi-bin/qr/simple_pay.cgi');
    }

    private function appendCandidate(array &$candidates, $value): void
    {
        if (!is_scalar($value)) {
            return;
        }
        $v = trim((string)$value);
        if ($v === '' || in_array($v, $candidates, true)) {
            return;
        }
        $candidates[] = $v;
    }

    private function normalizeUrl($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $url = trim((string)$value);
        if ($url === '') {
            return '';
        }

        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = trim($url, " \t\n\r\0\x0B\"'");

        for ($i = 0; $i < 2; $i++) {
            $decoded = rawurldecode($url);
            if ($decoded === $url) {
                break;
            }
            $url = trim($decoded);
        }

        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $url);
        return is_string($cleaned) ? trim($cleaned) : trim($url);
    }

    private function isAllowedPayUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (preg_match('/^(javascript|data):/i', $url)) {
            return false;
        }
        if (preg_match('/^(https?:\/\/|\/\/)/i', $url)) {
            return true;
        }
        return (bool)preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $url);
    }

    private function findValueRecursive(array $data, string $targetKey)
    {
        foreach ($data as $k => $v) {
            if ((string)$k === $targetKey && !is_array($v) && !is_object($v)) {
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

    private function requestGateway(array $payload): array
    {
        $gateway = trim((string)($this->channel['gateway'] ?? ''));
        if ($gateway === '') {
            $gateway = self::DEFAULT_GATEWAY;
        }

        $postBody = http_build_query($payload);
        $response = get_curl($gateway, $postBody);
        if (!$response) {
            throw new Exception('request leshua gateway failed');
        }

        $result = $this->parseResponseData((string)$response);
        if (empty($result)) {
            throw new Exception('parse leshua gateway response failed');
        }

        return $result;
    }

    private function parseResponseData(string $response): array
    {
        $response = trim($response);
        if ($response === '') {
            return [];
        }

        if (str_contains($response, '<')) {
            $xml = @simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml !== false) {
                $json = json_encode($xml, JSON_UNESCAPED_UNICODE);
                $arr = json_decode((string)$json, true);
                if (is_array($arr)) {
                    return $this->flattenRootNode($arr);
                }
            }
        }

        $jsonArr = json_decode($response, true);
        if (is_array($jsonArr)) {
            return $this->flattenRootNode($jsonArr);
        }

        parse_str($response, $queryArr);
        if (is_array($queryArr)) {
            return $this->flattenRootNode($queryArr);
        }

        return [];
    }

    private function parseNotifyData(): array
    {
        $post = request()->post();
        if (is_array($post) && !empty($post)) {
            return $this->flattenRootNode($post);
        }

        $raw = $this->getRawNotifyBody();
        if ($raw === '') {
            return [];
        }

        $parsed = $this->parseResponseData($raw);
        return is_array($parsed) ? $parsed : [];
    }

    private function flattenRootNode(array $data): array
    {
        if (isset($data['leshua']) && is_array($data['leshua'])) {
            return $data['leshua'];
        }
        return $data;
    }

    private function getRawNotifyBody(): string
    {
        $raw = trim((string)file_get_contents('php://input'));
        if ($raw === '') {
            $raw = trim((string)request()->getContent());
        }
        return $raw;
    }

    private function verifySign(array $data, string $key, array $excludeFields, bool $includeEmptyValues, bool $uppercase): bool
    {
        $provided = trim((string)($data['sign'] ?? ''));
        if ($provided === '') {
            return false;
        }

        $expected = $this->buildSign($data, $key, $includeEmptyValues, $uppercase, $excludeFields);
        if ($uppercase) {
            return strtoupper($provided) === strtoupper($expected);
        }
        return strtolower($provided) === strtolower($expected);
    }

    private function buildSign(
        array $data,
        string $key,
        bool $includeEmptyValues,
        bool $uppercase,
        array $excludeFields = ['sign']
    ): string {
        $pairs = [];
        ksort($data);

        foreach ($data as $k => $v) {
            $name = (string)$k;
            if ($name === '' || in_array($name, $excludeFields, true)) {
                continue;
            }
            if (is_array($v)) {
                if (empty($v)) {
                    $value = '';
                } else {
                    continue;
                }
            } elseif (is_object($v)) {
                $arr = (array)$v;
                if (empty($arr)) {
                    $value = '';
                } else {
                    continue;
                }
            } else {
                $value = trim((string)$v);
            }
            if (!$includeEmptyValues && $value === '') {
                continue;
            }
            $pairs[] = $name . '=' . $value;
        }

        $stringA = implode('&', $pairs);
        $stringSignTemp = $stringA . '&key=' . $key;
        $md5 = md5($stringSignTemp);
        return $uppercase ? strtoupper($md5) : strtolower($md5);
    }

    private function getMerchantId(): string
    {
        $merchantId = trim((string)($this->channel['appurl'] ?? ''));
        if ($merchantId === '') {
            throw new Exception('merchant id cannot be empty');
        }
        return $merchantId;
    }

    private function getTradeKey(): string
    {
        $key = trim((string)($this->channel['appkey'] ?? ''));
        if ($key === '') {
            throw new Exception('trade sign key cannot be empty');
        }
        return $key;
    }

    private function getOrderExpiration(): string
    {
        $expire = trim((string)($this->channel['appmchid'] ?? ''));
        if ($expire === '' || !preg_match('/^\d+$/', $expire)) {
            $expire = '300';
        }
        return $expire;
    }

    private function makeNonce(): string
    {
        return strtolower(md5(uniqid((string)mt_rand(), true)));
    }

    private function formatAmount(float $amount): string
    {
        $cents = (int)round($amount * 100);
        if ($cents <= 0) {
            throw new Exception('invalid amount');
        }
        return (string)$cents;
    }
}
