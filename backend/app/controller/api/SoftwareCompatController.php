<?php

declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseApiController;
use app\model\Merchant;
use app\model\MerchantChannel;
use app\model\Order;
use app\service\payment\CallbackTrustService;
use app\service\payment\LocalOrderStore;
use app\service\payment\OpenApiGuardService;
use app\service\payment\OrderService;
use app\service\payment\PluginNotifyLogService;
use app\service\system\AccountService;
use app\service\system\ConfigService;
use app\service\system\JsonStoreService;
use app\service\system\MerchantApiService;
use app\service\system\MerchantChannelService;
use app\service\system\PaymentMetaService;
use support\Request;
use support\Response;
use Throwable;

class SoftwareCompatController extends BaseApiController
{
    public function verify(Request $request): Response
    {
        $payload = $this->compatPayload($request);
        if (($guard = $this->validateSoftwareCompatRequest($payload, 'software.verify')) !== null) {
            return $guard;
        }
        $merchantId = $this->resolveMerchantId($payload);
        $key = $this->resolveCredential($payload, ['key', 'token']);

        if ($merchantId <= 0 || $key === '') {
            return $this->legacyResponse(201, '商户ID和通讯密钥不可为空');
        }

        $merchant = $this->authorizeMerchant($merchantId, $key);
        if ($merchant === null) {
            return $this->legacyResponse(201, '商户不存在或密钥错误!');
        }

        return $this->legacyResponse(200, '验证成功!');
    }

    public function heartbeat(Request $request): Response
    {
        $payload = $this->compatPayload($request);
        if (($guard = $this->validateSoftwareCompatRequest($payload, 'software.heartbeat')) !== null) {
            return $guard;
        }
        $merchantId = $this->resolveMerchantId($payload);
        $key = $this->resolveCredential($payload, ['key', 'token']);
        $channelId = $this->resolveChannelId($payload);

        if ($channelId <= 0) {
            return $this->legacyResponse(201, '通道ID不可为空');
        }

        $merchant = $this->authorizeMerchant($merchantId, $key);
        if ($merchant === null) {
            return $this->legacyResponse(201, '商户不存在或密钥错误!');
        }

        $channel = $this->findChannel($merchantId, $channelId);
        if ($channel === null) {
            return $this->legacyResponse(201, '通道不存在');
        }

        $this->updateChannelHeartbeat($merchantId, $channelId, $payload, $channel);

        return $this->legacyResponse(200, '验证成功!');
    }

    public function checkOrder(Request $request): Response
    {
        $payload = $this->compatPayload($request);
        if (($guard = $this->validateSoftwareCompatRequest($payload, 'software.check-order')) !== null) {
            return $guard;
        }
        $merchantId = $this->resolveMerchantId($payload);
        $key = $this->resolveCredential($payload, ['key', 'token']);
        $channelId = $this->resolveChannelId($payload);

        if ($channelId <= 0) {
            return $this->legacyResponse(201, '通道ID不可为空');
        }

        $merchant = $this->authorizeMerchant($merchantId, $key);
        if ($merchant === null) {
            return $this->legacyResponse(201, '商户不存在或密钥错误!');
        }

        $channel = $this->findChannel($merchantId, $channelId);
        if ($channel === null) {
            return $this->legacyResponse(201, '通道不存在');
        }

        $methodCode = $this->normalizeMethodCode(
            (string)($payload['type'] ?? $payload['method'] ?? $channel['method_code'] ?? '')
        );

        $items = [];
        foreach ($this->merchantOrders($merchantId) as $order) {
            if (!$this->isPendingOrder($order)) {
                continue;
            }

            if ((int)($order->merchant_channel_id ?? $order->channel ?? 0) !== $channelId) {
                continue;
            }

            if ($methodCode !== '' && !$this->orderMatchesMethod($order, $methodCode)) {
                continue;
            }

            $items[] = [
                'id' => (int)($order->id ?? 0),
                'name' => (string)($order->subject ?? $order->name ?? ''),
                'type' => $this->normalizeMethodCode((string)($order->channel_code ?? $order->typename ?? '')),
                'money' => $this->formatMoney($order->amount ?? $order->realmoney ?? '0'),
                'truemoney' => $this->formatMoney($order->payable_amount ?? $order->amount ?? $order->realmoney ?? '0'),
                'account_id' => (int)($order->merchant_channel_id ?? $order->channel ?? 0),
                'trade_no' => (string)($order->trade_no ?? ''),
                'out_trade_no' => (string)($order->out_trade_no ?? ''),
            ];
        }

        if ($items === []) {
            return $this->legacyResponse(201, '暂无查询到此账户订单信息');
        }

        usort($items, static fn(array $left, array $right): int => ($right['id'] ?? 0) <=> ($left['id'] ?? 0));

        return $this->legacyResponse(200, '返回成功', ['data' => $items]);
    }

