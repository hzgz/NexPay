<?php

namespace app\service\payment;

use app\service\system\ConfigService;
use app\service\system\SettingsService;
use Throwable;

class CallbackService
{
    private const STATUS_PENDING = 0;
    private const STATUS_FAILED = 1;
    private const STATUS_SUCCESS = 2;
    private const DEFAULT_TIMEOUT = 10;
    private const DEFAULT_RETRY_DELAY = [60, 120, 300, 600, 1800];

    public static function dispatchPendingCallbacks(int $limit = 20): array
    {
        $callbacks = self::pendingCallbacks($limit);
        $checked = 0;
        $succeeded = 0;
        $failed = 0;
        $deferred = 0;

        foreach ($callbacks as $callback) {
            $result = self::dispatchCallbackSafely($callback);
            $checked += (int)$result['checked'];
            $succeeded += (int)$result['succeeded'];
            $failed += (int)$result['failed'];
            $deferred += (int)$result['deferred'];
        }

        return [
            'checked' => $checked,
            'succeeded' => $succeeded,
            'deferred' => $deferred,
            'failed' => $failed,
        ];
    }

    public static function dispatchPendingCallbacksWithSummary(int $limit = 20): array
    {
        $before = self::summary();
        $result = self::dispatchPendingCallbacks($limit);
        $after = self::summary();

        return array_merge($result, [
            'queue_due_before' => (int)($before['pending_due'] ?? 0),
            'queue_scheduled_before' => (int)($before['pending_scheduled'] ?? 0),
            'queue_exhausted_before' => (int)($before['retry_exhausted'] ?? 0),
            'queue_attention_before' => (int)($before['attention_total'] ?? 0),
            'queue_due_after' => (int)($after['pending_due'] ?? 0),
            'queue_scheduled_after' => (int)($after['pending_scheduled'] ?? 0),
            'queue_exhausted_after' => (int)($after['retry_exhausted'] ?? 0),
            'queue_attention_after' => (int)($after['attention_total'] ?? 0),
            'next_due_time_after' => (string)($after['next_due_time'] ?? ''),
        ]);
    }

    public static function logs(int $merchantId = 0, int $limit = 100): array
    {
        $items = [];
        $nowTs = time();

        foreach (self::callbackRows($limit, $merchantId) as $callback) {
            $order = self::findOrder((int)$callback->order_id);
            $notifyPayload = $order && is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
            $callbackPayload = is_array($notifyPayload['callback'] ?? null) ? $notifyPayload['callback'] : [];
            $status = (int)($callback->status ?? self::STATUS_PENDING);
            $response = trim((string)($callback->last_error ?? ''));
            $nextTime = (string)($callback->next_time ?? '');
            $nextTs = self::timestamp($nextTime);
            $runtimeException = (bool)($callbackPayload['runtime_exception'] ?? false);
            $manualRetry = (bool)($callbackPayload['manual_retry'] ?? false);
            $dueNow = $status === self::STATUS_PENDING && $nextTs > 0 && $nextTs <= $nowTs;
            if ($response === '') {
                $response = trim((string)($callbackPayload['response'] ?? ''));
            }
            $state = self::describeCallbackState(
                $status,
                $callbackPayload,
                (int)($callback->retry_count ?? 0),
                (int)($callback->max_retry ?? 0)
            );

            $items[] = [
                'id' => (int)($callback->id ?? 0),
                'merchant_id' => (int)($callback->merchant_id ?? 0),
                'order_id' => (int)($callback->order_id ?? 0),
                'trade_no' => (string)($order->trade_no ?? ''),
                'out_trade_no' => (string)($order->out_trade_no ?? ''),
                'notify_url' => (string)($callback->notify_url ?? ''),
                'status' => (string)($state['status'] ?? self::statusKey($status)),
                'result' => (string)($state['label'] ?? self::statusLabel($status, (int)($callback->retry_count ?? 0))),
                'result_key' => (string)($state['key'] ?? self::statusKey($status)),
                'result_theme' => (string)($state['theme'] ?? 'warning'),
                'result_hint' => (string)($state['hint'] ?? ''),
                'response' => $response,
                'retry_count' => (int)($callback->retry_count ?? 0),
                'max_retry' => (int)($callback->max_retry ?? 0),
                'next_time' => $nextTime,
                'created_at' => (string)($callback->created_at ?? ''),
                'updated_at' => (string)($callback->updated_at ?? ''),
                'status_code' => $status,
                'can_retry' => $status !== self::STATUS_SUCCESS,
                'retry_exhausted' => (bool)($state['retry_exhausted'] ?? false),
                'runtime_exception' => $runtimeException,
                'manual_retry' => $manualRetry,
                'rejected' => (bool)($callbackPayload['rejected'] ?? false),
                'canceled' => (bool)($callbackPayload['canceled'] ?? false),
                'last_notified_at' => (string)($callbackPayload['notified_at'] ?? ''),
                'due_now' => $dueNow,
                'overdue_minutes' => $dueNow ? (int)floor(max(0, $nowTs - $nextTs) / 60) : 0,
                'next_due_in_seconds' => $status === self::STATUS_PENDING && !$dueNow && $nextTs > 0 ? max(0, $nextTs - $nowTs) : 0,
                'needs_attention' => $status === self::STATUS_FAILED || $runtimeException || $dueNow,
            ];
        }

        return $items;
    }

