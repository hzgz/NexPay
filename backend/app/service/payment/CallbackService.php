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

            $items[] = [
                'id' => (int)($callback->id ?? 0),
                'merchant_id' => (int)($callback->merchant_id ?? 0),
                'order_id' => (int)($callback->order_id ?? 0),
                'trade_no' => (string)($order->trade_no ?? ''),
                'out_trade_no' => (string)($order->out_trade_no ?? ''),
                'notify_url' => (string)($callback->notify_url ?? ''),
                'status' => self::statusKey($status),
                'result' => self::statusLabel($status, (int)($callback->retry_count ?? 0)),
                'response' => $response,
                'retry_count' => (int)($callback->retry_count ?? 0),
                'max_retry' => (int)($callback->max_retry ?? 0),
                'next_time' => $nextTime,
                'created_at' => (string)($callback->created_at ?? ''),
                'updated_at' => (string)($callback->updated_at ?? ''),
                'status_code' => $status,
                'can_retry' => $status !== self::STATUS_SUCCESS,
                'retry_exhausted' => $status === self::STATUS_FAILED && (int)($callback->retry_count ?? 0) >= (int)($callback->max_retry ?? 0),
                'runtime_exception' => $runtimeException,
                'manual_retry' => $manualRetry,
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

            if ($runtimeException) {
                $summary['runtime_exception_total']++;
            }

            if ($manualRetry) {
                $summary['manual_retry_total']++;
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

                if ($retryCount >= $maxRetry) {
                    $summary['retry_exhausted']++;
                }

                if ($updatedTs > $lastFailedTs) {
                    $lastFailedTs = $updatedTs;
                }
            } else {
                $summary['pending_total']++;

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
                'message' => 'callback not found',
            ];
        }

        if ((int)($callback->status ?? self::STATUS_PENDING) === self::STATUS_SUCCESS) {
            return [
                'checked' => 0,
                'succeeded' => 0,
                'deferred' => 0,
                'failed' => 0,
                'message' => 'callback already succeeded',
            ];
        }

        return self::dispatchCallbackSafely($callback, true);
    }

    public static function enqueueOrder(object $order, object $merchant): void
    {
        if (!$order->notify_url) {
            return;
        }

        if (!LocalOrderStore::isBusinessOrder($order)) {
            return;
        }

        if (self::hasExistingCallback((int)$order->id)) {
            return;
        }

        $payload = self::buildPayload($order, $merchant);
        self::createCallback([
            'order_id' => (int)$order->id,
            'merchant_id' => (int)$merchant->id,
            'notify_url' => $order->notify_url,
            'payload' => $payload,
            'retry_count' => 0,
            'max_retry' => self::configuredMaxRetry(),
            'status' => self::STATUS_PENDING,
            'next_time' => date('Y-m-d H:i:s'),
            'last_error' => '',
        ]);
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
        $query = http_build_query($payload);
        $url = $notifyUrl . (str_contains($notifyUrl, '?') ? '&' : '?') . $query;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "User-Agent: NexPay Callback/1.0\r\n",
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return [false, 'request failed'];
        }

        $body = trim((string)$result);
        if ($body !== '' && strcasecmp($body, 'success') === 0) {
            return [true, 'success'];
        }

        return [false, $body !== '' ? self::truncate($body) : 'empty response'];
    }

    private static function dispatchCallback(object $callback, bool $manual = false): array
    {
        $order = self::findOrder((int)$callback->order_id);
        if (!$order) {
            self::updateCallback((int)$callback->id, [
                'retry_count' => (int)$callback->retry_count + 1,
                'status' => self::STATUS_FAILED,
                'last_error' => 'order not found',
                'next_time' => date('Y-m-d H:i:s', time() + 3600),
            ]);

            return self::dispatchResult(1, 0, 0, 1, 'order not found');
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

            return self::dispatchResult(1, 1, 0, 0, 'success');
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
            self::saveOrder($order, [
                'callback_status' => 3,
                'callback_count' => $retryCount,
                'notify_payload' => $orderNotify,
            ]);

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

        $message = self::truncate(trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'callback dispatch exception');
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
            ];

            self::saveOrder($order, [
                'callback_status' => $status === self::STATUS_FAILED ? 3 : 1,
                'callback_count' => $retryCount,
                'notify_payload' => $orderNotify,
            ]);
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
        $schedule = [60, 120, 300, 600, 1800];
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

    private static function configuredMaxRetry(): int
    {
        $settings = SettingsService::all(false);
        $api = is_array($settings['api'] ?? null) ? $settings['api'] : [];
        $value = (int)trim((string)($api['notify_retry'] ?? '5'));

        return max(1, min(20, $value > 0 ? $value : 5));
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

    private static function hasExistingCallback(int $orderId): bool
    {
        if (self::canUseDatabase()) {
            return \app\model\CallbackQueue::where('order_id', $orderId)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SUCCESS])
                ->find() !== null;
        }

        if (method_exists(LocalOrderStore::class, 'hasPendingOrSuccessCallback')) {
            return LocalOrderStore::hasPendingOrSuccessCallback($orderId);
        }

        return false;
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
