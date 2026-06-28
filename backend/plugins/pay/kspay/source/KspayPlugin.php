<?php

declare(strict_types=1);

namespace plugins\payment\kspay;

use app\common\BasePayment;
use app\common\PaymentContext;
use app\service\payment\CallbackTrustService;
use Exception;
use think\facade\Db;

class KspayPlugin extends BasePayment
{
    private const USERINFO_API_URL = 'https://pay.ssl.kuaishou.com/rest/k/pay/userInfo';
    private const CASHIER_API_URL = 'https://pay.ssl.kuaishou.com/rest/k/pay/kscoin/deposit/nlogin/kspay/cashier';
    private const QRCODE_API_URL = 'https://www.kuaishoupay.com/pay/order/pc/trade/cashier';
    private const CONFIRM_API_URL = 'https://pay.ssl.kuaishou.com/rest/k/pay/kscoin/deposit/nlogin/kspay/confirm';
    private const DEFAULT_EXPIRE_SECONDS = 360;

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $ctx->order['trade_no'] . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = trim((string)($ctx->order['typename'] ?? ''));
        if ($typename === '' || !method_exists($this, $typename)) {
            return ['type' => 'error', 'msg' => 'Unsupported pay type'];
        }

        return $this->$typename($ctx);
    }

    public function alipay(PaymentContext $ctx): array
    {
        try {
            $codeUrl = $this->createQrcode($ctx);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '快手支付下单失败：' . $e->getMessage()];
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'page', 'page' => 'wxopen'];
        }

        $page = $ctx->isMobile || $ctx->mdevice === 'alipay' ? 'alipay_wap' : 'alipay_qrcode';
        return ['type' => 'qrcode', 'page' => $page, 'url' => $codeUrl, 'expire' => strtotime((string)($ctx->order['addtime'] ?? '')) + self::DEFAULT_EXPIRE_SECONDS];
    }

    public function wxpay(PaymentContext $ctx): array
    {
        try {
            $codeUrl = $this->createQrcode($ctx);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '快手支付下单失败：' . $e->getMessage()];
        }

        if ($ctx->mdevice === 'wechat') {
            return ['type' => 'jump', 'url' => $codeUrl];
        }

        $page = $ctx->isMobile ? 'wxpay_wap' : 'wxpay_qrcode';
        return ['type' => 'qrcode', 'page' => $page, 'url' => $codeUrl, 'expire' => strtotime((string)($ctx->order['addtime'] ?? '')) + self::DEFAULT_EXPIRE_SECONDS];
    }

    public function queryOrder(string $ksOrderId): array
    {
        $ksOrderId = trim($ksOrderId);
        if ($ksOrderId === '') {
            throw new Exception('ksOrderId cannot be empty');
        }

        $response = $this->requestJson(self::CONFIRM_API_URL, [
            'ksOrderId' => $ksOrderId,
            'kpn' => 'KUAISHOU',
            'kpf' => 'PC_WEB',
        ]);

        $resultCode = (int)($response['result'] ?? 0);
        $paid = $resultCode === 1;

        return [
            'orderStatus' => $paid ? 2 : 0,
            'result' => $resultCode,
            'ksOrderId' => $ksOrderId,
            'billTradeNo' => (string)($response['ksOrderId'] ?? $ksOrderId),
            'billMchTradeNo' => (string)($response['outOrderNo'] ?? ''),
            'response' => $response,
        ];
    }

    public function buildQueryTargets(array $order): array
    {
        $targets = [];
        $seen = [];
        $push = static function (?string $value) use (&$targets, &$seen): void {
            $value = trim((string)$value);
            if ($value === '' || isset($seen[$value])) {
                return;
            }
            $seen[$value] = true;
            $targets[] = $value;
        };

        $push((string)($order['api_trade_no'] ?? ''));
        $meta = $this->readOrderExt((string)($order['ext'] ?? ''));
        $push($meta['ksOrderId'] ?? '');
        $push($meta['outOrderNo'] ?? '');
        $push($meta['merchantId'] ?? '');
        $push($this->extractKsOrderIdFromPayUrl((string)($order['payurl'] ?? '')));

        return $targets;
    }

    public function isPaidStatus($status): bool
    {
        $status = strtoupper((string)$status);
        return in_array($status, ['1', '2', 'PAID', 'SUCCESS', 'PAY_SUCCESS'], true);
    }

    public function isUnpaidStatus($status): bool
    {
        $status = strtoupper((string)$status);
        return in_array($status, ['0', 'PENDING', 'UNPAID', 'WAIT_PAY'], true);
    }

    public function cron(array $channel): int
    {
        $channelId = (int)($channel['id'] ?? 0);
        if ($channelId <= 0) {
            return 0;
        }

        $start = date('Y-m-d H:i:s', time() - 1800);
        $orders = Db::name('order')
            ->where('channel', $channelId)
            ->where('status', 0)
            ->where('addtime', '>=', $start)
            ->order('addtime', 'asc')
            ->select()
            ->toArray();

        $processed = 0;
        foreach ($orders as $item) {
            $tradeNo = (string)($item['trade_no'] ?? '');
            if ($tradeNo === '') {
                continue;
            }

            $targets = $this->buildQueryTargets($item);
            if (empty($targets)) {
                continue;
            }

            $fullOrder = $this->getOrder($tradeNo);
            if (!$fullOrder) {
                continue;
            }

            foreach ($targets as $target) {
                usleep(100000);
                try {
                    $result = $this->queryOrder((string)$target);
                } catch (\Throwable $e) {
                    echo $tradeNo . ' 查询失败：' . $e->getMessage() . PHP_EOL;
                    continue;
                }

                if (!$this->isPaidStatus($result['orderStatus'] ?? null)) {
                    continue;
                }

                $notifyApiTradeNo = trim((string)($result['ksOrderId'] ?? $target));
                $billTradeNo = $result['billTradeNo'] ?? null;
                $billMchTradeNo = $result['billMchTradeNo'] ?? null;

                try {
                    CallbackTrustService::beginTrusted([
                        'scope' => 'notify',
                        'action' => 'notify',
                        'plugin_code' => 'kspay',
                        'channel_id' => (int)($channel['id'] ?? 0),
                        'merchant_id' => (int)($fullOrder['uid'] ?? $fullOrder['merchant_id'] ?? 0),
                        'source' => 'plugin-query',
                        'verification' => 'provider-order-query',
                    ], static function () use ($channel, $fullOrder, $notifyApiTradeNo, $billTradeNo, $billMchTradeNo) {
                        (new \app\service\OrderProcessService($channel, $fullOrder))
                            ->processNotify($notifyApiTradeNo, null, $billTradeNo, $billMchTradeNo);
                    });
                    $processed++;
                    echo '订单 ' . $tradeNo . ' 支付成功' . PHP_EOL;
                } catch (\Throwable $e) {
                    echo '订单 ' . $tradeNo . ' 回调处理失败：' . $e->getMessage() . PHP_EOL;
                }

                break;
            }
        }

        return $processed;
    }

    private function createQrcode(PaymentContext $ctx): string
    {
        $channel = $this->channel;
        $ksId = trim((string)($channel['appurl'] ?? ''));
        if ($ksId === '') {
            throw new Exception('快手ID不能为空');
        }

        $amount = (float)($ctx->order['realmoney'] ?? 0);
        if ($amount <= 0) {
            throw new Exception('订单金额无效');
        }
        if (abs(($amount * 10) - round($amount * 10)) > 0.00001) {
            throw new Exception('支付金额必须是 0.1 的倍数');
        }

        $userId = $this->getUserId($ksId);
        if ($userId === '') {
            throw new Exception('未能获取快手用户ID');
        }

        $orderData = [
            'ksCoin' => (int)round($amount * 10),
            'userId' => $userId,
            'kpn' => 'KUAISHOU',
            'customize' => 'false',
            'source' => 'PC_WEB',
            'kpf' => 'PC_WEB',
            'fen' => (int)round($amount * 100),
        ];

        $orderResponse = $this->requestJson(self::CASHIER_API_URL, $orderData);
        $outOrderNo = (string)($orderResponse['outOrderNo'] ?? '');
        $merchantId = (string)($orderResponse['merchantId'] ?? '');
        $ksOrderId = (string)($orderResponse['ksOrderId'] ?? '');
        if ($outOrderNo === '' || $merchantId === '' || $ksOrderId === '') {
            throw new Exception('下单返回数据不完整');
        }

        $qrcodeResponse = $this->requestJson(self::QRCODE_API_URL, [], 'GET', [
            'merchant_id' => $merchantId,
            'out_order_no' => $outOrderNo,
            'js_sdk_version' => '3.0.4',
        ]);
        $qrcodeUrl = trim((string)($qrcodeResponse['qrcode_url'] ?? ''));
        if ($qrcodeUrl === '') {
            throw new Exception('未返回支付二维码链接');
        }

        $this->updateOrder($ctx->order['trade_no'], $ksOrderId);
        $this->updateOrderExt($ctx->order['trade_no'], [
            'version' => 1,
            'ksId' => $ksId,
            'userId' => $userId,
            'outOrderNo' => $outOrderNo,
            'merchantId' => $merchantId,
            'ksOrderId' => $ksOrderId,
            'qrcodeUrl' => $qrcodeUrl,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
        Db::name('order')->where('trade_no', $ctx->order['trade_no'])->update([
            'payurl' => substr($qrcodeUrl, 0, 500),
        ]);

        return $qrcodeUrl;
    }

    private function getUserId(string $ksId): string
    {
        $response = $this->requestJson(self::USERINFO_API_URL, ['id' => $ksId]);
        return trim((string)($response['userId'] ?? ''));
    }

    private function extractKsOrderIdFromPayUrl(string $payUrl): string
    {
        if ($payUrl === '') {
            return '';
        }

        $query = (string)(parse_url($payUrl, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        return trim((string)($params['ksOrderId'] ?? $params['out_order_no'] ?? ''));
    }

    private function readOrderExt(string $ext): array
    {
        if ($ext === '') {
            return [];
        }

        $decoded = json_decode($ext, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function requestJson(string $url, array $payload = [], string $method = 'POST', array $query = []): array
    {
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init();
        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers,
        ];

        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json;charset=utf-8']);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!is_string($response) || $response === '') {
            return [];
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }
}
