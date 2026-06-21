<?php

declare(strict_types=1);

namespace plugins\payment\jindd;

/**
 * @see https://open.jindd.com.cn/jdd/
 */
class JinddClient
{
    private string $gateway_url = 'http://op.jindd.com.cn/gateway.do';
    private string $service_name;
    private string $merchant_private_key;
    private string $platform_public_key;
    private string $version = '1.0';
    private string $charset = 'UTF-8';
    private string $sign_type = 'RSA2';

    public function __construct(string $service_name, string $merchant_private_key, string $platform_public_key)
    {
        $this->service_name = $service_name;
        $this->merchant_private_key = $merchant_private_key;
        $this->platform_public_key = $platform_public_key;
    }

    //请求API接口并解析返回数据
    public function execute(string $api_name, array $bizContent): array
    {
        $params = [
            'api_name' => $api_name,
            'service_name' => $this->service_name,
            'timestamp' => getMillisecond(),
            'charset' => $this->charset,
            'version' => $this->version,
            'sign_type' => $this->sign_type,
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ];
        $params['sign'] = $this->generateSign($params);
        $response = get_curl($this->gateway_url, http_build_query($params));
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == '10000') {
            if (!$this->verifySign($result)) {
                throw new \Exception('返回数据验签失败');
            }
            return json_decode($result['biz_content'], true);
        } elseif (isset($result['sub_msg'])) {
            throw new \Exception($result['sub_msg']);
        } elseif (isset($result['msg'])) {
            throw new \Exception($result['msg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //获取待签名字符串
    private function getSignContent(array $param): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $v !== '' && $v !== null) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        return $signstr;
    }

    //请求参数签名
    private function generateSign(array $param): string
    {
        return $this->rsaPrivateSign($this->getSignContent($param));
    }

    //验签方法
    public function verifySign(array $param): bool
    {
        if (empty($param['sign'])) return false;
        return $this->rsaPubilcVerify($this->getSignContent($param), $param['sign']);
    }

    //应用私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $priKey = $this->merchant_private_key;
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new \Exception('签名失败，应用私钥不正确');
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
            throw new \Exception('验签失败，平台公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
