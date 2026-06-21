<?php

declare(strict_types=1);

namespace plugins\payment\alipayhk;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class AlipayhkPlugin extends BasePayment
{
    private array $alipayConfig;
    private array $trade_information = ['business_type' => '5', 'other_business_type' => '在线充值'];

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->alipayConfig = $this->getConfig();
    }

    private function getConfig(): array
    {
        return [
            'partner' => $this->channel['appid'],
            'key' => $this->channel['appkey'],
            'sign_type' => 'MD5',
        ];
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
        }

        return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
    }

    public function alipay(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $type = request()->get('type', '');

        if ($this->channel['appswitch'] == 1 && empty($type)) {
            return view($this->payRoot . 'view/select.html', [
                'order' => $ctx->order,
            ]);
        }

        if ($ctx->isMobile) {
            if (in_array('2', $this->channel['apptype'])) {
                return $this->wappay($ctx);
            } elseif (in_array('1', $this->channel['apptype'])) {
                return $this->submitpc($ctx);
            } elseif (in_array('3', $this->channel['apptype'])) {
                if ($ctx->mdevice === 'alipay') {
                    return $this->apppay($ctx);
                } else {
                    $code_url = $siteurl . 'pay/apppay/' . $tradeNo . '/?type=' . $type;
                    return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
                }
            }
        } else {
            if (in_array('1', $this->channel['apptype'])) {
                $code_url = '/pay/submitpc/' . $tradeNo . '/?type=' . $type;
                return ['type' => 'qrcode', 'page' => 'alipay_qrcodepc', 'url' => $code_url];
            } elseif (in_array('2', $this->channel['apptype'])) {
                $code_url = $siteurl . 'pay/wappay/' . $tradeNo . '/?type=' . $type;
                return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
            } elseif (in_array('3', $this->channel['apptype'])) {
                $code_url = $siteurl . 'pay/apppay/' . $tradeNo . '/?type=' . $type;
                return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
            }
        }

        return ['type' => 'error', 'msg' => '未配置可用的支付方式'];
    }

    public function submitpc(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $type = request()->get('type', '');

        $parameter = [
            'service' => 'create_forex_trade',
            'partner' => trim($this->alipayConfig['partner']),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'out_trade_no' => $tradeNo,
            'subject' => $ctx->ordername,
            'currency' => 'HKD',
            'rmb_fee' => $ctx->order['realmoney'],
            'refer_url' => $siteurl,
            'product_code' => 'NEW_WAP_OVERSEAS_SELLER',
            'qr_pay_mode' => '4',
            'qrcode_width' => '230',
            'trade_information' => json_encode($this->trade_information),
            '_input_charset' => 'utf-8',
        ];
        if (!empty($type)) {
            $parameter['payment_inst'] = trim($type);
        }

        $client = new AlipayGlobalClient($this->alipayConfig);
        if ($ctx->isMobile && $ctx->mdevice !== 'alipay') {
            try {
                $url = $client->buildRequestForm($parameter, 'REDIRECT');
                $html = get_curl($url, 0, 0, 0, 0, 0, 0, 0, 1);
                $html = mb_convert_encoding($html, 'utf-8', 'gbk');
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            if (preg_match('!<input name="qrCode" type="hidden" value="(.*?)"!i', $html, $match)) {
                $code_url = $match[1];
                return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
            } else {
                return ['type' => 'error', 'msg' => '支付宝下单失败！获取二维码失败'];
            }
        } else {
            $html_text = $client->buildRequestForm($parameter);
            $html_text = '<!DOCTYPE html><html><body><style>body{margin:0;padding:0}.waiting{position:absolute;width:100%;height:100%;background:#fff url(/static/img/load.gif) no-repeat fixed center/80px;}</style><div class="waiting"></div>' . $html_text . '</body></html>';
            return ['type' => 'html', 'data' => $html_text];
        }
    }

    public function wappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $type = request()->get('type', '');

        $parameter = [
            'service' => 'create_forex_trade_wap',
            'partner' => trim($this->alipayConfig['partner']),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'out_trade_no' => $tradeNo,
            'subject' => $ctx->ordername,
            'currency' => 'HKD',
            'rmb_fee' => $ctx->order['realmoney'],
            'refer_url' => $siteurl,
            'product_code' => 'NEW_WAP_OVERSEAS_SELLER',
            'trade_information' => json_encode($this->trade_information),
            '_input_charset' => 'utf-8',
        ];
        if (!empty($type)) {
            $parameter['payment_inst'] = trim($type);
        }

        $client = new AlipayGlobalClient($this->alipayConfig);
        $html_text = $client->buildRequestForm($parameter);
        return ['type' => 'html', 'data' => $html_text];
    }

    public function apppay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $type = request()->get('type', '');
        $d = request()->get('d', '');

        $parameter = [
            'service' => 'mobile.securitypay.pay',
            'partner' => trim($this->alipayConfig['partner']),
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'out_trade_no' => $tradeNo,
            'subject' => $ctx->ordername,
            'payment_type' => '1',
            'seller_id' => trim($this->alipayConfig['partner']),
            'currency' => 'HKD',
            'rmb_fee' => $ctx->order['realmoney'],
            'forex_biz' => 'FP',
            'refer_url' => $siteurl,
            'product_code' => 'NEW_WAP_OVERSEAS_SELLER',
            'trade_information' => json_encode($this->trade_information),
            '_input_charset' => 'utf-8',
        ];
        if (!empty($type)) {
            $parameter['payment_inst'] = trim($type);
        }

        $client = new AlipayGlobalClient($this->alipayConfig);
        $result = $client->buildSdkParam($parameter);
        if ($ctx->method == 'app') {
            return ['type' => 'app', 'data' => $result];
        }
        if ($d == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        $code_url = 'alipays://platformapi/startApp?appId=20000125&orderSuffix=' . urlencode($result) . '#Intent;scheme=alipays;package=com.eg.android.AlipayGphone;end';
        return ['type' => 'page', 'page' => 'alipay_h5', 'data' => ['code_url' => $code_url, 'redirect_url' => $redirect_url]];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $postData = request()->post();
        $client = new AlipayGlobalClient($this->alipayConfig);

        //计算得出通知验证结果
        $verify_result = $client->verify($postData);

        if ($verify_result) { //验证成功
            $out_trade_no = $postData['out_trade_no'] ?? '';
            $trade_no = $postData['trade_no'] ?? '';
            $buyer_id = $postData['buyer_id'] ?? '';
            $total_fee = $postData['total_fee'] ?? 0;

            if ($postData['trade_status'] == 'TRADE_FINISHED' || $postData['trade_status'] == 'TRADE_SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no']) {
                    ($this->markTrustedCallback($ctx, 'notify', 'alipay-hk-signature'))(function () use ($ctx, $trade_no, $buyer_id) {
                        $this->processNotify($ctx->order, $trade_no, $buyer_id);
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
        $getData = request()->get();
        $client = new AlipayGlobalClient($this->alipayConfig);

        //计算得出通知验证结果
        $verify_result = $client->verify($getData);
        if ($verify_result) {
            $out_trade_no = $getData['out_trade_no'] ?? '';
            $trade_no = $getData['trade_no'] ?? '';
            $buyer_id = $getData['buyer_id'] ?? '';
            $total_fee = $getData['total_fee'] ?? 0;

            if ($getData['trade_status'] == 'TRADE_FINISHED' || $getData['trade_status'] == 'TRADE_SUCCESS') {
                if ($out_trade_no == $ctx->order['trade_no']) {
                    return $this->processReturn($ctx->order, $trade_no, $buyer_id);
                } else {
                    return ['type' => 'error', 'msg' => '订单信息校验失败'];
                }
            } else {
                return ['type' => 'error', 'msg' => 'trade_status=' . ($getData['trade_status'] ?? '')];
            }
        } else {
            //验证失败
            return ['type' => 'error', 'msg' => '签名验证失败！'];
        }
    }

    public function query(array $order): array
    {
        $client = new AlipayGlobalClient($this->alipayConfig);
        $result = $client->sendRequest([
            'service' => 'single_trade_query',
            'partner' => trim($this->alipayConfig['partner']),
            'out_trade_no' => $order['trade_no'],
            '_input_charset' => 'utf-8',
        ]);
        if (isset($result['is_success']) && $result['is_success'] === 'T') {
            $data = $result['response']['trade'];
            return [
                'api_trade_no' => $data['trade_no'],
                'status' => $data['trade_status'] === 'TRADE_SUCCESS' || $data['trade_status'] === 'TRADE_FINISHED' ? 1 : 0,
                'money' => $data['total_fee'],
                'buyer' => $data['buyer_id'] ?? '',
                'endtime' => $data['gmt_payment'] ?? '',
            ];
        } else {
            throw new Exception($result['error'] ?? '订单查询失败');
        }
    }

    //退款
    public function refund($order): array
    {
        $params = [
            'service' => 'forex_refund',
            'partner' => trim($this->alipayConfig['partner']),
            'out_return_no' => $order['refund_no'],
            'out_trade_no' => $order['trade_no'],
            'return_rmb_amount' => $order['refundmoney'],
            'currency' => 'HKD',
            'gmt_return' => date('Y-m-d H:i:s'),
            '_input_charset' => 'utf-8',
        ];
        $client = new AlipayGlobalClient($this->alipayConfig);
        $result = $client->sendRequest($params);
        if (isset($result['is_success']) && $result['is_success'] == 'T') {
            return ['code' => 0];
        } elseif (isset($result['error'])) {
            return ['code' => 1, 'msg' => $result['error']];
        } else {
            return ['code' => 1, 'msg' => '未知错误'];
        }
    }
}
