<?php

declare(strict_types=1);

namespace plugins\payment\huishouqian;

use Exception;

class PayApp
{
    private string $gateway = 'https://api.huishouqian.com/api/acquiring';
    private string $merchantNo;
    private string $sign_key;
    private string $private_key_pwd;
    private string $public_key_path;
    private string $private_key_path;
    private string $version = '1.0';
    private string $format = 'JSON';
    private string $signType = 'RSA2';

    public function __construct(string $merchantNo, string $sign_key, string $private_key_pwd, string $private_key_path, string $public_key_path, bool $is_test = false)
    {
        $this->merchantNo = $merchantNo;
        $this->sign_key = $sign_key;
        $this->private_key_pwd = $private_key_pwd;
        $this->private_key_path = $private_key_path;
        $this->public_key_path = $public_key_path;
        if ($is_test) {
            $this->gateway = 'https://test-api.huishouqian.com/api/acquiring';
        }
    }

    //发起API请求
    public function submit(string $method, array $biz_params): array
    {
        $params = [
            'method' => $method,
            'version' => $this->version,
            'format' => $this->format,
            'merchantNo' => $this->merchantNo,
            'signType' => $this->signType,
            'signContent' => json_encode($biz_params),
        ];

        $params['sign'] = $this->generateSign($params);

        $response = get_curl($this->gateway, http_build_query($params));
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);
        if (isset($arr['sign'])) {
            if (!$this->verifyResponse($arr)) {
                throw new Exception('返回数据验签失败');
            }
        }
        if (isset($arr['success']) && $arr['success'] == true) {
            $result = json_decode($arr['result'], true);
            return $result;
        } else {
            throw new Exception($arr['errorMsg'] ?? '返回数据解析失败');
        }
    }

    //请求参数签名
    private function generateSign(array $param): string
    {
        $signstr = 'method=' . $param['method'] . '&version=' . $param['version'] . '&format=' . $param['format'] . '&merchantNo=' . $param['merchantNo'] . '&signType=' . $param['signType'] . '&signContent=' . $param['signContent'] . '&key=' . $this->sign_key;
        //私钥签名
        return $this->rsaPrivateSign($signstr);
    }

    //请求返回验签
    private function verifyResponse(array $param): bool
    {
        if (empty($param['sign'])) return false;
        if ($param['success']) {
            $signstr = 'result=' . $param['result'] . '&success=true&key=' . $this->sign_key;
        } else {
            $signstr = 'errorCode=' . $param['errorCode'] . '&errorMsg=' . $param['errorMsg'] . '&success=false&key=' . $this->sign_key;
        }
        //公钥验签
        if ($this->rsaPubilcVerify($signstr, $param['sign'])) {
            return true;
        }
        return false;
    }

    //回调验签
    public function verifyNotify(array $param): bool
    {
        if (empty($param['sign'])) return false;
        $signstr = 'method=' . $param['method'] . '&version=' . $param['version'] . '&format=' . $param['format'] . '&merchantNo=' . $param['merchantNo'] . '&signType=' . $param['signType'] . '&signContent=' . $param['signContent'] . '&key=' . $this->sign_key;
        //公钥验签
        if ($this->rsaPubilcVerify($signstr, $param['sign'])) {
            return true;
        }
        return false;
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $pkcs12 = file_get_contents($this->private_key_path);
        openssl_pkcs12_read($pkcs12, $keyarr, $this->private_key_pwd);
        $private_key = openssl_pkey_get_private($keyarr["pkey"]);
        if (!$private_key) {
            throw new Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $private_key, OPENSSL_ALGO_SHA256);
        return bin2hex($signature);
    }

    //平台公钥验签
    private function rsaPubilcVerify(string $data, string $signature): bool
    {
        $keyFile = file_get_contents($this->public_key_path);
        $public_key = openssl_pkey_get_public($keyFile);
        if (!$public_key) {
            throw new Exception('验签失败，平台公钥不正确');
        }
        $result = openssl_verify($data, hex2bin($signature), $public_key, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
