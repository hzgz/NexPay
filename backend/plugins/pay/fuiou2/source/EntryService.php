<?php

declare(strict_types=1);

namespace plugins\payment\fuiou2;

class EntryService
{
    private string $ins_cd;
    private string $key;
    private \XmlWriter $xml;
    private string $gateway_url = 'https://mchntapi.fuioupay.com';

    public function __construct(string $ins_cd, string $key, bool $is_test = false)
    {
        $this->ins_cd = $ins_cd;
        $this->key = $key;
        if ($is_test) {
            $this->gateway_url = 'http://www-1.fuiou.com:28090/wmp';
        }
        $this->xml = new \XmlWriter();
    }

    //发起API请求
    public function submit(string $path, array $params, bool $is_file = false, bool $is_download = false)
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'trace_no' => getSid(),
            'ins_cd' => $this->ins_cd,
        ];

        foreach ($params as $key => $value) {
            if (!$is_file && $value) $params[$key] = mb_convert_encoding((string) $params[$key], 'GBK', 'UTF-8');
        }

        $params = array_merge($public_params, $params);
        $params['sign'] = $this->makeSign($params);

        if ($is_file) {
            $response = get_curl($requrl, $params);
        } else {
            $xml = "<?xml version=\"1.0\" encoding=\"GBK\" standalone=\"yes\"?><xml>" . $this->toXml($params) . "</xml>";
            $response = get_curl($requrl, 'req=' . urlencode(urlencode($xml)));
        }
        if (!$response) throw new \Exception('接口请求失败');
        if ($is_download) return $response;
        if (strpos($response, '%3Cxml%3E') !== false) {
            $response = urldecode($response);
        }
        $response = str_ireplace('encoding="gbk"', 'encoding="utf-8"', $response);
        try {
            $result = json_decode(json_encode(simplexml_load_string($response)), true);
        } catch (\Exception) {
            $result = null;
        }
        if (empty($result)) {
            $response = mb_convert_encoding($response, 'UTF-8', 'GBK');
            $result = json_decode(json_encode(simplexml_load_string($response)), true);
        }

        if (isset($result['ret_code']) && ($result['ret_code'] == '000000' || $result['ret_code'] == '0000')) {
            return $result;
        } elseif (isset($result['return_code']) && $result['return_code'] == 'SUCCESS') {
            return $result;
        } elseif (isset($result['ret_msg'])) {
            throw new \Exception($result['ret_msg']);
        } elseif (isset($result['return_msg'])) {
            throw new \Exception($result['return_msg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //发起API请求（JSON格式）
    public function submit_json(string $path, array $params, bool $is_file = false): array
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'trace_no' => getSid(),
            'ins_cd' => $this->ins_cd,
        ];

        foreach ($params as $key => $value) {
            if (!$is_file && !is_array($value) && !isNullOrEmpty($value)) $params[$key] = mb_convert_encoding((string) $params[$key], 'GBK', 'UTF-8');
        }

        $params = array_merge($public_params, $params);
        $params['sign'] = $this->makeSign($params);

        if ($is_file) {
            $response = get_curl($requrl, $params);
        } else {
            $json = json_encode($params);
            $response = get_curl($requrl, 'req=' . urlencode($json));
        }
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);

        if (isset($result['ret_code']) && ($result['ret_code'] == '000000' || $result['ret_code'] == '0000')) {
            return $result;
        } elseif (isset($result['ret_msg'])) {
            throw new \Exception($result['ret_msg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    //发起API请求（JSON v2格式）
    public function submit_json_v2(string $path, array $params): array
    {
        $requrl = $this->gateway_url . $path;
        $public_params = [
            'traceNo' => getSid(),
            'insCd' => $this->ins_cd,
        ];

        foreach ($params as $key => $value) {
            if (!is_array($value) && !isNullOrEmpty($value)) $params[$key] = mb_convert_encoding((string) $params[$key], 'GBK', 'UTF-8');
        }

        $params = array_merge($public_params, $params);
        $params['sign'] = $this->makeSign($params);

        $json = json_encode($params);
        $response = get_curl($requrl, 'req=' . urlencode($json));
        if (!$response) throw new \Exception('接口请求失败');
        $result = json_decode($response, true);

        if (isset($result['retCode']) && $result['retCode'] == '0000') {
            return $result;
        } elseif (isset($result['retMsg'])) {
            throw new \Exception($result['retMsg']);
        } else {
            throw new \Exception('返回数据解析失败');
        }
    }

    public function verifySign(array $param): bool
    {
        $sign = $param['sign'] ?? '';
        if (!$sign) return false;
        return $sign === $this->makeSign($param);
    }

    private function makeSign(array $param): string
    {
        ksort($param);
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k != "sign" && !$v instanceof \CURLFile && !is_array($v) && !$this->isEmpty($v)) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr .= 'key=' . $this->key;
        return md5($signstr);
    }

    private function isEmpty($value): bool
    {
        return $value === null || $value === '';
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