    public function pcNotify(Request $request): Response
    {
        $payload = $this->compatPayload($request);
        if (($guard = $this->validateSoftwareCompatRequest($payload, 'software.pc-notify')) !== null) {
            return $guard;
        }
        $merchantId = $this->resolveMerchantId($payload);
        $key = $this->resolveCredential($payload, ['key', 'token']);
        $channelId = $this->resolveChannelId($payload);

        if ($channelId <= 0) {
            return $this->legacyResponse(201, '通道ID不可为空');
        }

        $merchant = $this->authorizeMerchant($merchantId, $key);
        if ($merchant === null) {
            return $this->legacyResponse(201, '商户不存在或密钥错误!');
        }

        $channel = $this->findChannel($merchantId, $channelId);
        if ($channel === null) {
            return $this->legacyResponse(201, '通道不存在');
        }

        $methodCode = $this->normalizeMethodCode(
            (string)($payload['type'] ?? $payload['method'] ?? $channel['method_code'] ?? '')
        );
        $amount = $this->resolveAmount($payload, ['money', 'amount', 'price']);
        $orderNo = $this->resolveText($payload, ['orderNo', 'order_no', 'out_trade_no', 'trade_no']);

        if ($amount === null && $orderNo === '') {
            return $this->legacyResponse(201, '订单金额或订单号不可为空');
        }

        $matched = $this->matchPendingOrder($merchantId, [
            'channel_id' => $channelId,
            'method_code' => $methodCode,
            'identifier' => $orderNo,
            'amount' => $amount,
        ]);

        if (($matched['error'] ?? '') !== '') {
            return $this->legacyResponse(201, (string)$matched['error']);
        }

        $order = $matched['order'] ?? null;
        if (!is_object($order)) {
            return $this->legacyResponse(201, '订单超时或不存在');
        }

        try {
            $completed = $this->completeOrderFromMonitor(
                $request,
                $order,
                $channel,
                'software-pcnotify',
                [
                    'buyer' => 'software-monitor',
                    'bill_trade_no' => $this->resolveText($payload, ['tradeNo', 'channel_trade_no', 'monitor_trade_no']),
                    'bill_mch_trade_no' => $orderNo,
                    'amount' => $amount,
                ],
                $payload
            );

            return $this->legacyResponse(200, '回调成功!', [
                'trade_no' => (string)($completed->trade_no ?? ''),
                'out_trade_no' => (string)($completed->out_trade_no ?? ''),
            ]);
        } catch (Throwable $exception) {
            return $this->legacyResponse(201, $exception->getMessage());
        }
    }

