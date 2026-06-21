<?php

declare(strict_types=1);

namespace plugins\payment\alipayrp;

use app\common\PaymentContext;
use app\common\BasePayment;
use think\facade\Db;

class AlipayrpPlugin extends BasePayment
{
    private array $alipayConfig;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->alipayConfig = $this->getConfig();
    }

    private function getConfig(): array
    {
        $config = [
            'app_id' => $this->channel['appid'],
            'app_private_key' => $this->channel['appsecret'],
            'smid' => $this->channel['appmchid'],
            'sign_type' => 'RSA2',
            'charset' => 'UTF-8',
            'cert_mode' => 1,
            'app_cert_path' => getCertFilePath($this->channel['app_cert_path']),
            'alipay_cert_path' => getCertFilePath($this->channel['alipay_cert_path']),
            'root_cert_path' => getCertFilePath($this->channel['root_cert_path']),
        ];
        return $config;
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/pagepay/' . $tradeNo . '/?d=1'];
        } else {
            return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
        }
    }

    public function mapi(PaymentContext $ctx): array
    {
        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => '/pay/pagepay/' . $ctx->order['trade_no'] . '/?d=1'];
        } else {
            return $this->qrcode($ctx);
        }
    }

    /**
     * 获取收款方支付宝UID
     */
    private function getPayee(array $order): string
    {
        if (!empty($this->channel['appmchid'])) return $this->channel['appmchid'];
        $alipay_uid = Db::name('user')->where('uid', $order['uid'])->value('alipay_uid');
        return $alipay_uid ?: '';
    }

    /**
     * 扫码支付
     */
    public function qrcode(PaymentContext $ctx): array
    {
        if (empty($this->getPayee($ctx->order))) {
            return ['type' => 'error', 'msg' => '当前商户未绑定支付宝账号'];
        }

        $code_url = request()->siteurl . 'pay/pagepay/' . $ctx->order['trade_no'] . '/';
        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
    }

    /**
     * 红包转账页面支付
     */
    public function pagepay(PaymentContext $ctx): array
    {
        $config = $this->alipayConfig;
        [$user_type, $user_id] = alipay_oauth($ctx->order['trade_no'], $config);
        if ($user_type === 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        $blocks = checkBlockUser($user_id, $ctx->order['trade_no']);
        if ($blocks) return $blocks;

        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $bizContent = [
            'out_biz_no' => $ctx->order['trade_no'],
            'trans_amount' => $ctx->order['realmoney'],
            'product_code' => 'STD_RED_PACKET',
            'biz_scene' => 'PERSONAL_PAY',
            'order_title' => $ctx->ordername,
            'business_params' => json_encode(['sub_biz_scene' => 'REDPACKET', 'payer_binded_alipay_uid' => $user_id], JSON_UNESCAPED_UNICODE),
        ];
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $result = $aop->transAppPay($bizContent);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }
        if (request()->get('d') === '1') {
            $redirect = 'data.backurl';
        } else {
            $redirect = '\'/pay/ok/' . $ctx->order['trade_no'] . '/\'';
        }
        $codeUrl = 'alipays://platformapi/startApp?appId=20000125&orderSuffix=' . urlencode($result) . '#Intent;scheme=alipays;package=com.eg.android.AlipayGphone;end';
        return ['type' => 'page', 'page' => 'alipay_h5', 'data' => ['code_url' => $codeUrl, 'redirect_url' => $redirect]];
    }

    /**
     * 订单查询
     */
    public function query(array $order): array
    {
        $bizContent = [
            'product_code' => 'STD_RED_PACKET',
            'biz_scene' => 'PERSONAL_PAY',
            'out_biz_no' => $order['trade_no'],
        ];
        $aop = new \Alipay\AlipayTransferService($this->alipayConfig);
        $result = $aop->aopExecute('alipay.fund.trans.common.query', $bizContent);
        return [
            'api_trade_no' => $result['order_id'],
            'status' => $result['status'] === 'SUCCESS' ? 1 : 0,
            'money' => $result['trans_amount'] ?? '',
            'endtime' => $result['pay_date'] ?? '',
        ];
    }

    /**
     * 异步回调
     */
    public function notify(PaymentContext $ctx): array
    {
        $aop = new \Alipay\AlipayTransferService($this->alipayConfig);
        $verify_result = $aop->check(request()->post());

        if ($verify_result) {
            $msg_method = request()->post('msg_method');
            if ($msg_method === 'alipay.fund.trans.order.changed') {
                $bizContent = json_decode(request()->post('biz_content', '{}'), true);
                if ($bizContent && ($bizContent['product_code'] ?? '') === 'STD_RED_PACKET' && ($bizContent['biz_scene'] ?? '') === 'PERSONAL_PAY') {
                    $out_trade_no = $bizContent['out_biz_no']; //商户订单号
                    $order_id = $bizContent['order_id']; //支付宝转账单据号
                    $trans_amount = $bizContent['trans_amount']; //转账金额

                    $order = $ctx->order;
                    if ($out_trade_no === $order['trade_no'] && $bizContent['status'] === 'SUCCESS') {
                        if ($order['settle'] <= 1) {
                            usleep(300000);
                            $out_biz_no = date("YmdHis") . rand(11111, 99999);
                            $payee_user_id = $this->getPayee($order);
                            try {
                                $aop->redPacketTansfer($out_biz_no, $trans_amount, $payee_user_id, config_get('sitename'), $order_id);
                                $order['settle'] = 2;
                            } catch (\Throwable $e) {
                                $aop->writeLog('redPacketTansfer:' . $e->getMessage());
                                $order['settle'] = 3;
                            }
                        }
                        $this->processNotify($order, $order_id);
                    }
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    /**
     * 退款
     */
    public function refund(array $order): array
    {
        $out_biz_no = date("YmdHis") . rand(11111, 99999);
        try {
            $aop = new \Alipay\AlipayTransferService($this->alipayConfig);
            $result = $aop->redPacketRefund($out_biz_no, $order['api_trade_no'], $order['refundmoney']);
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        return [
            'code' => 0,
            'trade_no' => $result['refund_order_id'] ?? '',
            'refund_fee' => $result['refund_amount'] ?? '',
            'refund_time' => $result['refund_date'] ?? '',
        ];
    }
}
