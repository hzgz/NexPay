<?php

declare(strict_types=1);

namespace plugins\payment\allinpay;

/**
 * https://prodoc.allinpay.com/
 */
class AllinpayClient
{
    private string $sign_type = 'RSA';
    private string $version = '11';
    private string $orgid;
    private string $cusid;
    private string $appid;
    private string $platform_public_key;
    private string $merchant_private_key;

    public function __construct(string $orgid, string $cusid, string $appid, string $platform_public_key, string $merchant_private_key)
    {
        $this->orgid = $orgid;
        $this->cusid = $cusid;
        $this->appid = $appid;
        $this->platform_public_key = $platform_public_key;
        $this->merchant_private_key = $merchant_private_key;
    }

    //发起API请求
    public function submit(string $requrl, array $params, bool $file = false): array
    {
        $public_params = [
            'orgid' => $this->orgid,
            'appid' => $this->appid,
            'cusid' => $this->cusid,
            'version' => $this->version,
            'randomstr' => getSid(),
            'signtype' => $this->sign_type,
        ];

        $params = array_merge($public_params, $params);
        $params['sign'] = $this->generateSign($params);

        $response = get_curl($requrl, $file ? $params : http_build_query($params));
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['retcode']) && $result['retcode'] === 'SUCCESS') {
            return $result;
        } elseif (isset($result['retmsg'])) {
            throw new \Exception($result['retmsg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //获取收银台参数
    public function cashier(array $params): array
    {
        $public_params = [
            'orgid' => $this->orgid,
            'cusid' => $this->cusid,
            'appid' => $this->appid,
            'version' => '12',
            'randomstr' => getSid(),
            'signtype' => $this->sign_type,
        ];

        $params = array_merge($public_params, $params);
        $params['sign'] = $this->generateSign($params);
        return $params;
    }

    //获取待签名字符串
    private function getSignContent(array $param): string
    {
        ksort($param);
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k != "sign" && !$v instanceof \CURLFile && $v !== '' && $v !== null) {
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

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $priKey = $this->merchant_private_key;
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
        $pubKey = $this->platform_public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new \Exception('验签失败，平台公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid);
        return $result === 1;
    }
}
