<?php

declare(strict_types=1);

namespace plugins\payment\alipaycode;

use app\common\PaymentContext;
use app\common\BasePayment;

class AlipaycodePlugin extends BasePayment
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
            'alipay_public_key' => $this->channel['appkey'],
            'app_private_key' => $this->channel['appsecret'],
            'app_auth_token' => $this->channel['apptoken'] ?? '',
            'sign_type' => 'RSA2',
            'charset' => 'UTF-8',
            'gateway_url' => 'https://openapi.alipay.com/gateway.do',
            'cert_mode' => ($this->channel['sign_type'] ?? '0') == '1' ? 1 : 0,
        ];
        if ($config['cert_mode'] == 1) {
            $config['app_cert_path'] = getCertFilePath($this->channel['app_cert_path']);
            $config['alipay_cert_path'] = getCertFilePath($this->channel['alipay_cert_path']);
            $config['root_cert_path'] = getCertFilePath($this->channel['root_cert_path']);
        }
        return $config;
    }

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . $ctx->order['trade_no'] . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $siteurl = request()->siteurl;
        return ['type' => 'jump', 'url' => $siteurl . 'pay/qrcode/' . $ctx->order['trade_no'] . '/'];
    }

    //扫码支付
    public function qrcode(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];
        $code_url = request()->siteurl . 'pay/pay/' . $tradeNo . '/';

        if (!empty(config_get('alipay_qrcode_url'))) {
            $code_url = config_get('alipay_qrcode_url') . 'pay/pay/' . $tradeNo . '/';
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url, 'expire' => strtotime($ctx->order['addtime']) + 360];
        }
    }

    public function pay(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];

        if (config_get('alipay_wappaylogin') == 1) {
            [$user_type, $user_id] = alipay_oauth($tradeNo, $this->alipayConfig);
            $blocks = checkBlockUser($user_id, $tradeNo);
            if ($blocks) return $blocks;
        }

        /*if ($this->channel['appswitch'] == 1) {
            $params = [
                'productCode' => 'TRANSFER_TO_ALIPAY_ACCOUNT',
                'bizScene' => 'YUEBAO',
                'transAmount' => $ctx->order['realmoney'],
                'remark' => $ctx->order['trade_no'],
                'businessParams' => [
                    'returnUrl' => 'alipays://platformapi/startapp?appId=2021001167654035&nbupdate=syncforce',
                ],
                'payeeInfo' => [
                    'identity' => $this->channel['appmchid'],
                    'identityType' => 'ALIPAY_USER_ID',
                ],
            ];
            $url = 'https://render.alipay.com/p/yuyan/180020010001206672/rent-index.html?formData=' . rawurlencode(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return ['type' => 'jump', 'url' => $url];
        }*/

        return view($this->payRoot . 'view/pay.html', [
            'order' => $ctx->order,
            'appmchid' => $this->channel['appmchid'],
        ]);
    }

    public function query(array $order): array
    {
        $start_time = date('Y-m-d H:i:s', strtotime($order['addtime']) - 60);
        $end_time = date('Y-m-d H:i:s', strtotime($order['addtime']) + 360);
        $aop = new \Alipay\AlipayBillService($this->alipayConfig);
        $result = $aop->accountlogQuery($start_time, $end_time, 1, 2000);
        if (empty($result['detail_list'])) {
            throw new \Exception('时间段范围内未查询到订单');
        }
        foreach ($result['detail_list'] as $item) {
            if (isset($item['trans_memo']) && isset($item['trans_amount'])) {
                $trade_no = str_replace('请勿添加备注-', '', $item['trans_memo']);
                $money = $item['trans_amount'];
                if ($trade_no == $order['trade_no'] && $money == $order['realmoney']) {
                    return [
                        'api_trade_no' => $item['alipay_order_no'],
                        'status' => 1,
                        'money' => $money,
                        'buyer' => $item['other_account'] ?? null,
                        'endtime' => $item['trans_dt'] ?? '',
                    ];
                }
            }
        }
        throw new \Exception('时间段范围内未查询到该订单');
    }
}
