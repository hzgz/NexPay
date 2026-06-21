<?php

declare(strict_types=1);

namespace plugins\payment\lakala;

use Exception;

/**
 * https://o.lakala.com/#/home/document
 */
class LakalaClient
{
    //appid
    protected string $appid;

    //平台证书文件
    private string $platform_cert_file;

    //商户证书文件
    private string $merchant_cert_file;

    //商户私钥文件
    private string $merchant_private_file;

    protected string $gateway_url = 'https://s2.lakala.com';
    private string $version = '3.0';
    private string $schema = 'LKLAPI-SHA256withRSA';

    public string $request_body = '';
    public string $response_body = '';
    public string $res_code = '';

    public function __construct(string $appid, string $platformCertPath, string $merchantCertPath, string $merchantKeyPath, bool $isTest = false)
    {
        $this->appid = $appid;
        $this->platform_cert_file = $platformCertPath;
        $this->merchant_cert_file = $merchantCertPath;
        $this->merchant_private_file = $merchantKeyPath;
        if ($isTest) {
            $this->gateway_url = 'https://test.wsmsd.cn/sit';
        }
    }

    //发起请求
    public function execute(string $path, array $params): array
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'req_time' => date('YmdHis'),
            'version' => $this->version,
            'req_data' => $params
        ];

        $body = json_encode($public_params, JSON_UNESCAPED_UNICODE);
        $authorization = $this->getAuthorization($body);
        $this->request_body = $body;
        $resp = $this->curl($requrl, $body, $authorization);
        $this->response_body = $resp;
        $result = json_decode($resp, true);

        if (isset($result['code']) && ($result['code'] == 'BBS00000' || $result['code'] == 'BBS10000' || $result['code'] == '000000')) {
            $this->res_code = $result['code'];
            return $result['resp_data'];
        } elseif (isset($result['msg'])) {
            $this->res_code = $result['code'];
            throw new Exception('[' . $result['code'] . ']' . $result['msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //发起请求old
    public function execute_old(string $path, array $params): array
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'timestamp' => getMillisecond(),
            'rnd' => getSid(),
            'ver' => '1.0.0',
            'reqId' => date('YmdHis') . rand(10000, 99999),
            'reqData' => $params
        ];

        $body = json_encode($public_params, JSON_UNESCAPED_UNICODE);
        $authorization = $this->getAuthorization($body);
        $this->request_body = $body;
        $resp = $this->curl($requrl, $body, $authorization);
        $this->response_body = $resp;
        $result = json_decode($resp, true);

        if (isset($result['retCode']) && ($result['retCode'] == 'BBS00000' || $result['retCode'] == 'BBS10000' || $result['retCode'] == '000000')) {
            $this->res_code = $result['retCode'];
            return $result['respData'] ?? [];
        } elseif (isset($result['retMsg'])) {
            throw new Exception('[' . $result['retCode'] . ']' . $result['retMsg']);
        } elseif (isset($result['resMsg'])) {
            throw new Exception('[' . $result['retCode'] . ']' . $result['resMsg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //签名
    public function getAuthorization(string $body): string
    {
        $mchSerialNo = $this->getMchSerialNo();
        $nonceStr = random(12);
        $timestamp = time();

        $message = $this->appid . "\n" . $mchSerialNo . "\n" . $timestamp . "\n" . $nonceStr . "\n" . $body . "\n";

        $signature = $this->rsaPrivateSign($message);

        return $this->schema . " appid=\"" . $this->appid . "\",serial_no=\"" . $mchSerialNo . "\",timestamp=\"" . $timestamp . "\",nonce_str=\"" . $nonceStr . "\",signature=\"" . $signature . "\"";
    }

    //验签
    public function verifySign(string $authorization, string $body): bool
    {
        $authorization = str_replace($this->schema . " ", "", $authorization);
        $authorization = str_replace(",", "&", $authorization);
        $authorization = str_replace("\"", "", $authorization);
        $array = $this->convertUrlQuery($authorization);

        if (!isset($array['signature'])) return false;

        $message = $array['timestamp'] . "\n" . $array['nonce_str'] . "\n" . $body . "\n";

        return $this->rsaPublicVerify($message, $array['signature']);
    }

    // 私钥加签
    private function rsaPrivateSign(string $data): string
    {
        $priKey = file_get_contents($this->merchant_private_file);
        $res = openssl_pkey_get_private($priKey);
        if (!$res) {
            throw new Exception('私钥加签失败，商户私钥错误');
        }
        $result = openssl_sign($data, $sign, $res, OPENSSL_ALGO_SHA256);
        if (!$result) throw new Exception('sign error');
        return base64_encode($sign);
    }

    // 公钥验签
    private function rsaPublicVerify(string $data, string $sign): bool
    {
        $cert = file_get_contents($this->platform_cert_file);
        $res = openssl_pkey_get_public($cert);
        if (!$res) {
            throw new Exception('公钥验签失败，平台公钥错误');
        }
        return openssl_verify($data, base64_decode($sign), $res, OPENSSL_ALGO_SHA256) === 1;
    }

    //从商户证书获取序列号
    private function getMchSerialNo(): string
    {
        $cert = file_get_contents($this->merchant_cert_file);
        $ssl = openssl_x509_parse($cert);
        if (!$ssl) {
            throw new Exception('获取证书序列号失败');
        }
        return $ssl['serialNumber'];
    }

    //请求
    protected function curl(string $url, string $data, string $authorization): string
    {
        $headers = [
            "Authorization: " . $authorization,
            "Accept: application/json",
            "Content-Type: application/json; charset=utf-8",
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Linux; U; Android 4.0.4; es-mx; HTC_One_X Build/IMM76D) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0");
        $res = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new Exception($errmsg, 0);
        }
        curl_close($ch);
        return $res;
    }

    //签名参数转数组
    private function convertUrlQuery(string $query): array
    {
        $queryParts = explode('&', $query);

        $params = [];
        foreach ($queryParts as $param) {
            $item = explode('=', $param);
            $params[$item[0]] = $item[1];
        }
        if ($params['signature']) {
            $params['signature'] = substr($query, strrpos($query, 'signature=') + 10);
        }

        return $params;
    }
}
