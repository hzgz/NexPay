<?php

declare(strict_types=1);

namespace plugins\payment\kunpeng;

use Exception;

class KunpengClient
{
    //接口地址
    private string $gateway_url = 'https://ipay.globebill.com';

    //商户ID
    private string $mchid;

    //商户私钥
    private string $private_key_path;

    //平台公钥
    private string $public_key_path;

    private string $version = 'V1.0';
    private string $sign_type = 'RSA2';

    public function __construct(string $mchid, string $privateKeyPath, string $publicKeyPath)
    {
        $this->mchid = $mchid;
        $this->private_key_path = $privateKeyPath;
        $this->public_key_path = $publicKeyPath;
    }

    //支付接口
    public function execute(string $path, array $params, bool $ret = false, bool $public = true, bool $multipart = false): array
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'version' => $this->version,
            'merchantNo' => $this->mchid,
        ];
        if ($public) $params = array_merge($public_params, $params);
        $params['signType'] = $this->sign_type;
        $params['digest'] = $this->generateSign($params);
        $response = get_curl($requrl, $multipart ? $params : http_build_query($params));
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if ($ret) return $result;
        if (isset($result['resultCode']) && $result['resultCode'] == '0000') {
            if ($path != '/mas/ptMer/query.do' && !$this->verifySign($result)) {
                //throw new Exception('返回数据验签失败');
            }
            return $result;
        } elseif (isset($result['resultMsg'])) {
            throw new Exception($result['resultMsg']);
        } elseif (preg_match('/<sysError>(.*?)<\/sysError>/', $response, $matches)) {
            throw new Exception($matches[1]);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //进件接口
    public function execute2(string $path, array $params, bool $ret = false, bool $multipart = false): array
    {
        $params = array_filter($params, function ($v) {
            return $v !== null;
        });
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'version' => $this->version,
            'orgid' => $this->mchid,
        ];
        $params = array_merge($public_params, $params);
        $params['signtype'] = $this->sign_type;
        $params['digest'] = $this->generateSign($params);
        $response = get_curl($requrl, $multipart ? $params : http_build_query($params));
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if ($ret) return $result;
        if (isset($result['resultcode']) && $result['resultcode'] == '0000') {
            if (!$this->verifySign($result)) {
                throw new Exception('返回数据验签失败');
            }
            return $result;
        } elseif (isset($result['resultmsg'])) {
            throw new Exception($result['resultmsg']);
        } elseif (preg_match('/<sysError>(.*?)<\/sysError>/', $response, $matches)) {
            throw new Exception($matches[1]);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //账户分账接口
    public function execute3(string $path, array $params, bool $ret = false, bool $multipart = false): array
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'version' => $this->version,
            'custno' => $this->mchid,
        ];
        $params = array_merge($public_params, $params);
        $params['signtype'] = $this->sign_type;
        $params['digest'] = $this->generateSign($params);
        $response = get_curl($requrl, $multipart ? $params : http_build_query($params));
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if ($ret) return $result;
        if (isset($result['resultcode']) && $result['resultcode'] == '0000') {
            if (!$this->verifySign($result)) {
                throw new Exception('返回数据验签失败');
            }
            return $result;
        } elseif (isset($result['resultmsg'])) {
            throw new Exception($result['resultmsg']);
        } elseif (preg_match('/<sysError>(.*?)<\/sysError>/', $response, $matches)) {
            throw new Exception($matches[1]);
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
            if (!$v instanceof \CURLFile && $k != "digest" && $v !== '' && $v !== null) {
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
        if (empty($param['digest'])) return false;
        return $this->rsaPubilcSign($this->getSignContent($param), $param['digest']);
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

    //平台公钥验签
    private function rsaPubilcSign(string $data, string $signature): bool
    {
        $res = file_get_contents($this->public_key_path);
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new Exception('验签失败，平台公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
