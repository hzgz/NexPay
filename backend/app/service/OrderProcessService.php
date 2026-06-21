<?php

declare(strict_types=1);

namespace app\service;

use app\service\payment\CallbackTrustService;
use app\service\payment\OrderService;
use app\service\payment\LegacyPaymentGatewayService;
use app\service\payment\PluginNotifyLogService;
use app\service\system\ConfigService;
use app\service\system\PluginCodeService;
use Throwable;

class OrderProcessService
{
    public function __construct(
        private readonly array $channel,
        private readonly array $order
    ) {
    }

    public function processNotify(
        mixed $apiTradeNo = null,
        mixed $buyer = null,
        mixed $billTradeNo = null,
        mixed $billMchTradeNo = null,
        mixed $endTime = null
    ): object {
        $tradeNo = trim((string)($this->order['trade_no'] ?? ''));
        try {
            $trust = $this->assertTrustedNotify('notify', 'Untrusted order notify blocked');
            $order = OrderService::findByTradeNo($tradeNo);
            $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];

            foreach ([
                'api_trade_no' => $apiTradeNo,
                'buyer' => $buyer,
                'bill_trade_no' => $billTradeNo,
                'bill_mch_trade_no' => $billMchTradeNo,
            ] as $key => $value) {
                $text = trim((string)$value);
                if ($text !== '') {
                    $notifyPayload[$key] = $text;
                }
            }

            if (!isset($notifyPayload['legacy_channel'])) {
                $notifyPayload['legacy_channel'] = $this->channel;
            }

            if (!isset($notifyPayload['legacy_order'])) {
                $notifyPayload['legacy_order'] = $this->order;
            }

            OrderService::saveOrder($order, ['notify_payload' => $notifyPayload]);

            $completed = OrderService::completeOrder($order, [
                'source' => 'legacy-plugin',
                'txid' => trim((string)$apiTradeNo),
                'paid_at' => trim((string)$endTime),
                'confirmations' => 1,
                'buyer' => trim((string)$buyer),
                'bill_trade_no' => trim((string)$billTradeNo),
                'bill_mch_trade_no' => trim((string)$billMchTradeNo),
                'callback_trust' => $trust,
            ]);

            $this->writeNotifyProcessLog('success', $tradeNo, '支付通知已落单');
            return $completed;
        } catch (Throwable $exception) {
            $this->writeNotifyProcessLog('failed', $tradeNo, $exception->getMessage());
            throw $exception;
        }
    }

    public function processReturn(
        mixed $apiTradeNo = null,
        mixed $buyer = null,
        mixed $billTradeNo = null,
        mixed $billMchTradeNo = null,
        mixed $endTime = null
    ): string {
        $trust = $this->assertTrustedNotify('return', 'Untrusted payment return blocked');
        CallbackTrustService::beginTrusted(array_replace($trust, [
            'scope' => 'notify',
            'action' => 'notify',
        ]), function () use (&$order, $apiTradeNo, $buyer, $billTradeNo, $billMchTradeNo, $endTime) {
            $order = $this->processNotify($apiTradeNo, $buyer, $billTradeNo, $billMchTradeNo, $endTime);
        });
        $returnUrl = trim((string)($order->return_url ?? ''));
        if ($returnUrl !== '') {
            return $returnUrl;
        }

        return rtrim((string)ConfigService::gatewayBaseUrl(), '/') . '/pay/checkout/' . $order->trade_no;
    }

    private function writeNotifyProcessLog(string $status, string $tradeNo, string $message): void
    {
        try {
            PluginNotifyLogService::write([
                'action' => 'notify',
                'stage' => 'order-process',
                'trade_no' => $tradeNo,
                'channel_id' => (int)($this->channel['id'] ?? 0),
                'merchant_id' => (int)($this->channel['merchant_id'] ?? $this->order['merchant_id'] ?? 0),
                'plugin_code' => (string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? ''),
                'method_code' => (string)($this->channel['channel_code'] ?? $this->channel['type'] ?? $this->order['typename'] ?? ''),
                'status' => $status,
                'message' => $message,
                'context' => [
                    'trust' => CallbackTrustService::describeCurrent(),
                ],
            ]);
        } catch (Throwable) {
        }
    }

    private function assertTrustedNotify(string $scope, string $message): array
    {
        $pluginCode = PluginCodeService::normalize((string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? ''));
        $trust = CallbackTrustService::assertTrusted([
            'plugin_code' => $pluginCode,
            'scope' => strtolower(trim($scope)),
        ], $message);

        $allowedActions = match (strtolower(trim($scope))) {
            'notify' => ['notify', 'preauthnotify', 'webhook'],
            'return' => ['return'],
            default => [strtolower(trim($scope))],
        };

        $action = strtolower(trim((string)($trust['action'] ?? '')));
        if ($allowedActions !== [] && !in_array($action, $allowedActions, true)) {
            throw new \RuntimeException($message . ': action mismatch');
        }

        return $trust;
    }
}
