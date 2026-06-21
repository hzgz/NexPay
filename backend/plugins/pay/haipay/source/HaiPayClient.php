<?php

/**
 * http://opendocs.hkrt.cn:8181/docs/saas
 */

declare(strict_types=1);

namespace plugins\payment\haipay;

class HaiPayClient
{
    private string $pay_url = 'https://saas-front.hkrt.cn';
    private string $mch_url = 'http://saas.hkrt.cn:8080';

    private string $accessid;

    private string $accesskey;

    public function __construct(string $accessid, string $accesskey, bool $isTest = false)
    {
        $this->accessid = $accessid;
        $this->accesskey = $accesskey;
        if ($isTest) {
            $this->pay_url = 'http://39.106.187.68:8080';
            $this->mch_url = 'http://47.95.131.62:8080';
        }
    }

    //发起支付请求
    public function payRequest(string $path, array $params): array
    {
        $requrl = $this->pay_url . $path;
        $params['accessid'] = $this->accessid;
        $params['req_id'] = date('YmdHis') . rand(1000, 9999);
        $params['sign'] = $this->make_sign($params);

        $data = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$data) throw new \Exception('接口请求失败');

        $result = json_decode($data, true);

        if (isset($result['result_code']) && $result['result_code'] == 10000) {
            return $result;
        } elseif (!empty($result['result_msg'])) {
            throw new \Exception($result['result_msg']);
        } else {
            throw new \Exception($result['return_msg'] ?? '返回数据解析失败');
        }
    }

    //发起进件请求
    public function mchRequest(string $path, array $params): array
    {
        $requrl = $this->mch_url . $path;
        $params['accessid'] = $this->accessid;
        $params['sign'] = $this->make_sign($params);

        $data = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$data) throw new \Exception('接口请求失败');
        $result = json_decode($data, true);

        if (isset($result['return_code']) && $result['return_code'] == 10000) {
            return $result;
        } elseif (!empty($result['result_msg'])) {
            throw new \Exception($result['result_msg']);
        } else {
            throw new \Exception($result['return_msg'] ?? '返回数据解析失败');
        }
    }

    public function mchRequest2(string $path, array $params): ?array
    {
        $requrl = $this->mch_url . $path;
        $params['accessid'] = $this->accessid;
        $params['sign'] = $this->make_sign($params);

        $data = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$data) throw new \Exception('接口请求失败');
        return json_decode($data, true);
    }

    public function verify(array $params): bool
    {
        if (!isset($params['sign'])) return false;
        $sign = $this->make_sign($params);
        return $sign === $params['sign'];
    }

    private function get_sign_str($param): string
    {
        if (!is_array($param)) return (string)$param;
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $v !== null && $v !== '') {
                if (is_array($v)) {
                    if (empty($v)) continue;
                    if (array_keys($v) === range(0, count($v) - 1)) {
                        $strs = [];
                        foreach ($v as $vv) {
                            $strs[] = $this->get_sign_str($vv);
                        }
                        $v = implode('&', $strs);
                    } else {
                        $v = $this->get_sign_str($v);
                    }
                }
                $signstr .= $k . '=' . $v . '&';
            }
        }
        return substr($signstr, 0, -1);
    }

    private function make_sign(array $param): string
    {
        $signstr = $this->get_sign_str($param);
        $signstr .= $this->accesskey;
        return strtoupper(md5($signstr));
    }
}
