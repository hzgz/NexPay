<?php

declare(strict_types=1);

namespace plugins\payment\cloudpay;

class AlipayEcoClient
{
    private string $gateway_url = 'https://cloudpaygw.alipay-eco.com/';

    //服务商应用ID
    private string $isv_app_id;

    //平台公钥
    private string $platform_public_key;

    //企业私钥
    private string $merchant_private_key;

    private string $charset = 'UTF-8';
    private string $signType = 'RSA2';

    public function __construct(string $isv_app_id, string $platform_public_key, string $merchant_private_key)
    {
        $this->isv_app_id = $isv_app_id;
        $this->platform_public_key = $platform_public_key;
        $this->merchant_private_key = $merchant_private_key;
    }

    //发起请求
    public function execute(string $service, array $bizContent): array
    {
        $params = [
            'service' => $service,
            'isv_app_id' => $this->isv_app_id,
            'request_id' => getSid(),
            'sign_type' => $this->signType,
            'charset' => $this->charset,
            'utc_timestamp' => time(),
            'version' => '1.0',
            'mock' => 'false',
        ];
        $params['biz_content'] = json_encode($bizContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $params['sign'] = $this->generateSign($params);
        $response = $this->curl($this->gateway_url, $params);
        $result = json_decode($response, true);
        if (isset($result['response'])) {
            if (isset($result['sign']) && !empty($result['sign'])) {
                /*if(!$this->verifyResponse($response, $result['sign'])){
                    throw new \Exception('对返回数据使用云支付公钥验签失败');
                }*/
            }
            if ($result['response']['code'] == '10000') {
                return $result['response'];
            } elseif (isset($result['response']['sub_code'])) {
                throw new \Exception('[' . $result['response']['sub_code'] . ']' . $result['response']['sub_msg']);
            } else {
                throw new \Exception($result['response']['msg']);
            }
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    private function generateSign(array $params): string
    {
        return $this->rsaPrivateSign($this->getSignContent($params));
    }

    private function getSignContent(array $params): string
    {
        ksort($params);
        $signstr = "";
        foreach ($params as $k => $v) {
            if ($this->isEmpty($v) || $k == 'sign' || $k == 'sign_type') continue;
            $signstr .= '&' . $k . '=' . $v;
        }
        $signstr = substr($signstr, 1);
        return $signstr;
    }

    //异步通知回调验签
    public function verify(array $params, string $sign): bool
    {
        if (!$params || empty($sign)) {
            return false;
        }
        try {
            return $this->rsaPubilcVerify($this->getSignContent($params), $sign);
        } catch (\Exception $ex) {
            return false;
        }
    }

    //验证返回内容签名
    private function verifyResponse(string $response, string $sign): bool
    {
        $signDataStartIndex = strpos($response, '"response":') + strlen('"response":');
        $signDataEndIndex = strrpos($response, ',"sign"');
        $indexLen = $signDataEndIndex - $signDataStartIndex;
        if ($indexLen < 1) return false;
        $signData = substr($response, $signDataStartIndex, $indexLen);

        $checkResult = $this->rsaPubilcVerify($signData, $sign);
        if (!$checkResult) {
            if (strpos($signData, '\/') > 0) {
                $signData = str_replace('\/', '/', $signData);
                $checkResult = $this->rsaPubilcVerify($signData, $sign);
            }
        }
        return $checkResult;
    }

    private function isEmpty($value): bool
    {
        return $value === null || $value === '';
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
            throw new \Exception('签名失败，企业私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($signature);
        return $signature;
    }

    //平台公钥验签
    private function rsaPubilcVerify(string $data, string $signature): bool
    {
        $pubKey = $this->platform_public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new \Exception('验签失败，云支付公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    private function curl(string $url, ?array $postFields = null): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if (is_array($postFields) && 0 < count($postFields)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        }
        $response = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new \Exception($errmsg, 0);
        }
        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpStatusCode != 200) {
            curl_close($ch);
            throw new \Exception($response, $httpStatusCode);
        }
        curl_close($ch);
        return $response;
    }
}
