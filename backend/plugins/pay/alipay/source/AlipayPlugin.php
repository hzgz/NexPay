<?php

declare(strict_types=1);

namespace plugins\payment\alipay;

use app\common\PaymentContext;
use app\common\BasePayment;

class AlipayPlugin extends BasePayment
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
            'sign_type' => 'RSA2',
            'charset' => 'UTF-8',
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
        $tradeNo = $ctx->order['trade_no'];
        $apptype = $this->channel['apptype'] ?? [];
        $isAlipay = $ctx->mdevice === 'alipay';

        if ($isAlipay && in_array('4', $apptype) && !in_array('2', $apptype)) {
            if (!empty(config_get('localurl_alipay')) && strpos(config_get('localurl_alipay'), request()->host()) === false) {
                return ['type' => 'jump', 'url' => config_get('localurl_alipay') . 'pay/jspay/' . $tradeNo . '/?d=1'];
            }
            return ['type' => 'jump', 'url' => '/pay/jspay/' . $tradeNo . '/?d=1'];
        } elseif ($ctx->isMobile && (in_array('3', $apptype) || in_array('4', $apptype) || in_array('8', $apptype)) && !in_array('2', $apptype) || !$ctx->isMobile && !in_array('1', $apptype)) {
            return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
        } else {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/?wap=1'];
            }
            if (!empty(config_get('localurl_alipay')) && strpos(config_get('localurl_alipay'), request()->host()) === false) {
                return ['type' => 'jump', 'url' => config_get('localurl_alipay') . 'pay/submit/' . $tradeNo . '/'];
            }

            if ($ctx->isMobile && in_array('2', $apptype)) {
                if (config_get('alipay_wappaylogin') == 1) {
                    if ($isAlipay) {
                        return ['type' => 'jump', 'url' => '/pay/submitwap/' . $tradeNo . '/?d=1'];
                    } else {
                        return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
                    }
                }
                return $this->submitwap($ctx, true);
            } elseif (in_array('1', $apptype)) {
                if (config_get('alipay_paymode') == 1 || $ctx->isMobile) {
                    return ['type' => 'jump', 'url' => '/pay/qrcodepc/' . $tradeNo . '/'];
                }
                return $this->pagePay($ctx);
            } elseif (in_array('6', $apptype)) {
                if (config_get('alipay_wappaylogin') == 1 && !$isAlipay || $ctx->isMobile && !$isAlipay) {
                    return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
                }
                return ['type' => 'jump', 'url' => '/pay/apppay/' . $tradeNo . '/?d=1'];
            } elseif (in_array('7', $apptype)) {
                return ['type' => 'jump', 'url' => '/pay/minipay/' . $tradeNo . '/?d=1'];
            } elseif (in_array('5', $apptype)) {
                if (config_get('alipay_wappaylogin') == 1 && !$isAlipay) {
                    return ['type' => 'jump', 'url' => '/pay/qrcode/' . $tradeNo . '/'];
                }
                return ['type' => 'jump', 'url' => '/pay/preauth/' . $tradeNo . '/?d=1'];
            }
        }
        return ['type' => 'jump', 'url' => '/pay/submit/' . $tradeNo . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $method = $ctx->method;
        $apptype = $this->channel['apptype'] ?? [];
        $isMobile = $ctx->isMobile;
        $mdevice = $ctx->mdevice;

        if ($method === 'app') {
            return $this->apppay($ctx);
        } elseif ($method === 'jsapi') {
            if (in_array('7', $apptype) && $ctx->order['is_applet'] == 1) {
                return $this->jsapipay($ctx);
            } else {
                return $this->jspay($ctx);
            }
        } elseif ($method === 'scan') {
            return $this->scanpay($ctx);
        } elseif ($mdevice === 'alipay' && in_array('4', $apptype) && !in_array('2', $apptype)) {
            if (!empty(config_get('localurl_alipay')) && strpos(config_get('localurl_alipay'), request()->host()) === false) {
                return ['type' => 'jump', 'url' => config_get('localurl_alipay') . 'pay/jspay/' . $ctx->order['trade_no'] . '/?d=1'];
            }
            return ['type' => 'jump', 'url' => request()->siteurl . 'pay/jspay/' . $ctx->order['trade_no'] . '/?d=1'];
        } elseif ($isMobile && (in_array('3', $apptype) || in_array('4', $apptype) || in_array('8', $apptype)) && !in_array('2', $apptype) || !$isMobile && !in_array('1', $apptype)) {
            return $this->qrcode($ctx);
        } else {
            if (!empty(config_get('localurl_alipay')) && strpos(config_get('localurl_alipay'), request()->host()) === false) {
                return ['type' => 'jump', 'url' => config_get('localurl_alipay') . 'pay/submit/' . $ctx->order['trade_no'] . '/'];
            }
            return ['type' => 'jump', 'url' => request()->siteurl . 'pay/submit/' . $ctx->order['trade_no'] . '/'];
        }
    }

    /**
     * 订单实名信息写入 bizContent（证件号、姓名、最小年龄等）
     */
    private function handleExtUser(array &$bizContent, array $order): void
    {
        if (empty($order['cert_no']) && empty($order['cert_name']) && empty($order['min_age'])) {
            return;
        }
        $ext = ['need_check_info' => 'T'];
        if (!empty($order['cert_no'])) {
            $ext['cert_type'] = 'IDENTITY_CARD';
            $ext['cert_no'] = $order['cert_no'];
        }
        if (!empty($order['cert_name'])) {
            $ext['name'] = $order['cert_name'];
        }
        if (isset($order['min_age'])) {
            $ext['min_age'] = $order['min_age'];
        }
        $bizContent['ext_user_info'] = $ext;
    }

    /**
     * 电脑网站支付
     */
    private function pagePay(PaymentContext $ctx): array
    {
        $config = $this->alipayConfig;
        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $config['return_url'] = request()->siteurl . 'pay/return/' . $ctx->order['trade_no'] . '/';
        $bizContent = [
            'out_trade_no' => $ctx->order['trade_no'],
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ];
        if (!empty($this->channel['appmchid'])) {
            $bizContent['seller_id'] = $this->channel['appmchid'];
        }
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
        $this->handleExtUser($bizContent, $ctx->order);
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $html = $aop->pagePay($bizContent);
            return ['type' => 'html', 'data' => $html];
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }
    }

    /**
     * 电脑网站支付扫码
     */
    public function qrcodepc(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->isMobile) {
            $config = $this->alipayConfig;
            $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $tradeNo . '/';
            $config['return_url'] = $siteurl . 'pay/return/' . $tradeNo . '/';
            $config['pageMethod'] = '2';
            $bizContent = [
                'out_trade_no' => $tradeNo,
                'total_amount' => $ctx->order['realmoney'],
                'subject' => $ctx->ordername,
                'qr_pay_mode' => '4',
                'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            ];
            if (!empty($this->channel['appmchid'])) {
                $bizContent['seller_id'] = $this->channel['appmchid'];
            }
            $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
            $this->handleExtUser($bizContent, $ctx->order);
            try {
                $aop = new \Alipay\AlipayTradeService($config);
                $url = $aop->pagePay($bizContent);
                $html = get_curl($url, 0, 0, 0, 0, 0, 0, 0, 1);
                $html = mb_convert_encoding($html, 'utf-8', 'gbk');
            } catch (\Throwable $e) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
            }
            if (preg_match('!<input name="qrCode" type="hidden" value="(.*?)"!i', $html, $match)) {
                $codeUrl = $match[1];
                return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $codeUrl];
            } else {
                return ['type' => 'error', 'msg' => '支付宝下单失败！获取二维码失败'];
            }
        } else {
            $codeUrl = '/pay/submitpc/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'alipay_qrcodepc', 'url' => $codeUrl];
        }
    }

    /**
     * 电脑网站支付扫码跳转
     */
    public function submitpc(PaymentContext $ctx): array
    {
        $config = $this->alipayConfig;
        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $config['return_url'] = request()->siteurl . 'pay/return/' . $ctx->order['trade_no'] . '/';
        $bizContent = [
            'out_trade_no' => $ctx->order['trade_no'],
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'qr_pay_mode' => '4',
            'qrcode_width' => '230',
            'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ];
        if (!empty($this->channel['appmchid'])) {
            $bizContent['seller_id'] = $this->channel['appmchid'];
        }
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
        $this->handleExtUser($bizContent, $ctx->order);
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $html = $aop->pagePay($bizContent);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }
        $html = '<!DOCTYPE html><html><body><style>body{margin:0;padding:0}.waiting{position:absolute;width:100%;height:100%;background:#fff url(/static/img/load.gif) no-repeat fixed center/80px;}</style><div class="waiting"></div>' . $html . '</body></html>';
        return ['type' => 'html', 'data' => $html];
    }

    /**
     * 手机网站支付扫码跳转
     */
    public function submitwap(PaymentContext $ctx, bool $useReturnUrl = false): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $config = $this->alipayConfig;

        if (config_get('alipay_wappaylogin') == 1 && $ctx->mdevice === 'alipay') {
            [$userType, $userId] = alipay_oauth($tradeNo, $config);
            $blocks = checkBlockUser($userId, $tradeNo);
            if ($blocks) return $blocks;
        }

        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $tradeNo . '/';
        if ($useReturnUrl || request()->get('d') === '1') {
            $config['return_url'] = request()->siteurl . 'pay/return/' . $tradeNo . '/';
        } else {
            $config['return_url'] = request()->siteurl . 'pay/ok/' . $tradeNo . '/';
        }
        $bizContent = [
            'out_trade_no' => $tradeNo,
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ];
        if (!empty($this->channel['appmchid'])) {
            $bizContent['seller_id'] = $this->channel['appmchid'];
        }
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
        $this->handleExtUser($bizContent, $ctx->order);
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $html = $aop->wapPay($bizContent);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }
        return ['type' => 'html', 'data' => $html];
    }

    /**
     * 扫码支付
     */
    public function qrcode(PaymentContext $ctx): array
    {
        $order = $ctx->order;
        $tradeNo = $order['trade_no'];
        $siteurl = request()->siteurl;
        $apptype = $this->channel['apptype'] ?? [];

        if (!in_array('3', $apptype) && in_array('2', $apptype)) {
            $code_url = $siteurl . 'pay/submitwap/' . $tradeNo . '/';
            if (!empty(config_get('localurl_alipay')) && strpos(config_get('localurl_alipay'), request()->host()) === false) {
                $code_url = config_get('localurl_alipay') . 'pay/submitwap/' . $tradeNo . '/';
            }
            if (request()->get('wap') && !config_get('alipay_wappaylogin') && $ctx->isMobile && $ctx->mdevice !== 'wechat') {
                return $this->submitwap($ctx);
            }
        } elseif (!in_array('3', $apptype) && in_array('4', $apptype)) {
            $code_url = $siteurl . 'pay/jspay/' . $tradeNo . '/';
            if (!empty(config_get('localurl_alipay')) && strpos(config_get('localurl_alipay'), request()->host()) === false) {
                $code_url = config_get('localurl_alipay') . 'pay/jspay/' . $tradeNo . '/';
            }
        } elseif (!in_array('3', $apptype) && in_array('6', $apptype)) {
            $code_url = $siteurl . 'pay/apppay/' . $tradeNo . '/';
            if (!empty(config_get('localurl_alipay')) && strpos(config_get('localurl_alipay'), request()->host()) === false) {
                $code_url = config_get('localurl_alipay') . 'pay/apppay/' . $tradeNo . '/';
            }
        } elseif (!in_array('3', $apptype) && in_array('7', $apptype)) {
            $code_url = $siteurl . 'pay/minipay/' . $tradeNo . '/';
        } elseif (!in_array('3', $apptype) && in_array('5', $apptype)) {
            $code_url = $siteurl . 'pay/preauth/' . $tradeNo . '/';
            if (!empty(config_get('localurl_alipay')) && strpos(config_get('localurl_alipay'), request()->host()) === false) {
                $code_url = config_get('localurl_alipay') . 'pay/preauth/' . $tradeNo . '/';
            }
        } else {
            $config = $this->alipayConfig;
            $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $tradeNo . '/';
            $bizContent = [
                'out_trade_no' => $tradeNo,
                'total_amount' => $order['realmoney'],
                'subject' => $ctx->ordername,
                'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            ];
            if (!empty($this->channel['appmchid'])) {
                $bizContent['seller_id'] = $this->channel['appmchid'];
            }
            if (!in_array('3', $apptype) && in_array('8', $apptype)) {
                $bizContent['product_code'] = 'QR_CODE_OFFLINE';
            }
            $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
            try {
                $aop = new \Alipay\AlipayTradeService($config);
                $result = $aop->qrPay($bizContent);
                $code_url = $result['qr_code'] ?? '';
            } catch (\Throwable $e) {
                return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
            }
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    /**
     * APP 支付
     */
    public function apppay(PaymentContext $ctx): array
    {
        $config = $this->alipayConfig;
        if (config_get('alipay_wappaylogin') == 1 && $ctx->mdevice === 'alipay') {
            [$userType, $userId] = alipay_oauth($ctx->order['trade_no'], $config);
            $blocks = checkBlockUser($userId, $ctx->order['trade_no']);
            if ($blocks) return $blocks;
        }

        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $bizContent = [
            'out_trade_no' => $ctx->order['trade_no'],
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ];
        if (!empty($this->channel['appmchid'])) {
            $bizContent['seller_id'] = $this->channel['appmchid'];
        }
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
        $this->handleExtUser($bizContent, $ctx->order);
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $result = $aop->appPay($bizContent);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }
        if ($ctx->method === 'app') {
            return ['type' => 'app', 'data' => $result];
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
     * 预授权支付
     */
    public function preauth(PaymentContext $ctx): array
    {
        $config = $this->alipayConfig;

        if (config_get('alipay_wappaylogin') == 1 && $ctx->mdevice === 'alipay') {
            [$userType, $userId] = alipay_oauth($ctx->order['trade_no'], $config);
            $blocks = checkBlockUser($userId, $ctx->order['trade_no']);
            if ($blocks) return $blocks;
        }

        $config['notify_url'] = config_get('localurl') . 'pay/preauthnotify/' . $ctx->order['trade_no'] . '/';
        $bizContent = [
            'out_order_no' => $ctx->order['trade_no'],
            'out_request_no' => $ctx->order['trade_no'],
            'order_title' => $ctx->ordername,
            'amount' => $ctx->order['realmoney'],
            'product_code' => 'PREAUTH_PAY',
            'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ];
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $result = $aop->preAuthFreeze($bizContent);
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
     * 当面付JS支付
     */
    public function jspay(PaymentContext $ctx): array
    {
        $config = $this->alipayConfig;
        $user_id = $ctx->order['sub_openid'] ?? null;
        if ($user_id) {
            $user_type = is_numeric($user_id) && substr($user_id, 0, 4) == '2088' ? 'userid' : 'openid';
        } else {
            [$user_type, $user_id] = alipay_oauth($ctx->order['trade_no'], $config);
        }
        $blocks = checkBlockUser($user_id, $ctx->order['trade_no']);
        if ($blocks) return $blocks;

        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $bizContent = [
            'out_trade_no' => $ctx->order['trade_no'],
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ];
        if (!empty($this->channel['appmchid'])) {
            $bizContent['seller_id'] = $this->channel['appmchid'];
        }
        $bizContent[$user_type === 'userid' ? 'buyer_id' : 'buyer_open_id'] = $user_id;
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $result = $aop->jsPay($bizContent);
            $alipay_trade_no = $result['trade_no'] ?? '';
            if ($ctx->method === 'jsapi') {
                return ['type' => 'jsapi', 'data' => $alipay_trade_no];
            }
            if (request()->get('d') === '1') {
                $redirect = 'data.backurl';
            } else {
                $redirect = '\'/pay/ok/' . $ctx->order['trade_no'] . '/\'';
            }
            return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $alipay_trade_no, 'redirect_url' => $redirect]];
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }
    }

    /**
     * JSAPI支付
     */
    public function jsapipay(PaymentContext $ctx): array
    {
        $user_id = $ctx->order['sub_openid'] ?? '';
        if (empty($user_id)) {
            return ['type' => 'error', 'msg' => '缺少用户标识'];
        }
        $user_type = is_numeric($user_id) && substr($user_id, 0, 4) === '2088' ? 'userid' : 'openid';
        $blocks = checkBlockUser($user_id, $ctx->order['trade_no']);
        if ($blocks) {
            return $blocks;
        }
        $config = $this->alipayConfig;
        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $bizContent = [
            'out_trade_no' => $ctx->order['trade_no'],
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'product_code' => 'JSAPI_PAY',
            'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'op_app_id' => !empty($ctx->order['sub_appid']) ? $ctx->order['sub_appid'] : $config['app_id'],
        ];
        if (!empty($this->channel['appmchid'])) {
            $bizContent['seller_id'] = $this->channel['appmchid'];
        }
        $bizContent[$user_type === 'openid' ? 'op_buyer_open_id' : 'buyer_id'] = $user_id;
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $result = $aop->jsPay($bizContent);
            return ['type' => 'jsapi', 'data' => $result['trade_no'] ?? ''];
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }
    }

    /**
     * 支付宝小程序支付
     */
    public function alipaymini(PaymentContext $ctx): array
    {
        $auth_code = request()->get('auth_code', '', 'trim');
        if ($auth_code === '') {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'auth_code不能为空']];
        }
        $config = $this->alipayConfig;
        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        try {
            [$app_id, $userType, $userId] = alipay_mini_oauth($ctx->order['trade_no'], $auth_code, $config);
        } catch (\Throwable $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($userId, $ctx->order['trade_no']);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg'] ?? '']];
        }

        $bizContent = [
            'out_trade_no' => $ctx->order['trade_no'],
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'product_code' => 'JSAPI_PAY',
            'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'op_app_id' => $app_id,
        ];
        if (!empty($this->channel['appmchid'])) {
            $bizContent['seller_id'] = $this->channel['appmchid'];
        }
        $bizContent[$userType === 'openid' ? 'op_buyer_open_id' : 'buyer_id'] = $userId;
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $result = $aop->jsPay($bizContent);
            $alipay_trade_no = $result['trade_no'] ?? '';
        } catch (\Throwable $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付宝下单失败！' . $e->getMessage()]];
        }
        return ['type' => 'json', 'data' => ['code' => 0, 'data' => $alipay_trade_no]];
    }

    /**
     * H5跳转小程序支付
     */
    public function minipay(PaymentContext $ctx): array
    {
        $code_url = alipaymini_jump_scheme($ctx->order, $this->channel['appid'] ?? null);
        if (request()->get('d') === '1') {
            $redirect = 'data.backurl';
        } else {
            $redirect = '\'/pay/ok/' . $ctx->order['trade_no'] . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_h5', 'data' => ['code_url' => $code_url, 'redirect_url' => $redirect]];
    }

    /**
     * 付款码支付
     */
    public function scanpay(PaymentContext $ctx): array
    {
        $authCode = $ctx->order['auth_code'] ?? '';
        if (empty($authCode)) {
            return ['type' => 'error', 'msg' => '缺少付款码'];
        }
        $config = $this->alipayConfig;
        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $bizContent = [
            'out_trade_no' => $ctx->order['trade_no'],
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'auth_code' => $authCode,
            'scene' => 'bar_code',
        ];
        if (!empty($this->channel['appmchid'])) {
            $bizContent['seller_id'] = $this->channel['appmchid'];
        }
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];
        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $result = $aop->scanPay($bizContent);
            $buyerId = $result['buyer_user_id'] ?? $result['buyer_open_id'] ?? '';
            return ['type' => 'scan', 'data' => [
                'type' => $ctx->order['typename'],
                'trade_no' => $result['out_trade_no'] ?? '',
                'api_trade_no' => $result['trade_no'] ?? '',
                'buyer' => $buyerId,
                'money' => $result['total_amount'] ?? '',
            ]];
        } catch (\Alipay\Aop\AlipayResponseException $e) {
            $code = $e->getRetCode();
            if ($code === '10003' || $code === '20000') {
                if ($code === '10003') sleep(2);
                $retry = 0;
                $success = false;
                $result = null;
                while ($retry < 6) {
                    sleep(3);
                    try {
                        $result = $aop->query(null, $ctx->order['trade_no']);
                    } catch (\Throwable $ex) {
                        return ['type' => 'error', 'msg' => '支付宝支付失败！订单查询失败:' . $ex->getMessage()];
                    }
                    if (($result['trade_status'] ?? '') === 'TRADE_SUCCESS') {
                        $success = true;
                        break;
                    } elseif (($result['trade_status'] ?? '') !== 'WAIT_BUYER_PAY') {
                        return ['type' => 'error', 'msg' => '支付宝支付失败！订单超时或用户取消支付'];
                    }
                    $retry++;
                }
                if ($success && $result) {
                    $buyerId = $result['buyer_user_id'] ?? $result['buyer_open_id'] ?? '';
                    return ['type' => 'scan', 'data' => [
                        'type' => $ctx->order['typename'],
                        'trade_no' => $result['out_trade_no'] ?? '',
                        'api_trade_no' => $result['trade_no'] ?? '',
                        'buyer' => $buyerId,
                        'money' => $result['total_amount'] ?? '',
                    ]];
                } else {
                    try {
                        $aop->cancel(['out_trade_no' => $ctx->order['trade_no']]);
                    } catch (\Throwable $ex) {
                    }
                    return ['type' => 'error', 'msg' => '支付宝支付失败！订单已超时'];
                }
            }
            return ['type' => 'error', 'msg' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }
    }

    /**
     * 异步回调
     */
    public function notify(PaymentContext $ctx): array
    {
        $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
        $verify_result = $aop->check(request()->post());

        if ($verify_result) {
            // 商户订单号
            $out_trade_no = request()->post('out_trade_no');
            // 支付宝交易号
            $trade_no = request()->post('trade_no');
            // 买家支付宝
            $buyer_id = request()->post('buyer_id') ?: request()->post('buyer_open_id');
            // 交易金额
            $total_amount = (float) request()->post('total_amount', 0);
            $order = $ctx->order;
            $trade_status = request()->post('trade_status');
            $end_time = request()->post('gmt_payment');

            if ($trade_status === 'TRADE_FINISHED') {
                // 退款日期超过可退款期限后（如三个月可退款），支付宝系统发送该交易状态通知
            } elseif ($trade_status === 'TRADE_SUCCESS') {
                if ($out_trade_no === $order['trade_no'] && round($total_amount, 2) == round((float) $order['realmoney'], 2)) {
                    ($this->markTrustedCallback($ctx, 'notify', 'alipay-rsa-check'))(function () use ($order, $trade_no, $buyer_id, $end_time) {
                        $this->processNotify($order, $trade_no, $buyer_id, null, null, $end_time);
                    });
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            // 验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    /**
     * 同步回调
     */
    public function return(PaymentContext $ctx): array
    {
        $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
        $verify_result = $aop->check(request()->get());

        if ($verify_result) {
            // 商户订单号
            $out_trade_no = request()->get('out_trade_no');
            // 支付宝交易号
            $trade_no = request()->get('trade_no');
            // 交易金额
            $total_amount = (float) request()->get('total_amount', 0);
            $order = $ctx->order;

            if ($out_trade_no === $order['trade_no'] && round($total_amount, 2) == round((float) $order['realmoney'], 2)) {
                return ($this->markTrustedCallback($ctx, 'return', 'alipay-rsa-check'))(function () use ($order, $trade_no) {
                    return $this->processReturn($order, $trade_no);
                });
            } else {
                return ['type' => 'error', 'msg' => '订单信息校验失败'];
            }
        } else {
            // 验证失败
            return ['type' => 'error', 'msg' => '支付宝返回验证失败'];
        }
    }

    /**
     * 预授权支付回调
     */
    public function preauthnotify(PaymentContext $ctx): array
    {
        $config = $this->alipayConfig;
        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $aop = new \Alipay\AlipayService($config);
        $verify_result = $aop->check(request()->post());

        if ($verify_result) {
            // 商户订单号
            $out_trade_no = request()->post('out_order_no');
            // 资金授权订单号
            $auth_no = request()->post('auth_no');
            $buyer_id = request()->post('payer_user_id');

            if ($out_trade_no === $ctx->order['trade_no']) {
                $bizContent = [
                    'out_trade_no' => $ctx->order['trade_no'],
                    'total_amount' => $ctx->order['realmoney'],
                    'subject' => $ctx->ordername,
                    'product_code' => 'PREAUTH_PAY',
                    'auth_no' => $auth_no,
                    'auth_confirm_mode' => 'COMPLETE',
                ];
                if (!empty($this->channel['appmchid'])) {
                    $bizContent['seller_id'] = $this->channel['appmchid'];
                }
                try {
                    $tradeService = new \Alipay\AlipayTradeService($config);
                    $result = $tradeService->scanPay($bizContent);
                } catch (\Throwable $e) {
                    $this->updateOrder($ctx->order['trade_no'], $auth_no, $buyer_id, 4);
                    return ['type' => 'html', 'data' => 'success'];
                    //return ['type'=>'error','msg'=>'支付宝下单失败！'.$e->getMessage()];
                }
                $trade_no = $result['trade_no'] ?? '';
                $buyer_id = $result['buyer_user_id'] ?? $result['buyer_open_id'] ?? $buyer_id;
                $total_amount = $result['total_amount'];
                ($this->markTrustedCallback($ctx, 'notify', 'alipay-preauth-check'))(function () use ($ctx, $trade_no, $buyer_id) {
                    $this->processNotify($ctx->order, $trade_no, $buyer_id);
                });
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            // 验证失败
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    /**
     * 退款
     */
    public function refund(array $order): array
    {
        $bizContent = [
            'trade_no' => $order['api_trade_no'],
            'refund_amount' => $order['refundmoney'],
            'out_request_no' => $order['refund_no'],
        ];
        try {
            $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
            $result = $aop->refund($bizContent);
            return [
                'code' => 0,
                'trade_no' => $result['trade_no'] ?? '',
                'refund_fee' => $result['refund_fee'] ?? '',
            ];
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 关闭订单
     */
    public function close(array $order): array
    {
        $bizContent = ['out_trade_no' => $order['trade_no']];
        try {
            $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
            $aop->close($bizContent);
            return ['code' => 0];
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 订单查询
     */
    public function query(array $order): array
    {
        $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
        $result = $aop->query(null, $order['trade_no']);
        return [
            'api_trade_no' => $result['trade_no'],
            'status' => $result['trade_status'] == 'TRADE_SUCCESS' || $result['trade_status'] == 'TRADE_FINISHED' ? 1 : 0,
            'money' => $result['total_amount'],
            'buyer' => $result['buyer_user_id'] ?? $result['buyer_open_id'] ?? '',
            'endtime' => $result['send_pay_date'] ?? '',
        ];
    }

    /**
     * 转账（单笔到支付宝/银行卡）
     */
    public function transfer(array $bizParam): array
    {
        $config = $this->alipayConfig;
        try {
            $transfer = new \Alipay\AlipayService($config);
            if ($bizParam['type'] === 'alipay') {

                if (!empty($config['app_cert_path']) && !empty($config['alipay_cert_path']) && !empty($config['root_cert_path'])) {
                    $payee_type = is_numeric($bizParam['payee_account'] ?? '') && substr($bizParam['payee_account'], 0, 4) === '2088' ? 'ALIPAY_USER_ID' : (strpos($bizParam['payee_account'], '@') !== false || is_numeric($bizParam['payee_account']) ? 'ALIPAY_LOGON_ID' : 'ALIPAY_OPEN_ID');
                    $bizContent = [
                        'out_biz_no' => $bizParam['out_biz_no'],
                        'trans_amount' => (string) $bizParam['money'],
                        'product_code' => 'TRANS_ACCOUNT_NO_PWD',
                        'biz_scene' => 'DIRECT_TRANSFER',
                        'order_title' => $bizParam['transfer_name'] ?? '',
                        'payee_info' => [
                            'identity' => $bizParam['payee_account'],
                            'identity_type' => $payee_type
                        ],
                        'business_params' => json_encode(['payer_show_name_use_alias' => 'true']),
                    ];
                    if (!empty($bizParam['payee_real_name'])) {
                        $bizContent['payee_info']['name'] = $bizParam['payee_real_name'];
                    }
                    if (!empty($bizParam['transfer_desc'])) {
                        $bizContent['remark'] = $bizParam['transfer_desc'];
                    }
                    if (!empty(config_get('transfer_alipay_scene_name'))) {
                        $bizContent['transfer_scene_name'] = config_get('transfer_alipay_scene_name');
                        $bizContent['transfer_scene_report_infos'] = [];
                        $info_types = explode('|', config_get('transfer_alipay_info_type', ''));
                        $info_contents = explode('|', config_get('transfer_alipay_info_content', ''));
                        foreach ($info_types as $i => $info_type) {
                            $bizContent['transfer_scene_report_infos'][] = [
                                'info_type' => $info_type,
                                'info_content' => $info_contents[$i] ?? $info_contents[0] ?? '',
                            ];
                        }
                    }
                    $result = $transfer->aopExecute('alipay.fund.trans.uni.transfer', $bizContent);
                    return ['code' => 0, 'status' => 1, 'orderid' => $result['order_id'] ?? '', 'paydate' => $result['trans_date'] ?? ''];

                } else {
                    $payee_type = is_numeric($bizParam['payee_account']) && substr($bizParam['payee_account'], 0, 4) === '2088' ? 'ALIPAY_USERID' : 'ALIPAY_LOGONID';
                    $bizContent = [
                        'out_biz_no' => $bizParam['out_biz_no'],
                        'payee_type' => $payee_type,
                        'payee_account' => $bizParam['payee_account'],
                        'amount' => (string) $bizParam['money'],
                        'payer_show_name' => $bizParam['transfer_name'] ?? '',
                    ];
                    if (!empty($bizParam['payee_real_name'])) {
                        $bizContent['payee_real_name'] = $bizParam['payee_real_name'];
                    }
                    if (!empty($bizParam['transfer_desc'])) {
                        $bizContent['remark'] = $bizParam['transfer_desc'];
                    }
                    $result = $transfer->aopExecute('alipay.fund.trans.toaccount.transfer', $bizContent);
                    return ['code' => 0, 'status' => 1, 'orderid' => $result['order_id'] ?? '', 'paydate' => $result['pay_date'] ?? ''];
                }
                
            } else {
                $bizContent = [
                    'out_biz_no' => $bizParam['out_biz_no'],
                    'trans_amount' => (string) $bizParam['money'],
                    'product_code' => 'TRANS_BANKCARD_NO_PWD',
                    'biz_scene' => 'DIRECT_TRANSFER',
                    'order_title' => $bizParam['transfer_name'] ?? '',
                    'payee_info' => [
                        'identity_type' => 'BANKCARD_ACCOUNT',
                        'identity' => $bizParam['payee_account'],
                        'name' => $bizParam['payee_real_name'],
                        'bankcard_ext_info' => [
                            'account_type' => '2'
                        ],
                    ],
                ];
                if (!empty($bizParam['transfer_desc'])) {
                    $bizContent['remark'] = $bizParam['transfer_desc'];
                }
                if (!empty(config_get('transfer_alipay_scene_name'))) {
                    $bizContent['transfer_scene_name'] = config_get('transfer_alipay_scene_name');
                    $bizContent['transfer_scene_report_infos'] = [];
                    $info_types = explode('|', config_get('transfer_alipay_info_type', ''));
                    $info_contents = explode('|', config_get('transfer_alipay_info_content', ''));
                    foreach ($info_types as $i => $info_type) {
                        $bizContent['transfer_scene_report_infos'][] = [
                            'info_type' => $info_type,
                            'info_content' => $info_contents[$i] ?? $info_contents[0] ?? '',
                        ];
                    }
                }
                $result = $transfer->aopExecute('alipay.fund.trans.uni.transfer', $bizContent);
                return ['code' => 0, 'status' => 1, 'orderid' => $result['order_id'] ?? '', 'paydate' => $result['trans_date'] ?? ''];
            }
        } catch (\Alipay\Aop\AlipayResponseException $e) {
            return ['code' => -1, 'errcode' => $e->getErrCode(), 'msg' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 转账查询
     */
    public function transfer_query(array $bizParam): array
    {
        try {
            $aop = new \Alipay\AlipayTransferService($this->alipayConfig);
            $result = $aop->query($bizParam['orderid'], 1);
            if (($result['status'] ?? '') === 'SUCCESS') {
                $status = 1;
            } elseif (($result['status'] ?? '') === 'DEALING' || ($result['status'] ?? '') === 'WAIT_PAY') {
                $status = 0;
            } else {
                $status = 2;
            }
            $errmsg = '';
            if (!empty($result['fail_reason'])) {
                $errmsg = '[' . $result['error_code'] . ']' . $result['fail_reason'];
            }
            return [
                'code' => 0,
                'status' => $status,
                'amount' => $result['trans_amount'] ?? '',
                'paydate' => $result['pay_date'] ?? '',
                'errmsg' => $errmsg,
            ];
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 电子回单
     */
    public function transfer_proof(array $bizParam): array
    {
        try {
            $aop = new \Alipay\AlipayBillService($this->alipayConfig);
            $out_biz_no = $bizParam['out_biz_no'];
            $session_key = 'ereceipt_' . $out_biz_no;
            if (session('?' . $session_key)) {
                $file_id = session($session_key);
            } else {
                $result = $aop->ereceiptApply('FUND_DETAIL', $bizParam['orderid'] ?? '');
                $file_id = $result['file_id'] ?? '';
                usleep(300000);
            }
            $result = $aop->ereceiptQuery($file_id);
            if (($result['status'] ?? '') === 'SUCCESS') {
                session($session_key, $file_id);
                return ['code' => 0, 'msg' => '电子回单生成成功！', 'download_url' => $result['download_url'] ?? ''];
            }
            if (($result['status'] ?? '') === 'FAIL') {
                return ['code' => -1, 'msg' => '电子回单生成失败，' . ($result['error_message'] ?? '')];
            }
            session($session_key, $file_id);
            return ['code' => 0, 'msg' => '电子回单正在生成中，请稍后再试！'];
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 余额查询
     */
    public function balance_query(array $bizParam): array
    {
        $config = $this->alipayConfig;
        $user_id = $bizParam['user_id'] ?? '';
        $user_type = is_numeric($user_id) && substr($user_id, 0, 4) === '2088' ? 0 : 1;
        try {
            $aop = new \Alipay\AlipayTransferService($this->alipayConfig);
            $result = $aop->accountQuery($user_id, $user_type);
            $available = $result['available_amount'] ?? '0';
            $freeze = $result['freeze_amount'] ?? '0';
            $msg = '账户可用余额：' . $available . '元，冻结余额：' . $freeze . '元';

            if (!empty($config['app_cert_path']) && !empty($config['alipay_cert_path']) && !empty($config['root_cert_path'])) {
                $product_code = 'DEFAULT';
                $biz_scene = 'DEFAULT';
            } elseif ($bizParam['type'] === 'bank') {
                $product_code = 'TRANS_BANKCARD_NO_PWD';
                $biz_scene = 'DIRECT_TRANSFER';
            } else {
                $product_code = 'TRANS_ACCOUNT_NO_PWD';
                $biz_scene = 'DIRECT_TRANSFER';
            }
            $result = $aop->quotaQuery($product_code, $biz_scene);
            $limit_types = ['SECURITY_PUNISHED' => '安全限制', 'ACCOUNT_QUOTA_LIMITED' => '账户额度限制'];
            if (isset($result['active_quota_is_new']) && $result['active_quota_is_new'] === true) {
                $msg .= '；单笔最大金额：' . $result['new_quota_single_max'] . '元，单日剩余额度：' . $result['new_quota_daily_remain'] . '元';
                if (isset($result['active_new_quota_daily_remain_limited']) && $result['active_new_quota_daily_remain_limited'] === true && isset($result['active_new_quota_daily_remain_limit_type'])) {
                    $msg .= '['.$limit_types[$result['active_new_quota_daily_remain_limit_type']].']';
                }
                $msg .= '，单月剩余额度：' . $result['new_quota_monthly_remain'] . '元';
                if (isset($result['active_new_quota_monthly_remain_limited']) && $result['active_new_quota_monthly_remain_limited'] === true && isset($result['active_new_quota_monthly_remain_limit_type'])) {
                    $msg .= '['.$limit_types[$result['active_new_quota_monthly_remain_limit_type']].']';
                }
            } else {
                $msg .= '；单日剩余额度：对公' . $result['to_corporate_daily_available_amount'] . '元/对私'.$result['to_private_daily_available_amount'].'元，单月剩余额度：对公' . $result['to_corporate_monthly_available_amount'] . '元/对私'.$result['to_private_monthly_available_amount'].'元';
            }
            return [
                'code' => 0,
                'amount' => $available,
                'msg' => $msg,
            ];
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 协议签约回调
     */
    public function signnotify(PaymentContext $ctx): array
    {
        $aop = new \Alipay\AlipayService($this->alipayConfig);
        $verify_result = $aop->check(request()->post());
        if ($verify_result) {
            if (request()->post('personal_product_code') === 'FUND_SAFT_SIGN_WITHHOLDING_P') {
                if (request()->post('status') === 'NORMAL') {
                    if (\app\lib\AddonManager::isEnabled('alipaysatf')) {
                        (new \plugins\addons\alipaysatf\lib\AlipaySATF())->signNotify(request()->post());
                    }
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'check sign fail'];
        }
    }

    /**
     * 支付宝应用网关
     */
    public function appgw(PaymentContext $ctx): array
    {
        $aop = new \Alipay\AlipayService($this->alipayConfig);
        $verify_result = $aop->check(request()->post());
        if ($verify_result) {
            $msg_method = request()->post('msg_method');
            $biz_content = request()->post('biz_content', '{}');
            if ($msg_method === 'alipay.merchant.tradecomplain.changed') {
                // 交易投诉通知回调
                $bizContent = json_decode($biz_content, true);
                if ($bizContent && isset($bizContent['complain_event_id'])) {
                    $model = \app\logic\ComplainLogic::getModel($this->channel);
                    $model->refreshNewInfo($bizContent['complain_event_id']);
                }
            } elseif ($msg_method === 'alipay.fund.trans.order.changed') {
                // 资金单据状态变更通知
                $bizContent = json_decode($biz_content, true);
                if ($bizContent && ($bizContent['product_code'] ?? '') === 'FUND_ACCOUNT_BOOK' && ($bizContent['biz_scene'] ?? '') === 'SATF_DEPOSIT') { //记账本充值回调
                    if (\app\lib\AddonManager::isEnabled('alipaysatf')) {
                        (new \plugins\addons\alipaysatf\lib\AlipaySATF())->rechargeNotify($bizContent);
                    }
                } elseif ($bizContent && ($bizContent['product_code'] ?? '') === 'SINGLE_TRANSFER_NO_PWD' && ($bizContent['biz_scene'] ?? '') === 'ENTRUST_TRANSFER') { //转账下发回调
                    if (\app\lib\AddonManager::isEnabled('alipaysatf')) {
                        (new \plugins\addons\alipaysatf\lib\AlipaySATF())->transferNotify($bizContent);
                    }
                } elseif ($bizContent && ($bizContent['product_code'] ?? '') === 'SINGLE_TRANSFER_NO_PWD' && ($bizContent['biz_scene'] ?? '') === 'ENTRUST_ALLOCATION') { //记账本调拨回调
                    if (\app\lib\AddonManager::isEnabled('alipaysatf')) {
                        (new \plugins\addons\alipaysatf\lib\AlipaySATF())->transferNotify($bizContent);
                    }
                }
            } elseif ($msg_method === 'alipay.fund.expandindirect.order.changed') {
                // 资金二级商户KYB代进件状态通知
                $bizContent = json_decode($biz_content, true);
                if ($bizContent && isset($bizContent['order_id']) && \app\lib\AddonManager::isEnabled('alipaysatf')) {
                    (new \plugins\addons\alipaysatf\lib\AlipaySATF())->applyNotify($bizContent);
                }
            } elseif ($msg_method === 'alipay.security.risk.complaints.merchants.notify') {
                // 商户交易投诉通知
                $bizContent = json_decode($biz_content, true);
                if ($bizContent && isset($bizContent['complaint_id'])) {
                    $model = \app\logic\ComplainLogic::getModel($this->channel);
                    $model->refreshNewInfo($bizContent['complaint_id'], $bizContent);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        } else {
            return ['type' => 'html', 'data' => 'check sign fail'];
        }
    }
}
