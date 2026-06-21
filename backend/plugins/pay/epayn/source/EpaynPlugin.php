<?php

declare(strict_types=1);

namespace plugins\payment\epayn;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class EpaynPlugin extends BasePayment
{
    private array $epayConfig;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->epayConfig = [
            'apiurl' => $channel['appurl'],
            'pid' => $channel['appid'],
            'platform_public_key' => $channel['appkey'],
            'merchant_private_key' => $channel['appsecret'],
        ];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appswitch'] == 1) {
            return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $tradeNo . '/'];
        }

        $epay = new EpayCore($this->epayConfig);
        $params = [
            'type' => $ctx->order['typename'],
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'out_trade_no' => $tradeNo,
            'name' => $ctx->order['name'],
            'money' => $ctx->order['realmoney'],
        ];
        if (!empty($this->channel['merchant_id'])) $params['merchant_id'] = $this->channel['merchant_id'];
        if (!empty($this->channel['channel_id'])) $params['channel_id'] = $this->channel['channel_id'];

        if (is_https() && substr($this->epayConfig['apiurl'], 0, 7) == 'http://') {
            $jump_url = $epay->getPayLink($params);
            return ['type' => 'jump', 'url' => $jump_url];
        } else {
            $html_text = $epay->pagePay($params, '正在跳转');
            return ['type' => 'html', 'data' => $html_text];
        }
    }

    public function mapi(PaymentContext $ctx): array
    {
        if ($this->channel['appswitch'] == 1) {
            $typename = $ctx->order['typename'];
            return $this->$typename($ctx);
        } else {
            return ['type' => 'jump', 'url' => request()->siteurl . 'pay/submit/' . $ctx->order['trade_no'] . '/'];
        }
    }

    private function getDevice(PaymentContext $ctx): string
    {
        if ($ctx->mdevice === 'wechat') return 'wechat';
        if ($ctx->mdevice === 'qq') return 'qq';
        if ($ctx->mdevice === 'alipay') return 'alipay';
        if ($ctx->mdevice === 'douyin') return 'douyin';
        if ($ctx->isMobile) return 'mobile';
        return 'pc';
    }

    //统一下单接口
    private function pay_mapi(string $method, string $type, PaymentContext $ctx, ?string $auth_code = null, ?string $sub_openid = null, ?string $sub_appid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $epay = new EpayCore($this->epayConfig);
        $params = [
            'method' => $method,
            'type' => $type,
            'device' => $this->getDevice($ctx),
            'clientip' => request()->clientip,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'out_trade_no' => $tradeNo,
            'name' => $ctx->order['name'],
            'money' => $ctx->order['realmoney'],
        ];
        if ($auth_code) $params['auth_code'] = $auth_code;
        if ($sub_openid) $params['sub_openid'] = $sub_openid;
        if ($sub_appid) $params['sub_appid'] = $sub_appid;
        if (!empty($this->channel['merchant_id'])) $params['merchant_id'] = $this->channel['merchant_id'];
        if (!empty($this->channel['channel_id'])) $params['channel_id'] = $this->channel['channel_id'];

        return self::lockPayData($tradeNo, function () use ($epay, $params) {
            $result = $epay->apiPay($params);
            return [$result['pay_type'], $result['pay_info']];
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('web', 'alipay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } elseif ($method == 'html') {
            return ['type' => 'html', 'data' => $url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $url];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('web', 'wxpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } elseif ($method == 'html') {
            return ['type' => 'html', 'data' => $url];
        } elseif ($method == 'urlscheme') {
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $url];
        } else {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $url];
            } elseif ($ctx->isMobile) {
                return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $url];
            } else {
                return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $url];
            }
        }
    }

    //QQ扫码支付
    public function qqpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('web', 'qqpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } elseif ($method == 'html') {
            return ['type' => 'html', 'data' => $url];
        } else {
            if ($ctx->mdevice === 'qq') {
                return ['type' => 'jump', 'url' => $url];
            } elseif ($ctx->isMobile && !request()->get('qrcode')) {
                return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $url];
            } else {
                return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $url];
            }
        }
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('web', 'bank', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } elseif ($method == 'html') {
            return ['type' => 'html', 'data' => $url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $url];
        }
    }

    //京东支付
    public function jdpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('web', 'jdpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } elseif ($method == 'html') {
            return ['type' => 'html', 'data' => $url];
        } else {
            return ['type' => 'qrcode', 'page' => 'jdpay_qrcode', 'url' => $url];
        }
    }

    //抖音支付
    public function douyinpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('web', 'douyinpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } else {
            if ($ctx->isMobile) {
                return ['type' => 'qrcode', 'page' => 'douyinpay_wap', 'url' => $url];
            } else {
                return ['type' => 'qrcode', 'page' => 'douyinpay_qrcode', 'url' => $url];
            }
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $epayNotify = new EpayCore($this->epayConfig);

        //计算得出通知验证结果
        $verify_result = $epayNotify->verify(request()->get());

        if ($verify_result) { //验证成功
            //商户订单号
            $out_trade_no = request()->get('out_trade_no');

            //易支付交易号
            $trade_no = request()->get('trade_no');

            //交易金额
            $money = (float) request()->get('money');

            //支付人账号
            $buyer = request()->get('buyer');

            $api_trade_no = request()->get('api_trade_no');

            $endtime = request()->get('endtime');

            if (request()->get('trade_status') == 'TRADE_SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no'] && round($money, 2) == round((float)$ctx->order['realmoney'], 2)) {
                    $this->processNotify($ctx->order, $trade_no, $buyer, $api_trade_no, null, $endtime);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            //验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        $epayNotify = new EpayCore($this->epayConfig);

        //计算得出通知验证结果
        $verify_result = $epayNotify->verify(request()->get());
        if ($verify_result) {
            //商户订单号
            $out_trade_no = request()->get('out_trade_no');

            //易支付交易号
            $trade_no = request()->get('trade_no');

            //交易金额
            $money = (float) request()->get('money');

            //支付人账号
            $buyer = request()->get('buyer');

            $api_trade_no = request()->get('api_trade_no');

            $endtime = request()->get('endtime');

            if (request()->get('trade_status') == 'TRADE_SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no'] && round($money, 2) == round((float)$ctx->order['realmoney'], 2)) {
                    return ($this->markTrustedCallback($ctx, 'return', 'epayn-signature'))(function () use ($ctx, $trade_no, $buyer, $api_trade_no, $endtime) {
                        return $this->processReturn($ctx->order, $trade_no, $buyer, $api_trade_no, null, $endtime);
                    });
                } else {
                    return ['type' => 'error', 'msg' => '订单信息校验失败'];
                }
            } else {
                return ['type' => 'error', 'msg' => 'trade_status=' . request()->get('trade_status')];
            }
        } else {
            //验证失败
            return ['type' => 'error', 'msg' => '验证失败！'];
        }
    }

    public function query(array $order): array
    {
        $epay = new EpayCore($this->epayConfig);
        $result = $epay->queryOrderByOutTradeNo($order['trade_no']);
        return [
            'api_trade_no' => $result['trade_no'],
            'status' => $result['status'],
            'money' => $result['money'],
            'buyer' => $result['buyer'] ?? null,
            'bill_trade_no' => $result['api_trade_no'] ?? null,
            'endtime' => $result['endtime'] ?? null,
        ];
    }

    //退款
    public function refund($order): array
    {
        $epay = new EpayCore($this->epayConfig);
        try {
            $result = $epay->refund($order['refund_no'], $order['api_trade_no'], $order['refundmoney']);
            return ['code' => 0, 'trade_no' => $result['refund_no'], 'refund_fee' => $result['money']];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //转账
    public function transfer($bizParam): array
    {
        $epay = new EpayCore($this->epayConfig);
        $params = [
            'type' => $bizParam['type'],
            'account' => $bizParam['payee_account'],
            'name' => $bizParam['payee_real_name'],
            'money' => $bizParam['money'],
            'remark' => $bizParam['transfer_desc'],
            'out_biz_no' => $bizParam['out_biz_no'],
        ];
        try {
            $result = $epay->execute('api/transfer/submit', $params);
            if (isset($result['jumpurl'])) {
                return ['code' => 0, 'status' => $result['status'], 'orderid' => $result['out_biz_no'], 'paydate' => $result['paydate'], 'wxpackage' => $result['jumpurl']];
            } else {
                return ['code' => 0, 'status' => $result['status'], 'orderid' => $result['out_biz_no'], 'paydate' => $result['paydate']];
            }
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //转账查询
    public function transfer_query($bizParam): array
    {
        $epay = new EpayCore($this->epayConfig);
        $params = [
            'out_biz_no' => $bizParam['out_biz_no'],
        ];
        try {
            $result = $epay->execute('api/transfer/query', $params);
            return ['code' => 0, 'status' => $result['status'], 'amount' => $result['amount'], 'paydate' => $result['paydate'], 'errmsg' => $result['errmsg']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //余额查询
    public function balance_query($bizParam): array
    {
        $epay = new EpayCore($this->epayConfig);
        try {
            $result = $epay->execute('api/transfer/balance', []);
            return ['code' => 0, 'amount' => $result['available_money']];
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
