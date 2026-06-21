<?php

declare(strict_types=1);

namespace plugins\payment\epaylinks;

class EfpsService
{
    private string $gateway_url = 'https://efps.epaylinks.cn';
    private string $customer_code;
    private string $sign_no;

    private string $rsaPrivateKeyFilePath;
    private string $publicKeyFilePath;
    private mixed $rsaPrivateKey;
    private mixed $publicKey;

    public function __construct(string $customer_code, string $sign_no, string $rsaPrivateKeyFilePath, string $publicKeyFilePath)
    {
        $this->customer_code = $customer_code;
        $this->sign_no = $sign_no;
        $this->rsaPrivateKeyFilePath = $rsaPrivateKeyFilePath;
        $this->publicKeyFilePath = $publicKeyFilePath;
        if (!file_exists($this->rsaPrivateKeyFilePath)) {
            throw new \Exception('商户私钥证书不存在');
        }
        if (!file_exists($this->publicKeyFilePath)) {
            throw new \Exception('易票联公钥证书不存在');
        }
        $this->rsaPrivateKey = $this->rsaPrivateLoad();
        $this->publicKey = $this->rsaPublicLoad();
    }

    //发起API请求
    public function submit(string $path, array $params): array
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'customerCode' => $this->customer_code,
            'nonceStr' => md5((string)time()),
        ];

        $params = array_merge($public_params, $params);
        $body = json_encode($params);
        $sign = $this->rsaPrivateSign($body);

        $response = $this->http_post_json($requrl, $body, $sign);
        $result = json_decode($response, true);
        if (isset($result['returnCode']) && $result['returnCode'] == '0000') {
            return $result;
        } elseif (isset($result['returnMsg'])) {
            throw new \Exception($result['returnMsg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //发起API请求
    public function submit2(string $path, array $params): array
    {
        $requrl = $this->gateway_url . $path;
        $body = json_encode($params);
        $sign = $this->rsaPrivateSign($body);

        $response = $this->http_post_json($requrl, $body, $sign);
        $result = json_decode($response, true);
        if (isset($result['respCode']) && $result['respCode'] == '0000') {
            return $result;
        } elseif (isset($result['respMsg'])) {
            throw new \Exception($result['respMsg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //验签方法
    public function verifySign(string $body, ?string $sign = null): bool
    {
        if (empty($sign)) return false;
        return $this->rsaPubilcVerify($body, $sign);
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        openssl_sign($data, $sign, $this->rsaPrivateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    //平台公钥验签
    private function rsaPubilcVerify(string $data, string $sign): bool
    {
        $result = openssl_verify($data, base64_decode($sign), $this->publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    //平台公钥加密
    public function rsaPublicEncrypt(string $data): string
    {
        openssl_public_encrypt($data, $crypttext, $this->publicKey);
        return base64_encode($crypttext);
    }

    private function rsaPublicLoad(): mixed
    {
        $pubKey = file_get_contents($this->publicKeyFilePath);
        $res = openssl_get_publickey($pubKey);
        if (!$res) {
            throw new \Exception('加密失败，平台公钥证书不正确');
        }
        return $res;
    }

    private function rsaPrivateLoad(): mixed
    {
        $priKey = file_get_contents($this->rsaPrivateKeyFilePath);
        $res = openssl_get_privatekey($priKey);
        if (!$res) {
            throw new \Exception('签名失败，商户私钥证书不正确');
        }
        return $res;
    }

    private function http_post_json(string $url, string $jsonStr, string $sign): string
    {
        $ch = curl_init();
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'x-efps-sign-no: ' . $this->sign_no,
            'x-efps-sign-type: SHA256withRSA',
            'x-efps-sign: ' . $sign,
            'x-efps-timestamp: ' . date('YmdHis'),
        ];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonStr);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new \Exception($errmsg);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            curl_close($ch);
            throw new \Exception('http_code = ' . $httpCode);
        }
        curl_close($ch);
        return $response;
    }
}
