<?php

declare(strict_types=1);

namespace plugins\payment\yinyingtong;

use think\facade\Cache;
use Exception;

/**
 * @see https://ecn6ul7ztz1a.feishu.cn/docx/F9WxdXYw0oFwxoxAJ8EcpQz9nab
 */
class GcoinClient
{
    private static string $gateway_url = 'https://gpay-gcoin-web.gomepay.com';
    private ?string $access_token = null;
    private string $client_id;
    private string $client_secret;

    public function __construct(string $client_id, string $client_secret)
    {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
    }

    public function execute(string $path, array $params, ?array $biz_params = null, bool $auth = true): array
    {
        $clientip = request()->clientip;
        $req_no = date('YmdHis') . rand(1000, 9999);
        $timestamp = getMillisecond();
        $public_params = [
            'req_no' => $req_no,
            'user_id' => '000000',
            'timestamp' => $timestamp,
            'app_version' => '1.0.0',
            'device_id' => '1000001',
            'device' => ['lo' => '', 'la' => '', 'ip' => $clientip],
        ];
        if ($auth) {
            $public_params['authorization'] = 'bearer ' . $this->getAccessToken();
        }
        $params = array_merge($public_params, $params);
        if ($biz_params) {
            $json = json_encode($biz_params, JSON_UNESCAPED_SLASHES);
            $enc_data = $this->encrypt($json, $req_no, $timestamp);
            $params['encdata_str'] = $enc_data;
        }
        $params['signature'] = $this->generateSign($params);
        $data = json_encode($params, JSON_UNESCAPED_SLASHES);
        $response = get_curl(self::$gateway_url . $path, $data, 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['op_ret_code']) && $result['op_ret_code'] == '000') {
            if ($result['sub_ret_code'] == '000000') {
                if (isset($result['encdata_str'])) {
                    $dec_data = $this->decrypt($result['encdata_str'], $req_no, $timestamp);
                    $result['data'] = json_decode($dec_data, true);
                }
                return $result;
            } else {
                throw new Exception('[' . $result['sub_ret_code'] . ']' . $result['sub_ret_msg']);
            }
        } elseif (isset($result['sub_ret_code'])) {
            throw new Exception('[' . $result['sub_ret_code'] . ']' . $result['sub_ret_msg']);
        } elseif (isset($result['op_err_msg'])) {
            throw new Exception($result['op_err_msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    public function getAccessToken(): string
    {
        if ($this->access_token) {
            return $this->access_token;
        }
        $result = Cache::get('yyt_gcoin_token');
        if (is_array($result) && isset($result['access_token']) && isset($result['expires_in']) && $result['expires_in'] > time() + 200) {
            $this->access_token = $result['access_token'];
            return $this->access_token;
        }
        $path = '/gcoin/oauth/getToken';
        $params = [
            'invoke_source' => '00',
        ];
        $biz_params = [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'password',
        ];
        try {
            $result = $this->execute($path, $params, $biz_params, false);
        } catch (Exception $e) {
            throw new Exception('获取访问令牌失败: ' . $e->getMessage());
        }
        if ($result['sub_ret_code'] == '000000') {
            $data = [
                'access_token' => $result['access_token'],
                'expires_in' => intval(substr($result['expires_in'], 0, 10)),
            ];
            Cache::set('yyt_gcoin_token', $data);
            $this->access_token = $result['access_token'];
            return $this->access_token;
        } else {
            throw new Exception('获取访问令牌失败[' . $result['op_ret_subcode'] . ']' . $result['op_err_submsg']);
        }
    }

    private function encrypt(string $data, string $req_no, string $timestamp): string
    {
        $md5str = md5($req_no . $timestamp);
        $key = substr($md5str, 0, 16);
        $iv = substr($md5str, 16, 16);
        $sm4 = new \Rtgm\sm\RtSm4($key);
        $enc_data = $sm4->encrypt($data, 'sm4-cbc', $iv);
        return strtoupper($enc_data);
    }

    private function decrypt(string $data, string $req_no, string $timestamp): string
    {
        $md5str = md5($req_no . $timestamp);
        $key = substr($md5str, 0, 16);
        $iv = substr($md5str, 16, 16);
        $sm4 = new \Rtgm\sm\RtSm4($key);
        return $sm4->decrypt($data, 'sm4-cbc', $iv);
    }

    private function verifySign(array $params): bool
    {
        if (!isset($params['signature'])) return false;
        $sign = $params['signature'];
        $generatedSign = $this->generateSign($params);
        return $sign === $generatedSign;
    }

    private function generateSign(array $params): string
    {
        ksort($params);
        $signStr = '';
        foreach ($params as $key => $value) {
            if ($key != 'signature' && $value !== null && !is_array($value)) {
                $signStr .= $this->percentEncode($key) . '=' . $this->percentEncode((string)$value) . '&';
            }
        }
        $signStr = $this->percentEncode(substr($signStr, 0, -1));
        return hash('sha256', $signStr);
    }

    private function percentEncode(string $str): string
    {
        $search = ['+', '*', '%7E'];
        $replace = ['%20', '%2A', '~'];
        return str_replace($search, $replace, urlencode($str));
    }
}
