<?php

declare(strict_types=1);

namespace plugins\payment\hwkjpay;

use Exception;

/**
 * @see http://docs.hwkjpay.com/
 */
class PayClient
{
    //接口地址
    private string $gateway_url = 'https://openapi.hwkjpay.com/gateway.do';

    //商户号
    private string $mch_no;

    //商户私钥
    private string $private_key_path;

    //平台公钥
    private string $public_key_path;

    private string $version = '1.0';
    private string $charset = 'utf-8';
    private string $sign_type = 'RSA';

    public function __construct(string $mch_no, string $private_key_path, string $public_key_path)
    {
        $this->mch_no = $mch_no;
        $this->private_key_path = $private_key_path;
        $this->public_key_path = $public_key_path;
    }

    //请求API接口并解析返回数据
    public function execute(string $method, array $bizContent, ?string $notify_url = null, ?string $return_url = null): array
    {
        $params = [
            'mch_no' => $this->mch_no,
            'method' => $method,
            'version' => $this->version,
            'charset' => $this->charset,
            'sign_type' => $this->sign_type,
            'timestamp' => date('Y-m-d H:i:s'),
            'biz_content' => json_encode($bizContent)
        ];
        if ($notify_url) $params['notify_url'] = $notify_url;
        if ($return_url) $params['return_url'] = $return_url;
        $params['sign'] = $this->generateSign($params);
        $response = get_curl($this->gateway_url, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 20000) {
            if (!$this->verifySign($result)) {
                throw new Exception('返回数据验签失败');
            }
            return $result;
        } elseif (isset($result['sub_msg'])) {
            throw new Exception($result['sub_msg']);
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //获取待签名字符串
    private function getSignContent(array $param): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $v !== '' && $v !== null && !is_array($v)) {
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

    //应用私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $res = file_get_contents($this->private_key_path);
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new Exception('签名失败，应用私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($signature);
        return $signature;
    }

    //鸿闻公钥验签
    private function rsaPubilcSign(string $data, string $signature): bool
    {
        $res = file_get_contents($this->public_key_path);
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new Exception('验签失败，鸿闻公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
