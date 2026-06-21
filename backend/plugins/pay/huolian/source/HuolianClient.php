<?php

declare(strict_types=1);

namespace plugins\payment\huolian;

use Exception;

/**
 * 火脸支付API客户端
 * @see https://www.yuque.com/youyun-8yqqt/vpbgq7
 */
class HuolianClient
{
    //支付接口地址
    private string $gateway_url = 'https://open.lianok.com/open/v1/api/biz/do';

    //上传文件接口地址
    private string $upload_url = 'https://entry.lianok.com/openapi/v2/api/biz/file';

    //支付接口地址V2
    private string $gateway_v2_url = 'https://entry.lianok.com/openapi/v2/api/biz/do';

    //对接商授权编号
    private string $authCode;

    //对接商MD5加密盐
    private string $salt;

    public function __construct(string $authCode, string $salt)
    {
        $this->authCode = $authCode;
        $this->salt = $salt;
    }

    //发起请求
    public function execute(string $resource, array $params): mixed
    {
        $params = array_filter($params, function ($v) {
            return $v !== null;
        });
        $commonData = [
            'authCode' => $this->authCode,
            'requestTime' => date('YmdHis'),
            'resource' => $resource,
            'versionNo' => '1'
        ];
        $commonData['sign'] = $this->make_sign(array_merge($commonData, $params));
        $commonData['params'] = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $data = get_curl($this->gateway_url, json_encode($commonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$data) throw new Exception('接口请求失败');

        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 0 && $result['status'] == 200) {
            return $result['data'];
        } else {
            throw new Exception($result['message'] ?? '返回数据解析失败');
        }
    }

    //上传文件
    public function upload(string $resource, string $file_path, string $file_name, string $file_tag = 'common'): array
    {
        $params = [
            'authCode' => $this->authCode,
            'requestTime' => date('YmdHis'),
            'resource' => $resource,
        ];
        $params['sign'] = $this->make_sign($params);
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $mime_type = self::mime_content_type($file_ext);
        if (empty($mime_type)) $mime_type = 'image/jpeg';
        $params['file'] = new \CURLFile($file_path, $mime_type, $file_name);
        $params['fileTag'] = $file_tag;

        $data = get_curl($this->upload_url, $params);
        if (!$data) throw new Exception('接口请求失败');
        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 0) {
            return $result['data'];
        } else {
            throw new Exception($result['message'] ?? '返回数据解析失败');
        }
    }

    //发起请求
    public function execute_v2(string $resource, array $params): mixed
    {
        $params = array_filter($params, function ($v) {
            return $v !== null;
        });
        $commonData = [
            'authCode' => $this->authCode,
            'requestTime' => date('YmdHis'),
            'resource' => $resource,
            'params' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        $commonData['sign'] = $this->make_sign($commonData);

        $data = get_curl($this->gateway_v2_url, json_encode($commonData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=utf-8']);
        if (!$data) throw new Exception('接口请求失败');

        $result = json_decode($data, true);

        if (isset($result['code']) && $result['code'] == 0 && $result['status'] == 200) {
            return $result['data'];
        } else {
            throw new Exception($result['message'] ?? '返回数据解析失败');
        }
    }

    private static function mime_content_type(string $ext): string
    {
        $mime_types = [
            'png' => 'image/png',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
        ];
        return $mime_types[$ext] ?? '';
    }

    public function verify(array $param): bool
    {
        if (!isset($param['sign'])) return false;
        unset($param['code']);
        unset($param['message']);
        $sign = $this->make_sign($param);
        return $sign === $param['sign'];
    }

    private function make_sign(array $param): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $v !== null) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = strtolower($signstr);
        $signstr .= $this->salt;
        $sign = md5($signstr);
        return $sign;
    }
}
