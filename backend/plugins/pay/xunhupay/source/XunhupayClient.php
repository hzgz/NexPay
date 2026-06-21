<?php

declare(strict_types=1);

namespace plugins\payment\xunhupay;

use Exception;

class XunhupayClient
{
    private string $apiurl = 'https://api.xunhupay.com/payment/do.html';
    private string $appid;
    private string $appsecret;

    public function __construct(string $appid, string $appsecret, string $apiurl = '')
    {
        $this->appid = $appid;
        $this->appsecret = $appsecret;
        if (!empty($apiurl)) {
            $this->apiurl = $apiurl;
        }
    }

    //发起支付
    public function do_payment(array $params): array
    {
        return $this->execute($this->apiurl, $params);
    }

    //查询订单
    public function query_payment(array $params): array
    {
        $url = str_replace('/payment/do.html', '/payment/query.html', $this->apiurl);
        return $this->execute($url, $params);
    }

    //退款订单
    public function do_refund(array $params): array
    {
        $url = str_replace('/payment/do.html', '/payment/refund.html', $this->apiurl);
        return $this->execute($url, $params);
    }

    //发起通用请求
    public function execute(string $url, array $params): array
    {
        $publicParams = [
            'appid' => $this->appid,
            'time' => time(),
            'nonce_str' => str_shuffle((string) time())
        ];
        $params = array_merge($publicParams, $params);
        $params['hash'] = $this->generate_hash($params, $this->appsecret);
        $response = $this->curl_post($url, json_encode($params));
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['errcode']) && $result['errcode'] == 0) {
            if (isset($result['hash'])) {
                $hash = $this->generate_hash($result, $this->appsecret);
                if ($hash !== $result['hash']) {
                    throw new Exception('返回数据签名校验失败');
                }
            }
            return $result;
        } else {
            throw new Exception($result['errmsg'] ? $result['errmsg'] : '返回数据解析失败');
        }
    }

    //二维码图片链接解析二维码链接
    public function parseQrcode(string $url_qrcode): string
    {
        $redirect_url = $this->get_redirect_url($url_qrcode);
        if ($redirect_url) {
            $url = getSubstr($redirect_url, 'data=', '&');
            if ($url) {
                return base64_decode($url);
            }
        } else {
            $url = getSubstr($url_qrcode, 'data=', '&');
            if ($url) {
                return base64_decode($url);
            }
        }
        throw new Exception('获取二维码链接失败');
    }

    public function verify(array $arr): bool
    {
        if (!isset($arr['hash'])) return false;
        $hash = $this->generate_hash($arr, $this->appsecret);
        return $hash === $arr['hash'];
    }

    private function curl_post(string $url, string $post, int $timeout = 10): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        $httpheader[] = "Content-Type: application/json; charset=utf-8";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    private function get_redirect_url(string $url, int $timeout = 10): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return $redirect_url ?: '';
    }

    private function generate_hash(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "hash" && $v !== '' && !is_null($v)) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        return md5($signstr . $key);
    }
}
