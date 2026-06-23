<?php

declare(strict_types=1);

namespace plugins\payment\epayn;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class EpaynPlugin extends BasePayment
{
    private const LEGACY_GATEWAY_BASE = '/pay/';

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

        if (($this->channel['appswitch'] ?? '0') == '1') {
            return ['type' => 'jump', 'url' => $this->gatewayActionUrl((string) $ctx->order['typename'], $tradeNo)];
        }

        $directAction = $this->resolveDirectSubmitAction((string) ($ctx->order['typename'] ?? ''));
        if ($directAction !== null) {
            return $this->{$directAction}($ctx);
        }

        $epay = new EpayCore($this->epayConfig);
        $params = $this->appendCollectorRoute([
            'type' => $ctx->order['typename'],
            'notify_url' => $this->gatewayActionUrl('notify', $tradeNo, true),
            'return_url' => $this->gatewayActionUrl('return', $tradeNo, true),
            'out_trade_no' => $tradeNo,
            'name' => $ctx->order['name'],
            'money' => $ctx->order['realmoney'],
        ]);

        if (is_https() && str_starts_with((string) $this->epayConfig['apiurl'], 'http://')) {
            return ['type' => 'jump', 'url' => $epay->getPayLink($params)];
        }

        return ['type' => 'html', 'data' => $epay->pagePay($params, '正在跳转')];
    }

    public function mapi(PaymentContext $ctx): array
    {
        if (($this->channel['appswitch'] ?? '0') == '1') {
            $typename = $ctx->order['typename'];
            return $this->$typename($ctx);
        }

        return ['type' => 'jump', 'url' => $this->gatewayActionUrl('submit', (string) $ctx->order['trade_no'])];
    }

    private function getDevice(PaymentContext $ctx): string
    {
        if ($ctx->mdevice === 'wechat') {
            return 'wechat';
        }
        if ($ctx->mdevice === 'qq') {
            return 'qq';
        }
        if ($ctx->mdevice === 'alipay') {
            return 'alipay';
        }
        if ($ctx->mdevice === 'douyin') {
            return 'douyin';
        }
        if ($ctx->isMobile) {
            return 'mobile';
        }

        return 'pc';
    }

    private function collectorMerchantId(): string
    {
        foreach (['collector_merchant_id', 'custom_merchant_id', 'upstream_merchant_id'] as $key) {
            $value = trim((string) ($this->channel[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $pluginConfig = is_array($this->channel['plugin_config'] ?? null) ? $this->channel['plugin_config'] : [];
        return trim((string) ($pluginConfig['merchant_id'] ?? ''));
    }

    private function collectorChannelId(): string
    {
        $value = trim((string) ($this->channel['collector_channel_id'] ?? $this->channel['channel_id'] ?? ''));
        if ($value !== '') {
            return $value;
        }

        $pluginConfig = is_array($this->channel['plugin_config'] ?? null) ? $this->channel['plugin_config'] : [];
        return trim((string) ($pluginConfig['channel_id'] ?? ''));
    }

    private function appendCollectorRoute(array $params): array
    {
        $collectorMerchantId = $this->collectorMerchantId();
        if ($collectorMerchantId !== '') {
            $params['merchant_id'] = $collectorMerchantId;
        }

        $collectorChannelId = $this->collectorChannelId();
        if ($collectorChannelId !== '') {
            $params['channel_id'] = $collectorChannelId;
        }

        return $params;
    }

    private function resolveDirectSubmitAction(string $type): ?string
    {
        $type = trim($type);
        if ($type === '') {
            return null;
        }

        return in_array($type, ['alipay', 'wxpay', 'qqpay', 'bank', 'jdpay', 'douyinpay'], true)
            && method_exists($this, $type)
            ? $type
            : null;
    }

    private function isUpstreamEmptyCashier(string $url): bool
    {
        return str_contains($url, 'cashier?') && str_contains($url, 'other=1');
    }

    private function upstreamCustomChannelMessage(): string
    {
        return '上游易支付商户未配置当前支付方式的可用自定义通道';
    }

    private function payMapi(
        string $method,
        string $type,
        PaymentContext $ctx,
        ?string $authCode = null,
        ?string $subOpenid = null,
        ?string $subAppid = null
    ): array {
        $tradeNo = $ctx->order['trade_no'];

        $epay = new EpayCore($this->epayConfig);
        $params = [
            'method' => $method,
            'type' => $type,
            'device' => $this->getDevice($ctx),
            'clientip' => request()->clientip,
            'notify_url' => $this->gatewayActionUrl('notify', $tradeNo, true),
            'return_url' => $this->gatewayActionUrl('return', $tradeNo, true),
            'out_trade_no' => $tradeNo,
            'name' => $ctx->order['name'],
            'money' => $ctx->order['realmoney'],
        ];
        if ($authCode !== null && $authCode !== '') {
            $params['auth_code'] = $authCode;
        }
        if ($subOpenid !== null && $subOpenid !== '') {
            $params['sub_openid'] = $subOpenid;
        }
        if ($subAppid !== null && $subAppid !== '') {
            $params['sub_appid'] = $subAppid;
        }
        $params = $this->appendCollectorRoute($params);

        return self::lockPayData($tradeNo, function () use ($epay, $params) {
            $result = $epay->apiPay($params);
            $payType = (string) ($result['pay_type'] ?? '');
            $payInfo = (string) ($result['pay_info'] ?? '');

            if ($payType === 'jump' && $this->isUpstreamEmptyCashier($payInfo)) {
                throw new Exception($this->upstreamCustomChannelMessage());
            }

            if ($payType === '' || $payInfo === '') {
                throw new Exception((string) ($result['msg'] ?? '未返回有效支付信息'));
            }

            return [$payType, $payInfo];
        });
    }

    private function buildDirectPayResult(
        string $method,
        string $url,
        PaymentContext $ctx,
        string $defaultQrPage
    ): array {
        if ($method === 'jump') {
            return ['type' => 'jump', 'url' => $url];
        }
        if ($method === 'html') {
            return ['type' => 'html', 'data' => $url];
        }
        if ($method === 'urlscheme') {
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $url];
        }

        if ($defaultQrPage === 'wxpay_qrcode') {
            if ($ctx->mdevice === 'wechat') {
                return ['type' => 'jump', 'url' => $url];
            }
            if ($ctx->isMobile) {
                return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $url];
            }
        }

        if ($defaultQrPage === 'qqpay_qrcode') {
            if ($ctx->mdevice === 'qq') {
                return ['type' => 'jump', 'url' => $url];
            }
            if ($ctx->isMobile && !request()->get('qrcode')) {
                return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $url];
            }
        }

        if ($defaultQrPage === 'douyinpay_qrcode' && $ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'douyinpay_wap', 'url' => $url];
        }

        return ['type' => 'qrcode', 'page' => $defaultQrPage, 'url' => $url];
    }

    public function alipay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->payMapi('web', 'alipay', $ctx);
            return $this->buildDirectPayResult($method, $url, $ctx, 'alipay_qrcode');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }
    }

    public function wxpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->payMapi('web', 'wxpay', $ctx);
            return $this->buildDirectPayResult($method, $url, $ctx, 'wxpay_qrcode');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }
    }

    public function qqpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->payMapi('web', 'qqpay', $ctx);
            return $this->buildDirectPayResult($method, $url, $ctx, 'qqpay_qrcode');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }
    }

    public function bank(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->payMapi('web', 'bank', $ctx);
            return $this->buildDirectPayResult($method, $url, $ctx, 'bank_qrcode');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }
    }

    public function jdpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->payMapi('web', 'jdpay', $ctx);
            return $this->buildDirectPayResult($method, $url, $ctx, 'jdpay_qrcode');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }
    }

    public function douyinpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->payMapi('web', 'douyinpay', $ctx);
            return $this->buildDirectPayResult($method, $url, $ctx, 'douyinpay_qrcode');
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }
    }

    public function notify(PaymentContext $ctx): array
    {
        $epayNotify = new EpayCore($this->epayConfig);
        if (!$epayNotify->verify(request()->get())) {
            return ['type' => 'html', 'data' => 'fail'];
        }

        $outTradeNo = (string) request()->get('out_trade_no');
        $tradeNo = (string) request()->get('trade_no');
        $money = (float) request()->get('money');
        $buyer = request()->get('buyer');
        $apiTradeNo = request()->get('api_trade_no');
        $endtime = request()->get('endtime');

        if (
            request()->get('trade_status') === 'TRADE_SUCCESS'
            && $outTradeNo === (string) $ctx->order['trade_no']
            && round($money, 2) === round((float) $ctx->order['realmoney'], 2)
        ) {
            ($this->markTrustedCallback($ctx, 'notify', 'epayn-signature'))(function () use ($ctx, $tradeNo, $buyer, $apiTradeNo, $endtime): void {
                $this->processNotify($ctx->order, $tradeNo, $buyer, $apiTradeNo, null, $endtime);
            });
        }

        return ['type' => 'html', 'data' => 'success'];
    }

    public function return(PaymentContext $ctx): array
    {
        $epayNotify = new EpayCore($this->epayConfig);
        if (!$epayNotify->verify(request()->get())) {
            return ['type' => 'error', 'msg' => '验签失败'];
        }

        $outTradeNo = (string) request()->get('out_trade_no');
        $tradeNo = (string) request()->get('trade_no');
        $money = (float) request()->get('money');
        $buyer = request()->get('buyer');
        $apiTradeNo = request()->get('api_trade_no');
        $endtime = request()->get('endtime');

        if (request()->get('trade_status') !== 'TRADE_SUCCESS') {
            return ['type' => 'error', 'msg' => 'trade_status=' . request()->get('trade_status')];
        }

        if ($outTradeNo !== (string) $ctx->order['trade_no'] || round($money, 2) !== round((float) $ctx->order['realmoney'], 2)) {
            return ['type' => 'error', 'msg' => '订单信息校验失败'];
        }

        return ($this->markTrustedCallback($ctx, 'return', 'epayn-signature'))(function () use ($ctx, $tradeNo, $buyer, $apiTradeNo, $endtime) {
            return $this->processReturn($ctx->order, $tradeNo, $buyer, $apiTradeNo, null, $endtime);
        });
    }

    public function query(array $order): array
    {
        $epay = new EpayCore($this->epayConfig);
        $result = $epay->queryOrderByOutTradeNo((string) $order['trade_no']);

        return [
            'api_trade_no' => $result['trade_no'] ?? '',
            'status' => $result['status'] ?? 0,
            'money' => $result['money'] ?? 0,
            'buyer' => $result['buyer'] ?? null,
            'bill_trade_no' => $result['api_trade_no'] ?? null,
            'endtime' => $result['endtime'] ?? null,
        ];
    }

    public function refund($order): array
    {
        $epay = new EpayCore($this->epayConfig);
        try {
            $result = $epay->refund((string) $order['refund_no'], (string) $order['api_trade_no'], $order['refundmoney']);
            return [
                'code' => 0,
                'status' => $result['status'] ?? 1,
                'trade_no' => $result['refund_no'] ?? '',
                'refund_fee' => $result['money'] ?? $order['refundmoney'],
            ];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

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
            $payload = [
                'code' => 0,
                'status' => $result['status'] ?? 0,
                'orderid' => $result['out_biz_no'] ?? $bizParam['out_biz_no'],
                'paydate' => $result['paydate'] ?? '',
            ];
            if (isset($result['jumpurl'])) {
                $payload['wxpackage'] = $result['jumpurl'];
            }
            return $payload;
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    public function transfer_query($bizParam): array
    {
        $epay = new EpayCore($this->epayConfig);
        try {
            $result = $epay->execute('api/transfer/query', [
                'out_biz_no' => $bizParam['out_biz_no'],
            ]);
            return [
                'code' => 0,
                'status' => $result['status'] ?? 0,
                'amount' => $result['amount'] ?? 0,
                'paydate' => $result['paydate'] ?? '',
                'errmsg' => $result['errmsg'] ?? '',
            ];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    public function balance_query($bizParam): array
    {
        $epay = new EpayCore($this->epayConfig);
        try {
            $result = $epay->execute('api/transfer/balance', []);
            return ['code' => 0, 'amount' => $result['available_money'] ?? 0];
        } catch (Exception $ex) {
            return ['code' => -1, 'msg' => $ex->getMessage()];
        }
    }

    private function gatewayActionUrl(string $action, string $tradeNo, bool $absolute = false): string
    {
        $action = trim(strtolower($action));
        $tradeNo = trim($tradeNo);

        $path = self::LEGACY_GATEWAY_BASE . rawurlencode($action) . '/' . rawurlencode($tradeNo) . '/';

        if (!$absolute) {
            return $path;
        }

        return rtrim((string) config_get('localurl'), '/') . $path;
    }
}