    public static function summary(int $merchantId = 0): array
    {
        $summary = [
            'total' => 0,
            'pending_total' => 0,
            'pending_due' => 0,
            'pending_scheduled' => 0,
            'success_total' => 0,
            'failed_total' => 0,
            'retry_exhausted' => 0,
            'runtime_exception_total' => 0,
            'manual_retry_total' => 0,
            'rejected_total' => 0,
            'canceled_total' => 0,
            'retrying_total' => 0,
            'attention_total' => 0,
            'next_due_time' => '',
            'oldest_due_time' => '',
            'oldest_due_age_minutes' => 0,
            'last_success_time' => '',
            'last_failed_time' => '',
        ];

        $nowTs = time();
        $nextDueTs = 0;
        $oldestDueTs = 0;
        $lastSuccessTs = 0;
        $lastFailedTs = 0;

        foreach (self::callbackRows(0, $merchantId) as $callback) {
            $summary['total']++;

            $status = (int)($callback->status ?? self::STATUS_PENDING);
            $retryCount = (int)($callback->retry_count ?? 0);
            $maxRetry = max(1, (int)($callback->max_retry ?? self::configuredMaxRetry()));
            $nextTime = (string)($callback->next_time ?? '');
            $nextTs = self::timestamp($nextTime);
            $updatedAt = (string)($callback->updated_at ?? $callback->created_at ?? '');
            $updatedTs = self::timestamp($updatedAt);

            $order = self::findOrder((int)($callback->order_id ?? 0));
            $notifyPayload = $order && is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
            $callbackPayload = is_array($notifyPayload['callback'] ?? null) ? $notifyPayload['callback'] : [];
            $runtimeException = (bool)($callbackPayload['runtime_exception'] ?? false);
            $manualRetry = (bool)($callbackPayload['manual_retry'] ?? false);
            $state = self::describeCallbackState($status, $callbackPayload, $retryCount, $maxRetry);

            if ($runtimeException) {
                $summary['runtime_exception_total']++;
            }

            if ($manualRetry) {
                $summary['manual_retry_total']++;
            }

            if (!empty($callbackPayload['rejected'])) {
                $summary['rejected_total']++;
            }

            if (!empty($callbackPayload['canceled'])) {
                $summary['canceled_total']++;
            }

            $needsAttention = false;

            if ($status === self::STATUS_SUCCESS) {
                $summary['success_total']++;
                if ($updatedTs > $lastSuccessTs) {
                    $lastSuccessTs = $updatedTs;
                }
            } elseif ($status === self::STATUS_FAILED) {
                $summary['failed_total']++;
                $needsAttention = true;

                if (!empty($state['retry_exhausted'])) {
                    $summary['retry_exhausted']++;
                }

                if ($updatedTs > $lastFailedTs) {
                    $lastFailedTs = $updatedTs;
                }
            } else {
                $summary['pending_total']++;
                if (in_array((string)($state['key'] ?? ''), ['retrying', 'manual_retry', 'runtime_exception_retry'], true)) {
                    $summary['retrying_total']++;
                }

                if ($nextTs > 0 && $nextTs <= $nowTs) {
                    $summary['pending_due']++;
                    $needsAttention = true;

                    if ($oldestDueTs === 0 || $nextTs < $oldestDueTs) {
                        $oldestDueTs = $nextTs;
                    }
                } else {
                    $summary['pending_scheduled']++;
                    if ($nextTs > 0 && ($nextDueTs === 0 || $nextTs < $nextDueTs)) {
                        $nextDueTs = $nextTs;
                    }
                }
            }

            if ($runtimeException) {
                $needsAttention = true;
            }

            if ($needsAttention) {
                $summary['attention_total']++;
            }
        }

        if ($oldestDueTs > 0) {
            $summary['oldest_due_time'] = date('Y-m-d H:i:s', $oldestDueTs);
            $summary['oldest_due_age_minutes'] = (int)floor(max(0, $nowTs - $oldestDueTs) / 60);
        }

        if ($nextDueTs > 0) {
            $summary['next_due_time'] = date('Y-m-d H:i:s', $nextDueTs);
        } elseif ($oldestDueTs > 0) {
            $summary['next_due_time'] = date('Y-m-d H:i:s', $oldestDueTs);
        }

        if ($lastSuccessTs > 0) {
            $summary['last_success_time'] = date('Y-m-d H:i:s', $lastSuccessTs);
        }

        if ($lastFailedTs > 0) {
            $summary['last_failed_time'] = date('Y-m-d H:i:s', $lastFailedTs);
        }

        return $summary;
    }

    public static function retryNow(int $callbackId): array
    {
        $callback = self::findCallback($callbackId);
        if (!$callback) {
            return [
                'checked' => 0,
                'succeeded' => 0,
                'deferred' => 0,
                'failed' => 1,
                'message' => '回调记录不存在',
            ];
        }

        if ((int)($callback->status ?? self::STATUS_PENDING) === self::STATUS_SUCCESS) {
            return [
                'checked' => 0,
                'succeeded' => 0,
                'deferred' => 0,
                'failed' => 0,
                'message' => '回调已成功',
            ];
        }

        return self::dispatchCallbackSafely($callback, true);
    }

