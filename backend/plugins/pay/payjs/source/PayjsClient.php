<?php

declare(strict_types=1);

namespace plugins\payment\payjs;

class PayjsClient
{
    private string $key;
    private string $mchid;

    public function __construct(string $mchid, string $key)
    {
        $this->mchid = $mchid;
        $this->key = $key;
    }

    public function pay(array $data): array
    {
        $apiurl = 'https://payjs.cn/api/native';

        $data['mchid'] = $this->mchid;
        $data['sign'] = $this->sign($data);

        $return = $this->post($data, $apiurl);
        return json_decode($return, true);
    }

    public function mwebpay(array $data): array
    {
        $apiurl = 'https://payjs.cn/api/mweb';

        $data['mchid'] = $this->mchid;
        $data['sign'] = $this->sign($data);

        $return = $this->post($data, $apiurl);
        return json_decode($return, true);
    }

    public function cashier(array $data): string
    {
        $apiurl = 'https://payjs.cn/api/cashier';

        $data['mchid'] = $this->mchid;
        $data['sign'] = $this->sign($data);

        return $apiurl . '?' . http_build_query($data);
    }

    public function checkOrder(string $payjs_order_id): array
    {
        $apiurl = 'https://payjs.cn/api/check';

        $data['payjs_order_id'] = $payjs_order_id;
        $data['sign'] = $this->sign($data);

        $return = $this->post($data, $apiurl);
        return json_decode($return, true);
    }

    public function closeOrder(string $payjs_order_id): array
    {
        $apiurl = 'https://payjs.cn/api/close';

        $data['payjs_order_id'] = $payjs_order_id;
        $data['sign'] = $this->sign($data);

        $return = $this->post($data, $apiurl);
        return json_decode($return, true);
    }

    public function refund(string $payjs_order_id): array
    {
        $apiurl = 'https://payjs.cn/api/refund';

        $data['payjs_order_id'] = $payjs_order_id;
        $data['sign'] = $this->sign($data);

        $return = $this->post($data, $apiurl);
        return json_decode($return, true);
    }

    public function post(array $data, string $url): string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $rst = curl_exec($ch);
        curl_close($ch);
        if ($rst === false) throw new \Exception('接口请求失败');

        return $rst;
    }

    public function sign(array $attributes): string
    {
        ksort($attributes);
        $sign = strtoupper(md5(urldecode(http_build_query($attributes)) . '&key=' . $this->key));
        return $sign;
    }

    public function checkSign(array $arr): bool
    {
        $user_sign = $arr['sign'];
        unset($arr['sign']);
        array_filter($arr);
        ksort($arr);
        $check_sign = strtoupper(md5(urldecode(http_build_query($arr) . '&key=' . $this->key)));
        if ($user_sign != $check_sign) return false;
        else return true;
    }
}
