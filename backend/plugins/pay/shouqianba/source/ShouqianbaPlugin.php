<?php

declare(strict_types=1);

namespace plugins\payment\shouqianba;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

/**
 * https://doc.shouqianba.com/
 */
class ShouqianbaPlugin extends BasePayment
{
    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/wxpay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return ['type' => 'jump', 'url' => '/pay/bank/' . $tradeNo . '/'];
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    private function getClient(): ShouqianbaClient
    {
        return new ShouqianbaClient($this->channel['appid'], $this->channel['appkey']);
    }

    //下单通用
    private function addOrder(PaymentContext $ctx, string $payway, string $sub_payway): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $pay = $this->getClient();
        $arr = [
            'client_sn' => $tradeNo,
            'total_amount' => strval($ctx->order['realmoney'] * 100),
            'subject' => $ctx->ordername,
            'payway' => $payway,
            'sub_payway' => $sub_payway,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
        ];
        $result = $pay->precreate($arr);

        if (isset($result['result_code']) && $result['result_code'] == '200') {
            if ($result['biz_response']['result_code'] == 'PRECREATE_SUCCESS') {
                $code_url = $result['biz_response']['data']['qr_code'];
            } else {
                throw new Exception('[' . $result['biz_response']['error_code'] . ']' . $result['biz_response']['error_message']);
            }
        } elseif(isset($result['error_code']) && isset($result['error_message'])) {
            throw new Exception('[' . $result['error_code'] . ']' . $result['error_message']);
        } else {
            throw new Exception('接口返回数据解析失败');
        }
        return $code_url;
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, '2', '2');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, '3', '2');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $pay = $this->getClient();
        $arr = [
            'client_sn' => $tradeNo,
            'total_amount' => strval($ctx->order['realmoney'] * 100),
            'subject' => $ctx->ordername,
            'payway' => '3',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
        ];
        $result = $pay->wap_api_pro($arr);
        return ['type' => 'jump', 'url' => $result];
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        $tradeNo = $ctx->order['trade_no'];

        $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->addOrder($ctx, '17', '2');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        if (!$json) return ['type' => 'html', 'data' => 'no data'];
        $sign = request()->header('authorization', '');

        if (ShouqianbaClient::verifySign($json, $sign)) {
            $data = json_decode($json, true);
            if ($data['status'] == 'SUCCESS') {
                if ($data['order_status'] == 'PAID') {
                    $out_trade_no = $data['client_sn'];
                    $api_trade_no = $data['sn'];
                    $money = $data['total_amount'];
                    $buyer = $data['payer_uid'];
                    $end_time = $data['finish_time'];

                    if ($out_trade_no == $ctx->order['trade_no']) {
                        $this->processNotify($ctx->order, $api_trade_no, $buyer, $data['trade_no'], null, $end_time);
                    }
                }
                return ['type' => 'html', 'data' => 'success'];
            } else {
                return ['type' => 'html', 'data' => 'status=' . $data['status']];
            }
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //支付返回页面
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query(array $order): array
    {
        $pay = $this->getClient();
        $arr = [
            'client_sn' => $order['trade_no'],
        ];
        $result = $pay->query($arr);
        if (isset($result['result_code']) && $result['result_code'] == '200') {
            if ($result['biz_response']['result_code'] == 'SUCCESS') {
                $data = $result['biz_response']['data'];
                return [
                    'api_trade_no' => $data['sn'],
                    'status' => $data['order_status'] == 'PAID' ? 1 : 0,
                    'money' => $data['total_amount'],
                    'buyer' => $data['payer_uid'] ?? '',
                    'bill_trade_no' => $data['trade_no'] ?? '',
                    'endtime' => $data['finish_time'] ?? '',
                ];
            } else {
                throw new \Exception('[' . $result['biz_response']['error_code'] . ']' . $result['biz_response']['error_message']);
            }
        } elseif(isset($result['error_code']) && isset($result['error_message'])) {
            throw new \Exception('[' . $result['error_code'] . ']' . $result['error_message']);
        } else {
            throw new \Exception('接口返回数据解析失败');
        }
    }

    //退款
    public function refund(array $order): array
    {
        $pay = $this->getClient();
        $arr = [
            'sn' => $order['api_trade_no'],
            'refund_request_no' => $order['refund_no'],
            'refund_amount' => strval($order['refundmoney'] * 100),
        ];
        $result = $pay->refund($arr);

        if (isset($result['result_code']) && $result['result_code'] == '200') {
            if ($result['biz_response']['result_code'] == 'REFUND_SUCCESS') {
                return ['code' => 0, 'trade_no' => $result['biz_response']['data']['client_sn'], 'refund_fee' => $result['biz_response']['data']['total_amount']];
            } else {
                return ['code' => -1, 'msg' => '[' . $result['biz_response']['error_code'] . ']' . $result['biz_response']['error_message']];
            }
        } elseif(isset($result['error_code']) && isset($result['error_message'])) {
            return ['code' => -1, 'msg' => '[' . $result['error_code'] . ']' . $result['error_message']];
        } else {
            return ['code' => -1, 'msg' => '接口返回数据解析失败'];
        }
    }

    public function active()
    {
        $channel = $this->channel;

        $list = [];
        $data = cache('shouqianba_terminal');
        if ($data) {
            $decoded = json_decode((string)$data, true);
            if (is_array($decoded)) {
                $list = $decoded;
            }
        }

        if (request()->has('submit', 'post')) {
            if (!checkRefererHost()) return;
            $vendor_sn = request()->post('vendor_sn', '', 'trim');
            $vendor_key = request()->post('vendor_key', '', 'trim');
            $app_id = request()->post('app_id', '', 'trim');
            $device_id = request()->post('device_id', '', 'trim');
            $code = request()->post('code', '', 'trim');
            if (empty($vendor_sn) || empty($vendor_key) || empty($app_id) || empty($device_id) || empty($code)) {
                return $this->showMsg('必填项不能为空', 3);
            }

            $pay = new ShouqianbaClient($vendor_sn, $vendor_key);
            $result = $pay->activate($app_id, $code, $device_id);

            if (isset($result['result_code']) && $result['result_code'] == '200') {
                $terminal_sn = $result['biz_response']['terminal_sn'];
                $terminal_key = $result['biz_response']['terminal_key'];
                $row = ['vendor_sn' => $vendor_sn, 'device_id' => $device_id, 'terminal_sn' => $terminal_sn, 'terminal_key' => $terminal_key];
                $list[] = $row;
                cache('shouqianba_terminal', json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $this->showMsg('终端激活成功！<br/>终端号：' . $terminal_sn . '<br/>终端密钥：' . $terminal_key . '<br/>', 1);
            } elseif(isset($result['error_code']) && isset($result['error_message'])) {
                return $this->showMsg('[' . $result['error_code'] . ']' . $result['error_message'], 4);
            } else {
                return $this->showMsg('接口返回数据解析失败', 4);
            }
        }

        return view($this->payRoot . 'view/active.html', [
            'channel' => $channel,
            'list' => $list,
        ]);
    }
}
