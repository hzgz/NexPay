<?php

declare(strict_types=1);

namespace plugins\payment\easypay;

use app\common\PaymentContext;
use app\common\BasePayment;
use Exception;

class EasypayPlugin extends BasePayment
{
    private function createClient(): EasypayClient
    {
        return new EasypayClient(
            $this->channel['appid'],
            $this->channel['reqtype'],
            $this->channel['appkey'],
            $this->channel['appsecret'],
            $this->channel['appswitch'] == 1
        );
    }

    private function getMchtCode(): string
    {
        return $this->channel['reqtype'] == 2 ? $this->channel['appmchid'] : $this->channel['appid'];
    }

    //扫码支付接口
    private function qrcode(PaymentContext $ctx, string $pay_type): string
    {
        $tradeNo = $ctx->order['trade_no'];
        $clientip = request()->clientip;

        $params = [
            'reqInfo' => [
                'mchtCode' => $this->getMchtCode(),
            ],
            'reqOrderInfo' => [
                'orgTrace' => $tradeNo,
                'transAmount' => intval(round($ctx->order['realmoney'] * 100)),
                'orderSub' => $ctx->ordername,
                'backUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            ],
            'payInfo' => [
                'payType' => $pay_type,
                'transDate' => date('Ymd'),
            ],
            'settleParamInfo' => [
                'delaySettleFlag' => '0',
                'patnerSettleFlag' => '0',
                'splitSettleFlag' => '0',
            ],
            'riskData' => [
                'customerIp' => $clientip,
            ],
        ];
        if (strpos($pay_type, 'UnionPay') === 0) {
            $params['qrBizParam'] = [
                'transType' => '10',
                'areaInfo' => '1561000',
            ];
        }
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx->order);
        }

        $client = $this->createClient();
        $result = self::lockPayData($tradeNo, function () use ($client, $params) {
            return $client->execute('/trade/native', $params);
        });

        if($this->channel['appswitch']==1){
			echo '<div>请求报文：<br/><textarea name="request_body" rows="3" style="width:100%">'.$client->request_body.'</textarea><br/>返回报文：<br/><textarea name="response_body" rows="3" style="width:100%">'.$client->response_body.'</textarea></div>';
		}