    public function report(Request $request, string $merchantId = ''): Response
    {
        $payload = $this->compatPayload($request);
        $reportMerchantId = $merchantId !== '' ? $merchantId : $this->extractReportMerchantId($request);
        $payload['report_url'] = rtrim((string)config_get('app_url', ConfigService::gatewayBaseUrl()), '/')
            . '/api/report/' . trim((string)$reportMerchantId);
        if (($guard = $this->validateMonitorReportRequest($payload)) !== null) {
            return $guard;
        }
        $resolvedMerchantId = $this->resolveMerchantId($payload, $reportMerchantId);
        $token = $this->resolveCredential($payload, ['token', 'key']);

        if ($resolvedMerchantId <= 0) {
            return $this->monitorResponse(201, '商户ID缺失');
        }

        if ($token === '') {
            return $this->monitorResponse(201, 'token 参数缺失');
        }

        $content = $payload['content'] ?? null;
        if ($content === null || (is_string($content) && trim($content) === '')) {
            return $this->monitorResponse(201, 'content 参数缺失');
        }

        $merchant = $this->authorizeMerchant($resolvedMerchantId, $token);
        if ($merchant === null) {
            return $this->monitorResponse(201, '商户不存在或密钥错误!');
        }

        $channelId = $this->resolveChannelId($payload);
        $channel = $channelId > 0 ? $this->findChannel($resolvedMerchantId, $channelId) : null;
        if ($channelId > 0 && $channel === null) {
            return $this->monitorResponse(201, 'channel_id invalid');
        }

        $contentArray = is_array($content) ? $content : json_decode((string)$content, true);
        if (!is_array($contentArray)) {
            return $this->monitorResponse(201, 'content 解析失败');
        }

        $message = trim((string)($contentArray['msg'] ?? $contentArray['message'] ?? ''));
        $packageName = trim((string)($contentArray['package_name'] ?? $contentArray['package'] ?? ''));
        $methodCode = $this->packageToMethodCode($packageName);
        if ($methodCode === '') {
            return $this->monitorResponse(400, '不支持的支付包类型');
        }

        $amount = $this->resolveAmount($contentArray, ['money', 'amount', 'price']);
        if ($amount === null) {
            $amount = $this->extractAmountFromMessage($message, $methodCode);
        }

        $orderNo = $this->resolveText($contentArray, ['orderNo', 'order_no', 'out_trade_no', 'trade_no', 'mark']);
        if ($amount === null && $orderNo === '') {
            return $this->monitorResponse(201, '未找到金额');
        }

        $matched = $this->matchPendingOrder($resolvedMerchantId, [
            'channel_id' => $channelId,
            'method_code' => $methodCode,
            'identifier' => $orderNo,
            'amount' => $amount,
        ]);

        if (($matched['error'] ?? '') !== '') {
            return $this->monitorResponse(201, (string)$matched['error']);
        }

        $order = $matched['order'] ?? null;
        if (!is_object($order)) {
            return $this->monitorResponse(201, '订单超时或不存在');
        }

        if ($channel === null) {
            $channel = $this->findChannel(
                $resolvedMerchantId,
                (int)($order->merchant_channel_id ?? $order->channel ?? 0)
            );
        }

        if ($channel === null) {
            return $this->monitorResponse(201, '通道不存在');
        }

        try {
            $completed = $this->completeOrderFromMonitor(
                $request,
                $order,
                $channel,
                'software-report',
                [
                    'buyer' => 'software-report',
                    'bill_trade_no' => $this->resolveText($contentArray, ['tradeNo', 'channel_trade_no', 'monitor_trade_no']),
                    'bill_mch_trade_no' => $orderNo,
                    'amount' => $amount,
                    'message' => $message,
                    'package_name' => $packageName,
                    'content' => $contentArray,
                ],
                $payload
            );

            return $this->monitorResponse(200, '处理成功', [
                'order_id' => (string)($completed->out_trade_no ?? ''),
                'trade_no' => (string)($completed->trade_no ?? ''),
                'subject' => (string)($completed->subject ?? ''),
            ]);
        } catch (Throwable $exception) {
            return $this->monitorResponse(201, $exception->getMessage());
        }
    }

    private function compatPayload(Request $request): array
    {
        $payload = $this->payload($request);
        return is_array($payload) ? $payload : [];
    }

    private function validateSoftwareCompatRequest(array $payload, string $scope): ?Response
    {
        try {
            OpenApiGuardService::assertSoftwareCompatFreshness($payload, $scope);
            return null;
        } catch (Throwable $exception) {
            return $this->legacyResponse(201, $exception->getMessage());
        }
    }

