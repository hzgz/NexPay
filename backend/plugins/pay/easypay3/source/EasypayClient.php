<?php

declare(strict_types=1);

namespace plugins\payment\easypay3;

/**
 * 易生易企通1.0
 * @see https://www.yuque.com/pandans/ws1g9s/ot6775
 * @see https://easypaydoc.apifox.cn/
 */
class EasypayClient
{
    private string $pay_gateway = 'https://platform.eycard.cn:8443';
    private string $refund_gateway = 'https://platform.eycard.cn:6111';
    private string $signType = 'RSA';
    private string $orgId;
    private string $orgMercode;
    private string $orgTermno;
    private string $easypay_public_key;
    private string $mch_rsa_private_key;

    public function __construct(string $orgId, string $orgMercode, string $orgTermno, string $easypay_public_key, string $mch_rsa_private_key)
    {
        $this->orgId = $orgId;
        $this->orgMercode = $orgMercode;
        $this->orgTermno = $orgTermno;
        $this->easypay_public_key = $easypay_public_key;
        $this->mch_rsa_private_key = $mch_rsa_private_key;
    }

    //发起支付请求
    public function paySubmit(string $path, string $orgTrace, array $data): array
    {
        $requrl = $this->pay_gateway . $path;
        $sign = $this->generateSign($data);
        $params = [
            'orgId' => $this->orgId,
            'orgMercode' => $this->orgMercode,
            'orgTermno' => $this->orgTermno,
            'orgTrace' => $orgTrace,
            'sign' => $sign,
            'signType' => $this->signType,
            'data' => $data,
        ];

        $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['sysRetcode']) && $result['sysRetcode'] == '000000') {
            if (!empty($result['sign'])) {
                if (!$this->verifySign($result['data'], $result['sign'])) {
                    throw new \Exception('返回数据验签失败');
                }
            }
            return $result['data'];
        } elseif (isset($result['sysRetmsg'])) {
            throw new \Exception('[' . $result['sysRetcode'] . ']' . $result['sysRetmsg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //发起退款请求
    public function refundSubmit(string $path, string $orgTrace, array $bizData): array
    {
        $requrl = $this->refund_gateway . $path;
        $sign = $this->generateSign($bizData);
        $params = [
            'orgId' => $this->orgId,
            'orgMercode' => $this->orgMercode,
            'orgTermno' => $this->orgTermno,
            'orgTrace' => $orgTrace,
            'sign' => $sign,
            'signType' => $this->signType,
            'bizData' => $bizData,
        ];

        $response = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['sysRetCode']) && $result['sysRetCode'] == '000000') {
            if (!empty($result['sign'])) {
                if (!$this->verifySign($result['bizData'], $result['sign'])) {
                    throw new \Exception('返回数据验签失败');
                }
            }
            return $result['bizData'];
        } elseif (isset($result['sysRetMsg'])) {
            throw new \Exception('[' . $result['sysRetCode'] . ']' . $result['sysRetMsg']);
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
            if ($k != "sign" && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        return substr($signstr, 0, -1);
    }

    //请求参数签名
    private function generateSign(array $param): string
    {
        return $this->rsaPrivateSign($this->getSignContent($param));
    }

    //验签方法
    public function verifySign(array $param, string $sign): bool
    {
        if (empty($sign)) return false;
        return $this->rsaPubilcVerify($this->getSignContent($param), $sign);
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $priKey = str_replace(["\n", "\r"], '', $this->mch_rsa_private_key);
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new \Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    //平台公钥验签
    private function rsaPubilcVerify(string $data, string $signature): bool
    {
        $pubKey = str_replace(["\n", "\r"], '', $this->easypay_public_key);
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new \Exception('验签失败，易生公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
