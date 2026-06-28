<?php

declare(strict_types=1);

namespace plugins\payment\xingyifuyhk;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class XingyifuyhkPlugin extends BasePayment
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
            $code_url = $data['codeUrl'];
        } catch (\Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }
        $type = request()->get('type');
        if ($type === 'alipay') {
            if ($ctx->mdevice === 'alipay') {
                $url = 'alipays://platformapi/startapp?saId=10000007&qrcode=' . urlencode($code_url);
                return ['type' => 'jump', 'url' => $url];
            } else {
                $expires_in = strval(strtotime($ctx->order['addtime']) + 360 - time());
                return view($this->payRoot . 'view/alipay_qrcode.html', [
                    'code_url' => $code_url,
                    'order' => $ctx->order,
                    'expires_in' => $expires_in
                ]);
            }
        } elseif ($type === 'wxpay') {
            if ($ctx->isMobile) {
                $query = urlencode('q=?codePlate=' . explode('?codePlate=', $code_url)[1]);
                $url = 'weixin://dl/business/?appid=wx7cd05626d476f4cc&path=pages/scene/index&query='.$query;
                return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $url];
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
        $start_time = date('Y-m-d H:i:s', strtotime($order['addtime']) - 60);
        $end_time = date('Y-m-d H:i:s', strtotime($order['addtime']) + 360);
        $params = [
            'pageNum' => 1,
            'pageSize' => 1000,
            'payState' => '1',
            'queryStartTime' => $start_time,
            'queryEndTime' => $end_time,
        ];
        $data = $this->request('POST', '/v1/payfly/pf2/mina/merch/order/page', $params);
        $orderList = $data['records'];
        if (empty($orderList)) throw new \Exception('时间段范围内未查询到订单');
        foreach ($orderList as $item) {
            if (!isset($item['payState']) || !isset($item['renPageName']) || $item['payState'] != 1) continue;
            $trade_no = trim($item['renPageName']);
            $money = round($item['amount'] / 100, 2);
            if ($trade_no == $order['trade_no']) {
                return [
                    'api_trade_no' => $item['orderId'],
                    'status' => 1,
                    'money' => $money,
                    'buyer' => $item['userOfficialId'] ?? null,
                    'bill_trade_no' => $item['edenOrderId'] ?? null,
                    'endtime' => $item['updateTime'],
                ];
            }
        }
        throw new \Exception('时间段范围内未查询到该订单');
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'orderId' => $order['api_trade_no'],
            'refundAmt' => intval(round($order['refundmoney'] * 100)),
            'billDataIds' => [],
        ];

        try {
            $result = $this->request('POST', '/v1/payfly/pf2/mina/merch/order/refund', $params);
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }

        return ['code' => 0];
    }

    private function createQrcode(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $money = intval(round($ctx->order['realmoney'] * 100));
        $params = [
            'deptId' => '',
            'copInfos' => [[
                'childCops' => [[
                    'composite' => 1,
                    'copType' => '1',
                    'copLabel' => $tradeNo,
                    'copPrompt' => '',
                    'copDefaultValue' => $money,
                    'copValue' => $money,
                    'copValueType' => 1,
                    'copRequire' => 0,
                    'copQuery' => 0,
                    'copLikeQuery' => 1,
                    'copReadonly' => 0,
                    'copSize' => null,
                    'copAboutAmt' => 1,
                    'copResourceShared' => 0,
                    'copId' => '',
                    'copChecklist' => 1,
                    'copCustom' => (object)[],
                ]],
                'composite' => '2',
                'copType' => '105',
                'copHtmlClass' => null,
                'copLabel' => $tradeNo,
                'copPrompt' => '',
                'copDefaultValue' => $money,
                'copValue' => $money,
                'copValueType' => 1,
                'copRequire' => 0,
                'copQuery' => 0,
                'copLikeQuery' => 1,
                'copReadonly' => 0,
                'copSize' => null,
                'copAboutAmt' => 1,
                'copResourceShared' => 0,
                'copChecklist' => 1,
                'copCustom' => (object)[],
            ]],
            'renId' => '',
            'renTitle' => $tradeNo,
            'renEffectiveDate' => date('Y-m-d H:i:s'),
            'renExpirationDate' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
            'managerId' => '',
            'snNoList' => [],
            'useTemplate' => 0,
            'confirmTwice' => 0,
            'managerIdList' => [],
        ];
        try {
            $renId = $this->request('POST', '/v1/payfly/pf2/mina/merch/renovation/createReceipt', $params);
        } catch (\Exception $ex) {
            throw new \Exception('创建收款单失败：' . $ex->getMessage());
        }

        $params = [
            'renId' => $renId,
            'status' => 2,
        ];
        try {
            $this->request('POST', '/v1/payfly/pf2/mina/merch/renovation/changeStatus', $params);
        } catch (\Exception $ex) {
            throw new \Exception('发布收款单失败：' . $ex->getMessage());
        }

        try {
            $data = $this->request('POST', '/v1/payfly/pf2/mina/merch/renovation/getReceipt?renId=' . $renId);
        } catch (\Exception $ex) {
            throw new \Exception('查询收款单失败：' . $ex->getMessage());
        }
        if (empty($data['qrCodeEncodeNoMgrStr'])) throw new \Exception('未返回收款链接');

        return ['renId' => $data['renId'], 'deptId' => $data['deptId'], 'codeUrl' => $data['qrCodeEncodeNoMgrStr']];
    }

    public function getOrderList(): array
    {
        $params = [
            'pageNum' => 1,
            'pageSize' => 1000,
            'payState' => '1',
            'queryStartTime' => date('Y-m-d H:i:s', strtotime('-6 minutes')),
        ];
        $data = $this->request('POST', '/v1/payfly/pf2/mina/merch/order/page', $params);
        return $data['records'];
    }

    public function closeOrder(int $renId): void
    {
        $params = [
            'renId' => $renId,
            'status' => 0,
        ];
        $this->request('POST', '/v1/payfly/pf2/mina/merch/renovation/changeStatus', $params);
    }

    private function request($method, $path, $params = null)
    {
        $url = 'https://yhk.postar.cn' . $path;
        $ua = 'Mozilla/5.0 (Linux; Android 15; NX769J Build/AQ3A.240812.002; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/142.0.7444.173 Mobile Safari/537.36 XWEB/1420283 MMWEBSDK/20250904 MMWEBID/1468 MicroMessenger/8.0.64.2940(0x28004050) WeChat/arm64 Weixin NetType/WIFI Language/zh_CN ABI/arm64 MiniProgramEnv/android';
        $referer = 'https://servicewechat.com/wxaceada211a82dcdd/34/page-frame.html';
        $headers = [
            'appsession: ' . $this->channel['appsession'],
            'client-type: C',
            'charset: utf-8',
            'Content-Type: application/json;charset=utf-8'
        ];
        $response = $this->curl($method, $url, $headers, $params ? json_encode($params) : null, $ua, $referer);
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 200) {
            return $result['data'];
        } elseif(isset($result['msg'])) {
            throw new \Exception($result['msg']);
        } else {
            throw new \Exception($response);
        }
    }

    private function curl(string $method, string $url, array $header, mixed $body = null, $ua = null, $referer = null, int $timeout = 10): mixed
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        curl_setopt($ch, CURLOPT_REFERER, $referer);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method == 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
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
