<?php

declare(strict_types=1);

namespace app\common;

use app\service\OrderProcessService;
use app\service\payment\CallbackTrustService;
use app\service\payment\LocalFundStore;
use app\service\payment\LocalTransferStore;
use app\service\payment\OrderService;
use app\service\payment\LegacyPaymentGatewayService;
use app\service\payment\PluginNotifyLogService;
use app\service\payment\QrCodeService;
use app\service\system\PluginCodeService;
use RuntimeException;
use support\Response;

abstract class BasePayment implements PaymentInterface
{
    protected array $channel;

    protected string $payRoot;

    public function __construct(array $channel)
    {
        $this->channel = $channel;
        $pluginCode = trim((string)($channel['plugin'] ?? ''));
        $pluginDir = legacy_plugin_directory_name($pluginCode !== '' ? $pluginCode : (string)($channel['plugin_code'] ?? ''));
        $this->payRoot = base_path()
            . DIRECTORY_SEPARATOR . 'plugins'
            . DIRECTORY_SEPARATOR . 'pay'
            . DIRECTORY_SEPARATOR . $pluginDir
            . DIRECTORY_SEPARATOR;
    }

    abstract public function submit(PaymentContext $ctx): array;

    protected function processNotify(
        array $order,
        mixed $api_trade_no = null,
        mixed $buyer = null,
        mixed $bill_trade_no = null,
        mixed $bill_mch_trade_no = null,
        mixed $end_time = null
    ): void {
        $this->assertTrustedCallback('notify', 'Untrusted payment notify blocked');
        (new OrderProcessService($this->channel, $order))->processNotify(
            $api_trade_no,
            $buyer,
            $bill_trade_no,
            $bill_mch_trade_no,
            $end_time
        );
    }

    protected function processReturn(
        array $order,
        mixed $api_trade_no = null,
        mixed $buyer = null,
        mixed $bill_trade_no = null,
        mixed $bill_mch_trade_no = null,
        mixed $end_time = null
    ): array {
        $this->assertTrustedCallback('return', 'Untrusted payment return blocked');
        $jumpUrl = (new OrderProcessService($this->channel, $order))->processReturn(
            $api_trade_no,
            $buyer,
            $bill_trade_no,
            $bill_mch_trade_no,
            $end_time
        );

        return ['type' => 'return', 'url' => $jumpUrl];
    }

    protected static function lockPayData(string $tradeNo, callable $func): mixed
    {
        $order = OrderService::findByTradeNo($tradeNo);
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        if (!empty($notifyPayload['legacy_ext'])) {
            return $notifyPayload['legacy_ext'];
        }

        $data = $func();
        if ($data !== null) {
            OrderService::saveOrder($order, [
                'notify_payload' => array_replace($notifyPayload, [
                    'legacy_ext' => $data,
                ]),
            ]);
            self::rememberQrSource($tradeNo, $data);
        }

        return $data;
    }

    protected static function rememberQrSource(string $tradeNo, mixed $data): void
    {
        $source = '';
        $meta = [];

        if (is_array($data)) {
            if (isset($data['url']) && is_string($data['url'])) {
                $source = trim($data['url']);
            } elseif (isset($data['address']) && is_string($data['address'])) {
                $source = trim($data['address']);
            } elseif (isset($data['payment_address']) && is_string($data['payment_address'])) {
                $source = trim($data['payment_address']);
            } elseif (isset($data[1]) && is_string($data[1])) {
                $source = trim($data[1]);
            }

            $meta = array_filter([
                'type' => is_string($data['type'] ?? null) ? trim((string)$data['type']) : null,
                'page' => is_string($data['page'] ?? null) ? trim((string)$data['page']) : null,
            ], static fn(mixed $value): bool => is_string($value) && $value !== '');
        } elseif (is_string($data)) {
            $source = trim($data);
        }

        if ($source !== '') {
            QrCodeService::rememberOrderSource($tradeNo, $source, $meta);
        }
    }

    protected function updateOrder(string $tradeNo, mixed $api_trade_no, mixed $buyer = null, mixed $status = null): void
    {
        $order = OrderService::findByTradeNo($tradeNo);
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];

