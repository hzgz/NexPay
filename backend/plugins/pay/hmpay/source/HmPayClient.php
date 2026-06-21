<?php

declare(strict_types=1);

namespace plugins\payment\hmpay;

/**
 * @see https://loy5f5.yuque.com/pvzkzf/prv188
 */
class HmPayClient
{
    public string $appId;
    public string $subAppId;
    public string $rsaPrivateKey;
    public string $platRsaPublicKey;
    public string $charset = "UTF-8";
    public string $signType = "RSA";
    public string $format = "JSON";
    public string $apiVersion = "1.0";
    public string $gatewayUrl = "https://hmpay.sandpay.com.cn/gateway/api";

    public function __construct(array $config)
    {
        $this->gatewayUrl = $config['gatewayUrl'];
        $this->appId = $config['appId'];
        $this->signType = $config['signType'];
        $this->rsaPrivateKey = $config['rsaPrivateKey'];
        $this->platRsaPublicKey = $config['platRsaPublicKey'];
        $this->charset = $config['charset'];
        $this->subAppId = $config['subAppId'];

        if (empty($this->appId) || trim($this->appId) === "") {
            throw new \Exception("appId should not be NULL!");
        }
        if (empty($this->rsaPrivateKey) || trim($this->rsaPrivateKey) === "") {
            throw new \Exception("rsaPrivateKey should not be NULL!");
        }
        if (empty($this->platRsaPublicKey) || trim($this->platRsaPublicKey) === "") {
            throw new \Exception("platRsaPublicKey should not be NULL!");
        }
    }

    public function execute(string $request): array
    {
        $response = $this->curl($this->gatewayUrl, $request);
        $jsonResp = json_decode($response, true);
        if (!$jsonResp || !isset($jsonResp['code'])) throw new \Exception("返回内容解析失败！");
        if ($jsonResp['code'] != 200) throw new \Exception("[" . $jsonResp['code'] . "]" . $jsonResp['msg']);
        $this->checkResponseSign($jsonResp);
        $data = json_decode($jsonResp['data'], true) ?? [];
        return $data;
    }

    public function request(string $method, array $bizContent): string
    {
        $tradeRequest = [];
        $tradeRequest['app_id'] = $this->appId;
        $tradeRequest['sub_app_id'] = $this->subAppId;
        $tradeRequest['method'] = $method;
        $tradeRequest['format'] = $this->format;
        $tradeRequest['charset'] = $this->charset;
        $tradeRequest['sign_type'] = $this->signType;
        $tradeRequest['timestamp'] = date("Y-m-d H:i:s");
        $tradeRequest['nonce'] = random(8);
        $tradeRequest['version'] = $this->apiVersion;
        $tradeRequest['biz_content'] = json_encode($bizContent, JSON_UNESCAPED_UNICODE);

        $tradeRequest['sign'] = $this->rsaSign($tradeRequest, $this->rsaPrivateKey, $this->signType);
        $request = json_encode($tradeRequest, JSON_UNESCAPED_UNICODE);
        return $request;
    }

    private function checkResponseSign(array $respObject): void
    {
        if (!empty($respObject)) {
            if (isset($respObject['code']) && $respObject['code'] == 200 && !empty($respObject['sign'])) {
                $checkResult = $this->rsaCheckV2($respObject);
                if (!$checkResult) {
                    throw new \Exception("返回内容验签失败！");
                }
            }
        }
    }

    private function rsaSign(array $params, string $privateKey, string $signType = "RSA"): string
    {
        return $this->sign($this->getSignContent($params), $privateKey, $signType);
    }

    private function getSignContent(array $params): string
    {
        ksort($params);

        $stringToBeSigned = "";
        $i = 0;
        foreach ($params as $k => $v) {
            if (false === $this->checkEmpty($v) && "@" != substr($v, 0, 1)) {
                if ($i == 0) {
                    $stringToBeSigned .= "$k" . "=" . "$v";
                } else {
                    $stringToBeSigned .= "&" . "$k" . "=" . "$v";
                }
                $i++;
            }
        }

        unset($k, $v);
        return $stringToBeSigned;
    }

    private function sign(string $data, string $privateKey, string $signType = "RSA"): string
    {
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($privateKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";

        if ("RSA2" == $signType) {
            openssl_sign($data, $sign, $res, OPENSSL_ALGO_SHA256);
        } else {
            openssl_sign($data, $sign, $res);
        }

        $sign = base64_encode($sign);
        return $sign;
    }

    private function checkEmpty($value): bool
    {
        if (!isset($value))
            return true;
        if ($value === null)
            return true;
        if (trim($value) === "")
            return true;

        return false;
    }

    /**
     * 验证签名
     * sign_type参与签名
     */
    public function rsaCheckV2(array $params): bool
    {
        $sign = $params['sign'];

        unset($params['sign']);
        return $this->verify($this->getCheckSignContent($params), $sign, $this->platRsaPublicKey, $this->signType);
    }

    private function getCheckSignContent(array $params): string
    {
        ksort($params);

        $stringToBeSigned = "";
        $i = 0;
        foreach ($params as $k => $v) {
            if ($i == 0) {
                $stringToBeSigned .= "$k" . "=" . "$v";
            } else {
                $stringToBeSigned .= "&" . "$k" . "=" . "$v";
            }
            $i++;
        }

        unset($k, $v);
        return $stringToBeSigned;
    }

    private function verify(string $data, string $sign, string $rsaPublicKey, string $signType = 'RSA'): bool
    {
        $pubKey = $rsaPublicKey;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";

        $result = false;
        if ("RSA2" == $signType) {
            $result = (openssl_verify($data, base64_decode($sign), $res, OPENSSL_ALGO_SHA256) === 1);
        } else {
            $result = (openssl_verify($data, base64_decode($sign), $res) === 1);
        }

        return $result;
    }

    private function curl(string $url, string $data, int $timeout = 30): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $headers = ['content-type: application/json', 'Request-Trace-Id:' . random(9)];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $reponse = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch), 0);
        } else {
            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (200 !== $httpStatusCode) {
                throw new \Exception($reponse, $httpStatusCode);
            }
        }
        curl_close($ch);
        return $reponse;
    }
}
