<?php

declare(strict_types=1);

namespace plugins\payment\shouqianba;

/**
 * https://doc.shouqianba.com/
 */
class ShouqianbaClient
{
    private string $terminal_sn;
    private string $terminal_key;
    private string $operator = 'epay';
    private string $api_domain = 'https://vsi-api.shouqianba.com';

    public function __construct(string $terminal_sn, string $terminal_key)
    {
        $this->terminal_sn = $terminal_sn;
        $this->terminal_key = $terminal_key;
    }

    //激活接口
    public function activate(string $app_id, string $code, string $device_id): ?array
    {
        $apiurl = $this->api_domain . '/terminal/activate';

        $params = [
            'app_id' => $app_id,
            'code' => $code,
            'device_id' => $device_id,
        ];

        return $this->pre_do_execute($apiurl, $params);
    }

    //签到接口
    public function checkin(string $device_id): ?array
    {
        $apiurl = $this->api_domain . '/terminal/checkin';

        $params = [
            'terminal_sn' => $this->terminal_sn,
            'device_id' => $device_id,
        ];

        return $this->pre_do_execute($apiurl, $params);
    }

    //预下单接口
    public function precreate(array $params): ?array
    {
        $apiurl = $this->api_domain . '/upay/v2/precreate';
        $params['terminal_sn'] = $this->terminal_sn;
        $params['operator'] = $this->operator;
        return $this->pre_do_execute($apiurl, $params);
    }

    //支付接口
    public function pay(array $params): ?array
    {
        $apiurl = $this->api_domain . '/upay/v2/pay';
        $params['terminal_sn'] = $this->terminal_sn;
        $params['operator'] = $this->operator;
        return $this->pre_do_execute($apiurl, $params);
    }

    //退款接口
    public function refund(array $params): ?array
    {
        $apiurl = $this->api_domain . '/upay/v2/refund';
        $params['terminal_sn'] = $this->terminal_sn;
        $params['operator'] = $this->operator;
        return $this->pre_do_execute($apiurl, $params);
    }

    //查询接口
    public function query(array $params): ?array
    {
        $apiurl = $this->api_domain . '/upay/v2/query';
        $params['terminal_sn'] = $this->terminal_sn;
        return $this->pre_do_execute($apiurl, $params);
    }

    //wap api pro 接口
    public function wap_api_pro(array $params): string
    {
        $params['terminal_sn'] = $this->terminal_sn;
        $params['operator'] = $this->operator;

        $sign = $this->getSign($params);
        $params['sign'] = $sign;

        return "https://qr.shouqianba.com/gateway?" . http_build_query($params);
    }

    //公钥验签
    public static function verifySign(string $plainText, string $sign): bool
    {
        $publicKeyPath = dirname(__FILE__) . '/cert/publickey.pem';
        $publicKey = file_get_contents($publicKeyPath);
        $resource = openssl_pkey_get_public($publicKey);
        if ($resource === false) return false;
        $result = openssl_verify($plainText, base64_decode($sign), $resource, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    private function pre_do_execute(string $url, array $params): ?array
    {
        $j_params = json_encode($params);
        $sign = md5($j_params . $this->terminal_key);
        $result = $this->post($url, $j_params, $sign);
        if (!$result) throw new \Exception('接口请求失败');
        return json_decode($result, true);
    }

    private function post(string $url, string $body, string $sign): string
    {
        $header = [
            "Format: json",
            "Content-Type: application/json",
            "Authorization: " . $this->terminal_sn . " " . $sign
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $rst = curl_exec($ch);
        curl_close($ch);
        return $rst;
    }

    private function getSign(array $params): string
    {
        ksort($params);

        $param_str = "";
        foreach ($params as $k => $v) {
            if ($k == 'sign' || $k == 'sign_type') continue;
            $param_str .= $k . '=' . $v . '&';
        }

        return strtoupper(md5($param_str . 'key=' . $this->terminal_key));
    }
}
