<?php

declare(strict_types=1);

namespace plugins\payment\cpcn;

use Exception;

class PayClient
{
    private string $mchId;
    private string $apiurl;
    private bool $isTest;

    public function __construct(string $mchId, string $apiurl, bool $isTest = false)
    {
        $this->mchId = $mchId;
        $this->apiurl = $apiurl;
        $this->isTest = $isTest;
    }

    //发起API请求
    public function payRequest(string $txcode, array $params): array
    {
        $requrl = $this->apiurl . 'pay.php';
        $params = [
            'txcode' => $txcode,
            'params' => json_encode($params),
            'test' => $this->isTest ? 1 : 0,
        ];
        $response = get_curl($requrl, http_build_query($params));
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 0) {
            return $result['data'];
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('中转API接口请求失败');
        }
    }

    //验签方法
    public function parseNotify(string $body, string $sign): array
    {
        if (empty($sign)) throw new Exception('no signature');
        $requrl = $this->apiurl . 'notify.php';
        $params = [
            'message' => $body,
            'signature' => $sign,
            'test' => $this->isTest ? 1 : 0,
        ];
        $response = get_curl($requrl, http_build_query($params));
        if (!$response) throw new Exception('接口请求失败');
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 0) {
            return $result['data'];
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('api error');
        }
    }

    //异步返回结果
    public function echoResult(bool $isSuccess = true, string $message = ''): string
    {
        if ($isSuccess) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><Response version="2.0"><Head><Code>2000</Code><Message>OK</Message></Head></Response>';
        } else {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><Response version="2.0"><Head><Code>2001</Code><Message>' . $message . '</Message></Head></Response>';
        }
        return base64_encode($xml);
    }
}
