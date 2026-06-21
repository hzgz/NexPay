<?php

declare(strict_types=1);

namespace plugins\payment\kuaiqian;

use Exception;

class KuaiqianApp
{
    public string $gateway_url = 'https://umgw.99bill.com/umgw/common/distribute.html';
    protected string $member_code;
    private string $merchat_key_pwd;
    private string $ssl_cert_pwd;
    private string $platform_cert_path;
    private string $merchat_key_path;
    private string $ssl_cert_path;
    private ?string $temp_path;

    public function __construct(
        string $memberCode,
        string $merchat_key_pwd,
        string $ssl_cert_pwd,
        string $merchat_key_path,
        string $platform_cert_path,
        string $ssl_cert_path,
        ?string $temp_path = null
    ) {
        if (!file_exists($merchat_key_path)) throw new Exception("商户证书文件不存在");
        if (!file_exists($platform_cert_path)) throw new Exception("快钱证书文件不存在");
        $this->member_code = $memberCode;
        $this->merchat_key_pwd = $merchat_key_pwd;
        $this->ssl_cert_pwd = $ssl_cert_pwd;
        $this->merchat_key_path = $merchat_key_path;
        $this->platform_cert_path = $platform_cert_path;
        $this->ssl_cert_path = $ssl_cert_path;
        $this->temp_path = $temp_path;
    }

