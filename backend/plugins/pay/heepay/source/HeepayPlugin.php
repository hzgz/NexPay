<?php

declare(strict_types=1);

namespace plugins\payment\heepay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

/**
 * https://open.heepay.com/www/index.html#/openDoc?type=menu&id=2022
 */
class HeepayPlugin extends BasePayment
{
    private function getPayParam(PaymentContext $ctx, string $pay_type, ?string $sub_appid = null, ?string $sub_openid = null): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $param = [
            'version' => '1',
            'pay_type' => $pay_type,
            'agent_id' => $this->channel['appid'],
            'agent_bill_id' => $tradeNo,
            'agent_bill_time' => date('YmdHis'),
            'pay_amt' => $ctx->order['realmoney'],
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'user_ip' => str_replace('.', '_', request()->clientip),
            'goods_name' => mb_convert_encoding($ctx->ordername, 'GBK', 'UTF-8'),
            'sign_type' => 'MD5'
        ];
        if (!empty($this->channel['appmchid'])) $param['ref_agent_id'] = $this->channel['appmchid'];
        if ($ctx->isMobile) {
            $param['is_phone'] = '1';
        }
        $meta_option = [];
        if ($ctx->order['profits'] > 0) $meta_option['is_guarantee'] = '1';
        if ($pay_type == '30' && $sub_appid && $sub_openid) {
            $meta_option['s'] = '微信小程序';
            $meta_option['n'] = '在线商城';
            $meta_option['id'] = $siteurl;
            $meta_option['is_minipg'] = '1';
            $meta_option['wx_openid'] = $sub_openid;
            $meta_option['wx_sub_appid'] = $sub_appid;
        } elseif ($pay_type == '30' && $ctx->mdevice === 'wechat') {
            $param['is_frame'] = '1';
            $meta_option['s'] = 'WAP';
            $meta_option['n'] = '在线商城';
            $meta_option['id'] = $siteurl;
        }
        if (!empty($meta_option)) {
            $param['meta_option'] = base64_encode(mb_convert_encoding(json_encode($meta_option, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'GBK', 'UTF-8'));
        }
        if (!empty($this->channel['bank_id'])) {
            $bank_id = $this->channel['bank_id'];
            if (strpos($bank_id, ',') !== false) {
                $bank_ids = explode(',', $bank_id);
                $bank_id = $bank_ids[array_rand($bank_ids)];
            }
            $param['bank_id'] = $bank_id;
        }

        $signstr = 'version=' . $param['version'] . '&agent_id=' . $param['agent_id'] . '&agent_bill_id=' . $param['agent_bill_id'] . '&agent_bill_time=' . $param['agent_bill_time'] . '&pay_type=' . $param['pay_type'] . '&pay_amt=' . $param['pay_amt'] . '&notify_url=' . $param['notify_url'] . '&return_url=' . $param['return_url'] . '&user_ip=' . $param['user_ip'] . '&key=' . $this->channel['appkey'];
        if (!empty($param['ref_agent_id'])) $signstr .= '&ref_agent_id=' . $param['ref_agent_id'];
        $param['sign'] = md5($signstr);
        return $param;
    }

