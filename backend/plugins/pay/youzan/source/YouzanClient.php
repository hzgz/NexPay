<?php

declare(strict_types=1);

namespace plugins\payment\youzan;

use Exception;

class YouzanClient
{
    //接口地址
    private string $gateway_url = 'https://open.gaohuitong.com/api/auth_exempt/';

    //渠道来源
    private string $merchant_id;

    //商户私钥
    private string $merchant_private_key;

    //平台公钥
    private string $platform_public_key;

    //公钥唯一标识
    private string $public_key_id;

    private string $sign_type = 'SHA256withRSA';

    public function __construct(string $merchant_id, string $merchant_private_key, string $platform_public_key, string $public_key_id)
    {
        $this->merchant_id = $merchant_id;
        $this->merchant_private_key = $merchant_private_key;
        $this->platform_public_key = $platform_public_key;
        $this->public_key_id = $public_key_id;
    }

    //请求API接口并解析返回数据
    public function execute(string $service, array $params): array
    {
        $requrl = $this->gateway_url . $service;
        $timestamp = time();
        $noncestr = md5(uniqid((string)mt_rand(), true));
        $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sign = $this->generateSign($service, $timestamp, $noncestr, $body);
        $headers = [
            'signtype: ' . $this->sign_type,
            'publickeyid: ' . $this->public_key_id,
            'timestamp: ' . $timestamp,
            'noncestr: ' . $noncestr,
            'sign: ' . $sign,
            'mchid: ' . $this->merchant_id,
            'Content-Type: application/json; charset=utf-8',
        ];
        [$respHeader, $respBody] = $this->curl($requrl, $body, $headers);
        $result = json_decode($respBody, true);
        if (isset($result['success']) && $result['success'] === true) {
            if (!$this->verifyResponse($respHeader, $respBody)) {
                throw new Exception('返回数据验签失败');
            }
            return $result['data'];
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //请求参数签名
    private function generateSign(string $service, int $timestamp, string $noncestr, string $body): string
    {
        $arr = explode('/', $service);
        $service = substr_replace($arr[0], '_', strrpos($arr[0], '.'), 1) . '_' . $arr[1];
        $signstr = $service . "\n" . $timestamp . "\n" . $noncestr . "\n" . $body . "\n" . $this->merchant_id . "\n";
        return $this->rsaPrivateSign($signstr);
    }

    //验签方法
    private function verifySign(string $timestamp, string $noncestr, string $body, string $sign): bool
    {
        $signstr = $body . "\n" . $timestamp . "\n" . $noncestr . "\n";
        return $this->rsaPublicVerify($signstr, $sign);
    }

    //验证异步通知签名
    public function verifyNotify(string $data): bool
    {
        $sign = request()->header('youzan-sign');
        $timestamp = request()->header('youzan-timestamp');
        $nonceStr = request()->header('youzan-noncestr');
        if (empty($sign) || empty($nonceStr) || empty($timestamp)) return false;
        return $this->verifySign($timestamp, $nonceStr, $data, $sign);
    }

    //验证返回数据签名
    public function verifyResponse(string $respHeader, string $respBody): bool
    {
        $timestamp = '';
        $nonceStr = '';
        $sign = '';
        if (preg_match('/youzan-timestamp: (.*?)\r\n/i', $respHeader, $match)) {
            $timestamp = trim($match[1]);
        }
        if (preg_match('/youzan-noncestr: (.*?)\r\n/i', $respHeader, $match)) {
            $nonceStr = trim($match[1]);
        }
        if (preg_match('/youzan-sign: (.*?)\r\n/i', $respHeader, $match)) {
            $sign = trim($match[1]);
        }
        if (empty($sign) || empty($nonceStr) || empty($timestamp)) return false;
        return $this->verifySign($timestamp, $nonceStr, $respBody, $sign);
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $priKey = $this->merchant_private_key;
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($signature);
        return $signature;
    }

    //平台公钥验签
    private function rsaPublicVerify(string $data, string $signature): bool
    {
        $pubKey = $this->platform_public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new Exception('验签失败，平台公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function curl(string $url, string $body, array $header, int $timeout = 10): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($body) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $data = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new Exception($errmsg);
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($data, 0, $headerSize);
        $body = substr($data, $headerSize);
        curl_close($ch);
        return [$header, $body];
    }
}
