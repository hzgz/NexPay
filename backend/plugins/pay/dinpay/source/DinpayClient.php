<?php

namespace plugins\payment\dinpay;

use Exception;

class DinpayClient
{
    private $gateway_url = 'https://payment.dinpay.com/trx';
    private $sign_method = 'SM3WITHSM2';

    private $mchid;

    //平台公钥
    private $platform_public_key;
    //商户私钥
    private $merchant_private_key;

    //SM4算法IV（base64编码）
    public $sm4Iv = "T172oxqWwkr8wqB9D7aR7g==";
    //SM2算法userId
    public $sm2userId = "1234567812345678";

    public function __construct($mchid, $platform_public_key, $merchant_private_key, $isTest = false)
    {
        if (!function_exists('gmp_init')) throw new Exception('请先安装GMP扩展');
        $this->mchid = $mchid;
        if ($isTest) $this->gateway_url = 'https://paymenttest.dinpay.com/trx';
        $this->platform_public_key = $platform_public_key;
        $this->merchant_private_key = $merchant_private_key;
    }

    //发起API请求
    public function execute($path, $data, $file = null, $download = false)
    {
        //生成16位SM4随机秘钥
        $sm4Key = random(16);

        //加密后的SM4密钥
        $encrytionKey = base64_encode(hex2bin('04' . $this->sm2DoEncrypt($sm4Key)));

        $json = json_encode($data);
        $enc_data = $this->sm4DoEncrypt($json, $sm4Key);
        $sign = trim($this->sm2DoSign($enc_data));

        $params = [
            'merchantId' => $this->mchid,
            'timestamp' => date('YmdHis'),
            'data' => $enc_data,
            'encryptionKey' => $encrytionKey,
            'signatureMethod' => $this->sign_method,
            'sign' => $sign,
        ];
        if ($file) {
            if ($file instanceof \CURLFile) {
                $params['file'] = $file;
            }
            $response = get_curl($this->gateway_url . $path, $params);
        } else {
            $response = get_curl($this->gateway_url . $path, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        }
        if (!$response) throw new Exception('接口请求失败');
        if ($download) return $response;

        $result = json_decode($response, true);
        if (isset($result['code']) && ($result['code'] == '0000' || $result['code'] == '0001')) {
            if (!empty($result['sign'])) {
                if (!$this->sm2DoVerifySign($result['data'], $result['sign'])) {
                    throw new Exception('返回数据验签失败');
                }
            }
            return json_decode($result['data'], true);
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //使用商户私钥对数据进行sm2签名
    private function sm2DoSign($signStr)
    {
        $sm2 = new \Rtgm\sm\RtSm2('base64', true);
        return $sm2->doSign($signStr, $this->merchant_private_key, $this->sm2userId);
    }

    //使用平台公钥对数据进行sm2验签
    public function sm2DoVerifySign($signStr, $sign)
    {
        $sm2 = new \Rtgm\sm\RtSm2('base64', true);
        $isSign = $sm2->verifySign($signStr, $sign, $this->platform_public_key, $this->sm2userId);
        return $isSign;
    }

    //使用平台公钥对数据进行sm2加密
    private function sm2DoEncrypt($plaintext)
    {
        $sm2 = new \Rtgm\sm\RtSm2('base64');
        return $sm2->doEncrypt($plaintext, $this->platform_public_key);
    }

    //使用商户私钥对数据进行sm2解密
    private function sm2DoDecrypt($ciphertext)
    {
        $sm2 = new \Rtgm\sm\RtSm2('base64');
        return $sm2->doDecrypt($ciphertext, $this->merchant_private_key);
    }

    //sm4加密
    private function sm4DoEncrypt($plaintext, $key)
    {
        $sm4 = new \Rtgm\sm\RtSm4($key);
        return $sm4->encrypt($plaintext, 'sm4', base64_decode($this->sm4Iv), 'base64');
    }

    public function sm3ToHex($str)
    {
        $sm3 = new \Rtgm\sm\RtSm3();
        return $sm3->digest($str);
    }
}
