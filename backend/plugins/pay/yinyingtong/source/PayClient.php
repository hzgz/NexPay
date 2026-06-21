<?php

declare(strict_types=1);

namespace plugins\payment\yinyingtong;

use Exception;

/**
 * https://ecn6ul7ztz1a.feishu.cn/docx/NdZndQqRVou9XRxmK8Vcn07UnSf
 * https://ecn6ul7ztz1a.feishu.cn/docx/XqI7dQ7jioFdrRxxjXdcA9QDnMg
 * https://ecn6ul7ztz1a.feishu.cn/docx/CaC9dkAN3oqbOuxVJYXcApsgnqd
 */
class PayClient
{
    //支付网关地址
    private string $gateway_url = 'https://gc-gw.gomepay.com/gpayCashApi';

    //应用APPID
    private string $app_id;

    //应用密钥
    private string $app_secret;

    //浏览器标识
    private string $browser_brand = '99';

    //终端类型
    private string $terminal_type = '3';

    public function __construct(string $app_id, string $app_secret)
    {
        $this->app_id = $app_id;
        $this->app_secret = $app_secret;
    }

    /**
     * 根据设备信息设置浏览器标识和终端类型
     */
    public function setDeviceInfo(bool $isMobile, string $mdevice): void
    {
        if ($mdevice === 'alipay') {
            $this->browser_brand = '01';
        } elseif ($mdevice === 'wechat') {
            $this->browser_brand = '02';
        } elseif ($isMobile) {
            $this->browser_brand = '04';
        } else {
            $this->browser_brand = '99';
        }
        $this->terminal_type = $isMobile ? '4' : '3';
    }

    //发起请求
    public function execute(string $method, array $bizData): array
    {
        $params = [
            'app_id' => $this->app_id,
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'UTF-8',
            'sign_type' => 'MD5',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'client_ip' => real_ip(),
            'data' => json_encode($bizData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'req_no' => getSid(),
            'terminal_type' => $this->terminal_type,
            'browser_brand' => $this->browser_brand,
        ];
        $params['sign'] = $this->get_sign($params);

        $data = get_curl($this->gateway_url, json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 0, 0, 0, 0, ['method: cash-api@' . $method, 'Content-Type: application/json; charset=utf-8']);
        if (!$data) throw new Exception('接口请求失败');

        $result = json_decode($data, true);

        if (isset($result['code']) && ($result['code'] == '000000' || $result['code'] == '900888' || $result['code'] == '900889' || $result['code'] == '900001')) {
            return json_decode($result['data'], true);
        } elseif (isset($result['sub_msg'])) {
            throw new Exception('[' . $result['sub_code'] . ']' . $result['sub_msg']);
        } elseif (isset($result['data']) && strpos($result['data'], 'op_ret_code') !== false) {
            $result = json_decode($result['data'], true);
            if (isset($result['op_ret_code']) && ($result['op_ret_code'] == '000' || $result['op_ret_code'] == '701')) {
                return $result;
            } elseif (isset($result['op_ret_subcode'])) {
                throw new Exception('[' . $result['op_ret_subcode'] . ']' . $result['op_err_submsg']);
            } else {
                throw new Exception('[' . $result['op_ret_code'] . ']' . $result['op_ret_msg']);
            }
        } else {
            throw new Exception($result['msg'] ?? '返回数据解析失败');
        }
    }

    public function verify(array $params): bool
    {
        if (!isset($params['sign'])) return false;

        $sign = $this->get_verify_sign($params);

        return $sign === $params['sign'];
    }

    private function get_verify_sign(array $params): string
    {
        ksort($params);

        $signstr = '';
        foreach ($params as $k => $v) {
            if ($k != "sign" && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $this->app_secret;

        return strtoupper(md5($signstr));
    }

    private function get_sign(array $params): string
    {
        $sign_keys = ['req_no', 'app_id', 'sign_type', 'charset', 'format', 'version', 'data', 'timestamp', 'method'];
        ksort($params);

        $signstr = '';
        foreach ($params as $k => $v) {
            if (in_array($k, $sign_keys) && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $this->app_secret;

        return strtoupper(md5($signstr));
    }

    public function notify(string $data, string $key): array|false
    {
        $data = trim(substr($data, 107));
        $len = trim(substr($data, 0, 4));
        $cipher = trim(substr($data, 4));
        $dec_data = self::desDecrypt($cipher, $key);
        if (!$dec_data || strpos($dec_data, "\04\04\04\04") === false) return false;
        $json = explode("\04\04\04\04", $dec_data)[1];
        return json_decode(trim($json), true);
    }

    //DES 加密
    public static function desEncrypt(string $str, string $key): string
    {
        $key = substr($key, 0, 8);
        $encrypted = openssl_encrypt($str, 'des-ede3-ecb', $key, OPENSSL_RAW_DATA);
        return strtoupper(bin2hex($encrypted));
    }

    //DES 解密
    public static function desDecrypt(string $str, string $key): string|false
    {
        $decrypted = openssl_decrypt(hex2bin($str), 'des-ede3-ecb', $key, OPENSSL_RAW_DATA);
        if ($decrypted === false) return false;
        return rtrim($decrypted, "\0");
    }
}