    private function validateMonitorReportRequest(array $payload): ?Response
    {
        try {
            OpenApiGuardService::assertSoftwareCompatFreshness($payload, 'software.report');
            return null;
        } catch (Throwable $exception) {
            return $this->monitorResponse(201, $exception->getMessage());
        }
    }

    private function authorizeMerchant(int $merchantId, string $providedKey): ?object
    {
        if ($merchantId <= 0 || $providedKey === '') {
            return null;
        }

        $merchant = $this->loadMerchant($merchantId);
        if ($merchant === null) {
            return null;
        }

        if ((int)($merchant->status ?? 0) !== 1) {
            return null;
        }

        $expectedKey = trim((string)($merchant->mch_key ?? ''));
        if ($expectedKey === '') {
            return null;
        }

        return hash_equals(strtolower($expectedKey), strtolower($providedKey)) ? $merchant : null;
    }

    private function loadMerchant(int $merchantId): ?object
    {
        $record = null;

        if (database_available()) {
            try {
                $merchant = Merchant::find($merchantId);
                if ($merchant) {
                    $record = $this->toObject($merchant->toArray());
                }
            } catch (Throwable) {
            }
        }

        $local = AccountService::merchantCredentialById($merchantId);
        if ($record === null && is_array($local)) {
            $record = $this->toObject($local);
        } elseif ($record !== null && is_array($local)) {
            foreach ($local as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $record->{$key} = $value;
            }
        }

        if ($record === null) {
            return null;
        }

        $profile = MerchantApiService::credentialProfile($merchantId);
        foreach (['mch_key', 'rsa_public_key', 'rsa_private_key', 'api_sign_mode', 'sign_mode'] as $field) {
            $value = trim((string)($profile[$field] ?? ''));
            if ($value !== '') {
                $record->{$field} = $value;
            }
        }

        $record->id = (int)($record->id ?? $merchantId);
        $record->merchant_id = (int)($record->merchant_id ?? $merchantId);

        return $record;
    }

    private function findChannel(int $merchantId, int $channelId): ?array
    {
        if ($merchantId <= 0 || $channelId <= 0) {
            return null;
        }

        foreach ($this->channelItems($merchantId) as $item) {
            if ((int)($item['id'] ?? 0) === $channelId) {
                return $item;
            }
        }

        return null;
    }