    private function getBankPayParam(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $param = [
            'version' => '3',
            'pay_type' => '20',
            'pay_code' => '0',
            'agent_id' => $this->channel['appid'],
            'agent_bill_id' => $tradeNo,
            'agent_bill_time' => date('YmdHis'),
            'pay_amt' => $ctx->order['realmoney'],
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'user_ip' => str_replace('.', '_', request()->clientip),
            'goods_name' => mb_convert_encoding($ctx->ordername, 'GBK', 'UTF-8'),
            'bank_card_type' => '-1',
            'sign_type' => 'MD5'
        ];
        if (!empty($this->channel['appmchid'])) $param['ref_agent_id'] = $this->channel['appmchid'];
        if ($ctx->order['profits'] > 0) $param['meta_option'] = base64_encode('{"is_guarantee":"1"}');

        $signstr = 'version=' . $param['version'] . '&agent_id=' . $param['agent_id'] . '&agent_bill_id=' . $param['agent_bill_id'] . '&agent_bill_time=' . $param['agent_bill_time'] . '&pay_type=' . $param['pay_type'] . '&pay_amt=' . $param['pay_amt'] . '&notify_url=' . $param['notify_url'] . '&return_url=' . $param['return_url'] . '&user_ip=' . $param['user_ip'] . '&bank_card_type=' . $param['bank_card_type'] . '&key=' . $this->channel['appkey'];
        if (!empty($param['ref_agent_id'])) $signstr .= '&ref_agent_id=' . $param['ref_agent_id'];
        $param['sign'] = md5($signstr);
        return $param;
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            $pay_type = '22';
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->isMobile && $ctx->mdevice !== 'wechat') {
                return ['type' => 'jump', 'url' => '/pay/wxwappay/' . $tradeNo . '/'];
            }
            $pay_type = '30';
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('3', $this->channel['apptype'])) {
                $pay_type = '34';
            } elseif (in_array('1', $this->channel['apptype'])) {
                $pay_type = '20';
            } else {
                $pay_type = $ctx->isMobile ? '34' : '64';
            }
        }

        $apiurl = 'https://pay.Heepay.com/Payment/Index.aspx';
        $param = $pay_type == '20' ? $this->getBankPayParam($ctx) : $this->getPayParam($ctx, $pay_type);
        $url = $apiurl . '?' . http_build_query($param);

        return ['type' => 'jump', 'url' => $url];
    }

    public function mapi(PaymentContext $ctx): array
    {
        if ($ctx->method === 'app' && $ctx->order['typename'] == 'wxpay') {
            return $this->wxapppay($ctx);
        } elseif ($ctx->order['typename'] == 'alipay') {
            $pay_type = '22';
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->isMobile && $ctx->mdevice !== 'wechat') {
                return $this->wxwappay($ctx);
            }
            $pay_type = '30';
        } elseif ($ctx->order['typename'] == 'bank') {
            if (in_array('3', $this->channel['apptype'])) {
                $pay_type = '34';
            } elseif (in_array('1', $this->channel['apptype'])) {
                $pay_type = '20';
            } else {
                $pay_type = $ctx->isMobile ? '34' : '64';
            }
        }

        $apiurl = 'https://pay.Heepay.com/Payment/Index.aspx';
        $param = $pay_type == '20' ? $this->getBankPayParam($ctx) : $this->getPayParam($ctx, $pay_type);
        $url = $apiurl . '?' . http_build_query($param);

        return ['type' => 'jump', 'url' => $url];
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $code = request()->get('code', '', 'trim');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        //①、获取用户openid
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];

        //②、统一下单
        $apiurl = 'https://pay.Heepay.com/Payment/Index.aspx';
        $param = $this->getPayParam($ctx, '30', $wxinfo['appid'], $openid);
        $response = get_curl($apiurl, http_build_query($param));
        if (!$response) return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '接口请求失败']];
        $result = json_decode($response, true);
        if (isset($result['package'])) {
            return ['type' => 'json', 'data' => ['code' => 0, 'data' => $result]];
        } elseif (preg_match('!Object moved to <a href=\"(.*?)\">here!', $response, $match)) {
            if (strpos($match[1], 'Error.aspx?message=')) {
                $message = explode('Error.aspx?message=', $match[1])[1];
                $message = $this->unicode_urldecode($message);
                return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $message]];
            } else {
                return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！']];
            }
        } else {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！']];
        }
    }

    //汇付宝微信小程序支付
    private function appletpay(PaymentContext $ctx)
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        $apiurl = 'https://pay.heepay.com/Phone/SDK/PayInit.aspx';
        $param = [
            'version' => '1',
            'pay_type' => '30',
            'agent_id' => $this->channel['appid'],
            'agent_bill_id' => $tradeNo,
            'agent_bill_time' => date('YmdHis'),
            'pay_amt' => $ctx->order['realmoney'],
            'notify_url' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            'return_url' => $siteurl . 'pay/return/' . $tradeNo . '/',
            'user_ip' => str_replace('.', '_', request()->clientip),
            'goods_name' => mb_convert_encoding($ctx->ordername, 'GBK', 'UTF-8'),
            'sign_type' => 'MD5'
        ];
        if (!empty($this->channel['appmchid'])) $param['ref_agent_id'] = $this->channel['appmchid'];
        $meta_option = ['s' => '微信小程序', 'n' => '在线商城', 'id' => $siteurl];
        if ($ctx->order['profits'] > 0) $meta_option['is_guarantee'] = '1';
        $param['meta_option'] = base64_encode(mb_convert_encoding(json_encode($meta_option, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'GBK', 'UTF-8'));
        if (!empty($this->channel['bank_id'])) {
            $bank_id = $this->channel['bank_id'];
            if (strpos($bank_id, ',') !== false) {
                $bank_ids = explode(',', $bank_id);
                $bank_id = $bank_ids[array_rand($bank_ids)];
            }
            $param['bank_id'] = $bank_id;
        }

        $signstr = 'version=' . $param['version'] . '&agent_id=' . $param['agent_id'] . '&agent_bill_id=' . $param['agent_bill_id'] . '&agent_bill_time=' . $param['agent_bill_time'] . '&pay_type=' . $param['pay_type'] . '&pay_amt=' . $param['pay_amt'] . '&notify_url=' . $param['notify_url'] . '&user_ip=' . $param['user_ip'] . '&key=' . $this->channel['appkey'];
        $param['sign'] = md5($signstr);

        return self::lockPayData($tradeNo, function () use ($apiurl, $param) {
            $response = get_curl($apiurl, http_build_query($param));
            if (!$response) throw new Exception('接口请求失败');
            if (preg_match('!<token_id>(.*?)</token_id>!', $response, $match)) {
                $token_id = $match[1];
            } elseif (preg_match('!<error>(.*?)</error>!', $response, $match)) {
                throw new Exception($match[1]);
            } else {
                throw new Exception('接口调用失败');
            }
            return $token_id;
        });
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype'])) {
            try {
                $token_id = $this->appletpay($ctx);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => '微信支付下单失败，' . $e->getMessage()];
            }
            $url = 'https://pay.heepay.com/MSite/Cashier/Code2Session.aspx?appid=wxfac21f54eeaabb58&token_id=' . $token_id;
            $response = get_curl($url);
            if (!$response) return ['type' => 'error', 'msg' => '接口请求失败'];
            $arr = json_decode($response, true);
            if (isset($arr['openlink'])) {
                return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $arr['openlink']];
            } else {
                return ['type' => 'error', 'msg' => '小程序跳转链接生成失败，' . ($arr['errmsg'] ?? '')];
            }
        } elseif ($this->channel['appwxa'] > 0) {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
            try {
                $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
            } catch (Exception $e) {
                return ['type' => 'error', 'msg' => $e->getMessage()];
            }
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
        } else {
            $code_url = $siteurl . 'pay/submit/' . $tradeNo . '/';
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        }
    }

    public function wxapppay(PaymentContext $ctx): array
    {
        try {
            $token_id = $this->appletpay($ctx);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => '微信支付下单失败，' . $e->getMessage()];
        }
        return ['type' => 'wxapp', 'data' => ['appId' => 'wxfac21f54eeaabb58', 'miniProgramId' => 'gh_5c5293af946b', 'path' => 'pages/init/init?token_id=' . $token_id]];
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $signstr = 'result=' . request()->get('result') . '&agent_id=' . request()->get('agent_id') . '&jnet_bill_no=' . request()->get('jnet_bill_no') . '&agent_bill_id=' . request()->get('agent_bill_id') . '&pay_type=' . request()->get('pay_type') . '&pay_amt=' . request()->get('pay_amt') . '&remark=' . request()->get('remark') . '&key=' . $this->channel['appkey'];
        $sign = md5($signstr);

        if ($sign === request()->get('sign')) {
            if (request()->get('result') == '1') {
                $out_trade_no = request()->get('agent_bill_id');
                $api_trade_no = request()->get('jnet_bill_no');
                $money = (float) request()->get('pay_amt');
                $end_time = request()->get('deal_time');

                if ($out_trade_no == $tradeNo && round($money, 2) == round((float) $ctx->order['realmoney'], 2)) {
                    $this->processNotify($ctx->order, $api_trade_no, request()->get('pay_user'), request()->get('trade_bill_no'), null, $end_time);
                }
                return ['type' => 'html', 'data' => 'ok'];
            } else {
                return ['type' => 'html', 'data' => 'result=' . request()->get('result')];
            }
        } else {
            return ['type' => 'html', 'data' => 'error'];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $signstr = 'result=' . request()->get('result') . '&agent_id=' . request()->get('agent_id') . '&jnet_bill_no=' . request()->get('jnet_bill_no') . '&agent_bill_id=' . request()->get('agent_bill_id') . '&pay_type=' . request()->get('pay_type') . '&pay_amt=' . request()->get('pay_amt') . '&remark=' . request()->get('remark') . '&key=' . $this->channel['appkey'];
        $sign = md5($signstr);

        if ($sign === request()->get('sign')) {
            if (request()->get('result') == '1') {
                $out_trade_no = request()->get('agent_bill_id');
                $api_trade_no = request()->get('jnet_bill_no');
                $money = (float) request()->get('pay_amt');
                $end_time = request()->get('deal_time');

                if ($out_trade_no == $tradeNo && round($money, 2) == round((float) $ctx->order['realmoney'], 2)) {
                    return ($this->markTrustedCallback($ctx, 'return', 'heepay-signature'))(function () use ($ctx, $api_trade_no, $end_time) {
                        return $this->processReturn($ctx->order, $api_trade_no, request()->get('pay_user'), null, $end_time);
                    });
                } else {
                    return ['type' => 'error', 'msg' => '订单信息校验失败'];
                }
            } else {
                return ['type' => 'error', 'msg' => 'result=' . request()->get('result')];
            }
        } else {
            return ['type' => 'error', 'msg' => '验证失败！'];
        }
    }

    public function query(array $order): array
    {
        $apiurl = 'https://query.heepay.com/Payment/Query.aspx';
        $param = [
            'version' => '2',
            'agent_id' => $this->channel['appid'],
            'agent_bill_id' => $order['trade_no'],
            'agent_bill_time' => date('YmdHis', strtotime($order['addtime'])),
            'return_mode' => '0',
        ];
        $signstr = 'version=' . $param['version'] . '&agent_id=' . $param['agent_id'] . '&agent_bill_id=' . $param['agent_bill_id'] . '&agent_bill_time=' . $param['agent_bill_time'] . '&return_mode=' . $param['return_mode'] . '&key=' . $this->channel['appkey'];
        $param['sign'] = md5($signstr);
        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $data = mb_convert_encoding($data, 'UTF-8', 'GBK');
        $arr = explode('|', $data);
        if (count($arr) <= 1) throw new \Exception($data);
        $result = [];
        foreach ($arr as $row) {
            $row = explode('=', $row);
            if (count($row) == 2) {
                $result[$row[0]] = $row[1];
            }
        }
        return [
            'api_trade_no' => $result['jnet_bill_no'],
            'status' => $result['result'] == '1' ? 1 : 0,
            'money' => $result['pay_amt'],
            'buyer' => $result['pay_user'] ?? '',
            'bill_trade_no' => $result['trade_bill_no'] ?? '',
            'endtime' => $result['deal_time'] ?? '',
        ];
    }

    //退款
    public function refund(array $order): array
    {
        $apiurl = 'https://pay.heepay.com/API/Payment/PaymentRefund.aspx';
        if (round((float) $order['refundmoney'], 2) == round((float) $order['realmoney'], 2)) {
            $param = [
                'version' => '1',
                'agent_id' => $this->channel['appid'],
                'agent_bill_id' => $order['trade_no'],
                'notify_url' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
                'sign_type' => 'MD5'
            ];
            $signstr = 'agent_bill_id=' . $param['agent_bill_id'] . '&agent_id=' . $param['agent_id'] . '&key=' . $this->channel['appsecret'] . '&notify_url=' . $param['notify_url'] . '&version=' . $param['version'];
        } else {
            $refund_details = $order['trade_no'] . ',' . $order['refundmoney'] . ',' . $order['refund_no'];
            $param = [
                'version' => '1',
                'agent_id' => $this->channel['appid'],
                'refund_details' => $refund_details,
                'notify_url' => config_get('localurl') . 'pay/refundnotify/' . $order['trade_no'] . '/',
                'sign_type' => 'MD5'
            ];
            $signstr = 'agent_id=' . $param['agent_id'] . '&key=' . $this->channel['appsecret'] . '&notify_url=' . $param['notify_url'] . '&refund_details=' . $param['refund_details'] . '&version=' . $param['version'];
        }

        $param['sign'] = md5(strtolower($signstr));

        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $data = mb_convert_encoding($data, 'UTF-8', 'GBK');
        $result = json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);

        if (isset($result['ret_code']) && $result['ret_code'] == '0000') {
            $result = ['code' => 0];
        } else {
            $result = ['code' => -1, 'msg' => $result["ret_msg"] ?? '返回内容解析失败'];
        }
        return $result;
    }

    //退款异步回调
    public function refundnotify(PaymentContext $ctx): array
    {
        $signstr = 'agent_id=' . request()->get('agent_id') . '&hy_bill_no=' . request()->get('hy_bill_no') . '&agent_bill_id=' . request()->get('agent_bill_id') . '&agent_refund_bill_no=' . request()->get('agent_refund_bill_no') . '&refund_amt=' . request()->get('refund_amt') . '&refund_status=' . request()->get('refund_status') . '&hy_deal_time=' . request()->get('hy_deal_time') . '&key=' . $this->channel['appsecret'];
        $sign = md5(strtolower($signstr));

        if ($sign === request()->get('sign')) {
            $status = request()->get('refund_status') == 1 ? 1 : 2;
            ($this->markTrustedCallback($ctx, 'refundnotify', 'heepay-signature'))(function () use ($status) {
                $this->processRefund(
                    request()->get('agent_refund_bill_no'),
                    $status,
                    request()->get('refund_status') == 1 ? '' : 'heepay refund failed',
                    request()->get('hy_bill_no'),
                    request()->get('refund_amt'),
                    request()->get('hy_deal_time')
                );
            });
            return ['type' => 'html', 'data' => 'ok'];
        } else {
            return ['type' => 'html', 'data' => 'error'];
        }
    }

    private function unicode_urldecode(string $url): string
    {
        preg_match_all('/%u([[:alnum:]]{4})/', $url, $a);
        foreach ($a[1] as $uniord) {
            $dec = hexdec($uniord);
            $utf = '';
            if ($dec < 12) {
                $utf = chr($dec);
            } elseif ($dec < 204) {
                $utf = chr(192 + (($dec - ($dec % 64)) / 64));
                $utf .= chr(128 + ($dec % 64));
            } else {
                $utf = chr(224 + (($dec - ($dec % 4096)) / 4096));
                $utf .= chr(128 + ((($dec % 4096) - ($dec % 64)) / 64));
                $utf .= chr(128 + ($dec % 64));
            }
            $url = str_replace('%u' . $uniord, $utf, $url);
        }
        return urldecode($url);
    }

    private function queryBankCardInfo(string $bank_card_no): array
    {
        $apiurl = 'https://pay.heepay.com/API/PayTransit/QueryBankCardInfo.aspx';
        $param = [
            'version' => '3',
            'agent_id' => $this->channel['appid'],
            'bank_card_no' => $bank_card_no,
        ];
        $signstr = 'agent_id=' . $param['agent_id'] . '&bank_card_no=' . $param['bank_card_no'] . '&key=' . $this->channel['transfer_key'] . '&version=' . $param['version'];
        $param['sign'] = md5(strtolower($signstr));

        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $data = mb_convert_encoding($data, 'UTF-8', 'GBK');
        $result = json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);

        if (isset($result['ret_code']) && $result['ret_code'] == '0000') {
            $result = ['code' => 0, 'bank_card_no' => $result['bank_card_no'], 'bank_name' => $result['bank_name'], 'bank_type' => $result['bank_type'], 'bank_card_type' => $result['bank_card_type']];
        } else {
            $result = ['code' => -1, 'msg' => $result["ret_msg"] ?? '返回内容解析失败'];
        }
        return $result;
    }

    //转账
    public function transfer(array $bizParam): array
    {
        $transit_type = '';
        if ($bizParam['type'] == 'bank') {
            $bank_card_info = $this->queryBankCardInfo($bizParam['payee_account']);
            if ($bank_card_info['code'] == -1) return ['code' => -1, 'msg' => '查询银行卡信息失败：' . $bank_card_info['msg']];

            $detail_data = $bizParam['out_biz_no'] . '^' . $bank_card_info['bank_type'] . '^0^' . $bizParam['payee_account'] . '^' . $bizParam['payee_real_name'] . '^' . sprintf('%.2f', $bizParam['money']) . '^' . $bizParam['transfer_desc'] . '^浙江省^杭州市^' . $bank_card_info['bank_name'];
            $apiurl = 'https://pay.heepay.com/API/PayTransit/PayTransferWithSmallAll.aspx';
        } else {
            $detail_data = $bizParam['out_biz_no'] . '^0^' . $bizParam['payee_account'] . '^' . $bizParam['payee_real_name'] . '^' . sprintf('%.2f', $bizParam['money']) . '^' . $bizParam['transfer_desc'];
            $apiurl = 'https://pay.heepay.com/API/PayTransit/PayTransferThridWithSmall.aspx';
            if ($bizParam['type'] == 'alipay') $transit_type = '4';
            elseif ($bizParam['type'] == 'wxpay') $transit_type = '5';
        }
        $param = [
            'version' => '3',
            'agent_id' => $this->channel['appid'],
            'batch_no' => $bizParam['out_biz_no'],
            'batch_amt' => sprintf('%.2f', $bizParam['money']),
            'batch_num' => '1',
            'detail_data' => $detail_data,
            'notify_url' => config_get('localurl') . 'pay/transfernotify/' . $this->channel['id'] . '/',
            'ext_param1' => '123',
            'transit_type' => $transit_type,
            'sign_type' => 'MD5'
        ];
        $signstr = 'agent_id=' . $param['agent_id'] . '&batch_amt=' . $param['batch_amt'] . '&batch_no=' . $param['batch_no'] . '&batch_num=' . $param['batch_num'] . '&detail_data=' . $param['detail_data'] . '&ext_param1=' . $param['ext_param1'] . '&key=' . $this->channel['transfer_key'] . '&notify_url=' . $param['notify_url'] . '&version=' . $param['version'];
        $param['sign'] = md5(strtolower($signstr));
        $param['detail_data'] = mb_convert_encoding($param['detail_data'], 'GBK', 'UTF-8');
        $param['detail_data'] = $this->tripleDesEncrypt($param['detail_data'], $this->channel['transfer_des_key']);

        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $data = mb_convert_encoding($data, 'UTF-8', 'GBK');
        $result = json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);

        if (isset($result['ret_code']) && $result['ret_code'] == '0000') {
            return ['code' => 0, 'status' => 0, 'orderid' => $bizParam['out_biz_no'], 'paydate' => date('Y-m-d H:i:s')];
        } else {
            $result = ['code' => -1, 'msg' => $result["ret_msg"] ?? '返回内容解析失败'];
        }
        return $result;
    }

    //转账查询
    public function transfer_query(array $bizParam): array
    {
        $apiurl = 'https://pay.heepay.com/API/PayTransit/QueryTransfer.aspx';
        $param = [
            'version' => '3',
            'agent_id' => $this->channel['appid'],
            'batch_no' => $bizParam['out_biz_no'],
            'sign_type' => 'MD5'
        ];
        $signstr = 'agent_id=' . $param['agent_id'] . '&batch_no=' . $param['batch_no'] . '&key=' . $this->channel['transfer_key'] . '&version=' . $param['version'];
        $param['sign'] = md5(strtolower($signstr));

        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $data = mb_convert_encoding($data, 'UTF-8', 'GBK');
        $result = json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);

        if (isset($result['ret_code']) && $result['ret_code'] == '0000') {
            $status = 0;
            $errmsg = null;
            $paydate = null;
            if ($result['detail_data']) {
                $detail_data = $this->tripleDesDecrypt($result['detail_data'], $this->channel['transfer_des_key']);
                $detail_data = mb_convert_encoding($detail_data, 'UTF-8', 'GBK');
                $row = explode('|', $detail_data)[0];
                $arr = explode('^', $row);
                if ($arr[0] == $bizParam['out_biz_no']) {
                    $status = $arr[4] == 'S' ? 1 : 2;
                    if ($arr[4] == 'S') $paydate = $arr[5];
                    else $errmsg = $arr[5];
                }
            }
            $result = ['code' => 0, 'status' => $status, 'amount' => $result['batch_amt'], 'errmsg' => $errmsg, 'paydate' => $paydate];
        } else {
            $result = ['code' => -1, 'msg' => $result["ret_msg"] ?? '返回内容解析失败'];
        }
        return $result;
    }

    //付款凭证
    public function transfer_proof(array $bizParam): array
    {
        $apiurl = 'https://www.heepay.com/API/PayTransit/PayTransferGetProof.aspx';
        $param = [
            'version' => '3',
            'agent_id' => $this->channel['appid'],
            'batch_no' => $bizParam['out_biz_no'],
            'sign_type' => 'MD5'
        ];
        $signstr = 'agent_id=' . $param['agent_id'] . '&batch_no=' . $param['batch_no'] . '&key=' . $this->channel['transfer_key'] . '&version=' . $param['version'];
        $param['sign'] = md5(strtolower($signstr));

        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $data = mb_convert_encoding($data, 'UTF-8', 'GBK');
        $result = json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);

        if (isset($result['ret_code']) && $result['ret_code'] == '0000') {
            if ($result['file_path']) {
                $file_path = $this->tripleDesDecrypt($result['file_path'], $this->channel['transfer_des_key']);
                $result = ['code' => 0, 'url' => $file_path];
            } else {
                $result = ['code' => -1, 'msg' => $result["ret_msg"] ?? '未返回下载地址'];
            }
        } else {
            $result = ['code' => -1, 'msg' => $result["ret_msg"] ?? '返回内容解析失败'];
        }
        return $result;
    }

    //余额查询
    public function balance_query(array $bizParam): array
    {
        $apiurl = 'https://www.heepay.com/API/Merchant/QueryBank.aspx';
        $param = [
            'version' => '1',
            'agent_id' => $this->channel['appid'],
        ];
        $signstr = 'version=' . $param['version'] . '&agent_id=' . $param['agent_id'] . '&key=' . $this->channel['appkey'];
        $param['sign'] = md5($signstr);

        $data = get_curl($apiurl, http_build_query($param));
        if (!$data) return ['code' => -1, 'msg' => '接口请求失败'];
        $data = mb_convert_encoding($data, 'UTF-8', 'GBK');
        $status = substr($data, 0, 1);
        $ret = str_replace('|', '&', substr($data, 2));
        parse_str($ret, $result);

        if ($status == 'S') {
            $result = ['code' => 0, 'amount' => round($result['can_Used_Amt'] - $result['lock_Amt'], 2)];
        } else {
            $result = ['code' => -1, 'msg' => $ret ?? '返回内容解析失败'];
        }
        return $result;
    }

    //转账异步回调
    public function transfernotify(PaymentContext $ctx): array
    {
        $signstr = 'ret_code=' . request()->post('ret_code') . '&ret_msg=' . request()->post('ret_msg') . '&agent_id=' . request()->post('agent_id') . '&hy_bill_no=' . request()->post('hy_bill_no') . '&status=' . request()->post('status') . '&batch_no=' . request()->post('batch_no') . '&batch_amt=' . request()->post('batch_amt') . '&batch_num=' . request()->post('batch_num') . '&detail_data=' . request()->post('detail_data') . '&ext_param1=' . request()->post('ext_param1') . '&key=' . $this->channel['transfer_key'];
        $signstr = mb_convert_encoding($signstr, 'UTF-8', 'GBK');
        $sign = md5(strtolower($signstr));

        if ($sign === request()->post('sign')) {
            if (request()->post('status') == 1) {
                $detail_data = mb_convert_encoding(request()->post('detail_data'), 'UTF-8', 'GBK');
                $detail_data = explode('|', $detail_data);
                foreach ($detail_data as $row) {
                    $arr = explode('^', $row);
                    if (!$arr[0]) continue;
                    $out_biz_no = $arr[0];
                    $status = $arr[4] == 'S' ? 1 : 2;
                    $errmsg = $arr[4] == 'F' ? $arr[5] : null;
                    ($this->markTrustedCallback($ctx, 'transfernotify', 'heepay-signature'))(function () use ($out_biz_no, $status, $errmsg) {
                        $this->processTransfer($out_biz_no, $status, $errmsg);
                    });
                }
            }
            return ['type' => 'html', 'data' => 'ok'];
        } else {
            return ['type' => 'html', 'data' => 'error'];
        }
    }

    private function tripleDesEncrypt(string $data, string $key): string
    {
        $encrypted = openssl_encrypt($data, 'des-ede3', $key, OPENSSL_RAW_DATA);
        return strtoupper(bin2hex($encrypted));
    }

    private function tripleDesDecrypt(string $data, string $key): string
    {
        $data = hex2bin($data);
        return openssl_decrypt($data, 'des-ede3', $key, OPENSSL_RAW_DATA);
    }

    //进件通知
    public function applynotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) {
            return ['type' => 'json', 'data' => ['code' => '10001', 'msg' => 'data error']];
        }

        $model = \app\logic\ApplymentLogic::getModel2($this->channel);
        $result = null;
        if ($model) $result = $model->notify($data);

        if (!$result) $result = ['code' => '40004', 'msg' => 'error'];

        return ['type' => 'json', 'data' => $result];
    }

    //提现通知
    public function cashnotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $data = json_decode($json, true);
        if (!$data) {
            return ['type' => 'html', 'data' => 'data error'];
        }

        $model = \app\logic\ApplymentLogic::getModel2($this->channel);
        if ($model) $model->cashnotify($data);
        return ['type' => 'html', 'data' => 'success'];
    }
}
