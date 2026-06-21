<?php

declare(strict_types=1);

namespace plugins\payment\llianpay;

/**
 * https://api-doc.lianlianpay.com/openplatform
 */
class LLianPayClient
{
    public string $gateway_url = 'https://openapi.lianlianpay.com';
    private string $signType = 'RSA';
    private string $mchId;
    private string $llianpay_public_key = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCSS/DiwdCf/aZsxxcacDnooGph3d2JOj5GXWi+q3gznZauZjkNP8SKl3J2liP0O6rU/Y/29+IUe+GTMhMOFJuZm1htAtKiu5ekW0GlBMWxf4FPkYlQkPE0FtaoMP3gYfh+OwI+fIRrpW3ySn3mScnc6Z700nU/VYrRkfcSCbSnRwIDAQAB';
    private $llianpay_public_res;
    private string $merchant_private_key;

    public function __construct(string $mchId, string $merchant_private_key)
    {
        $this->mchId = $mchId;
        $this->merchant_private_key = $merchant_private_key;
        $this->llianpay_public_res = $this->getPublicKey();
    }

    //发起API请求
    public function sendRequest(string $path, array $params, bool $return = false): array
    {
        $requrl = $this->gateway_url . $path;
        $body = json_encode($params);
        $signVar = $this->rsaPrivateSign($body);

        $header = [
            'Content-Type: application/json;charset=UTF-8',
            'timestamp: ' . date("YmdHis"),
            'Signature-Data: ' . $signVar,
            'Signature-Type: ' . $this->signType,
            'mch_id: ' . $this->mchId
        ];

        [$respHeader, $respBody] = $this->curl($requrl, $header, $body);
        $result = json_decode($respBody, true);
        if ($return) return $result;
        if (isset($result['ret_code']) && $result['ret_code'] == '0000') {
            if (preg_match('/Signature-Data: (.*?)\r\n/', $respHeader, $match)) {
                $sign = $match[1];
                if (!$this->rsaPubilcVerify($respBody, $sign)) {
                    throw new \Exception('返回数据验签失败');
                }
            }
            return $result;
        } elseif (isset($result['ret_msg'])) {
            if (preg_match('/Signature-Data: (.*?)\r\n/', $respHeader, $match)) {
                $sign = $match[1];
                if (!$this->rsaPubilcVerify($respBody, $sign)) {
                    throw new \Exception('返回数据验签失败');
                }
            }
            throw new \Exception('[' . $result['ret_code'] . ']' . $result['ret_msg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //上传文件
    public function upload(string $file_path, string $file_name, string $context_type = 'USER_IMAGE'): string
    {
        $ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $this->gateway_url = 'https://mpay-file-openapi.lianlianpay.com';
        $path = '/v1/file/uploadfile';
        $params = [
            'user_no' => $this->mchId,
            'txn_time' => date('YmdHis'),
            'txn_seqno' => date('YmdHis') . rand(1000, 9999),
            'file_type' => strtolower($ext),
            'file_context' => base64_encode(file_get_contents($file_path)),
            'timestamp' => date('YmdHis'),
            'context_type' => $context_type,
        ];
        $result = $this->sendRequest($path, $params);
        return $result['doc_id'];
    }

    //验签方法
    public function verifySign(string $body, string $sign): bool
    {
        if (empty($sign)) return false;
        try {
            return $this->rsaPubilcVerify($body, $sign);
        } catch (\Exception $e) {
            return false;
        }
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $md5Var = md5($data);
        $priKey = $this->merchant_private_key;
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new \Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($md5Var, $signature, $pkeyid, OPENSSL_ALGO_MD5);
        $signature = base64_encode($signature);
        return $signature;
    }

    //平台公钥验签
    private function rsaPubilcVerify(string $data, string $signature): bool
    {
        $md5Var = md5($data);
        $pubkeyid = $this->llianpay_public_res;
        if (!$pubkeyid) {
            throw new \Exception('验签失败，连连公钥不正确');
        }
        $result = openssl_verify($md5Var, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_MD5);
        return $result === 1;
    }

    //平台公钥加密
    public function rsaPublicEncrypt(string $data): string|false
    {
        $pubkeyid = $this->llianpay_public_res;
        if (!$pubkeyid) {
            throw new \Exception('加密失败，连连公钥不正确');
        }

        $encrypted = '';
        $partLen = openssl_pkey_get_details($pubkeyid)['bits'] / 8 - 11;
        $plainData = str_split($data, (int)$partLen);
        foreach ($plainData as $chunk) {
            $partialEncrypted = '';
            $encryptionOk = openssl_public_encrypt($chunk, $partialEncrypted, $pubkeyid);
            if ($encryptionOk === false) {
                return false;
            }
            $encrypted .= $partialEncrypted;
        }
        return base64_encode($encrypted);
    }

    public function getPublicKey()
    {
        $pubKey = $this->llianpay_public_key;
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        return openssl_pkey_get_public($res);
    }

    private function curl(string $url, array $header, ?string $body = null, int $timeout = 10): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $data = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new \Exception($errmsg, 0);
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($data, 0, $headerSize);
        $body = substr($data, $headerSize);
        curl_close($ch);
        return [$header, $body];
    }
}