    private function channelItems(int $merchantId): array
    {
        $items = [];

        try {
            $payload = MerchantChannelService::all($merchantId);
            foreach ((array)($payload['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[(int)($item['id'] ?? 0)] = $item;
            }
        } catch (Throwable) {
        }

        foreach ((array)JsonStoreService::load('merchant_channels', []) as $record) {
            if (!is_array($record) || (int)($record['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            foreach ((array)($record['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[(int)($item['id'] ?? 0)] = $item;
            }
        }

        krsort($items);
        return array_values($items);
    }

    private function merchantOrders(int $merchantId): array
    {
        $items = [];
        $seenTradeNos = [];

        foreach ($this->rawMerchantOrders($merchantId) as $order) {
            $tradeNo = trim((string)($order->trade_no ?? ''));
            if ($tradeNo === '' || isset($seenTradeNos[$tradeNo])) {
                continue;
            }

            try {
                $items[] = OrderService::findByTradeNoForRead($tradeNo, [
                    'source' => 'software-compat-merchant-orders',
                ]);
            } catch (Throwable) {
                $items[] = $order;
            }
            $seenTradeNos[$tradeNo] = true;
        }

        usort($items, function (object $left, object $right): int {
            $leftId = (int)($left->id ?? 0);
            $rightId = (int)($right->id ?? 0);
            if ($leftId !== $rightId) {
                return $rightId <=> $leftId;
            }

            return strcmp((string)($right->created_at ?? ''), (string)($left->created_at ?? ''));
        });

        return $items;
    }

    private function rawMerchantOrders(int $merchantId): array
    {
        $orders = [];

        if (database_available()) {
            try {
                foreach (Order::where('merchant_id', $merchantId)->order('id', 'desc')->select()->all() as $order) {
                    $tradeNo = trim((string)($order->trade_no ?? ''));
                    if ($tradeNo === '') {
                        continue;
                    }
                    $orders[$tradeNo] = $order;
                }
            } catch (Throwable) {
            }
        }

        foreach (LocalOrderStore::ordersByMerchant($merchantId) as $order) {
            $tradeNo = trim((string)($order->trade_no ?? ''));
            if ($tradeNo === '') {
                continue;
            }
            $orders[$tradeNo] = $order;
        }

        return array_values($orders);
    }

    private function isPendingOrder(object $order): bool
    {
        if ((int)($order->status ?? -1) !== OrderService::STATUS_PENDING) {
            return false;
        }

        $expireTime = trim((string)($order->expire_time ?? ''));
        if ($expireTime === '') {
            return true;
        }

        $timestamp = strtotime($expireTime);
        return $timestamp === false || $timestamp > time();
    }

    private function orderMatchesMethod(object $order, string $methodCode): bool
    {
        if ($methodCode === '') {
            return true;
        }

        $current = $this->normalizeMethodCode((string)($order->channel_code ?? $order->typename ?? ''));
        return $current === $methodCode;
    }

    private function matchPendingOrder(int $merchantId, array $criteria): array
    {
        $channelId = (int)($criteria['channel_id'] ?? 0);
        $methodCode = $this->normalizeMethodCode((string)($criteria['method_code'] ?? ''));
        $identifier = trim((string)($criteria['identifier'] ?? ''));
        $amount = isset($criteria['amount']) ? (float)$criteria['amount'] : null;

        if ($identifier !== '') {
            foreach ($this->merchantOrders($merchantId) as $order) {
                if (!$this->isPendingOrder($order)) {
                    continue;
                }

                if ($channelId > 0 && (int)($order->merchant_channel_id ?? $order->channel ?? 0) !== $channelId) {
                    continue;
                }

                if ($methodCode !== '' && !$this->orderMatchesMethod($order, $methodCode)) {
                    continue;
                }

                $tradeNo = trim((string)($order->trade_no ?? ''));
                $outTradeNo = trim((string)($order->out_trade_no ?? ''));
                if (strcasecmp($tradeNo, $identifier) !== 0 && strcasecmp($outTradeNo, $identifier) !== 0) {
                    continue;
                }

                if ($amount !== null && !$this->moneyEquals($amount, $order->payable_amount ?? $order->amount ?? 0)) {
                    continue;
                }

                return ['order' => $order];
            }

            return ['order' => null];
        }

        $candidates = [];
        foreach ($this->merchantOrders($merchantId) as $order) {
            if (!$this->isPendingOrder($order)) {
                continue;
            }

            if ($channelId > 0 && (int)($order->merchant_channel_id ?? $order->channel ?? 0) !== $channelId) {
                continue;
            }

            if ($methodCode !== '' && !$this->orderMatchesMethod($order, $methodCode)) {
                continue;
            }

            if ($amount !== null && !$this->moneyEquals($amount, $order->payable_amount ?? $order->amount ?? 0)) {
                continue;
            }

            $candidates[] = $order;
        }

        if ($candidates === []) {
            return ['order' => null];
        }

        if (count($candidates) > 1) {
            return ['order' => null, 'error' => '匹配到多个待支付订单，请上传订单号精确回调'];
        }

        return ['order' => $candidates[0]];
    }

    private function completeOrderFromMonitor(
        Request $request,
        object $order,
        array $channel,
        string $source,
        array $context,
        array $payload
    ): object {
        $trust = [
            'merchant_id' => (int)($order->merchant_id ?? 0),
            'channel_id' => (int)($order->merchant_channel_id ?? $channel['id'] ?? 0),
            'action' => 'notify',
            'scope' => 'notify',
            'source' => 'software-compat',
            'verification' => 'merchant-key',
        ];

        try {
            return CallbackTrustService::beginTrusted($trust, function () use ($order, $channel, $source, $context, $payload, $request) {
                $txid = trim((string)($context['bill_trade_no'] ?? ''));
                if ($txid === '') {
                    $txid = strtoupper(str_replace('-', '_', $source)) . '_' . date('YmdHis') . random_int(1000, 9999);
                }

                $completed = OrderService::completeOrder($order, [
                    'source' => $source,
                    'txid' => $txid,
                    'paid_at' => date('Y-m-d H:i:s'),
                    'confirmations' => 1,
                    'buyer' => trim((string)($context['buyer'] ?? 'software-monitor')),
                    'bill_trade_no' => $txid,
                    'bill_mch_trade_no' => trim((string)($context['bill_mch_trade_no'] ?? '')),
                    'callback_trust' => CallbackTrustService::describeCurrent(),
                ]);

                $this->writeCompatLog('success', $source, $completed, $channel, '订单已完成', $request, [
                    'payload' => $payload,
                    'context' => $context,
                    'trust' => CallbackTrustService::describeCurrent(),
                ]);

                return $completed;
            });
        } catch (Throwable $exception) {
            $this->writeCompatLog('failed', $source, $order, $channel, $exception->getMessage(), $request, [
                'payload' => $payload,
                'context' => $context,
                'trust' => $trust,
            ]);
            throw $exception;
        }
    }

    private function writeCompatLog(
        string $status,
        string $action,
        object $order,
        array $channel,
        string $message,
        Request $request,
        array $context = []
    ): void {
        try {
            PluginNotifyLogService::write([
                'action' => $action,
                'stage' => 'software-compat',
                'trade_no' => (string)($order->trade_no ?? ''),
                'channel_id' => (int)($order->merchant_channel_id ?? $channel['id'] ?? 0),
                'merchant_id' => (int)($order->merchant_id ?? 0),
                'plugin_code' => (string)($channel['plugin_code'] ?? ''),
                'method_code' => (string)($channel['method_code'] ?? $order->channel_code ?? ''),
                'status' => $status,
                'message' => $message,
                'request' => PluginNotifyLogService::requestSnapshot($request),
                'context' => $context,
            ]);
        } catch (Throwable) {
        }
    }

    private function updateChannelHeartbeat(int $merchantId, int $channelId, array $payload, array $channel): void
    {
        $statusCode = $this->normalizeStatusCode($payload['status'] ?? 0);
        $runtime = [
            'last_heartbeat_at' => date('Y-m-d H:i:s'),
            'status' => $statusCode,
            'reported_type' => $this->normalizeMethodCode((string)($payload['type'] ?? $channel['method_code'] ?? '')),
            'reported_pid' => trim((string)($payload['pid'] ?? '')),
            'reported_mode' => trim((string)($payload['mode'] ?? '')),
            'reported_temp_param' => trim((string)($payload['tempParam'] ?? $payload['temp_param'] ?? '')),
        ];

        if (database_available()) {
            try {
                $record = MerchantChannel::where('merchant_id', $merchantId)->where('id', $channelId)->find();
                if ($record) {
                    $config = is_array($record->config ?? null) ? $record->config : [];
                    $config['monitor_runtime'] = $runtime;
                    $record->config = $config;
                    $record->save();
                }
            } catch (Throwable) {
            }
        }

        $records = JsonStoreService::load('merchant_channels', []);
        $changed = false;
        foreach ($records as $recordIndex => $record) {
            if (!is_array($record) || (int)($record['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            foreach ((array)($record['items'] ?? []) as $itemIndex => $item) {
                if (!is_array($item) || (int)($item['id'] ?? 0) !== $channelId) {
                    continue;
                }

                $config = is_array($item['config'] ?? null) ? $item['config'] : [];
                $config['monitor_runtime'] = $runtime;
                $records[$recordIndex]['items'][$itemIndex]['config'] = $config;
                $changed = true;
                break 2;
            }
        }

        if ($changed) {
            JsonStoreService::save('merchant_channels', $records);
        }
    }

    private function packageToMethodCode(string $packageName): string
    {
        return match (trim($packageName)) {
            'com.eg.android.AlipayGphone' => 'alipay',
            'com.tencent.mm' => 'wxpay',
            'com.tencent.mobileqq' => 'qqpay',
            default => '',
        };
    }

    private function extractAmountFromMessage(string $message, string $methodCode = ''): ?float
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        $patterns = match ($this->normalizeMethodCode($methodCode)) {
            'alipay' => [
                '/成功收款\s*([0-9]+(?:\.[0-9]+)?)\s*元/u',
                '/(?:付款金额|收款金额|到账金额)[^0-9￥¥]*[￥¥]?\s*([0-9]+(?:\.[0-9]+)?)/u',
                '/[￥¥]\s*([0-9]+(?:\.[0-9]+)?)/u',
                '/([0-9]+(?:\.[0-9]+)?)\s*元/u',
            ],
            'wxpay' => [
                '/成功收款\s*([0-9]+(?:\.[0-9]+)?)\s*元/u',
                '/(?:赞赏到账|收款金额|到账金额|到账通知)[^0-9￥¥]*[￥¥]?\s*([0-9]+(?:\.[0-9]+)?)/u',
                '/[￥¥]\s*([0-9]+(?:\.[0-9]+)?)/u',
                '/([0-9]+(?:\.[0-9]+)?)\s*元/u',
            ],
            default => [
                '/[￥¥]\s*([0-9]+(?:\.[0-9]+)?)/u',
                '/([0-9]+(?:\.[0-9]+)?)\s*元/u',
            ],
        };

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) !== 1) {
                continue;
            }

            foreach ($matches as $match) {
                $value = str_replace([',', ' '], '', trim((string)$match));
                if ($value !== '' && is_numeric($value)) {
                    return round((float)$value, 2);
                }
            }
        }

        return null;
    }

    private function resolveMerchantId(array $payload, string|int $fallback = ''): int
    {
        foreach (['id', 'merchant_id', 'pid', 'uid'] as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $value = trim((string)$payload[$key]);
            if ($value !== '' && ctype_digit($value)) {
                return (int)$value;
            }
        }

        $value = trim((string)$fallback);
        return $value !== '' && ctype_digit($value) ? (int)$value : 0;
    }

    private function extractReportMerchantId(Request $request): int
    {
        $path = trim($request->path(), '/');
        if (preg_match('#^api/report/(\d+)(?:/.*)?$#', $path, $matches) === 1) {
            return (int)$matches[1];
        }

        return 0;
    }

    private function resolveCredential(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveChannelId(array $payload): int
    {
        foreach (['channel_id', 'account_id', 'channelId'] as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '' && ctype_digit($value)) {
                return (int)$value;
            }
        }

        return 0;
    }

    private function resolveAmount(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = trim((string)$payload[$key]);
            if ($value === '' || !is_numeric($value)) {
                continue;
            }

            return round((float)$value, 2);
        }

        return null;
    }

    private function resolveText(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeMethodCode(string $code): string
    {
        return PaymentMetaService::normalizeMethodCode($code);
    }

    private function normalizeStatusCode(mixed $value): int
    {
        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return 0;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'online', 'success', 'enabled'], true)) {
            return 1;
        }

        return ((int)$normalized) > 0 ? 1 : 0;
    }

    private function moneyEquals(float $left, mixed $right): bool
    {
        return round($left, 2) === round((float)$right, 2);
    }

    private function formatMoney(mixed $value): string
    {
        return number_format((float)$value, 2, '.', '');
    }

    private function legacyResponse(int $code, string $message, array $data = []): Response
    {
        $payload = [
            'code' => $code,
            'msg' => $message,
        ];

        if (array_key_exists('data', $data)) {
            $payload['data'] = $data['data'];
        } elseif ($data !== []) {
            $payload['data'] = $data;
        }

        return json($payload)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function monitorResponse(int $code, string $message, array $data = []): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'redirect' => '',
        ])->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function toObject(array $row): object
    {
        return json_decode((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false)
            ?: (object)[];
    }
}