    //发起API请求
    public function execute(array $head, array $body): array
    {
        $apiurl = $this->gateway_url;

        $cryptoProcessor = new CryptoProcessor($this->merchat_key_path, $this->merchat_key_pwd, $this->platform_cert_path, $this->temp_path);

        //对明文body进行加密加签
        $salt = $head['memberCode'] . '_' . $this->getMillisecond();
        $body = json_encode($body, JSON_UNESCAPED_UNICODE);
        $request_Body_Final = $cryptoProcessor->seal($body, $salt);
        $request_Final['head'] = $head;
        $request_Final['requestBody'] = $request_Body_Final;

        //开始请求快钱，获取返回
        $result = $this->curl_ssl($apiurl, json_encode($request_Final, JSON_UNESCAPED_UNICODE));
        $responseMessage = json_decode($result, true);
        if (isset($responseMessage['head']['responseCode']) && $responseMessage['head']['responseCode'] == '0000') {
            //对返回body解密验签，拿到原文
            $signedData = $responseMessage['responseBody']['signedData'];
            $envelopedData = $responseMessage['responseBody']['envelopedData'];
            $salt = $responseMessage['head']['memberCode'] . '_' . $this->getMillisecond();
            $response_Body = $cryptoProcessor->unseal($signedData, $envelopedData, $salt);
            return json_decode($response_Body, true);
        } elseif (isset($responseMessage['head']['responseCode'])) {
            throw new Exception('[' . $responseMessage['head']['responseCode'] . ']' . $responseMessage['head']['responseTextMessage']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    public function notifyProcess(string $rawBody, ?array &$result): string
    {
        $requestMessage = json_decode($rawBody, true);
        if (!$requestMessage) throw new Exception('no data');
        //对返回body解密验签，拿到原文
        $cryptoProcessor = new CryptoProcessor($this->merchat_key_path, $this->merchat_key_pwd, $this->platform_cert_path, $this->temp_path);
        $signedData = $requestMessage['requestBody']['signedData'];
        $envelopedData = $requestMessage['requestBody']['envelopedData'];
        $salt = $requestMessage['head']['memberCode'] . '_' . $this->getMillisecond();
        $request_Body = $cryptoProcessor->unseal($signedData, $envelopedData, $salt);
        $result = ['head' => $requestMessage['head'], 'body' => json_decode($request_Body, true)];

        $head = [
            'version' => '1.0.0',
            'messageType' => 'A9005',
            'memberCode' => $requestMessage['head']['memberCode'],
            'externalRefNumber' => $requestMessage['head']['externalRefNumber'],
        ];
        $body = [
            'merchantId' => $result['body']['merchantId'] ?? $result['head']['memberCode'],
            'refNumber' => $result['body']['refNumber'],
            'isReceived' => '1'
        ];
        //对明文body进行加密加签
        $salt = $head['memberCode'] . '_' . $this->getMillisecond();
        $body = json_encode($body, JSON_UNESCAPED_UNICODE);
        $response_Body_Final = $cryptoProcessor->seal($body, $salt);
        $response_Final['head'] = $head;
        $response_Final['responseBody'] = $response_Body_Final;
        return json_encode($response_Final);
    }

    public function notifyProcessComplain(string $rawBody, ?array &$result): string
    {
        $requestMessage = json_decode($rawBody, true);
        if (!$requestMessage) throw new Exception('no data');
        //对返回body解密验签，拿到原文
        $cryptoProcessor = new CryptoProcessor($this->merchat_key_path, $this->merchat_key_pwd, $this->platform_cert_path, $this->temp_path);
        $signedData = $requestMessage['requestBody']['signedData'];
        $envelopedData = $requestMessage['requestBody']['envelopedData'];
        $salt = $requestMessage['head']['memberCode'] . '_' . $this->getMillisecond();
        $request_Body = $cryptoProcessor->unseal($signedData, $envelopedData, $salt);
        $result = ['head' => $requestMessage['head'], 'body' => json_decode($request_Body, true)];

        $head = [
            'version' => '1.0.0',
            'messageType' => 'A9005',
            'memberCode' => $requestMessage['head']['memberCode'],
        ];
        $body = [
            'isReceived' => '1'
        ];
        //对明文body进行加密加签
        $salt = $head['memberCode'] . '_' . $this->getMillisecond();
        $body = json_encode($body, JSON_UNESCAPED_UNICODE);
        $response_Body_Final = $cryptoProcessor->seal($body, $salt);
        $response_Final['head'] = $head;
        $response_Final['responseBody'] = $response_Body_Final;
        return json_encode($response_Final);
    }

    public function notifyProcessTransfer(string $rawBody, ?array &$result): string
    {
        $requestMessage = json_decode($rawBody, true);
        if (!$requestMessage) throw new Exception('no data');
        //对返回body解密验签，拿到原文
        $cryptoProcessor = new CryptoProcessor($this->merchat_key_path, $this->merchat_key_pwd, $this->platform_cert_path, $this->temp_path);
        $signedData = $requestMessage['requestBody']['signedData'];
        $envelopedData = $requestMessage['requestBody']['envelopedData'];
        $salt = $requestMessage['head']['memberCode'] . '_' . $this->getMillisecond();
        $request_Body = $cryptoProcessor->unseal($signedData, $envelopedData, $salt);
        $result = ['head' => $requestMessage['head'], 'body' => json_decode($request_Body, true)];

        $head = [
            'version' => '1.0.0',
            'messageType' => 'C1011',
            'memberCode' => $requestMessage['head']['memberCode'],
            'externalRefNumber' => $requestMessage['head']['externalRefNumber'],
            'origMessageType' => $requestMessage['head']['origMessageType'],
        ];
        $body = [
            'isReceived' => '1'
        ];
        //对明文body进行加密加签
        $salt = $head['memberCode'] . '_' . $this->getMillisecond();
        $body = json_encode($body, JSON_UNESCAPED_UNICODE);
        $response_Body_Final = $cryptoProcessor->seal($body, $salt);
        $response_Final['head'] = $head;
        $response_Final['responseBody'] = $response_Body_Final;
        return json_encode($response_Final);
    }

    //请求参数签名
    public function generateSign(array $param): string
    {
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k != "signMsg" && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        return $this->rsaPrivateSign($signstr);
    }

    //回调验签
    public function verifyNotify(array $param): bool
    {
        if (empty($param['signMsg'])) return false;
        $param_order = ['merchantAcctId', 'version', 'language', 'signType', 'payType', 'bankId', 'orderId', 'orderTime', 'orderAmount', 'bindCard', 'bindMobile', 'dealId', 'bankDealId', 'dealTime', 'payAmount', 'fee', 'ext1', 'ext2', 'payResult', 'aggregatePay', 'errCode', 'period'];
        $signstr = '';
        foreach ($param_order as $k) {
            if (!empty($param[$k])) {
                $signstr .= $k . '=' . $param[$k] . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        //公钥验签
        return $this->rsaPubilcSign($signstr, $param['signMsg']);
    }

    //商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $pkcs12 = file_get_contents($this->merchat_key_path);
        if (!openssl_pkcs12_read($pkcs12, $keyarr, $this->merchat_key_pwd)) {
            throw new Exception('商户证书读取失败');
        }
        $private_key = openssl_pkey_get_private($keyarr["pkey"]);
        if (!$private_key) {
            throw new Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $private_key, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    //平台公钥验签
    private function rsaPubilcSign(string $data, string $signature): bool
    {
        $keyFile = file_get_contents($this->platform_cert_path);
        $public_key = openssl_pkey_get_public($keyFile);
        if (!$public_key) {
            throw new Exception('验签失败，平台公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $public_key, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    public function curl_ssl(string $url, string $str): string
    {
        if (!file_exists($this->ssl_cert_path)) {
            throw new Exception('SSL客户端证书不存在');
        }
        $header[] = "Content-type: application/json;charset=utf-8";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $str);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
        curl_setopt($ch, CURLOPT_SSLCERT, $this->ssl_cert_path);
        curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->ssl_cert_pwd);
        $output = curl_exec($ch);

        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new Exception($errmsg);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            curl_close($ch);
            throw new Exception('http状态码异常[' . $httpCode . ']');
        }

        curl_close($ch);
        return $output;
    }

    public function curl(string $url, string $body, ?string $cookie = null): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Encoding: gzip,deflate,sdch";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Linux; Android 12; M2011K2C Build/SKQ1.211006.001) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.74 Mobile Safari/537.36");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        if ($cookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        $data = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new Exception($errmsg);
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($data, 0, $headerSize);
        $body = substr($data, $headerSize);
        curl_close($ch);
        return [$header, $body];
    }

    private function getMillisecond(): string
    {
        list($s1, $s2) = explode(' ', microtime());
        return sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000);
    }
}
