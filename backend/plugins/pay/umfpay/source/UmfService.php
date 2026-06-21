<?php

declare(strict_types=1);

namespace plugins\payment\umfpay;

use Exception;

class UmfService
{
    private string $pay_req_url = 'http://pay.soopay.net/spay/pay/payservice.do';
    private string $charset = 'UTF-8';
    private string $sign_type = 'RSA';
    private string $res_format = 'HTML';
    private string $version = '4.0';
    private string $mer_id;
    private string $platform_public_key;
    private string $merchant_private_key;

    public function __construct(string $mer_id, string $publicKeyPath, string $privateKeyPath)
    {
        $this->mer_id = $mer_id;
        if (!file_exists($publicKeyPath)) {
            throw new Exception('平台公钥文件不存在');
        }
        if (!file_exists($privateKeyPath)) {
            throw new Exception('商户私钥文件不存在');
        }
        $this->platform_public_key = file_get_contents($publicKeyPath);
        $this->merchant_private_key = file_get_contents($privateKeyPath);
    }

    //发起API请求
    public function submit(array $params): array
    {
        $public_params = [
            'charset' => $this->charset,
            'sign_type' => $this->sign_type,
            'res_format' => $this->res_format,
            'version' => $this->version,
            'amt_type' => 'RMB',
            'mer_id' => $this->mer_id,
        ];

        $params = array_merge($public_params, $params);
        $params = $this->doEncrypt($params);
        $params['sign'] = $this->generateSign($params);

        $response = get_curl($this->pay_req_url, http_build_query($params));
        if (!$response) {
            throw new Exception('请求接口失败');
        }
        //对账直接返回
        if ($params["service"] == "download_settle_file") {
            return ['raw' => $response];
        }
        return $this->parseHTMLStr($response);
    }

    //获取支付跳转链接
    public function getpayurl(array $params): string
    {
        $public_params = [
            'charset' => $this->charset,
            'sign_type' => $this->sign_type,
            'res_format' => $this->res_format,
            'version' => $this->version,
            'amt_type' => 'RMB',
            'mer_id' => $this->mer_id,
        ];

        $params = array_merge($public_params, $params);
        $params = $this->doEncrypt($params);
        $params['sign'] = $this->generateSign($params);

        $url = $this->pay_req_url . '?' . http_build_query($params);

        return $url;
    }

    private function parseHTMLStr(string $htmlStr): array
    {
        preg_match('/<META\s+name="MobilePayPlatform"\s+content="([\w\W]*?)"/si', $htmlStr, $matches);
        $content = $matches[1] ?? '';
        if (!$content) {
            throw new Exception('平台返回html解析失败');
        }
        $params = [];
        $strs = explode('&', $content);
        foreach ($strs as $str) {
            $arr = explode('=', $str);
            $params[$arr[0]] = $arr[1];
        }
        //if(!$this->verifySign($params)){
        //	throw new Exception('平台响应数据验证签名失败');
        //}
        return $params;
    }

    public function responseUMFstr(array $params): string
    {
        $public_params = [
            'sign_type' => $this->sign_type,
            'version' => $this->version,
            'mer_id' => $this->mer_id,
            'ret_code' => '0000',
            'ret_msg' => 'success'
        ];

        $params = array_merge($public_params, $params);
        $params['sign'] = $this->generateSign($params);
        $str = '';
        foreach ($params as $key => $value) {
            $str .= $key . '=' . $value . '&';
        }
        $str = substr($str, 0, -1);
        return $str;
    }

    //获取待签名字符串
    private function getSignContent(array $param): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $k != "sign_type" && $v != '') {
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

    //敏感字段加密
    private function doEncrypt(array $param): array
    {
        $chkKeys = [
            "card_id",
            "valid_date",
            "cvv2",
            "pass_wd",
            "identity_code",
            "card_holder",
            "recv_account",
            "recv_user_name",
            "identity_holder",
            "identityCode",
            "cardHolder",
            "mer_cust_name",
            "account_name",
            "bank_account",
            "endDate",
        ];
        foreach ($chkKeys as $key) {
            if (isset($param[$key])) {
                $param[$key] = $this->rsaPublicEncrypt($param[$key]);
            }
        }
        return $param;
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $pkeyid = openssl_get_privatekey($this->merchant_private_key);
        if (!$pkeyid) {
            throw new Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid);
        $signature = base64_encode($signature);
        return $signature;
    }

    //平台公钥验签
    private function rsaPubilcVerify(string $data, string $signature): bool
    {
        $pubkeyid = openssl_get_publickey($this->platform_public_key);
        if (!$pubkeyid) {
            throw new Exception('验签失败，平台公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid);
        return $result === 1;
    }

    //平台公钥加密
    private function rsaPublicEncrypt(string $data): string
    {
        $pubkeyid = openssl_get_publickey($this->platform_public_key);
        if (!$pubkeyid) {
            throw new Exception('加密失败，平台公钥不正确');
        }
        openssl_public_encrypt($data, $encrypted, $pubkeyid);
        $encrypted = base64_encode($encrypted);
        return $encrypted;
    }

    //商户私钥解密
    private function rsaPrivateDecrypt(string $data): string
    {
        $pkeyid = openssl_get_privatekey($this->merchant_private_key);
        if (!$pkeyid) {
            throw new Exception('解密失败，商户私钥不正确');
        }
        openssl_private_decrypt(base64_decode($data), $decrypted, $pkeyid);
        return $decrypted;
    }
}
