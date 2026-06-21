<?php

namespace plugins\payment\sumapay2;

use Exception;

class PayClient
{
    private string $key;
    private string $pluginRoot;

    public function __construct(string $key, string $pluginRoot)
    {
        $this->key = $key;
        $this->pluginRoot = $pluginRoot;
    }

    public function getSign(array $params, array $signKeys): string
    {
        $signstr = '';
        foreach ($signKeys as $key) {
            if (isset($params[$key])) {
                $signstr .= $params[$key];
            }
        }
        return hash_hmac('md5', $signstr, $this->key);
    }

    public function verify(array $params, array $signKeys): bool
    {
        if (!isset($params['resultSignature'])) return false;
        foreach ($params as $key => $value) {
            if ($value) $params[$key] = mb_convert_encoding($params[$key], 'UTF-8', 'GBK');
        }
        $signatrue = $this->getSign($params, $signKeys);
        return $signatrue === $params['resultSignature'];
    }

    public function verify2(array $params, array $signKeys): bool
    {
        if (!isset($params['signature'])) return false;
        foreach ($params as $key => $value) {
            if ($value) $params[$key] = mb_convert_encoding($params[$key], 'UTF-8', 'GBK');
        }
        $signatrue = $this->getSign($params, $signKeys);
        return $signatrue === $params['signature'];
    }

    public function execute(string $requrl, array $params, bool $file = false): array
    {
        foreach ($params as $key => $value) {
            if ($value && !$value instanceof \CURLFile) $params[$key] = mb_convert_encoding($params[$key], 'GBK', 'UTF-8');
        }
        $data = get_curl($requrl, $file ? $params : http_build_query($params));
        if (!$data) throw new Exception('接口请求失败');
        $data = mb_convert_encoding($data, 'UTF-8', 'GBK');
        $result = json_decode($data, true);
        if (isset($result['result']) && ($result['result'] == '00000' || $result['result'] == '00001')) {
            return $result;
        } elseif (isset($result['errorMsg'])) {
            throw new Exception($result['errorMsg']);
        } elseif (isset($result['failReason'])) {
            throw new Exception($result['failReason']);
        } elseif (isset($result['result'])) {
            $errcodePath = $this->pluginRoot . 'errcode.json';
            $errcode = json_decode(file_get_contents($errcodePath), true);
            if (isset($errcode[$result['result']])) {
                throw new Exception('[' . $result['result'] . ']' . $errcode[$result['result']]);
            }
            throw new Exception('result=' . $result['result']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }
}
