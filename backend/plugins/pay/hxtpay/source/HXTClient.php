<?php

declare(strict_types=1);

namespace plugins\payment\hxtpay;

use Exception;

/**
 * @see http://221.122.92.171/web/#/11 密码：hxt@)!(
 */
class HXTClient
{
    private string $gateway_url = 'https://sd.96299.com.cn';
    private string $channel_id;
    private string $key;
    private string $platform_public_key;

    public function __construct(string $channel_id, string $key, string $platform_public_key)
    {
        $this->channel_id = $channel_id;
        $this->key = $key;
        $this->platform_public_key = $platform_public_key;
    }

    //发起API请求
    public function execute(string $path, array $params): array
    {
        $requrl = $this->gateway_url . $path;
        $params['format'] = 'JSON';
        $params['timestamp'] = date('YmdHis');
        $params['sign_type'] = 'MD5';
        $params['sign'] = $this->make_sign($params);
        $headers = [
            'channel_id: ' . $this->channel_id
        ];

        $response = get_curl($requrl, http_build_query($params), 0, 0, 0, 0, 0, $headers);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 1) {
            return $result['data'];
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    public function upload(string $file_path, string $file_name): array
    {
        $requrl = $this->gateway_url . '/api/upload/uploadFile';
        $params = [
            'img' => new \CURLFile($file_path, null, $file_name),
        ];

        $response = get_curl($requrl, $params);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 1) {
            return $result['data'];
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    private function make_sign(array $param): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $v !== null && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $this->key;
        $sign = strtoupper(md5($signstr));
        return $sign;
    }

    //平台公钥加密
    public function encrypt(string $data): string
    {
        $key = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($this->platform_public_key, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $publickey = openssl_get_publickey($key);
        if (!$publickey) {
            throw new Exception("加密失败，公钥错误");
        }
        openssl_public_encrypt($data, $crypttext, $publickey);
        return base64_encode($crypttext);
    }

    public function verify(array $data): bool
    {
        if (!isset($data['sign'])) {
            return false;
        }
        $sign = $this->make_sign($data);
        if ($sign === $data['sign']) {
            return true;
        }
        return false;
    }
}