        if ($result['respStateInfo']['respCode'] == '000000') {
            if ($result['respStateInfo']['transState'] == 'X') {
                if (isset($result['respStateInfo']['appendRetCode'])) {
                    throw new Exception('[' . $result['respStateInfo']['appendRetCode'] . ']' . $result['respStateInfo']['appendRetMsg']);
                } else {
                    throw new Exception($result['respStateInfo']['transStatusDesc']);
                }
            }
            $this->updateOrder($tradeNo, $result['respOrderInfo']['outTrace']);
            return $result['respOrderInfo']['qrCode'];
        } else {
            throw new Exception($result['respStateInfo']['respDesc']);
        }
    }

    //JSAPI支付接口
    private function jsapi(PaymentContext $ctx, string $pay_type, ?string $openid = null, ?string $appid = null): mixed
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;
        $clientip = request()->clientip;

        $params = [
            'reqInfo' => [
                'mchtCode' => $this->getMchtCode(),
            ],
            'reqOrderInfo' => [
                'orgTrace' => $tradeNo,
                'transAmount' => intval(round($ctx->order['realmoney'] * 100)),
                'orderSub' => $ctx->ordername,
                'backUrl' => config_get('localurl') . 'pay/notify/' . $tradeNo . '/',
            ],
            'payInfo' => [
                'payType' => $pay_type,
                'transDate' => date('Ymd'),
            ],
            'settleParamInfo' => [
                'delaySettleFlag' => '0',
                'patnerSettleFlag' => '0',
                'splitSettleFlag' => '0',
            ],
            'riskData' => [
                'customerIp' => $clientip,
            ],
        ];
        if (strpos($pay_type, 'AliPay') === 0) {
            $params['aliBizParam'] = [
                'buyerId' => $openid,
            ];
        } elseif (strpos($pay_type, 'WeChat') === 0) {
            $params['wxBizParam'] = [
                'subAppid' => $appid,
                'subOpenId' => $openid,
            ];
        } elseif (strpos($pay_type, 'UnionPay') === 0) {
            $params['qrBizParam'] = [
                'userAuthCode' => session('unionpay_auth_code'),
                'userId' => $openid,
                'qrCode' => $siteurl,
                'paymentValidTime' => 1800,
                'transType' => '10',
                'areaInfo' => '1561000',
            ];
        }
        if ($ctx->order['profits'] > 0) {
            $this->handleProfits($params, $ctx->order);
        }

        $client = $this->createClient();
        $result = self::lockPayData($tradeNo, function () use ($client, $params) {
            return $client->execute('/trade/jsapi', $params);
        });

        if ($result['respStateInfo']['respCode'] == '000000') {
            if ($result['respStateInfo']['transState'] == 'X') {
                if (isset($result['respStateInfo']['appendRetCode'])) {
                    throw new Exception('[' . $result['respStateInfo']['appendRetCode'] . ']' . $result['respStateInfo']['appendRetMsg']);
                } else {
                    throw new Exception($result['respStateInfo']['transStatusDesc']);
                }
            }
            $this->updateOrder($tradeNo, $result['respOrderInfo']['outTrace']);
            if (strpos($pay_type, 'AliPay') === 0) {
                return $result['aliRespParamInfo']['tradeNo'] ?? '';
            } elseif (strpos($pay_type, 'WeChat') === 0) {
                return $result['wxRespParamInfo']['wcPayData'] ?? '';
            } elseif (strpos($pay_type, 'UnionPay') === 0) {
                return $result['qrRespParamInfo']['qrRedirectUrl'] ?? '';
            }
        } else {
            throw new Exception($result['respStateInfo']['respDesc']);
        }
        return null;
    }

    private function handleProfits(array &$params, array $order): void
    {
        $psreceiver = \app\logic\ProfitSharingLogic::getReceiver($order['profits']);
        if ($psreceiver) {
            $ordermoney = $params['reqOrderInfo']['transAmount'];
            $relation = [];
            $allmoney = 0;
            $i = 1;
            foreach ($psreceiver['info'] as $receiver) {
                $psmoney = intval(round(floor($order['realmoney'] * $receiver['rate'])));
                $allmoney += $psmoney;
                $relation[] = [
                    'separateTrade' => $order['trade_no'] . $i++,
                    'receiveMchtCode' => $receiver['account'],
                    'sepaTransAmount' => $psmoney,
                    'sepaFeeRatio' => 0,
                ];
            }
            if($allmoney < $ordermoney){
                $relation[] = [
                    'separateTrade' => $order['trade_no'] . $i++,
                    'receiveMchtCode' => $this->getMchtCode(),
                    'sepaTransAmount' => $ordermoney - $allmoney,
                    'sepaFeeRatio' => 0,
                ];
            }
            $relation[count($relation) - 1]['sepaFeeRatio'] = 100;
            $params['settleParamInfo']['splitSettleFlag'] = '2';
            $params['separateInfo'] = [
                'oriSeparateMchtCode' => $this->getMchtCode(),
                'separaBatchTrace' => $order['trade_no'],
                'transSumAmt' => $ordermoney,
                'transSumCount' => count($relation),
                'separateOrderDetailList' => $relation,
            ];
        }
    }

    public function submit(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        if ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => '/pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return ['type' => 'jump', 'url' => '/pay/alipay/' . $tradeNo . '/'];
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => '/pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
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
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($ctx->method == 'jsapi') {
            if ($ctx->order['typename'] == 'alipay') {
                return $this->alipayjs($ctx);
            } elseif ($ctx->order['typename'] == 'wxpay') {
                return $this->wxjspay($ctx);
            } elseif ($ctx->order['typename'] == 'bank') {
                return $this->bankjs($ctx);
            }
        } elseif ($ctx->order['typename'] == 'alipay') {
            if ($ctx->mdevice === 'alipay' && in_array('2', $this->channel['apptype'])) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/alipayjs/' . $tradeNo . '/?d=1'];
            } else {
                return $this->alipay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'wxpay') {
            if ($ctx->mdevice === 'wechat' && $this->channel['appwxmp'] > 0) {
                return ['type' => 'jump', 'url' => $siteurl . 'pay/wxjspay/' . $tradeNo . '/?d=1'];
            } elseif ($ctx->isMobile && $this->channel['appwxa'] > 0) {
                return $this->wxwappay($ctx);
            } else {
                return $this->wxpay($ctx);
            }
        } elseif ($ctx->order['typename'] == 'bank') {
            return $this->bank($ctx);
        }
        return ['type' => 'error', 'msg' => '不支持的支付方式'];
    }

    //支付宝扫码支付
    public function alipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if (in_array('2', $this->channel['apptype']) && !in_array('1', $this->channel['apptype'])) {
            $code_url = $siteurl . 'pay/alipayjs/' . $tradeNo . '/';
        } else {
            try {
                $code_url = $this->qrcode($ctx, 'AliPayNative');
            } catch (Exception $ex) {
                return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
            }
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $code_url];
        }
    }

    //支付宝JS支付
    public function alipayjs(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $user_type = null;
        if (!empty($ctx->order['sub_openid'])) {
            $user_id = $ctx->order['sub_openid'];
        } else {
            [$user_type, $user_id] = alipay_oauth($tradeNo);
        }

        $blocks = checkBlockUser($user_id, $tradeNo);
        if ($blocks) return $blocks;

        if ($user_type == 'openid') {
            return ['type' => 'error', 'msg' => '支付宝快捷登录获取uid失败，需将用户标识切换到uid模式'];
        }

        try {
            $alipay_trade_no = $this->jsapi($ctx, 'AliPayJsapi', $user_id);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '支付宝支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $alipay_trade_no];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'alipay_jspay', 'data' => ['alipay_trade_no' => $alipay_trade_no, 'redirect_url' => $redirect_url]];
    }

    //微信扫码支付
    public function wxpay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $siteurl = request()->siteurl;

        if ($this->channel['appwxa'] > 0 && $this->channel['appwxmp'] == 0) {
            $code_url = $siteurl . 'pay/wxwappay/' . $tradeNo . '/';
        } else {
            $code_url = $siteurl . 'pay/wxjspay/' . $tradeNo . '/';
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $code_url];
        } elseif ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $code_url];
        } else {
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $code_url];
        }
    }

    //微信手机支付
    public function wxwappay(PaymentContext $ctx): array
    {
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
        try {
            $code_url = wxminipay_jump_scheme($wxinfo['id'], $ctx->order);
        } catch (Exception $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
        return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $code_url];
    }

    //微信公众号支付
    public function wxjspay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        //①、获取用户openid
        if (!empty($ctx->order['sub_openid'])) {
            if (!empty($ctx->order['sub_appid'])) {
                $wxinfo['appid'] = $ctx->order['sub_appid'];
            } else {
                if ($ctx->order['is_applet'] == 1) {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信小程序不存在'];
                } else {
                    $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
                    if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
                }
            }
            $openid = $ctx->order['sub_openid'];
        } else {
            $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxmp']);
            if (!$wxinfo) return ['type' => 'error', 'msg' => '支付通道绑定的微信公众号不存在'];
            $openid = wechat_oauth($wxinfo);
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) return $blocks;

        //②、统一下单
        try {
            $payinfo = $this->jsapi($ctx, 'WeChatJsapi', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '微信支付下单失败！' . $ex->getMessage()];
        }
        if ($ctx->method == 'jsapi') {
            return ['type' => 'jsapi', 'data' => $payinfo];
        }

        if (request()->get('d') == '1') {
            $redirect_url = 'data.backurl';
        } else {
            $redirect_url = '\'/pay/ok/' . $tradeNo . '/\'';
        }
        return ['type' => 'page', 'page' => 'wxpay_jspay', 'data' => ['jsApiParameters' => $payinfo, 'redirect_url' => $redirect_url]];
    }

    //微信小程序支付
    public function wxminipay(PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];
        $code = request()->get('code');
        if (empty($code)) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => 'code不能为空']];
        }

        //①、获取用户openid
        $wxinfo = \app\lib\Channel::getWeixin($this->channel['appwxa']);
        if (!$wxinfo) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '支付通道绑定的微信小程序不存在']];
        }
        try {
            $openid = wechat_applet_oauth($tradeNo, $code, $wxinfo);
        } catch (Exception $e) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $e->getMessage()]];
        }
        $blocks = checkBlockUser($openid, $tradeNo);
        if ($blocks) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => $blocks['msg']]];
        }

        //②、统一下单
        try {
            $payinfo = $this->jsapi($ctx, 'WeChatMiniApp', $openid, $wxinfo['appid']);
        } catch (Exception $ex) {
            return ['type' => 'json', 'data' => ['code' => -1, 'msg' => '微信支付下单失败！' . $ex->getMessage()]];
        }

        return ['type' => 'json', 'data' => ['code' => 0, 'data' => json_decode($payinfo, true)]];
    }

    //云闪付扫码支付
    public function bank(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->qrcode($ctx, 'UnionPayNative');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $code_url];
    }

    //云闪付JS支付
    public function bankjs(PaymentContext $ctx): array
    {
        try {
            $code_url = $this->jsapi($ctx, 'UnionPayJsapi', $ctx->order['sub_openid']);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => '云闪付下单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    //获取云闪付用户ID
    public function get_unionpay_userid(string $userAuthCode): array
    {
        $client = $this->createClient();

        $params = [
            'reqInfo' => [
                'mchtCode' => $this->getMchtCode(),
            ],
            'reqOrderInfo' => [
                'orgTrace' => date('YmdHis') . rand(100000, 999999),
                'authCode' => $userAuthCode,
                'appUpIdentifier' => get_unionpay_ua(),
            ],
        ];

        try {
            $result = $client->execute('/trade/user/getQrUserId', $params);
            if ($result['respStateInfo']['respCode'] == '000000') {
                session('unionpay_auth_code', $userAuthCode);
                return ['code' => 0, 'data' => $result['respOrderInfo']['userId']];
            } else {
                return ['code' => -1, 'msg' => $result['respStateInfo']['respDesc']];
            }
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    //异步回调
    public function notify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'json', 'data' => ['code' => '400001', 'msg' => 'no data']];

        $client = $this->createClient();
        $verify_result = $client->verifySign($arr['reqHeader'], $arr['reqBody'], $arr['reqSign']);

        if ($verify_result) {
            $data = $arr['reqBody'];
            if ($data['respStateInfo']['transState'] == '0' || $data['respStateInfo']['transState'] == '1') {
                $out_trade_no = $data['respOrderInfo']['orgTrace'];
                $api_trade_no = $data['respOrderInfo']['outTrace'];
                $buyer = $data['respOrderInfo']['userId'] ?? null;
                $bill_trade_no = $data['respOrderInfo']['pcTrace'] ?? null;
                $end_time = $data['respOrderInfo']['dateEnd'] . $data['respOrderInfo']['timeEnd'];
                if ($out_trade_no == $ctx->order['trade_no']) {
                    $this->processNotify($ctx->order, $api_trade_no, $buyer, $bill_trade_no, null, $end_time);
                }
            }
            return ['type' => 'json', 'data' => ['code' => '000000', 'msg' => 'Success']];
        } else {
            return ['type' => 'json', 'data' => ['code' => '100001', 'msg' => 'sign error']];
        }
    }

    //同步回调
    public function return(PaymentContext $ctx): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    public function query($order){
		$params = [
			'reqInfo' => [
				'mchtCode' => $this->getMchtCode(),
			],
			'reqOrderInfo' => [
				'orgTrace' => date('YmdHis').rand(100000,999999),
				'oriOrgTrace' => $order['trade_no'],
				'oriTransDate' => substr($order['trade_no'], 0, 8),
			],
			'payInfo' => [
				'transDate' => date('Ymd'),
			],
		];

		$client = $this->createClient();
		$result = $client->execute('/trade/tradeQuery', $params);
		return [
            'api_trade_no' => $result['respOrderInfo']['outTrace'],
            'status' => $result['respStateInfo']['transState'] == '0' || $result['respStateInfo']['transState'] == '1' ? 1 : 0,
            'money' => ($result['respOrderInfo']['amount'] ?? 0) / 100,
            'buyer' => $result['respOrderInfo']['userId'] ?? null,
            'bill_trade_no' => $result['respOrderInfo']['pcTrace'] ?? null,
            'endtime' => ($result['respOrderInfo']['dateEnd'] ?? null) . ($result['respOrderInfo']['timeEnd'] ?? null),
        ];
	}

    //退款
    public function refund($order): array
    {
        $client = $this->createClient();

        $params = [
            'reqInfo' => [
                'mchtCode' => $this->getMchtCode(),
            ],
            'reqOrderInfo' => [
                'orgTrace' => $order['refund_no'],
                'oriOutTrace' => $order['api_trade_no'],
                'oriTransDate' => substr($order['trade_no'], 0, 8),
                'refundAmount' => intval(round($order['refundmoney'] * 100)),
            ],
            'payInfo' => [
                'transDate' => date('Ymd'),
            ],
        ];

        try {
            $result = $client->execute('/trade/refund/apply', $params);
            if ($result['respStateInfo']['respCode'] == '000000') {
                if ($result['respStateInfo']['transState'] == 'X') {
                    if (isset($result['respStateInfo']['appendRetCode'])) {
                        return ['code' => -1, 'msg' => '[' . $result['respStateInfo']['appendRetCode'] . ']' . $result['respStateInfo']['appendRetMsg']];
                    } else {
                        return ['code' => -1, 'msg' => $result['respStateInfo']['transStatusDesc']];
                    }
                }
                return ['code' => 0, 'trade_no' => $result['respOrderInfo']['outTrace'], 'refund_fee' => $result['respOrderInfo']['refundAmount'] / 100];
            } else {
                return ['code' => -1, 'msg' => $result['respStateInfo']['respDesc']];
            }
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    //退款查询
	public function refundquery($order){
		$client = $this->createClient();

		$refund_no = '2025052715162033617';
		$params = [
			'reqInfo' => [
				'mchtCode' => $this->getMchtCode(),
			],
			'reqOrderInfo' => [
				'orgTrace' => date('YmdHis').rand(100000,999999),
				'oriOrgTrace' => $refund_no,
				'oriTransDate' => substr($refund_no, 0, 8),
			],
			'payInfo' => [
				'transDate' => date('Ymd'),
			],
		];
		
		$result = $client->execute('/trade/refund/query', $params);
		if($this->channel['appswitch']==1){
			echo '<div>请求报文：<br/><textarea name="request_body" rows="3" style="width:100%">'.$client->request_body.'</textarea><br/>返回报文：<br/><textarea name="response_body" rows="3" style="width:100%">'.$client->response_body.'</textarea></div>';
		}
		print_r($result);
	}

    //进件回调
    public function applynotify(PaymentContext $ctx): array
    {
        $json = request()->getContent();
        $arr = json_decode($json, true);
        if (!$arr) return ['type' => 'json', 'data' => ['code' => '400001', 'msg' => 'no data']];

        $client = $this->createClient();
        $verify_result = $client->verifySign($arr['reqHeader'], $arr['reqBody'], $arr['reqSign']);

        if ($verify_result) {
            $data = $arr['reqBody'];
            $model = \app\logic\ApplymentLogic::getModel2($this->channel);
            if ($model) $model->notify($data);

            return ['type' => 'json', 'data' => ['code' => '000000', 'msg' => 'Success']];
        } else {
            return ['type' => 'json', 'data' => ['code' => '100001', 'msg' => 'sign error']];
        }
    }
}