    public static function resendNow(int $callbackId): array
    {
        $callback = self::findCallback($callbackId);
        if (!$callback) {
            return [
                'checked' => 0,
                'succeeded' => 0,
                'deferred' => 0,
                'failed' => 1,
                'message' => '回调记录不存在',
            ];
        }

        return self::dispatchCallbackSafely($callback, true);
    }

    public static function shouldQueueOrder(object $order, bool $force = false): bool
    {
        $notifyUrl = trim((string)($order->notify_url ?? ''));
        if ($notifyUrl === '') {
            return false;
        }

        if ($force) {
            return true;
        }

        if (self::isInternalSuccessCallback($notifyUrl)) {
            return true;
        }

        return LocalOrderStore::isBusinessOrder($order);
    }

    public static function enqueueOrder(object $order, object $merchant, bool $force = false): void
    {
        if (!$order->notify_url) {
            return;
        }

        if (!self::shouldQueueOrder($order, $force)) {
            return;
        }

        $internalSuccessCallback = self::isInternalSuccessCallback((string)($order->notify_url ?? ''));

        $payload = self::buildPayload($order, $merchant);
        $payloadHash = self::payloadHash($payload);
        $existing = self::findCallbackByOrderId((int)($order->id ?? 0));
        if ($existing) {
            $changes = [
                'merchant_id' => (int)($merchant->id ?? $existing->merchant_id ?? 0),
                'notify_url' => (string)($order->notify_url ?? $existing->notify_url ?? ''),
                'payload' => $payload,
                'payload_hash' => $payloadHash,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $existingStatus = (int)($existing->status ?? self::STATUS_PENDING);
            $orderCallbackStatus = (int)($order->callback_status ?? self::STATUS_PENDING);
            if ($force || ($existingStatus !== self::STATUS_SUCCESS && $orderCallbackStatus !== self::STATUS_SUCCESS)) {
                $changes['status'] = self::STATUS_PENDING;
                $changes['last_error'] = '';
                $changes['next_time'] = date('Y-m-d H:i:s');
            }

            self::updateCallback((int)($existing->id ?? 0), $changes);

            if ($internalSuccessCallback) {
                $callback = self::findCallbackByOrderId((int)($order->id ?? 0));
                if ($callback !== null) {
                    self::dispatchCallbackSafely($callback);
                }
            }
            return;
        }

        self::createCallback([
            'order_id' => (int)$order->id,
            'merchant_id' => (int)$merchant->id,
            'notify_url' => $order->notify_url,
            'payload' => $payload,
            'payload_hash' => $payloadHash,
            'retry_count' => 0,
            'max_retry' => self::configuredMaxRetry(),
            'status' => self::STATUS_PENDING,
            'next_time' => date('Y-m-d H:i:s'),
            'last_error' => '',
        ]);
        $updatedOrder = self::findOrder((int)($order->id ?? 0)) ?? $order;
        LocalOrderEventStore::recordCallbackEnqueued($updatedOrder, [
            'retry_count' => 0,
            'max_retry' => self::configuredMaxRetry(),
            'response' => '',
            'manual_retry' => false,
            'notify_url' => (string)($order->notify_url ?? ''),
            'runtime_exception' => false,
            'status_text' => 'queued',
        ]);

        if ($internalSuccessCallback) {
            $callback = self::findCallbackByOrderId((int)($order->id ?? 0));
            if ($callback !== null) {
                self::dispatchCallbackSafely($callback);
            }
        }
    }

    public static function syncOrderPayload(object $order, object $merchant): ?object
    {
        $callback = self::findCallbackByOrderId((int)($order->id ?? 0));
        if ($callback === null) {
            return null;
        }

        $payload = self::buildPayload($order, $merchant);
        $payloadHash = self::payloadHash($payload);
        self::updateCallback((int)($callback->id ?? 0), [
            'merchant_id' => (int)($merchant->id ?? $callback->merchant_id ?? 0),
            'notify_url' => (string)($order->notify_url ?? $callback->notify_url ?? ''),
            'payload' => $payload,
            'payload_hash' => $payloadHash,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return self::findCallbackByOrderId((int)($order->id ?? 0));
    }

    public static function cancelOrderCallbacks(object $order, string $message = '订单已删除，已停止回调'): array
    {
        $orderId = (int)($order->id ?? 0);
        if ($orderId <= 0) {
            return ['updated' => 0, 'message' => self::truncate($message)];
        }

        $message = self::truncate(trim($message) !== '' ? $message : '订单已删除，已停止回调');
        $updated = 0;
        $payload = [
            'status' => self::STATUS_FAILED,
            'last_error' => $message,
            'next_time' => date('Y-m-d H:i:s', time() + 86400),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (self::canUseDatabase()) {
            try {
                $callbacks = \app\model\CallbackQueue::where('order_id', $orderId)
                    ->where('status', '<>', self::STATUS_SUCCESS)
                    ->select()
                    ->all();
                foreach ($callbacks as $callback) {
                    self::updateCallback((int)($callback->id ?? 0), $payload);
                    $updated++;
                }
            } catch (Throwable) {
                $updated = 0;
            }
        } elseif (method_exists(LocalOrderStore::class, 'callbacksByOrderId')) {
            foreach (LocalOrderStore::callbacksByOrderId($orderId) as $callback) {
                if ((int)($callback->status ?? self::STATUS_PENDING) === self::STATUS_SUCCESS) {
                    continue;
                }

                self::updateCallback((int)($callback->id ?? 0), $payload);
                $updated++;
            }
        }

        if ($updated > 0 || trim((string)($order->notify_url ?? '')) !== '') {
            $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
            $notifyPayload['callback'] = [
                'status' => 'failed',
                'response' => $message,
                'notified_at' => date('Y-m-d H:i:s'),
                'manual_retry' => false,
                'runtime_exception' => false,
                'canceled' => true,
                'reason_key' => 'canceled',
            ];
            self::saveOrder($order, [
                'callback_status' => 3,
                'notify_payload' => $notifyPayload,
            ]);

            $updatedOrder = self::findOrder($orderId);
            if ($updatedOrder) {
                LocalOrderEventStore::recordCallbackFailed($updatedOrder, [
                    'retry_count' => (int)($updatedOrder->callback_count ?? 0),
                    'max_retry' => self::configuredMaxRetry(),
                    'response' => $message,
                    'manual_retry' => false,
                    'notify_url' => (string)($updatedOrder->notify_url ?? ''),
                    'runtime_exception' => false,
                    'canceled' => true,
                    'status_text' => 'canceled',
                ]);
            }
        }

        return ['updated' => $updated, 'message' => $message];
    }

    public static function findCallbackByOrderId(int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        if (self::canUseDatabase()) {
            try {
                return \app\model\CallbackQueue::where('order_id', $orderId)->order('id', 'desc')->find();
            } catch (Throwable) {
                return null;
            }
        }

        if (method_exists(LocalOrderStore::class, 'findCallbackByOrderId')) {
            return LocalOrderStore::findCallbackByOrderId($orderId);
        }

        return null;
    }

    public static function findActiveCallbackByOrderId(int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        if (self::canUseDatabase()) {
            try {
                return \app\model\CallbackQueue::where('order_id', $orderId)
                    ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SUCCESS])
                    ->order('id', 'desc')
                    ->find();
            } catch (Throwable) {
                return null;
            }
        }

        return method_exists(LocalOrderStore::class, 'findActiveCallbackByOrderId')
            ? LocalOrderStore::findActiveCallbackByOrderId($orderId)
            : null;
    }

    private static function buildPayload(object $order, object $merchant): array
    {
        $protocol = OrderService::protocolForOrder($order);
        if ($protocol === 'v1') {
            $payload = [
                'pid' => (string)($merchant->id ?? $merchant->merchant_id ?? ''),
                'trade_no' => $order->trade_no,
                'out_trade_no' => $order->out_trade_no,
                'type' => $order->channel_code,
                'name' => $order->subject,
                'money' => self::formatMoney($order->amount),
                'trade_status' => 'TRADE_SUCCESS',
                'param' => $order->param,
            ];
            $payload['sign'] = SignService::md5Sign($payload, (string)$merchant->mch_key);
            $payload['sign_type'] = 'MD5';
            return $payload;
        }

        $payload = [
            'pid' => (string)($merchant->id ?? $merchant->merchant_id ?? ''),
            'trade_no' => $order->trade_no,
            'out_trade_no' => $order->out_trade_no,
            'api_trade_no' => $order->txid ?: $order->trade_no,
            'type' => $order->channel_code,
            'trade_status' => 'TRADE_SUCCESS',
            'addtime' => (string)$order->created_at,
            'endtime' => (string)$order->pay_time,
            'name' => $order->subject,
            'money' => self::formatMoney($order->amount),
            'param' => $order->param,
            'buyer' => '',
            'clientip' => $order->client_ip,
            'timestamp' => (string)time(),
            'sign_type' => 'RSA',
        ];
        $payload['sign'] = SignService::rsaSign($payload, ConfigService::platformPrivateKey());
        return $payload;
    }

    private static function deliver(string $notifyUrl, array $payload): array
    {
        if (self::isInternalSuccessCallback($notifyUrl)) {
            return [true, '回调成功'];
        }

        $query = http_build_query($payload);
        $url = $notifyUrl . (str_contains($notifyUrl, '?') ? '&' : '?') . $query;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::configuredTimeoutSeconds(),
                'ignore_errors' => true,
                'header' => "User-Agent: NexPay Callback/1.0\r\n",
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return [false, '请求失败'];
        }

        $body = trim((string)$result);
        if ($body !== '' && strcasecmp($body, 'success') === 0) {
            return [true, '回调成功'];
        }

        return [false, $body !== '' ? self::truncate($body) : '空响应'];
    }

    private static function isInternalSuccessCallback(string $notifyUrl): bool
    {
        $notifyUrl = trim($notifyUrl);
        if ($notifyUrl === '') {
            return false;
        }

        $gatewayBase = rtrim((string)ConfigService::gatewayBaseUrl(), '/');
        if ($gatewayBase !== '' && strcasecmp($notifyUrl, $gatewayBase . '/callback/success') === 0) {
            return true;
        }

        $path = (string)(parse_url($notifyUrl, PHP_URL_PATH) ?: '');
        if ($path === '/callback/success') {
            $host = strtolower((string)(parse_url($notifyUrl, PHP_URL_HOST) ?: ''));
            $baseHost = strtolower((string)(parse_url($gatewayBase, PHP_URL_HOST) ?: ''));
            if ($host !== '' && $baseHost !== '' && $host === $baseHost) {
                return true;
            }
        }

        return false;
    }

    private static function dispatchCallback(object $callback, bool $manual = false): array
    {
        $order = self::findOrder((int)$callback->order_id);
        if (!$order) {
            self::updateCallback((int)$callback->id, [
                'retry_count' => (int)$callback->retry_count + 1,
                'status' => self::STATUS_FAILED,
                'last_error' => '订单不存在',
                'next_time' => date('Y-m-d H:i:s', time() + 3600),
            ]);

            return self::dispatchResult(1, 0, 0, 1, '订单不存在');
        }

        if (trim((string)($order->deleted_at ?? '')) !== '') {
            return self::markCallbackRejected($callback, $order, '订单已删除');
        }

        if ((int)($order->status ?? OrderService::STATUS_PENDING) !== OrderService::STATUS_SUCCESS) {
            return self::markCallbackRejected($callback, $order, '订单未支付');
        }

        if ((int)($order->callback_status ?? 0) === self::STATUS_SUCCESS) {
            self::updateCallback((int)($callback->id ?? 0), [
                'status' => self::STATUS_SUCCESS,
                'last_error' => '',
                'next_time' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return self::dispatchResult(1, 1, 0, 0, '回调已成功');
        }

        $merchant = OrderService::gatewayMerchantById((int)($callback->merchant_id ?? $order->merchant_id ?? 0));
        if (!$merchant) {
            return self::markCallbackRejected($callback, $order, '商户不存在');
        }

        $latestPayload = self::buildPayload($order, $merchant);
        $latestPayloadHash = self::payloadHash($latestPayload);
        $storedPayloadHash = trim((string)($callback->payload_hash ?? ''));
        $storedPayload = is_array($callback->payload ?? null) ? $callback->payload : [];
        if ($storedPayloadHash === '' || $storedPayloadHash !== $latestPayloadHash || $storedPayload !== $latestPayload) {
            self::updateCallback((int)($callback->id ?? 0), [
                'payload' => $latestPayload,
                'payload_hash' => $latestPayloadHash,
            ]);
            $callback->payload = $latestPayload;
            $callback->payload_hash = $latestPayloadHash;
        }

        [$ok, $message] = self::deliver($callback->notify_url, is_array($callback->payload) ? $callback->payload : []);
        $retryCount = (int)$callback->retry_count + 1;
        $maxRetry = max(1, (int)$callback->max_retry);
        if ($manual) {
            $maxRetry = max($maxRetry, $retryCount);
        }
        $orderNotify = is_array($order->notify_payload) ? $order->notify_payload : [];
        $orderNotify['callback'] = [
            'status' => $ok ? 'success' : 'retrying',
            'response' => $message,
            'notified_at' => date('Y-m-d H:i:s'),
            'manual_retry' => $manual,
            'runtime_exception' => false,
            'rejected' => false,
            'canceled' => false,
            'reason_key' => $ok ? 'success' : ($manual ? 'manual_retry' : 'retrying'),
        ];

        if ($ok) {
            self::updateCallback((int)$callback->id, [
                'retry_count' => $retryCount,
                'max_retry' => $maxRetry,
                'status' => self::STATUS_SUCCESS,
                'last_error' => '',
                'next_time' => date('Y-m-d H:i:s'),
            ]);
            self::saveOrder($order, [
                'callback_status' => 2,
                'callback_count' => $retryCount,
                'notify_payload' => $orderNotify,
            ]);
            $updatedOrder = self::findOrder((int)($callback->order_id ?? 0));
            if ($updatedOrder) {
                LocalOrderEventStore::recordCallbackSuccess($updatedOrder, [
                    'retry_count' => $retryCount,
                    'max_retry' => $maxRetry,
                    'response' => $message,
                    'manual_retry' => $manual,
                    'notify_url' => (string)($callback->notify_url ?? ''),
                    'reason_key' => 'success',
                ]);
            }

            return self::dispatchResult(1, 1, 0, 0, '回调成功');
        }

        if ($retryCount >= $maxRetry) {
            self::updateCallback((int)$callback->id, [
                'retry_count' => $retryCount,
                'max_retry' => $maxRetry,
                'status' => self::STATUS_FAILED,
                'last_error' => $message,
                'next_time' => date('Y-m-d H:i:s', time() + 3600),
            ]);
            $orderNotify['callback']['status'] = 'failed';
            $orderNotify['callback']['reason_key'] = 'retry_exhausted';
            self::saveOrder($order, [
                'callback_status' => 3,
                'callback_count' => $retryCount,
                'notify_payload' => $orderNotify,
            ]);
            $updatedOrder = self::findOrder((int)($callback->order_id ?? 0));
            if ($updatedOrder) {
                LocalOrderEventStore::recordCallbackFailed($updatedOrder, [
                    'retry_count' => $retryCount,
                    'max_retry' => $maxRetry,
                    'response' => $message,
                    'manual_retry' => $manual,
                    'notify_url' => (string)($callback->notify_url ?? ''),
                    'runtime_exception' => false,
                    'reason_key' => 'retry_exhausted',
                    'status_text' => 'retry_exhausted',
                ]);
            }

            return self::dispatchResult(1, 0, 0, 1, $message);
        }

        self::updateCallback((int)$callback->id, [
            'retry_count' => $retryCount,
            'max_retry' => $maxRetry,
            'status' => self::STATUS_PENDING,
            'last_error' => $message,
            'next_time' => date('Y-m-d H:i:s', time() + self::retryDelay($retryCount)),
        ]);
        self::saveOrder($order, [
            'callback_status' => 1,
            'callback_count' => $retryCount,
            'notify_payload' => $orderNotify,
        ]);
        $updatedOrder = self::findOrder((int)($callback->order_id ?? 0));
        if ($updatedOrder) {
            LocalOrderEventStore::recordCallbackRetry($updatedOrder, [
                'retry_count' => $retryCount,
                'max_retry' => $maxRetry,
                'response' => $message,
                'manual_retry' => $manual,
                'notify_url' => (string)($callback->notify_url ?? ''),
                'runtime_exception' => false,
                'reason_key' => $manual ? 'manual_retry' : 'retrying',
                'status_text' => $manual ? 'manual_retry' : 'retrying',
            ]);
        }

        return self::dispatchResult(1, 0, 1, 0, $message);
    }

    private static function dispatchCallbackSafely(object $callback, bool $manual = false): array
    {
        try {
            return self::dispatchCallback($callback, $manual);
        } catch (Throwable $exception) {
            return self::handleDispatchException($callback, $exception, $manual);
        }
    }

    private static function dispatchResult(int $checked, int $succeeded, int $deferred, int $failed, string $message): array
    {
        return [
            'checked' => $checked,
            'succeeded' => $succeeded,
            'deferred' => $deferred,
            'failed' => $failed,
            'message' => $message,
        ];
    }

    private static function handleDispatchException(object $callback, Throwable $exception, bool $manual = false): array
    {
        $retryCount = (int)($callback->retry_count ?? 0) + 1;
        $maxRetry = max(1, (int)($callback->max_retry ?? self::configuredMaxRetry()));
        if ($manual) {
            $maxRetry = max($maxRetry, $retryCount);
        }

        $message = self::truncate(trim($exception->getMessage()) !== '' ? $exception->getMessage() : '回调派发异常');
        $status = $retryCount >= $maxRetry ? self::STATUS_FAILED : self::STATUS_PENDING;

        self::updateCallback((int)($callback->id ?? 0), [
            'retry_count' => $retryCount,
            'max_retry' => $maxRetry,
            'status' => $status,
            'last_error' => $message,
            'next_time' => date('Y-m-d H:i:s', time() + ($status === self::STATUS_FAILED ? 3600 : self::retryDelay($retryCount))),
        ]);

        $order = self::findOrder((int)($callback->order_id ?? 0));
        if ($order) {
            $orderNotify = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
            $orderNotify['callback'] = [
                'status' => $status === self::STATUS_FAILED ? 'failed' : 'retrying',
                'response' => $message,
                'notified_at' => date('Y-m-d H:i:s'),
                'manual_retry' => $manual,
                'runtime_exception' => true,
                'rejected' => false,
                'canceled' => false,
                'reason_key' => $status === self::STATUS_FAILED ? 'runtime_exception_exhausted' : 'runtime_exception_retry',
            ];

            self::saveOrder($order, [
                'callback_status' => $status === self::STATUS_FAILED ? 3 : 1,
                'callback_count' => $retryCount,
                'notify_payload' => $orderNotify,
            ]);
            $updatedOrder = self::findOrder((int)($callback->order_id ?? 0));
            if ($updatedOrder) {
                if ($status === self::STATUS_FAILED) {
                    LocalOrderEventStore::recordCallbackFailed($updatedOrder, [
                        'retry_count' => $retryCount,
                        'max_retry' => $maxRetry,
                        'response' => $message,
                        'manual_retry' => $manual,
                        'notify_url' => (string)($callback->notify_url ?? ''),
                        'runtime_exception' => true,
                        'reason_key' => 'runtime_exception_exhausted',
                        'status_text' => 'runtime_exception_exhausted',
                    ]);
                } else {
                    LocalOrderEventStore::recordCallbackRetry($updatedOrder, [
                        'retry_count' => $retryCount,
                        'max_retry' => $maxRetry,
                        'response' => $message,
                        'manual_retry' => $manual,
                        'notify_url' => (string)($callback->notify_url ?? ''),
                        'runtime_exception' => true,
                        'reason_key' => 'runtime_exception_retry',
                        'status_text' => 'runtime_exception_retry',
                    ]);
                }
            }
        }

        return self::dispatchResult(
            1,
            0,
            $status === self::STATUS_PENDING ? 1 : 0,
            $status === self::STATUS_FAILED ? 1 : 0,
            $message
        );
    }

    private static function retryDelay(int $retryCount): int
    {
        $schedule = self::configuredRetrySchedule();
        return $schedule[min(max($retryCount - 1, 0), count($schedule) - 1)];
    }

    private static function formatMoney(mixed $amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }

    private static function truncate(string $value, int $maxLength = 120): string
    {
        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) . '...' : $value;
    }

    private static function pendingCallbacks(int $limit): array
    {
        if (self::canUseDatabase()) {
            return \app\model\CallbackQueue::where('status', self::STATUS_PENDING)
                ->where('next_time', '<=', date('Y-m-d H:i:s'))
                ->order('id', 'asc')
                ->limit($limit)
                ->select()
                ->all();
        }

        if (method_exists(LocalOrderStore::class, 'pendingCallbacks')) {
            return LocalOrderStore::pendingCallbacks($limit);
        }

        return [];
    }

    private static function callbackRows(int $limit, int $merchantId = 0): array
    {
        $limit = max(0, $limit);

        if (self::canUseDatabase()) {
            $query = \app\model\CallbackQueue::order('id', 'desc');
            if ($merchantId > 0) {
                $query->where('merchant_id', $merchantId);
            }

            if ($limit > 0) {
                $query->limit($limit);
            }

            return $query->select()->all();
        }

        if (method_exists(LocalOrderStore::class, 'callbacks')) {
            return LocalOrderStore::callbacks($limit, $merchantId);
        }

        return [];
    }

    private static function findCallback(int $callbackId): ?object
    {
        if ($callbackId <= 0) {
            return null;
        }

        if (self::canUseDatabase()) {
            return \app\model\CallbackQueue::find($callbackId);
        }

        foreach (LocalOrderStore::callbacks(500) as $callback) {
            if ((int)($callback->id ?? 0) === $callbackId) {
                return $callback;
            }
        }

        return null;
    }

    private static function statusLabel(int $status, int $retryCount = 0): string
    {
        return match ($status) {
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
            default => $retryCount > 0 ? '重试中' : '待回调',
        };
    }

    private static function statusKey(int $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESS => 'success',
            self::STATUS_FAILED => 'failed',
            default => 'pending',
        };
    }

    /**
     * @param array<string, mixed> $callbackPayload
     * @return array{status:string,key:string,label:string,theme:string,hint:string,retry_exhausted:bool}
     */
    public static function describeCallbackState(int $status, array $callbackPayload = [], int $retryCount = 0, int $maxRetry = 0): array
    {
        $retryExhausted = $status === self::STATUS_FAILED && $maxRetry > 0 && $retryCount >= $maxRetry;
        $runtimeException = !empty($callbackPayload['runtime_exception']);
        $manualRetry = !empty($callbackPayload['manual_retry']);
        $rejected = !empty($callbackPayload['rejected']);
        $canceled = !empty($callbackPayload['canceled']);

        if ($status === self::STATUS_SUCCESS) {
            return [
                'status' => 'success',
                'key' => 'success',
                'label' => '回调成功',
                'theme' => 'success',
                'hint' => '',
                'retry_exhausted' => false,
            ];
        }

        if ($status === self::STATUS_FAILED) {
            if ($canceled) {
                return [
                    'status' => 'failed',
                    'key' => 'canceled',
                    'label' => '已取消',
                    'theme' => 'muted',
                    'hint' => '订单已删除，回调已停止',
                    'retry_exhausted' => false,
                ];
            }

            if ($rejected) {
                return [
                    'status' => 'failed',
                    'key' => 'rejected',
                    'label' => '已拒绝',
                    'theme' => 'danger',
                    'hint' => '当前订单不满足回调条件',
                    'retry_exhausted' => false,
                ];
            }

            if ($runtimeException && $retryExhausted) {
                return [
                    'status' => 'failed',
                    'key' => 'runtime_exception_exhausted',
                    'label' => '异常耗尽',
                    'theme' => 'danger',
                    'hint' => '回调执行异常且重试次数已耗尽',
                    'retry_exhausted' => true,
                ];
            }

            if ($retryExhausted) {
                return [
                    'status' => 'failed',
                    'key' => 'retry_exhausted',
                    'label' => '重试耗尽',
                    'theme' => 'danger',
                    'hint' => '回调多次失败，已停止自动重试',
                    'retry_exhausted' => true,
                ];
            }

            if ($runtimeException) {
                return [
                    'status' => 'failed',
                    'key' => 'runtime_exception',
                    'label' => '运行异常',
                    'theme' => 'danger',
                    'hint' => '回调执行时发生运行异常',
                    'retry_exhausted' => false,
                ];
            }

            return [
                'status' => 'failed',
                'key' => 'failed',
                'label' => '回调失败',
                'theme' => 'danger',
                'hint' => '',
                'retry_exhausted' => false,
            ];
        }

        if ($runtimeException) {
            return [
                'status' => 'pending',
                'key' => 'runtime_exception_retry',
                'label' => '异常重试中',
                'theme' => 'warning',
                'hint' => '执行异常，等待下次重试',
                'retry_exhausted' => false,
            ];
        }

        if ($manualRetry && $retryCount > 0) {
            return [
                'status' => 'pending',
                'key' => 'manual_retry',
                'label' => '手动重试',
                'theme' => 'warning',
                'hint' => '已由人工触发重试',
                'retry_exhausted' => false,
            ];
        }

        if ($retryCount > 0) {
            return [
                'status' => 'pending',
                'key' => 'retrying',
                'label' => '重试中',
                'theme' => 'warning',
                'hint' => '等待下次自动重试',
                'retry_exhausted' => false,
            ];
        }

        return [
            'status' => 'pending',
            'key' => 'queued',
            'label' => '待回调',
            'theme' => 'muted',
            'hint' => '',
            'retry_exhausted' => false,
        ];
    }

    private static function configuredMaxRetry(): int
    {
        $settings = SettingsService::all(false);
        $api = is_array($settings['api'] ?? null) ? $settings['api'] : [];
        $value = (int)trim((string)($api['notify_retry'] ?? '5'));

        return max(1, min(20, $value > 0 ? $value : 5));
    }

    private static function configuredTimeoutSeconds(): int
    {
        $settings = SettingsService::all(false);
        $api = is_array($settings['api'] ?? null) ? $settings['api'] : [];
        $value = (int)trim((string)($api['notify_timeout'] ?? (string)self::DEFAULT_TIMEOUT));

        return max(3, min(30, $value > 0 ? $value : self::DEFAULT_TIMEOUT));
    }

    private static function configuredRetrySchedule(): array
    {
        $settings = SettingsService::all(false);
        $api = is_array($settings['api'] ?? null) ? $settings['api'] : [];
        $raw = trim((string)($api['notify_retry_schedule'] ?? ''));
        if ($raw === '') {
            return self::DEFAULT_RETRY_DELAY;
        }

        $items = preg_split('/[\s,|]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $schedule = [];
        foreach ($items as $item) {
            $seconds = (int)trim((string)$item);
            if ($seconds > 0) {
                $schedule[] = max(10, min(86400, $seconds));
            }
        }

        return $schedule !== [] ? array_values($schedule) : self::DEFAULT_RETRY_DELAY;
    }

    private static function createCallback(array $payload): void
    {
        if (self::canUseDatabase()) {
            $model = new \app\model\CallbackQueue();
            $model->save($payload);
            return;
        }

        if (method_exists(LocalOrderStore::class, 'createCallback')) {
            LocalOrderStore::createCallback($payload);
        }
    }

    private static function updateCallback(int $id, array $payload): void
    {
        if (self::canUseDatabase()) {
            \app\model\CallbackQueue::where('id', $id)->update($payload);
            return;
        }

        if (method_exists(LocalOrderStore::class, 'updateCallback')) {
            LocalOrderStore::updateCallback($id, $payload);
        }
    }

    private static function markCallbackRejected(object $callback, object $order, string $message): array
    {
        $message = self::truncate($message);
        self::updateCallback((int)($callback->id ?? 0), [
            'status' => self::STATUS_FAILED,
            'last_error' => $message,
            'next_time' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $notifyPayload['callback'] = [
            'status' => 'failed',
            'response' => $message,
            'notified_at' => date('Y-m-d H:i:s'),
            'manual_retry' => false,
            'runtime_exception' => false,
            'rejected' => true,
            'canceled' => false,
            'reason_key' => 'rejected',
        ];
        self::saveOrder($order, [
            'callback_status' => 3,
            'notify_payload' => $notifyPayload,
        ]);

        $updatedOrder = self::findOrder((int)($callback->order_id ?? 0));
        if ($updatedOrder) {
            LocalOrderEventStore::recordCallbackFailed($updatedOrder, [
                'retry_count' => (int)($callback->retry_count ?? 0),
                'max_retry' => (int)($callback->max_retry ?? self::configuredMaxRetry()),
                'response' => $message,
                'manual_retry' => false,
                'notify_url' => (string)($callback->notify_url ?? ''),
                'runtime_exception' => false,
                'rejected' => true,
                'reason_key' => 'rejected',
                'status_text' => 'rejected',
            ]);
        }

        return self::dispatchResult(1, 0, 0, 1, $message);
    }

    private static function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function findOrder(int $orderId): ?object
    {
        return OrderService::findByIdOrNull($orderId);
    }

    private static function saveOrder(object $order, array $changes): void
    {
        OrderService::saveOrder($order, $changes);
    }

    private static function timestamp(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : $timestamp;
    }

    private static function canUseDatabase(): bool
    {
        static $usable = null;
        if ($usable !== null) {
            return $usable;
        }

        if (!database_available()) {
            $usable = false;
            return $usable;
        }

        try {
            \app\model\CallbackQueue::where('id', '>', 0)->limit(1)->find();
            $usable = true;
        } catch (Throwable) {
            $usable = false;
        }

        return $usable;
    }
}
