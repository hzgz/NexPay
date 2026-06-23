<?php

declare(strict_types=1);

namespace plugins\payment\epay;

use app\common\BasePayment;
use app\common\PaymentContext;
use Exception;

class EpayPlugin extends BasePayment
{
    private const LEGACY_GATEWAY_BASE = '/pay/';

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

        if (($this->channel['appswitch'] ?? 0) == 1) {
            return ['type' => 'jump', 'url' => $this->gatewayActionUrl((string) $ctx->order['typename'], $tradeNo)];
        }

        $directAction = $this->resolveDirectSubmitAction((string) ($ctx->order['typename'] ?? ''));
        if ($directAction !== null) {
            return $this->{$directAction}($ctx);
        }

        $epay = new EpayCore($this->epayConfig);
        $parameter = $this->appendCollectorRoute([
            'pid' => trim($this->epayConfig['pid']),
            'type' => $ctx->order['typename'],
            'notify_url' => $this->gatewayActionUrl('notify', $tradeNo, true),
            'return_url' => $this->gatewayActionUrl('return', $tradeNo, true),
            'out_trade_no' => $tradeNo,
            'name' => $ctx->order['name'],
            'money' => $ctx->order['realmoney'],
        ]);

        if (is_https() && substr((string) $this->epayConfig['apiurl'], 0, 7) === 'http://') {
            $jumpUrl = $epay->getPayLink($parameter);
            return ['type' => 'jump', 'url' => $jumpUrl];
        }

        $htmlText = $epay->pagePay($parameter, '正在跳转');
        return ['type' => 'html', 'data' => $htmlText];
    }

    public function mapi(PaymentContext $ctx): array
    {
        if (($this->channel['appswitch'] ?? 0) == 1) {
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

    private function pay_mapi(string $type, PaymentContext $ctx): array
    {
        $tradeNo = $ctx->order['trade_no'];

        $epay = new EpayCore($this->epayConfig);
        $parameter = $this->appendCollectorRoute([
            'pid' => trim($this->epayConfig['pid']),
            'type' => $type,
            'device' => $this->getDevice($ctx),
            'clientip' => request()->clientip,
            'notify_url' => $this->gatewayActionUrl('notify', $tradeNo, true),
            'return_url' => $this->gatewayActionUrl('return', $tradeNo, true),
            'out_trade_no' => $tradeNo,
            'name' => $ctx->order['name'],
            'money' => $ctx->order['realmoney'],
        ]);

        return self::lockPayData($tradeNo, function () use ($epay, $parameter) {
            $result = $epay->apiPay($parameter);

            if (isset($result['code']) && (int) $result['code'] === 1) {
                if (isset($result['payurl'])) {
                    $method = 'jump';
                    $url = (string) $result['payurl'];
                    if ($this->isUpstreamEmptyCashier($url)) {
                        throw new Exception($this->upstreamCustomChannelMessage());
                    }
                } elseif (isset($result['qrcode'])) {
                    $method = 'qrcode';
                    $url = (string) $result['qrcode'];
                } elseif (isset($result['urlscheme'])) {
                    $method = 'scheme';
                    $url = (string) $result['urlscheme'];
                } else {
                    throw new Exception('未返回支付链接');
                }
            } elseif (isset($result['msg'])) {
                throw new Exception((string) $result['msg']);
            } else {
                throw new Exception('获取支付接口数据失败');
            }

            return [$method, $url];
        });
    }

    public function alipay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('alipay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'jump', 'url' => $url];
        }

        return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $url];
    }

    public function wxpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('wxpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'jump', 'url' => $url];
        }
        if ($method === 'scheme') {
            return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $url];
        }
        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $url];
        }
        if ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $url];
        }

        return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $url];
    }

    public function qqpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('qqpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'jump', 'url' => $url];
        }
        if ($ctx->mdevice === 'qq') {
            return ['type' => 'jump', 'url' => $url];
        }
        if ($ctx->isMobile && !request()->get('qrcode')) {
            return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'url' => $url];
        }

        return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'url' => $url];
    }

    public function bank(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('bank', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'jump', 'url' => $url];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $url];
    }

    public function jdpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('jdpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'jump', 'url' => $url];
        }

        return ['type' => 'qrcode', 'page' => 'jdpay_qrcode', 'url' => $url];
    }

    public function douyinpay(PaymentContext $ctx): array
    {
        try {
            [$method, $url] = $this->pay_mapi('douyinpay', $ctx);
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => $ex->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'jump', 'url' => $url];
        }
        if ($ctx->isMobile) {
            return ['type' => 'qrcode', 'page' => 'douyinpay_wap', 'url' => $url];
        }

        return ['type' => 'qrcode', 'page' => 'douyinpay_qrcode', 'url' => $url];
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

        if (request()->get('trade_status') === 'TRADE_SUCCESS') {
            if ($outTradeNo === (string) $ctx->order['trade_no'] && round($money, 2) === round((float) $ctx->order['realmoney'], 2)) {
                ($this->markTrustedCallback($ctx, 'notify', 'epay-signature'))(function () use ($ctx, $tradeNo): void {
                    $this->processNotify($ctx->order, $tradeNo);
                });
            }
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

        if (request()->get('trade_status') !== 'TRADE_SUCCESS') {
            return ['type' => 'error', 'msg' => 'trade_status=' . request()->get('trade_status')];
        }

        if ($outTradeNo !== (string) $ctx->order['trade_no'] || round($money, 2) !== round((float) $ctx->order['realmoney'], 2)) {
            return ['type' => 'error', 'msg' => '订单信息校验失败'];
        }

        return ($this->markTrustedCallback($ctx, 'return', 'epay-signature'))(function () use ($ctx, $tradeNo) {
            return $this->processReturn($ctx->order, $tradeNo);
        });
    }

    public function query(array $order): array
    {
        $epay = new EpayCore($this->epayConfig);
        $result = $epay->queryOrderByOutTradeNo($order['trade_no']);
        $code = array_key_exists('code', $result) ? (int) $result['code'] : 1;
        if (in_array($code, [0, 1], true)) {
            return [
                'api_trade_no' => $result['trade_no'],
                'status' => $result['status'],
                'money' => $result['money'],
                'buyer' => $result['buyer'] ?? null,
                'bill_trade_no' => $result['api_trade_no'] ?? null,
                'endtime' => $result['endtime'] ?? null,
            ];
        }

        throw new Exception((string) ($result['msg'] ?? '返回数据解析失败'));
    }

    public function refund($order): array
    {
        $epay = new EpayCore($this->epayConfig);
        $result = $epay->refund($order['refund_no'], $order['api_trade_no'], $order['refundmoney']);

        if ((int) ($result['code'] ?? -1) === 0) {
            return ['code' => 0];
        }

        return ['code' => -1, 'msg' => (string) ($result['msg'] ?? '返回数据解析失败')];
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
