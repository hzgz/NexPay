<?php

declare(strict_types=1);

namespace plugins\payment\umfpay;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class UmfpayPlugin extends BasePayment
{
    private function createClient(): UmfService
    {
        return new UmfService(
            $this->channel['appid'],
            getCertFilePath($this->channel['platform_public_cert']),
            getCertFilePath($this->channel['merchant_private_cert'])
        );
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
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
        if ($ctx->order['typename'] == 'alipay') {
            return $this->alipay($ctx);
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat') {
                return $this->wxjspay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //扫码支付
    private function qrcode(PaymentContext $ctx, string $type): string
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'service' => 'active_scancode_order_new',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'goods_inf' => $ctx->ordername,
            'order_id' => $tradeNo,
            'mer_date' => date("Ymd"),
            'amount' => strval($ctx->order['realmoney'] * 100),
            'user_ip' => request()->clientip,
            'scancode_type' => $type,
            'mer_flag' => 'KMER',
            'consumer_id' => str_replace('.', '', request()->clientip)
        ];

        $client = $this->createClient();
        $result = $client->submit($params);

        if (isset($result['ret_code']) && $result['ret_code'] == '0000') {
            return base64_decode($result['bank_payurl']);
        } elseif (isset($result['ret_code'])) {
            throw new Exception('[' . $result['ret_code'] . ']' . $result['ret_msg']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'ALIPAY');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'WECHAT');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }

        if ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $params = [
            'service' => 'publicnumber_and_verticalcode',
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'ret_url' => request()->siteurl . 'pay/return/' . $tradeNo . '/',
            'goods_inf' => $ctx->ordername,
            'order_id' => $tradeNo,
            'mer_date' => date("Ymd"),
            'amount' => strval($ctx->order['realmoney'] * 100),
            'user_ip' => request()->clientip,
            'is_public_number' => 'Y',
        ];

        $client = $this->createClient();
        $url = $client->getpayurl($params);

        return ['type' => 'jump', 'url' => $url];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'UNION');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $client = $this->createClient();
        $verify_result = $client->verifySign(request()->get());

        $params = [
            'order_id' => request()->get('order_id'),
            'mer_date' => request()->get('mer_date'),
        ];

        if ($verify_result) {//验证成功

            if (request()->get('trade_state') == 'TRADE_SUCCESS') {
                if (request()->get('order_id') == $tradeNo) {
                    $this->processNotify($ctx->order, request()->get('trade_no'), request()->get('mer_cust_id'));
                }
            } else {
                $params['ret_code'] = '0001';
                $params['ret_msg'] = 'trade_state' . request()->get('trade_state');
            }
        } else {
            //验证失败
            $params['ret_code'] = '0001';
            $params['ret_msg'] = 'sign fail';
        }

        $response_str = $client->responseUMFstr($params);

        $html = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">';
        $html .= '<html><head>';
        $html .= '<META NAME="MobilePayPlatform" CONTENT="' . $response_str . '">';
        $html .= '</head>';
        $html .= '<body></body>';
        $html .= '</html>';

        return ['type' => 'html', 'data' => $html];
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'return', 'url' => '/pay/checkout/' . rawurlencode((string)($ctx->order['trade_no'] ?? ''))];
    }

    //退款
    public function refund(array $order): array
    {
        $params = [
            'service' => 'mer_refund',
            'refund_no' => $order['refund_no'],
            'order_id' => $order['trade_no'],
            'mer_date' => substr($order['trade_no'], 0, 8),
            'org_amount' => strval($order['realmoney'] * 100),
            'refund_amount' => strval($order['refundmoney'] * 100),
        ];

        try {
            $client = $this->createClient();
            $result = $client->submit($params);
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }

        if (isset($result['ret_code']) && $result['ret_code'] == '0000') {
            return ['code' => 0, 'trade_no' => $result['order_id'], 'refund_fee' => $result['refund_amt']];
        } elseif (isset($result['ret_code'])) {
            return ['code' => -1, 'msg' => '[' . $result['ret_code'] . ']' . $result['ret_msg']];
        } else {
            return ['code' => -1, 'msg' => '未知错误'];
        }
    }
}
