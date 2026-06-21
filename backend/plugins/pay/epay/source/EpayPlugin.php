<?php

declare(strict_types=1);

namespace plugins\payment\epay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class EpayPlugin extends BasePayment
{
    private array $epayConfig;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->epayConfig = [
            'apiurl' => $channel['appurl'],
            'pid' => $channel['appid'],
            'key' => $channel['appkey'],
        ];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($this->channel['appswitch'] == 1) {
            return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $tradeNo . '/'];
        }

        $epay = new EpayCore($this->epayConfig);
        $parameter = [
            'pid' => trim($this->epayConfig['pid']),
            'type' => $ctx->order['typename'],
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'out_trade_no' => $tradeNo,
            'name' => $ctx->order['name'],
            'money' => $ctx->order['realmoney'],
        ];
        //建立请求
        if (is_https() && substr($this->epayConfig['apiurl'], 0, 7) == 'http://') {
            $jump_url = $epay->getPayLink($parameter);
            return ['type' => 'jump', 'url' => $jump_url];
        } else {
            $html_text = $epay->pagePay($parameter, '正在跳转');
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

    //mapi接口下单
    private function pay_mapi(string $type, PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $epay = new EpayCore($this->epayConfig);
        $parameter = [
            'pid' => trim($this->epayConfig['pid']),
            'type' => $type,
            'device' => $this->getDevice($ctx),
            'clientip' => request()->clientip,
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'out_trade_no' => $tradeNo,
            'name' => $ctx->order['name'],
            'money' => $ctx->order['realmoney'],
        ];
        //建立请求
        return self::lockPayData($tradeNo, function () use ($epay, $parameter) {
            $result = $epay->apiPay($parameter);

            if (isset($result['code']) && $result['code'] == 1) {
                if (isset($result['payurl'])) {
                    $method = 'jump';
                    $url = $result['payurl'];
                } elseif (isset($result['qrcode'])) {
                    $method = 'qrcode';
                    $url = $result['qrcode'];
                } elseif (isset($result['urlscheme'])) {
                    $method = 'scheme';
                    $url = $result['urlscheme'];
                } else {
                    throw new Exception('未返回支付链接');
                }
            } elseif (isset($result['msg'])) {
                throw new Exception($result['msg']);
            } else {
                throw new Exception('获取支付接口数据失败');
            }
            return [$method, $url];
        });
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('alipay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $url];
        }
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('wxpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } elseif ($method == 'scheme') {
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
            [$method, $url] = $this->pay_mapi('qqpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
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
            [$method, $url] = $this->pay_mapi('bank', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } else {
            return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $url];
        }
    }

    //京东支付
    public function jdpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('jdpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method == 'jump') {
            return ['type' => 'jump', 'url' => $url];
        } else {
            return ['type' => 'qrcode', 'page' => 'jdpay_qrcode', 'url' => $url];
        }
    }

    //抖音支付
    public function douyinpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('douyinpay', $ctx);
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

            if (request()->get('trade_status') == 'TRADE_SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no'] && round($money, 2) == round((float)$ctx->order['realmoney'], 2)) {
                    ($this->markTrustedCallback($ctx, 'notify', 'epay-signature'))(function () use ($ctx, $trade_no) {
                        $this->processNotify($ctx->order, $trade_no);
                    });
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

            if (request()->get('trade_status') == 'TRADE_SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no'] && round($money, 2) == round((float)$ctx->order['realmoney'], 2)) {
                    return ($this->markTrustedCallback($ctx, 'return', 'epay-signature'))(function () use ($ctx, $trade_no) {
                        return $this->processReturn($ctx->order, $trade_no);
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
        if ($result['code'] == 0) {
            return [
                'api_trade_no' => $result['trade_no'],
                'status' => $result['status'],
                'money' => $result['money'],
                'buyer' => $result['buyer'] ?? null,
                'bill_trade_no' => $result['api_trade_no'] ?? null,
                'endtime' => $result['endtime'] ?? null,
            ];
        } else {
            throw new \Exception($result['msg'] ?? '返回数据解析失败');
        }
    }

    //退款
    public function refund($order): array
    {
        $epay = new EpayCore($this->epayConfig);
        $result = $epay->refund($order['refund_no'], $order['api_trade_no'], $order['refundmoney']);

        if ($result['code'] == 0) {
            return ['code' => 0];
        } else {
            return ['code' => -1, 'msg' => $result['msg'] ?? '返回数据解析失败'];
        }
    }
}
