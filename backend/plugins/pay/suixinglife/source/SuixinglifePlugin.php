<?php

declare(strict_types=1);

namespace plugins\payment\suixinglife;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class SuixinglifePlugin extends BasePayment
{
    public function __construct(array $channel)
    {
        parent::__construct($channel);
    }

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . $ctx->order['trade_no'] . '/?type=' . $ctx->order['typename']];
    }

    public function mapi(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => request()->siteurl . 'pay/qrcode/' . $ctx->order['trade_no'] . '/?type=' . $ctx->order['typename']];
    }

    public function qrcode(PaymentContext $ctx): mixed
    {
        try {
            $data = self::lockPayData($ctx->order['trade_no'], function () use ($ctx) {
                return $this->createQrcode($ctx);
            });
            $code_url = $data['qrCodeUrl'];
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }
        $this->updateOrder($ctx->order['trade_no'], $data['uuid']);

        $type = request()->get('type');
        if ($type === 'alipay') {
            if ($ctx->mdevice === 'alipay') {
                return ['type' => 'jump', 'url' => $code_url];
            } else {
                return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url, 'expire' => strtotime($ctx->order['addtime']) + 360];
            }
        } elseif ($type === 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $code_url];
            } elseif ($ctx->isMobile) {
                return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url, 'expire' => strtotime($ctx->order['addtime']) + 360];
            } else {
                return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url, 'expire' => strtotime($ctx->order['addtime']) + 360];
            }
        } elseif ($type === 'bank') {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url, 'expire' => strtotime($ctx->order['addtime']) + 360];
        } else {
            return ['type' => 'error', 'msg' => '不支持的支付方式'];
        }
    }

    public function query(array $order): array
    {
        $token = $this->loginAndGetToken();
        $startTs = strtotime($order['addtime']) - 60;
        $endTs = strtotime($order['addtime']) + 360;
        $start_time = date('His', $startTs);
        $end_time = date('His', $endTs);
        $params = [
            'pageNum' => 1,
            'pageSize' => 1000,
            'startDt' => date('Ymd', $startTs),
            'startTm' => $start_time,
            'endDt' => date('Ymd', $endTs),
            'endTm' => $end_time,
            'newFlag' => '1',
        ];
        $data = $this->request('/api/tranInfo/tranListByParam', $params, $token);
        $orderList = $data['list'] ?? [];
        if (empty($orderList)) throw new \Exception('时间段范围内未查询到订单');
        foreach ($orderList as $item) {
            if (!isset($item['uuid']) || !isset($item['tranStsDesc']) || $item['tranStsDesc'] != '成功') continue;

            $detail = $this->queryTranDetail($item['uuid'], $item['payImag'], $item['creDt']);
            if (!$detail) continue;

            $trade_no = $detail['tranExtend'];
            if (empty($trade_no)) continue;
            $money = $item['amt'];
            if ($trade_no == $order['trade_no']) {
                return [
                    'api_trade_no' => $item['uuid'],
                    'status' => 1,
                    'money' => $money,
                    'buyer' => $detail['consumerId'] ?? null,
                    'bill_trade_no' => $detail['transactionId'] ?? null,
                ];
            }
        }
        throw new \Exception('时间段范围内未查询到该订单');
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'data' => [
                'passWord' => $this->encryptPassword($this->channel['password']),
                'origUuid' => $order['api_trade_no'],
                'amt' => $order['refundmoney'],
                'payTime' => date("Y-m-d H:i:s", strtotime($order['endtime'])),
                'payType' => $order["type"] == 1 ? "ALIPAY" : "WECHAT",
                'refundSource' => 'APP',
            ],
        ];

        $token = $this->loginAndGetToken();
        try {
            $data = $this->request('/api/trade/refund', $params, $token);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        if ($data['tranSts'] == 'REFUNDSUC') {
            return ['code' => 0];
        } else {
            return ['code' => -1, 'msg' => '退款失败'];
        }
    }

    // 创建收款码
    private function createQrcode(PaymentContext $ctx): array
    {
        $token = $this->loginAndGetToken();
        $params = [
            'amt' => floatval($ctx->order['realmoney']),
            'remark' => $ctx->order['trade_no'],
        ];
        try {
            $data = $this->request('/api/amtQRCode/createQRCode', $params, $token);
        } catch (\Exception $ex) {
            throw new \Exception('创建收款码失败：' . $ex->getMessage());
        }
        return $data;
    }

    // 获取订单列表
    public function queryTranList(): array
    {
        $token = $this->loginAndGetToken();
        $startTs = strtotime('-6 minutes');
        $endTs = strtotime('+1 minutes');
        $params = [
            'pageNum' => 1,
            'pageSize' => 1000,
            'startDt' => date('Ymd', $startTs),
            'startTm' => date('His', $startTs),
            'endDt' => date('Ymd', $endTs),
            'endTm' => date('His', $endTs),
            'newFlag' => '1',
        ];
        $data = $this->request('/api/tranInfo/tranListByParam', $params, $token);
        return $data['list'] ?? [];
    }

    // 获取订单详情
    public function queryTranDetail($uuid, $payType, $creDt)
    {
        $token = $this->loginAndGetToken();
        $params = [
            'uuid' => $uuid,
            'payType' => $payType,
            'tranIdent' => '',
            'creDt' => $creDt,
        ];
        $data = $this->request('/api/tranInfo/queryTranInfo', $params, $token, true);
        return $data['voInfo'] ?? null;
    }

    // 登录并获取token
    private function loginAndGetToken()
    {
        $cacheKey = 'suixinglife_' . $this->channel['username'];
        if (!empty($this->channel['mno'])) {
            $cacheKey .= '_' . $this->channel['mno'];
        }
        $token = cache($cacheKey);
        if ($token) return $token;

        $equipmentId = substr(md5('suixinglife_' . $this->channel['username']), 0, 16);
        $params = [
            'header' => [
                'sysType' => 'Android',
                'prtVer' => '200.0',
                'reqTime' => (float)getMillisecond(),
                'appVer' => 293,
                'equipmentId' => $equipmentId
            ],
            'data' => [
                'loginAcc' => $this->channel['username'],
                'loginPwd' => $this->encryptPassword($this->channel['password']),
            ],
        ];
        $data = $this->request('/api/user/transForm/login', $params);
        if (empty($data['users'])) throw new Exception('当前账号商户列表为空');
        $userInfoCount = $data['userInfoCount'] ?? 1;
        if ($userInfoCount > 1) {
            $accountId = $data['accountId'];
            if (!empty($this->channel['mno'])) {
                $selectedUser = null;
                foreach ($data['users'] as $user) {
                    if ($user['mno'] == $this->channel['mno']) {
                        $selectedUser = $user;
                        break;
                    }
                }
                if (!$selectedUser) {
                    throw new Exception('未找到商户编码为' . $this->channel['mno'] . '的商户');
                }
            } else {
                $selectedUser = $data['users'][0];
            }
            $token = $this->selectMerchant($selectedUser['userId'], $accountId, $equipmentId);
        } else {
            $token = $data['users'][0]['token'];
        }

        cache($cacheKey, $token, 3600 * 24);
        return $token;
    }

    // 切换商户
    private function selectMerchant($userId, $accountId, $equipmentId)
    {
        $params = [
            'header' => [
                'sysType' => 'Android',
                'prtVer' => '200.0',
                'reqTime' => (float)getMillisecond(),
                'appVer' => 293,
                'equipmentId' => $equipmentId
            ],
            'data' => [
                'accountId' => $accountId,
                'userId' => $userId,
            ],
        ];
        $data = $this->request('/api/user/transForm/switch', $params);
        $token = $data['token'];
        return $token;
    }

    private function encryptPassword($password)
    {
        $key = 'abcdefgabcdefg12';
        $encrypted = openssl_encrypt($password, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
        return base64_encode($encrypted) . "\n";
    }

    private function request($path, $params, $token = null, $multipart = false)
    {
        $url = 'https://pretran.tianquetech.com' . $path;
        $headers = [
            'systemType: Android',
            'systemVersion: Xiaomi-16',
            'appVersion: 2.9.3',
            'Accept-Encoding: gzip',
        ];
        if (!$multipart) $headers[] = 'Content-Type: application/json;charset=UTF-8';
        $cookie = null;
        if ($token) {
            $headers[] = 'token: ' . $token;
            $cookie = 'token=' . $token;
        }
        $response = $this->curl($url, $headers, $multipart ? $params : json_encode($params), $cookie);
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == '0000') {
            return $result['data'];
        } elseif (isset($result['msg'])) {
            throw new \Exception($result['msg']);
        } else {
            throw new \Exception($response);
        }
    }

    private function curl(string $url, array $header, mixed $body = null, ?string $cookie = null, int $timeout = 10): mixed
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Dalvik/2.1.0 (Linux; U; Android 16; 25113PN0EC Build/BP2A.250605.031.A3)');
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($body) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($cookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        $data = curl_exec($ch);
        if (curl_errno($ch) > 0) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new \Exception($errmsg, 0);
        }
        curl_close($ch);
        return $data;
    }
}
