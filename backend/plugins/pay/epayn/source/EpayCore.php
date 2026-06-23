<?php
/* *
 * 彩虹易支付SDK服务类
 * 说明：
 * 包含发起支付、查询订单、回调验证等功能
 */

declare(strict_types=1);

namespace plugins\payment\epayn;

class EpayCore
{
    private string $apiurl;
    private string $pid;
    private string $platform_public_key;
    private string $merchant_private_key;

    private string $sign_type = 'RSA';

    public function __construct(array $config)
    {
        $this->apiurl = $this->normalizeApiBaseUrl((string)($config['apiurl'] ?? ''));
        $this->pid = $config['pid'];
        $this->platform_public_key = $config['platform_public_key'];
        $this->merchant_private_key = $config['merchant_private_key'];
    }

    // 发起支付（页面跳转）
    public function pagePay(array $param_tmp, string $button = '正在跳转'): string
    {
        $requrl = $this->apiurl . 'api/pay/submit';
        $param = $this->buildRequestParam($param_tmp);

        $html = '<form id="dopay" action="' . $requrl . '" method="post">';
        foreach ($param as $k => $v) {
            $html .= '<input type="hidden" name="' . $k . '" value="' . $v . '"/>';
        }
        $html .= '<input type="submit" value="' . $button . '"></form><script>document.getElementById("dopay").submit();</script>';

        return $html;
    }

    // 发起支付（获取链接）
    public function getPayLink(array $param_tmp): string
    {
        $requrl = $this->apiurl . 'api/pay/submit';
        $param = $this->buildRequestParam($param_tmp);
        return $requrl . '?' . http_build_query($param);
    }

    // 发起支付（API接口）
    public function apiPay(array $params): array
    {
        return $this->execute('api/pay/create', $params);
    }

    // 发起API请求
    public function execute(string $path, array $params): array
    {
        $path = ltrim($path, '/');
        $requrl = $this->apiurl . $path;
        $param = $this->buildRequestParam($params);
        $isMultipart = false;
        foreach ($param as $v) {
            if ($v instanceof \CURLFile) {
                $isMultipart = true;
                break;
            }
        }
        $response = $this->getHttpResponse($requrl, $isMultipart ? $param : http_build_query($param));
        if (!$response) throw new \Exception('接口请求失败');
        $arr = json_decode($response, true);
        if ($arr && $arr['code'] == 0) {
            if (!$this->verify($arr)) {
                throw new \Exception('返回数据验签失败');
            }
            return $arr;
        } else {
            throw new \Exception($arr ? $arr['msg'] : '请求失败');
        }
    }

    // 回调验证
    public function verify(array $arr): bool
    {
        if (empty($arr) || empty($arr['sign'])) return false;

        if (empty($arr['timestamp']) || abs(time() - $arr['timestamp']) > 300) return false;

        $sign = $arr['sign'];

        return $this->rsaPublicVerify($this->getSignContent($arr), $sign);
    }

    // 查询订单支付状态
    public function orderStatus(string $trade_no): bool
    {
        $result = $this->queryOrder($trade_no);
        return $result && ($result['status'] ?? 0) == 1;
    }

    // 查询订单
    public function queryOrder(string $trade_no): array
    {
        $params = [
            'trade_no' => $trade_no,
        ];
        return $this->execute('api/pay/query', $params);
    }

    // 查询订单
    public function queryOrderByOutTradeNo(string $out_trade_no): array
    {
        $params = [
            'out_trade_no' => $out_trade_no,
        ];
        return $this->execute('api/pay/query', $params);
    }
    
    // 订单退款
    public function refund(string $out_refund_no, string $trade_no, $money): array
    {
        $params = [
            'trade_no' => $trade_no,
            'money' => $money,
            'out_refund_no' => $out_refund_no,
        ];
        return $this->execute('api/pay/refund', $params);
    }

    // 订单退款查询
    public function refundquery(string $out_refund_no): array
    {
        $params = [
            'out_refund_no' => $out_refund_no,
        ];
        return $this->execute('api/pay/refundquery', $params);
    }

    private function buildRequestParam(array $params): array
    {
        $params['pid'] = $this->pid;
        $params['timestamp'] = time() . '';
        $mysign = $this->getSign($params);
        $params['sign'] = $mysign;
        $params['sign_type'] = $this->sign_type;
        return $params;
    }

    // 生成签名
    private function getSign(array $params): string
    {
        return $this->rsaPrivateSign($this->getSignContent($params));
    }

    // 获取待签名字符串
    private function getSignContent(array $params): string
    {
        ksort($params);
        $signstr = '';
        foreach ($params as $k => $v) {
            if ($v instanceof \CURLFile || is_array($v) || $this->isEmpty($v) || $k == 'sign' || $k == 'sign_type') continue;
            $signstr .= '&' . $k . '=' . $v;
        }
        return substr($signstr, 1);
    }

    private function isEmpty($value): bool
    {
        return $value === null || trim((string)$value) === '';
    }

    // 商户私钥签名
    private function rsaPrivateSign(string $data): string
    {
        $key = "-----BEGIN PRIVATE KEY-----\n" .
            wordwrap($this->merchant_private_key, 64, "\n", true) .
            "\n-----END PRIVATE KEY-----";
        $privatekey = openssl_get_privatekey($key);
        if (!$privatekey) {
            throw new \Exception('签名失败，商户私钥错误');
        }
        openssl_sign($data, $sign, $privatekey, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    // 平台公钥验签
    private function rsaPublicVerify(string $data, string $sign): bool
    {
        $key = "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($this->platform_public_key, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
        $publickey = openssl_get_publickey($key);
        if (!$publickey) {
            throw new \Exception("验签失败，平台公钥错误");
        }
        $result = openssl_verify($data, base64_decode($sign), $publickey, OPENSSL_ALGO_SHA256);
        return $result === 1;
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
