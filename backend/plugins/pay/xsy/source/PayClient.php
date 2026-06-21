<?php

declare(strict_types=1);

namespace plugins\payment\xsy;

use Exception;

/**
 * @see https://www.yuque.com/zhaohanying/egbhoy/wca6r5ueiaiyga3c
 */
class PayClient
{
    const VERSION = '1.0';
    const SIGN_TYPE = 'RSA';

    protected string $gateway_url = 'https://gateway-hpx.hnapay.com/order';
    protected string $appid;
    private string $public_key;
    private string $private_key;
    public string $res_code = '';

    public function __construct(string $appid, string $public_key, string $private_key, bool $isTest = false)
    {
        $this->appid = $appid;
        $this->public_key = $public_key;
        $this->private_key = $private_key;
        if ($isTest) $this->gateway_url = 'https://gateway-hpxtest1.hnapay.com/order';
    }

    //发起API请求
    public function request(string $path, array $data): array
    {
        $data = array_filter($data, function ($value) {
            return $value !== null;
        });
        $requrl = $this->gateway_url . $path;

        $param = [
            'reqId' => random(60),
            'orgNo' => $this->appid,
            'reqData' => $data,
            'signType' => self::SIGN_TYPE,
            'timestamp' => $this->getMillisecond(),
            'version' => self::VERSION
        ];
        $param['sign'] = $this->generateSign($param);
        $body = json_encode($param, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = get_curl($requrl, $body, 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new Exception('接口请求失败');

        $result = json_decode($response, true);

        if (isset($result['code']) && ($result['code'] == '0000' || $result['code'] == '0001')) {
            $this->res_code = $result['code'];
            return $result['respData'];
        } else {
            $this->res_code = $result['code'] ?? '';
            throw new Exception($result['msg'] ? $result['msg'] : '返回数据解析失败');
        }
    }

    //异步回调验证
    public function verifySign(array $data, ?string $rawdata): bool
    {
        if (!isset($data['sign'])) return false;

        return $this->rsaPublicVerify($this->getSignContent($data, $rawdata), $data['sign']);
    }

    //请求参数签名
    private function generateSign(array $param): string
    {
        return $this->rsaPrivateSign($this->getSignContent($param));
    }

    //获取待签名字符串
    private function getSignContent(array $param, ?string $rawdata = null): string
    {
        if (isset($param['respData']) && is_array($param['respData'])) {
            if ($rawdata) {
                $param['respData'] = getSubstr($rawdata, '"respData":', ',"sign"');
            } else {
                foreach ($param['respData'] as $k => $v) {
                    if ($v === '' || $v === null) {
                        unset($param['respData'][$k]);
                    }
                }
                $param['respData'] = json_encode($param['respData'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if (isset($param['reqData']) && is_array($param['reqData'])) {
            $param['reqData'] = json_encode($param['reqData'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

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

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $key = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($this->private_key, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $privatekey = openssl_get_privatekey($key);
        if (!$privatekey) {
            throw new Exception('签名失败，商户私钥错误');
        }
        openssl_sign($data, $sign, $privatekey);
        return base64_encode($sign);
    }

    //平台公钥验签
    private function rsaPublicVerify(string $data, string $sign): bool
    {
        $key = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($this->public_key, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $publickey = openssl_get_publickey($key);
        if (!$publickey) {
            throw new Exception("验签失败，平台公钥错误");
        }
        $result = openssl_verify($data, base64_decode($sign), $publickey);
        return $result === 1;
    }

    /**
     * 获取毫秒级时间戳
     */
    private function getMillisecond(): float
    {
        list($s1, $s2) = explode(' ', microtime());
        return (float) sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000);
    }
}
