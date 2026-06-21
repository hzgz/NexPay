<?php

declare(strict_types=1);

namespace plugins\payment\fuiou2;

/**
 * https://fundwx.fuiou.com/doc/#/
 */
class PayService
{
    private string $version = '1.0';
    private string $ins_cd;
    private string $mchnt_cd;
    private string $platform_public_key;
    private string $merchant_private_key;
    private \XmlWriter $xml;
    private string $gateway_url = 'https://spay-mc.fuioupay.com';

    public function __construct(string $ins_cd, string $mchnt_cd, string $platform_public_key, string $merchant_private_key, bool $is_test = false)
    {
        $this->ins_cd = $ins_cd;
        $this->mchnt_cd = $mchnt_cd;
        $this->platform_public_key = $platform_public_key;
        $this->merchant_private_key = $merchant_private_key;
        if ($is_test) {
            $this->gateway_url = 'https://fundwx.fuiou.com';
        }
        $this->xml = new \XmlWriter();
    }

    //发起API请求
    public function submit(string $path, array $params): array
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'version' => $this->version,
            'ins_cd' => $this->ins_cd,
            'mchnt_cd' => $this->mchnt_cd,
            'term_id' => '88888888',
            'random_str' => getSid(),
        ];

        foreach ($params as $key => $value) {
            if ($value) $params[$key] = mb_convert_encoding((string) $params[$key], 'GBK', 'UTF-8');
        }

        $params = array_merge($public_params, $params);
        $params['sign'] = $this->generateSign($params);

        $xml = "<?xml version=\"1.0\" encoding=\"GBK\" standalone=\"yes\"?><xml>" . $this->toXml($params) . "</xml>";

        $response = get_curl($requrl, 'req=' . urlencode(urlencode($xml)));
        if (!$response) throw new \Exception('接口请求失败');
        $response = urldecode($response);
        $result = json_decode(json_encode(simplexml_load_string($response)), true);

        if (isset($result['result_code']) && ($result['result_code'] == '000000' || $result['result_code'] == '030010')) {
            if (!$this->verifyResponse($result)) {
                throw new \Exception('返回数据验签失败');
            }
            return $result;
        } elseif (isset($result['result_msg'])) {
            throw new \Exception($result['result_msg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //发起API请求（JSON格式）
    public function request(string $path, array $params): array
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'version' => $this->version,
            'ins_cd' => $this->ins_cd,
            'mchnt_cd' => $this->mchnt_cd,
            'term_id' => '88888888',
            'random_str' => getSid(),
        ];

        foreach ($params as $key => $value) {
            if ($value) $params[$key] = mb_convert_encoding((string) $params[$key], 'GBK', 'UTF-8');
        }

        $params = array_merge($public_params, $params);
        $params['sign'] = $this->generateSign($params);

        $xml = "<?xml version=\"1.0\" encoding=\"GBK\" standalone=\"yes\"?><xml>" . $this->toXml($params) . "</xml>";

        $response = get_curl($requrl, 'req=' . urlencode(urlencode($xml)));
        if (!$response) throw new \Exception('接口请求失败');
        $response = urldecode($response);
        $result = json_decode(json_encode(simplexml_load_string($response)), true);

        if (isset($result['result_code']) && ($result['result_code'] == '000000' || $result['result_code'] == '030010')) {
            return $result;
        } elseif (isset($result['result_msg'])) {
            throw new \Exception($result['result_msg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    private function getSignContent(array $param): string
    {
        ksort($param);
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k != "sign" && substr($k, 0, 8) != 'reserved') {
                if (is_array($v)) $v = '';
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        return $signstr;
    }

    private function generateSign(array $param): string
    {
        return $this->rsaPrivateSign($this->getSignContent($param));
    }

    private function verifyResponse(array $param): bool
    {
        if (empty($param['sign'])) return false;
        foreach ($param as $key => $value) {
            if (is_array($value)) $param[$key] = '';
            else $param[$key] = mb_convert_encoding((string) $param[$key], 'GBK', 'UTF-8');
        }
        return $this->verifySign($param);
    }

    public function verifySign(array $param): bool
    {
        if (empty($param['sign'])) return false;
        return $this->rsaPubilcSign($this->getSignContent($param), $param['sign']);
    }

    private function rsaPrivateSign(string $data): string
    {
        $priKey = str_replace(["\r\n", "\r", "\n"], "", $this->merchant_private_key);
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($priKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        $pkeyid = openssl_pkey_get_private($res);
        if (!$pkeyid) {
            throw new \Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_MD5);
        return base64_encode($signature);
    }

    private function rsaPubilcSign(string $data, string $signature): bool
    {
        $pubKey = str_replace(["\r\n", "\r", "\n"], "", $this->platform_public_key);
        $res = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($pubKey, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $pubkeyid = openssl_pkey_get_public($res);
        if (!$pubkeyid) {
            throw new \Exception('验签失败，富友公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $pubkeyid, OPENSSL_ALGO_MD5);
        return $result === 1;
    }

    private function toXml(array $data, bool $eIsArray = false): string
    {
        if (!$eIsArray) {
            $this->xml->openMemory();
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->xml->startElement($key);
                $this->toXml($value, true);
                $this->xml->endElement();
                continue;
            }
            $this->xml->writeElement($key, (string)$value);
        }
        if (!$eIsArray) {
            $this->xml->endElement();
            return $this->xml->outputMemory(true);
        }
        return '';
    }
}
