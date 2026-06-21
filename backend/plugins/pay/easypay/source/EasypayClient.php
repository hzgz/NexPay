<?php

declare(strict_types=1);

namespace plugins\payment\easypay;

/**
 * 易生易企通2.0
 * @see https://apifox.com/apidoc/shared/9758ecc8-2c38-4ec6-914f-b09be6f563bc
 */
class EasypayClient
{
    private string $gateway_url = 'https://phoenix.eycard.cn/yqt';
    private string $reqId;
    private string $reqType;
    private string $easypay_public_key;
    private string $mch_rsa_private_key;

    public string $request_body = '';
    public string $response_body = '';

    public function __construct(string $reqId, string $reqType, string $easypay_public_key, string $mch_rsa_private_key, bool $isTest = false)
    {
        $this->reqId = $reqId;
        $this->reqType = $reqType;
        $this->easypay_public_key = $easypay_public_key;
        $this->mch_rsa_private_key = $mch_rsa_private_key;
        if ($isTest) {
            $this->gateway_url = 'https://d-phoenix-gap.easypay.com.cn:24443/yqt';
        }
    }

    //发起API请求
    public function execute(string $path, array $data): array
    {
        $requrl = $this->gateway_url . $path;
        $params = [
            'reqBody' => $data,
            'reqHeader' => [
                'transTime' => date('YmdHis'),
                'reqId' => $this->reqId,
                'reqType' => $this->reqType,
            ],
        ];
        $params['reqSign'] = $this->generateSign($params['reqHeader'], $params['reqBody']);

        $body = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->request_body = $body;
        $response = get_curl($requrl, $body, 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new \Exception('接口请求失败');
        $this->response_body = $response;
        $result = json_decode($response, true);
        if (isset($result['rspHeader']['rspCode']) && $result['rspHeader']['rspCode'] == '000000') {
            if (!$this->verifySign($result['rspHeader'], $result['rspBody'], $result['rspSign'])) {
                //throw new \Exception('返回数据验签失败');
            }
            return $result['rspBody'];
        } elseif (isset($result['rspHeader']['rspInfo'])) {
            throw new \Exception('[' . $result['rspHeader']['rspCode'] . ']' . $result['rspHeader']['rspInfo']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //获取待签名字符串
    private function getSignContent(array $header, array $body): string
    {
        $sortHeader = $this->sortJSON($header);
        $sortBody = $this->sortJSON($body);
        $sortHeader = json_encode($sortHeader, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sortBody = json_encode($sortBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $sortHeader . strtoupper(md5($sortBody));
    }

    //请求参数签名
    private function generateSign(array $header, array $body): string
    {
        return $this->rsaPrivateSign($this->getSignContent($header, $body));
    }

    //验签方法
    public function verifySign(array $header, array $body, string $sign): bool
    {
        if (empty($sign)) return false;
        return $this->rsaPubilcVerify($this->getSignContent($header, $body), $sign);
    }

    private function isJSONObject(mixed $value): bool
    {
        return is_array($value) && array_keys($value) !== range(0, count($value) - 1);
    }

    private function isJSONArray(mixed $value): bool
    {
        return is_array($value) && array_keys($value) === range(0, count($value) - 1);
    }

    private function sortArray(array $array): array
    {
        $sortedArray = [];
        foreach ($array as $item) {
            if (is_array($item)) {
                $sortedArray[] = $this->sortJSON($item);
            } else {
                $sortedArray[] = $item;
            }
        }
        return $sortedArray;
    }

    private function sortJSON(array $param): array
    {
        if ($this->isJSONArray($param)) {
            return $this->sortArray($param);
        }

        $keys = array_keys($param);
        sort($keys, SORT_STRING);

        $paramPair = [];
        foreach ($keys as $key) {
            if ($this->isJSONArray($param[$key])) {
                $paramPair[$key] = $this->sortArray($param[$key]);
            } elseif ($this->isJSONObject($param[$key])) {
                $paramPair[$key] = $this->sortJSON($param[$key]);
            } else {
                $paramPair[$key] = $param[$key];
            }
        }

        return $paramPair;
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $priKey = str_replace(["\n", "\r"], '', $this->mch_rsa_private_key);
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new \Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    //平台公钥验签
    private function rsaPubilcVerify(string $data, string $signature): bool
    {
        $pubKey = str_replace(["\n", "\r"], '', $this->easypay_public_key);
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new \Exception('验签失败，易生公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
