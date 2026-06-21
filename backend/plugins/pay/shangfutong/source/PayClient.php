<?php

declare(strict_types=1);

namespace plugins\payment\shangfutong;

/**
 * https://www.yuque.com/shouyinbei/grg57a
 */
class PayClient
{
    private string $gateway_url = 'https://pay.rscygroup.com';
    private string $app_id;
    private string $app_secret;

    public function __construct(string $app_id, string $app_secret)
    {
        $this->app_id = $app_id;
        $this->app_secret = $app_secret;
    }

    //发起请求
    public function execute(string $path, array $bizData): mixed
    {
        $requrl = $this->gateway_url . $path;
        $params = [
            'appId' => $this->app_id,
            'version' => '1.0',
            'reqId' => getSid(),
            'reqTime' => date('YmdHis'),
            'bizData' => json_encode($bizData),
            'signType' => 'MD5',
        ];
        $params['sign'] = $this->get_sign($params);

        $data = get_curl($requrl, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$data) throw new \Exception('接口请求失败');

        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == '000000') {
            if (!empty($result['bizData'])) {
                if (!$this->verify($result)) {
                    throw new \Exception('返回数据验签失败');
                }
                return json_decode($result['bizData'], true);
            } else {
                return true;
            }
        } else {
            throw new \Exception($result['msg'] ?? '返回数据解析失败');
        }
    }

    public function verify(array $params): bool
    {
        if (!isset($params['sign'])) return false;
        $sign = $this->get_sign($params);
        return $sign === $params['sign'];
    }

    private function get_sign(array $params): string
    {
        ksort($params);

        $signstr = '';
        foreach ($params as $k => $v) {
            if ($k != "sign" && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'appSecret=' . $this->app_secret;

        return md5($signstr);
    }
}
