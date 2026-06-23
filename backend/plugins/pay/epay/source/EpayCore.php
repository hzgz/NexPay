<?php
/* *
 * 彩虹易支付SDK服务类
 * 说明：
 * 包含发起支付、查询订单、回调验证等功能
 */

declare(strict_types=1);

namespace plugins\payment\epay;

class EpayCore
{
    private string $pid;
    private string $key;
    private string $submit_url;
    private string $mapi_url;
    private string $api_url;
    private string $sign_type = 'MD5';

    public function __construct(array $config)
    {
        $this->pid = $config['pid'];
        $this->key = $config['key'];
        $baseUrl = $this->normalizeApiBaseUrl((string)($config['apiurl'] ?? ''));
        $this->submit_url = $baseUrl . 'submit.php';
        $this->mapi_url = $baseUrl . 'mapi.php';
        $this->api_url = $baseUrl . 'api.php';
    }

    // 发起支付（页面跳转）
    public function pagePay(array $param_tmp, string $button = '正在跳转'): string
    {
        $param = $this->buildRequestParam($param_tmp);

        $html = '<form id="dopay" action="' . $this->submit_url . '" method="post">';
        foreach ($param as $k => $v) {
            $html .= '<input type="hidden" name="' . $k . '" value="' . $v . '"/>';
        }
        $html .= '<input type="submit" value="' . $button . '"></form><script>document.getElementById("dopay").submit();</script>';

        return $html;
    }

    // 发起支付（获取链接）
    public function getPayLink(array $param_tmp): string
    {
        $param = $this->buildRequestParam($param_tmp);
        return $this->submit_url . '?' . http_build_query($param);
    }

    // 发起支付（API接口）
    public function apiPay(array $param_tmp): ?array
    {
        $param = $this->buildRequestParam($param_tmp);
        $response = $this->getHttpResponse($this->mapi_url, http_build_query($param));
        if (!$response) throw new \Exception('接口请求失败');
        return json_decode($response, true);
    }

    // 回调验证
    public function verify(array $params): bool
    {
        if (empty($params)) return false;

        $sign = $this->getSign($params);

        return $sign === ($params['sign'] ?? '');
    }

    // 查询订单支付状态
    public function orderStatus(string $trade_no): bool
    {
        $result = $this->queryOrder($trade_no);
        return isset($result['status']) && $result['status'] == 1;
    }

    // 查询订单
    public function queryOrder(string $trade_no): ?array
    {
        $url = $this->api_url . '?act=order&pid=' . $this->pid . '&key=' . $this->key . '&trade_no=' . $trade_no;
        $response = $this->getHttpResponse($url);
        if (!$response) throw new \Exception('接口请求失败');
        return json_decode($response, true);
    }

    // 查询订单
    public function queryOrderByOutTradeNo(string $out_trade_no): ?array
    {
        $url = $this->api_url . '?act=order&pid=' . $this->pid . '&key=' . $this->key . '&out_trade_no=' . $out_trade_no;
        $response = $this->getHttpResponse($url);
        if (!$response) throw new \Exception('接口请求失败');
        return json_decode($response, true);
    }

    // 订单退款
    public function refund(string $refund_no, string $trade_no, $money): ?array
    {
        $url = $this->api_url . '?act=refund';
        $post = 'pid=' . $this->pid . '&key=' . $this->key . '&refund_no=' . $refund_no . '&trade_no=' . $trade_no . '&money=' . $money;
        $response = $this->getHttpResponse($url, $post);
        if (!$response) throw new \Exception('接口请求失败');
        return json_decode($response, true);
    }

    private function buildRequestParam(array $param): array
    {
        $mysign = $this->getSign($param);
        $param['sign'] = $mysign;
        $param['sign_type'] = $this->sign_type;
        return $param;
    }

    // 计算签名
    private function getSign(array $param): string
    {
        ksort($param);
        reset($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $k != "sign_type" && $v !== '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        $signstr .= $this->key;
        return md5($signstr);
    }

    // 请求外部资源
    private function getHttpResponse(string $url, $post = false, int $timeout = 10): string|bool
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
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    private function normalizeApiBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/') . '/';
    }
}
