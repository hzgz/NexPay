<?php

declare(strict_types=1);

namespace plugins\payment\easypay2;

/**
 * @see https://newbox.bhecard.com/inf3/index.htm
 */
class EasypayClient
{
    private string $gateway_url = 'https://newpay.bhecard.com/api_gateway.do';
    private string $partner;
    private string $easypay_public_key;
    private string $mch_private_key;
    private string $charset = 'UTF-8';
    private string $sign_type = 'RSA';

    public function __construct(string $partner, string $easypay_public_key, string $mch_private_key)
    {
        $this->partner = $partner;
        $this->easypay_public_key = $easypay_public_key;
        $this->mch_private_key = $mch_private_key;
    }

    //发起API请求
    public function execute(string $service, array $data): array
    {
        $biz_content = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $params = [
            'service' => $service,
            'partner' => $this->partner,
            'charset' => $this->charset,
            'sign' => $this->rsaPrivateSign($biz_content),
            'sign_type' => $this->sign_type,
            'biz_content' => $biz_content,
        ];

        $response = get_curl($this->gateway_url, http_build_query($params));
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        $nodeName = str_replace('.', '_', $service) . '_response';
        if (isset($result[$nodeName])) {
            if ($result[$nodeName]['code'] == '00') {
                if (!$this->verifyResponse($nodeName, $response, $result['sign'])) {
                    throw new \Exception('响应报文验签失败');
                }
                return $result[$nodeName];
            } else {
                throw new \Exception($result[$nodeName]['msg']);
            }
        } elseif (isset($result['null_response'])) {
            throw new \Exception($result['null_response']['msg']);
        } else {
            throw new \Exception('返回数据解析失败,' . $response);
        }
    }

    //收银台支付
    public function submit(string $service, array $data): string
    {
        $biz_content = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $params = [
            'service' => $service,
            'partner' => $this->partner,
            'charset' => $this->charset,
            'sign' => $this->rsaPrivateSign($biz_content),
            'sign_type' => $this->sign_type,
            'biz_content' => $biz_content,
        ];

        $html_text = '<form action="' . $this->gateway_url . '" method="post" id="dopay">';
        foreach ($params as $k => $v) {
            $v = htmlentities($v, ENT_QUOTES | ENT_HTML5);
            $html_text .= "<input type=\"hidden\" name=\"{$k}\" value=\"{$v}\" />\n";
        }
        $html_text .= '<input type="submit" value="正在跳转"></form><script>document.getElementById("dopay").submit();</script>';

        return $html_text;
    }

    //验证响应报文
    private function verifyResponse(string $nodeName, string $response, string $sign): bool
    {
        if (empty($sign)) return false;
        $nodeIndex = strpos($response, $nodeName);
        if (!$nodeIndex) {
            return false;
        }
        $signDataStartIndex = $nodeIndex + strlen($nodeName) + 2;
        $signIndex = strrpos($response, '"sign"');
        $signDataEndIndex = $signIndex - 1;
        $indexLen = $signDataEndIndex - $signDataStartIndex;
        if ($indexLen < 0) {
            return false;
        }
        $signData = substr($response, $signDataStartIndex, $indexLen);
        return $this->rsaPubilcVerify($signData, $sign);
    }

    //验签方法
    public function verifySign(string $biz_content, string $sign): bool
    {
        if (empty($sign)) return false;
        return $this->rsaPubilcVerify($biz_content, $sign);
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $priKey = str_replace(["\n", "\r"], '', $this->mch_private_key);
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new \Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid);
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
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid);
        return $result === 1;
    }
}
