<?php

declare(strict_types=1);

namespace plugins\payment\xingyifu;

/**
 * @see https://www.postar.cn/xyf/doc
 */
class PayClient
{
    private string $gateway_url = 'https://yyfsvxm.postar.cn';
    private string $public_key;

    public function __construct(string $public_key, bool $is_test = false)
    {
        $this->public_key = $public_key;
        if ($is_test) {
            $this->gateway_url = 'https://xyf-server-test.postar.cn';
        }
    }

    //请求API接口并解析返回数据
    public function execute(string $path, array $params): mixed
    {
        $requrl = $this->gateway_url . $path;
        $params['timeStamp'] = date('YmdHis');
        $params['version'] = '1.0.0';
        $params['sign'] = $this->generateSign($params);
        $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == '000000') {
            return $result['data'];
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
        return $this->rsaPublicSign($this->getSignContent($param));
    }

    //验签方法
    public function verifySign(array $param): bool
    {
        if (empty($param['sign'])) return false;
        return $this->rsaPubilcVerify($this->getSignContent($param), $param['sign']);
    }

    //平台公钥签名
    private function rsaPublicSign(string $data): string
    {
        $sha256 = hash("sha256", $data);
        $pubKey = $this->public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new \Exception('签名失败，公钥不正确');
        }
        openssl_public_encrypt($sha256, $signature, $pubkeyid);
        $signature = base64_encode($signature);
        return $signature;
    }

    //平台公钥验签
    private function rsaPubilcVerify(string $data, string $signature): bool
    {
        $sha256 = hash("sha256", $data);
        $pubKey = $this->public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new \Exception('验签失败，公钥不正确');
        }
        //$result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_SHA256);
        //return $result === 1;
        $data = str_split($signature, 256);
        $result = "";
        foreach ($data as $block) {
            openssl_public_decrypt($block, $dataDecrypt, $pubkeyid);
            if ($dataDecrypt === false) {
                return false;
            }
            $result .= $dataDecrypt;
        }
        return $sha256 === $result;
    }
}