        if ($api_trade_no !== null && trim((string)$api_trade_no) !== '') {
            $notifyPayload['api_trade_no'] = (string)$api_trade_no;
        }
        if ($buyer !== null && trim((string)$buyer) !== '') {
            $notifyPayload['buyer'] = (string)$buyer;
        }

        $changes = ['notify_payload' => $notifyPayload];
        if ($status !== null && $status !== '') {
            $changes['status'] = (int)$status;
        }

        OrderService::saveOrder($order, $changes);
    }

    protected function updateOrderExt(string $tradeNo, mixed $data): void
    {
        $order = OrderService::findByTradeNo($tradeNo);
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $notifyPayload['legacy_ext'] = $data;
        OrderService::saveOrder($order, ['notify_payload' => $notifyPayload]);
    }

    protected function processTransfer(
        mixed $outBizNo,
        mixed $status,
        mixed $errmsg = null,
        mixed $channelOrderNo = null,
        mixed $paydate = null
    ): void {
        $trust = $this->assertTrustedCallback('transfernotify', 'Untrusted transfer notify blocked');
        $outBizNo = trim((string)$outBizNo);
        if ($outBizNo === '') {
            $this->writeTransferProcessLog('failed', '', 'transfer notify missing out_biz_no', [
                'callback_status' => (string)$status,
                'errmsg' => (string)$errmsg,
                'trust' => $trust,
            ]);
        }
        if ($outBizNo === '') {
            throw new RuntimeException('代付回调缺少商户代付单号');
        }

        $transfer = LocalTransferStore::findTransferByBizNo($outBizNo, $outBizNo);
        if (!$transfer) {
            $this->writeTransferProcessLog('failed', $outBizNo, 'transfer record not found', [
                'callback_status' => (string)$status,
                'errmsg' => (string)$errmsg,
                'trust' => $trust,
            ]);
        }
        if (!$transfer) {
            throw new RuntimeException('代付记录不存在：' . $outBizNo);
        }

        if ((int)($transfer->status ?? 0) !== 0) {
            $this->writeTransferProcessLog('skipped', $outBizNo, 'transfer already processed', [
                'current_status' => (int)($transfer->status ?? 0),
                'result' => (string)($transfer->result ?? ''),
            ], $transfer);
            return;
        }

        $normalizedStatus = (int)$status;
        if (!in_array($normalizedStatus, [0, 1, 2], true)) {
            $normalizedStatus = 0;
        }

        $now = self::normalizeDateTime((string)$paydate);
        $message = trim((string)$errmsg);
        $pluginCode = PluginCodeService::normalize((string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? $transfer->channel_plugin_code ?? ''));
        $channelOrder = trim((string)$channelOrderNo);
        if ($channelOrder === '') {
            $channelOrder = trim((string)($transfer->channel_order_no ?? $transfer->channel_trade_no ?? $outBizNo));
        }
        $callbackPayload = self::transferCallbackPayload($outBizNo, $normalizedStatus, $message, $channelOrder);

        if ($normalizedStatus === 0) {
            LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
                'status' => 0,
                'result' => 'plugin_transfer_pending',
                'last_error' => $message !== '' ? $message : '插件代付处理中，等待查询或异步通知',
                'channel_order_no' => $channelOrder,
                'channel_trade_no' => $channelOrder,
                'channel_plugin_code' => $pluginCode,
                'raw_response' => $callbackPayload,
            ]);
            $this->writeTransferProcessLog('pending', $outBizNo, 'plugin transfer pending', $callbackPayload, $transfer);
            return;
        }

        if ($normalizedStatus === 2) {
            LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
                'status' => 2,
                'result' => 'plugin_transfer_failed',
                'last_error' => $message !== '' ? $message : '支付插件代付失败',
                'channel_order_no' => $channelOrder,
                'channel_trade_no' => $channelOrder,
                'channel_plugin_code' => $pluginCode,
                'rejected_at' => $now,
                'raw_response' => $callbackPayload,
            ]);
            $this->writeTransferProcessLog('failed', $outBizNo, $message !== '' ? $message : 'plugin transfer failed', $callbackPayload, $transfer);
            return;
        }

        $flow = LocalFundStore::recordTransferSuccess($transfer, [
            'source' => 'plugin-transfer-notify',
            'created_at' => $now,
            'plugin_code' => $pluginCode,
            'channel_order_no' => $channelOrder,
            'channel_trade_no' => $channelOrder,
            'proof_no' => $channelOrder,
            'operator' => 'plugin:' . $pluginCode,
            'result' => 'plugin_transferred',
        ]);

        LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
            'status' => 1,
            'available_money' => (string)($flow->balance_after ?? ''),
            'channel_order_no' => $channelOrder,
            'channel_trade_no' => $channelOrder,
            'channel_plugin_code' => $pluginCode,
            'proof_no' => $channelOrder,
            'operator' => 'plugin:' . $pluginCode,
            'result' => 'plugin_transferred',
            'last_error' => '',
            'finished_at' => $now,
            'raw_response' => $callbackPayload,
        ]);
        $this->writeTransferProcessLog('success', $outBizNo, 'plugin transfer completed', $callbackPayload + [
            'balance_after' => (string)($flow->balance_after ?? ''),
        ], $transfer);
    }

    protected function processRefund(
        mixed $refundNo,
        mixed $status,
        mixed $errmsg = null,
        mixed $channelOrderNo = null,
        mixed $refundFee = null,
        mixed $paydate = null,
        mixed $outRefundNo = null
    ): void {
        $trust = $this->assertTrustedCallback('refundnotify', 'Untrusted refund notify blocked');
        $refundNo = trim((string)$refundNo);
        $outRefundNo = trim((string)$outRefundNo);
        if ($refundNo === '' && $outRefundNo === '') {
            $this->writeRefundProcessLog('failed', '', '', 'refund notify missing refund_no/out_refund_no', [
                'callback_status' => (string)$status,
                'errmsg' => (string)$errmsg,
                'trust' => $trust,
            ]);
        }
        if ($refundNo === '' && $outRefundNo === '') {
            throw new RuntimeException('退款回调缺少退款单号');
        }

        $refund = LocalTransferStore::findRefundByAnyNo($refundNo, $outRefundNo);
        if (!$refund && $refundNo !== '') {
            $refund = LocalTransferStore::findRefundByTradeNo($refundNo);
            if ($refund) {
                $outRefundNo = (string)($refund->out_refund_no ?? $outRefundNo);
                $refundNo = (string)$refund->refund_no;
            }
        }
        if (!$refund && $outRefundNo !== '') {
            $refund = LocalTransferStore::findRefundByTradeNo($outRefundNo);
            if ($refund) {
                $refundNo = (string)$refund->refund_no;
                $outRefundNo = (string)($refund->out_refund_no ?? $outRefundNo);
            }
        }
        if (!$refund) {
            $this->writeRefundProcessLog('failed', $refundNo, $outRefundNo, 'refund record not found', [
                'callback_status' => (string)$status,
                'errmsg' => (string)$errmsg,
                'trust' => $trust,
            ]);
        }
        if (!$refund) {
            throw new RuntimeException('退款记录不存在：' . ($refundNo !== '' ? $refundNo : $outRefundNo));
        }

        if ((int)($refund->status ?? 0) !== 0) {
            $this->writeRefundProcessLog('skipped', $refundNo, $outRefundNo, 'refund already processed', [
                'current_status' => (int)($refund->status ?? 0),
                'result' => (string)($refund->result ?? ''),
            ], $refund);
            return;
        }

        $normalizedStatus = (int)$status;
        if (!in_array($normalizedStatus, [0, 1, 2], true)) {
            $normalizedStatus = 0;
        }

        $now = self::normalizeDateTime((string)$paydate);
        $message = trim((string)$errmsg);
        $pluginCode = PluginCodeService::normalize((string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? $refund->channel_plugin_code ?? ''));
        $channelOrder = trim((string)$channelOrderNo);
        if ($channelOrder === '') {
            $channelOrder = trim((string)($refund->channel_order_no ?? $refund->channel_trade_no ?? $refund->refund_no));
        }
        $amount = number_format((float)($refundFee ?? $refund->reducemoney ?? $refund->money ?? 0), 2, '.', '');
        $callbackPayload = self::refundCallbackPayload($refundNo, $outRefundNo, $normalizedStatus, $message, $channelOrder, $amount);

        if ($normalizedStatus === 0) {
            LocalTransferStore::updateRefund((string)$refund->refund_no, [
                'status' => 0,
                'result' => 'plugin_refund_pending',
                'last_error' => $message !== '' ? $message : '插件退款处理中，等待查询或异步通知',
                'channel_order_no' => $channelOrder,
                'channel_trade_no' => $channelOrder,
                'channel_plugin_code' => $pluginCode,
                'raw_response' => $callbackPayload,
            ]);
            $this->writeRefundProcessLog('pending', $refundNo, $outRefundNo, 'plugin refund pending', $callbackPayload, $refund);
            return;
        }

        if ($normalizedStatus === 2) {
            LocalTransferStore::updateRefund((string)$refund->refund_no, [
                'status' => 2,
                'result' => 'plugin_refund_failed',
                'last_error' => $message !== '' ? $message : '支付插件退款失败',
                'channel_order_no' => $channelOrder,
                'channel_trade_no' => $channelOrder,
                'channel_plugin_code' => $pluginCode,
                'raw_response' => $callbackPayload,
            ]);
            $this->writeRefundProcessLog('failed', $refundNo, $outRefundNo, $message !== '' ? $message : 'plugin refund failed', $callbackPayload, $refund);
            return;
        }

        $updated = OrderService::completeRefund($refund, [
            'source' => 'plugin-refund-notify',
            'created_at' => $now,
            'amount' => $amount,
            'trade_no' => (string)$refund->trade_no,
            'out_trade_no' => (string)$refund->out_trade_no,
            'out_refund_no' => (string)$refund->out_refund_no,
            'plugin_code' => $pluginCode,
            'channel_order_no' => $channelOrder,
            'channel_trade_no' => $channelOrder,
            'proof_no' => $channelOrder,
            'operator' => 'plugin:' . $pluginCode,
            'result' => 'plugin_refunded',
            'raw_response' => $callbackPayload,
        ]);
        $this->writeRefundProcessLog('success', $refundNo, $outRefundNo, 'plugin refund completed', $callbackPayload + [
            'balance_after' => (string)($updated->available_money ?? ''),
        ], $updated);
        return;
/*
        $flow = LocalFundStore::debit(
            (int)$refund->merchant_id,
            $amount,
            '退款扣款',
            'refund',
            (string)$refund->refund_no,
            $now,
            [
                'trade_no' => (string)$refund->trade_no,
                'out_trade_no' => (string)$refund->out_trade_no,
                'out_refund_no' => (string)$refund->out_refund_no,
                'plugin_code' => $pluginCode,
                'channel_order_no' => $channelOrder,
                'channel_trade_no' => $channelOrder,
            ]
        );

        $flow = LocalFundStore::recordRefundSuccess($refund, [
            'source' => 'plugin-refund-notify',
            'created_at' => $now,
            'amount' => $amount,
            'plugin_code' => $pluginCode,
            'channel_order_no' => $channelOrder,
            'channel_trade_no' => $channelOrder,
            'proof_no' => $channelOrder,
            'operator' => 'plugin:' . $pluginCode,
            'result' => 'plugin_refunded',
        ]);

        LocalTransferStore::updateRefund((string)$refund->refund_no, [
            'status' => 1,
            'result' => 'plugin_refunded',
            'last_error' => '',
            'channel_order_no' => $channelOrder,
            'channel_trade_no' => $channelOrder,
            'channel_plugin_code' => $pluginCode,
            'proof_no' => $channelOrder,
            'operator' => 'plugin:' . $pluginCode,
            'finished_at' => $now,
            'raw_response' => $callbackPayload + [
                'balance_after' => (string)($flow->balance_after ?? ''),
            ],
        ]);
        $this->writeRefundProcessLog('success', $refundNo, $outRefundNo, 'plugin refund completed', $callbackPayload + [
            'balance_after' => (string)($flow->balance_after ?? ''),
        ], $refund);
*/
    }

    protected function getOrder(string $tradeNo): ?array
    {
        $order = OrderService::findByTradeNoOrNull($tradeNo);
        return $order ? LegacyPaymentGatewayService::legacyOrderArray($order) : null;
    }

    protected function showMsg(string $content, int $type = 4, string|false $back = false): Response
    {
        $panels = [
            1 => '#e8fff4',
            2 => '#eef6ff',
            3 => '#fff7e6',
            4 => '#fff1f0',
        ];
        $border = [
            1 => '#7dd3a7',
            2 => '#93c5fd',
            3 => '#fbbf24',
            4 => '#f87171',
        ];
        $bg = $panels[$type] ?? $panels[4];
        $line = $border[$type] ?? $border[4];
        $backUrl = $back === false ? 'javascript:history.back()' : (string)$back;
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>NexPay 提示</title><style>body{margin:0;background:#f6f9ff;font-family:"Segoe UI","Microsoft YaHei",sans-serif;color:#152033}'
            . '.wrap{max-width:760px;margin:48px auto;padding:0 18px}.card{background:' . $bg . ';border:1px solid ' . $line . ';border-radius:20px;padding:28px;box-shadow:0 18px 48px rgba(15,23,42,.08)}'
            . '.title{font-size:24px;font-weight:700;margin:0 0 14px}.content{line-height:1.8}.action{margin-top:22px}.btn{display:inline-flex;padding:10px 18px;border-radius:999px;background:#1677ff;color:#fff;text-decoration:none}</style></head>'
            . '<body><div class="wrap"><div class="card"><h1 class="title">NexPay 提示</h1><div class="content">' . $content . '</div>'
            . '<div class="action"><a class="btn" href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '">返回</a></div></div></div></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    protected function markTrustedCallback(PaymentContext $ctx, string $scope, string $verification = ''): callable
    {
        $channelPlugin = PluginCodeService::normalize((string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? ''));
        $runtime = is_array($ctx->runtime ?? null) ? $ctx->runtime : [];
        $action = strtolower(trim((string)($runtime['action'] ?? '')));
        $pluginCode = PluginCodeService::normalize((string)($runtime['plugin_code'] ?? $channelPlugin));
        $verification = trim($verification);
        $channelId = isset($runtime['channel_id']) && $runtime['channel_id'] !== '' ? (int)$runtime['channel_id'] : null;
        $merchantId = isset($runtime['merchant_id']) && $runtime['merchant_id'] !== '' ? (int)$runtime['merchant_id'] : null;

        return static function (callable $callback) use ($scope, $verification, $action, $pluginCode, $channelId, $merchantId) {
            return CallbackTrustService::beginTrusted([
                'scope' => strtolower(trim($scope)),
                'action' => $action,
                'plugin_code' => $pluginCode,
                'channel_id' => $channelId,
                'merchant_id' => $merchantId,
                'source' => 'plugin-callback',
                'verification' => $verification !== '' ? $verification : 'plugin-self-verified',
            ], $callback);
        };
    }

    protected function markTrustedQueryResult(string $scope, string $reason = 'plugin-query'): callable
    {
        $pluginCode = PluginCodeService::normalize((string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? ''));
        $scope = strtolower(trim($scope));
        $action = $scope === 'return' ? 'return' : $scope;
        $reason = trim($reason);
        $channelId = isset($this->channel['id']) && $this->channel['id'] !== '' ? (int)$this->channel['id'] : null;
        $merchantId = isset($this->channel['merchant_id']) && $this->channel['merchant_id'] !== '' ? (int)$this->channel['merchant_id'] : null;

        return function (callable $callback) use ($scope, $action, $pluginCode, $reason, $channelId, $merchantId) {
            return CallbackTrustService::beginTrusted([
                'scope' => $scope,
                'action' => $action,
                'plugin_code' => $pluginCode,
                'channel_id' => $channelId,
                'merchant_id' => $merchantId,
                'source' => 'plugin-query',
                'verification' => $reason !== '' ? $reason : 'plugin-query',
            ], $callback);
        };
    }

    private static function transferCallbackPayload(string $outBizNo, int $status, string $message, string $channelOrder): array
    {
        return [
            'source' => 'plugin-transfer-notify',
            'out_biz_no' => $outBizNo,
            'status' => $status,
            'errmsg' => $message,
            'channel_order_no' => $channelOrder,
            'received_at' => date('Y-m-d H:i:s'),
        ];
    }

    private static function refundCallbackPayload(
        string $refundNo,
        string $outRefundNo,
        int $status,
        string $message,
        string $channelOrder,
        string $amount
    ): array {
        return [
            'source' => 'plugin-refund-notify',
            'refund_no' => $refundNo,
            'out_refund_no' => $outRefundNo,
            'status' => $status,
            'errmsg' => $message,
            'channel_order_no' => $channelOrder,
            'amount' => $amount,
            'received_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function writeTransferProcessLog(
        string $status,
        string $outBizNo,
        string $message,
        array $context = [],
        ?object $transfer = null
    ): void {
        try {
            PluginNotifyLogService::write([
                'action' => 'transfernotify',
                'stage' => 'transfer-process',
                'channel_id' => (int)($this->channel['id'] ?? $transfer?->channel_id ?? 0),
                'merchant_id' => (int)($transfer?->merchant_id ?? $this->channel['merchant_id'] ?? 0),
                'plugin_code' => PluginCodeService::normalize((string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? $transfer?->channel_plugin_code ?? '')),
                'method_code' => (string)($this->channel['channel_code'] ?? $this->channel['type'] ?? ''),
                'status' => $status,
                'message' => $message,
                'context' => array_replace([
                    'biz_no' => (string)($transfer?->biz_no ?? ''),
                    'out_biz_no' => $outBizNo !== '' ? $outBizNo : (string)($transfer?->out_biz_no ?? ''),
                    'amount' => (string)($transfer?->money ?? ''),
                    'current_status' => $transfer !== null ? (int)($transfer->status ?? 0) : null,
                ], $context),
            ]);
        } catch (\Throwable) {
        }
    }

    private function writeRefundProcessLog(
        string $status,
        string $refundNo,
        string $outRefundNo,
        string $message,
        array $context = [],
        ?object $refund = null
    ): void {
        try {
            PluginNotifyLogService::write([
                'action' => 'refundnotify',
                'stage' => 'refund-process',
                'trade_no' => (string)($refund?->trade_no ?? ''),
                'channel_id' => (int)($this->channel['id'] ?? 0),
                'merchant_id' => (int)($refund?->merchant_id ?? $this->channel['merchant_id'] ?? 0),
                'plugin_code' => PluginCodeService::normalize((string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? $refund?->channel_plugin_code ?? '')),
                'method_code' => (string)($this->channel['channel_code'] ?? $this->channel['type'] ?? ''),
                'status' => $status,
                'message' => $message,
                'context' => array_replace([
                    'refund_no' => $refundNo !== '' ? $refundNo : (string)($refund?->refund_no ?? ''),
                    'out_refund_no' => $outRefundNo !== '' ? $outRefundNo : (string)($refund?->out_refund_no ?? ''),
                    'amount' => (string)($refund?->reducemoney ?? $refund?->money ?? ''),
                    'current_status' => $refund !== null ? (int)($refund->status ?? 0) : null,
                ], $context),
            ]);
        } catch (\Throwable) {
        }
    }

    private static function normalizeDateTime(string $value): string
    {
        $timestamp = trim($value) !== '' ? strtotime($value) : false;
        return date('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());
    }

    private function assertTrustedCallback(string $scope, string $message): array
    {
        $scope = strtolower(trim($scope));
        $pluginCode = PluginCodeService::normalize((string)($this->channel['plugin_code'] ?? $this->channel['plugin'] ?? ''));
        $trust = CallbackTrustService::assertTrusted([
            'plugin_code' => $pluginCode,
            'scope' => $scope,
        ], $message);

        $allowedActions = $this->allowedActionsForScope($scope);
        $action = strtolower(trim((string)($trust['action'] ?? '')));
        if ($allowedActions !== [] && !in_array($action, $allowedActions, true)) {
            throw new RuntimeException($message . ': action mismatch');
        }

        return $trust;
    }

    private function allowedActionsForScope(string $scope): array
    {
        return match ($scope) {
            'notify' => ['notify', 'preauthnotify', 'webhook'],
            'return' => ['return'],
            'refundnotify' => ['refundnotify', 'webhook'],
            'transfernotify' => ['transfernotify', 'webhook'],
            default => [$scope],
        };
    }
}
