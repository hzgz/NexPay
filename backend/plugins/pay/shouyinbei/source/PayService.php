<?php

declare(strict_types=1);

namespace plugins\payment\shouyinbei;

/**
 * @see https://www.yuque.com/shouyinbei/123
 */
class PayService
{
    private string $gateway_url = 'https://open.shouyinbeipay.com';
    private string $orgid = 'SYB_TEST';
    private string $appid;
    private $platform_public_key;
    private $app_private_key;

    public function __construct(string $appid, string $orgid, string $platform_public_key, string $app_private_key)
    {
        $this->appid = $appid;
        $this->orgid = $orgid;
        $this->platform_public_key = $this->loadPublicKey($platform_public_key);
        $this->app_private_key = $this->loadPrivateKey($app_private_key);
    }

    //发起API请求
    public function submit(string $path, array $params): array
    {
        $data = [
            'requestId' => getSid(),
            'orgId' => $this->orgid,
            'appId' => $this->appid,
            'timestamp' => date('YmdHis'),
        ];
        //生成aes对称加密密钥
        $key = substr(getSid(), 0, 16);
        //对数据加密
        $bizContent = $this->aesEncrypt(json_encode($params), $key);
        $data['bizContent'] = $bizContent;
        //对密钥进行非对称加密
        $secretKey = $this->rsaPublicEncrypt($key);
        $data['check'] = $secretKey;
        //生成签名
        $data['sign'] = $this->generateSign($data);

        $requrl = $this->gateway_url . $path;
        $response = get_curl($requrl, http_build_query($data));
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == '0000') {
            if (!$this->verifySign($result)) {
                throw new \Exception('返回数据签名校验失败');
            }
            $json = $this->aesDecrypt($result['data'], $key);
            if (!$json) throw new \Exception('返回数据解密失败');
            return json_decode($json, true) ?? [];
        } elseif (isset($result['message'])) {
            throw new \Exception($result['message']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //AES加密 加密算法采用 AES/ECB/NoPadding
    private function aesEncrypt(string $data, string $key): string
    {
        $encryptStr = base64_encode(openssl_encrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA));
        return $encryptStr;
    }

    //AES解密
    private function aesDecrypt(string $encrypted, string $key): string
    {
        $data = openssl_decrypt(base64_decode($encrypted), 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        $data = substr($data, 0, strrpos($data, '}') + 1);
        return $data;
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
        return $this->rsaPubilcSign($this->getSignContent($param), $param['sign']);
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        openssl_sign($data, $signature, $this->app_private_key, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    //平台公钥验签
    private function rsaPubilcSign(string $data, string $signature): bool
    {
        $result = openssl_verify($data, base64_decode($signature), $this->platform_public_key, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    //平台公钥加密
    private function rsaPublicEncrypt(string $data): ?string
    {
        $result = openssl_public_encrypt($data, $encrypted, $this->platform_public_key);
        return $result ? base64_encode($encrypted) : null;
    }

    //加载平台公钥
    private function loadPublicKey(string $public_key)
    {
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($public_key, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_get_publickey($res);
        if (!$pubkeyid) {
            throw new \Exception('平台公钥不正确');
        }
        return $pubkeyid;
    }

    //加载应用私钥
    private function loadPrivateKey(string $private_key)
    {
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($private_key, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $prikeyid = openssl_get_privatekey($res);
        if (!$prikeyid) {
            throw new \Exception('商户应用不正确');
        }
        return $prikeyid;
    }
}
