<?php

namespace app\service\payment;

class OrderStatusService
{
    public const DISPLAY_PENDING = 0;
    public const DISPLAY_SUCCESS = 1;
    public const DISPLAY_FAILED = 2;
    public const DISPLAY_EXPIRED = 3;
    public const DISPLAY_CLOSED = 4;
    public const DISPLAY_PENDING_CONFIRM = 5;
    public const DISPLAY_CALLBACK_FAILED = 6;
    public const DISPLAY_REFUNDED = 7;

    public static function forOperations(array|object $order): array
    {
        $row = self::normalizeOrder($order);
        $payment = self::forCheckout($row);
        $callback = self::callbackState($row);
        $refundAmount = self::refundAmount($row);
        $amount = self::amountValue($row);

        $status = [
            'key' => self::keyByCode($payment['code']),
            'label' => self::operationsLabelByCode($payment['code']),
            'code' => $payment['code'],
            'theme' => self::themeByCode($payment['code']),
        ];

        if ($payment['code'] === self::DISPLAY_SUCCESS) {
            if ($amount > 0 && $refundAmount > 0 && $refundAmount + 0.00001 >= $amount) {
                $status = self::statusPayload(self::DISPLAY_REFUNDED);
            } elseif (($callback['code'] ?? 0) === 3) {
                $status = self::statusPayload(self::DISPLAY_CALLBACK_FAILED);
            } else {
                $status = self::statusPayload(self::DISPLAY_SUCCESS);
            }
        }

        return array_merge($status, [
            'raw_status_code' => (int)($row['status'] ?? OrderService::STATUS_PENDING),
            'payment_status_key' => (string)$payment['key'],
            'payment_status_label' => (string)$payment['label'],
            'payment_status_code' => (int)$payment['code'],
            'callback_status_key' => (string)$callback['key'],
            'callback_status_label' => (string)$callback['label'],
            'callback_status_code' => (int)$callback['code'],
            'callback_status_theme' => (string)($callback['theme'] ?? 'warning'),
            'callback_status_hint' => (string)($callback['hint'] ?? ''),
            'refund_amount' => number_format($refundAmount, 2, '.', ''),
        ]);
    }

    public static function forCheckout(array|object $order): array
    {
        $row = self::normalizeOrder($order);
        $rawStatus = (int)($row['status'] ?? OrderService::STATUS_PENDING);

        $displayCode = match ($rawStatus) {
            OrderService::STATUS_SUCCESS => self::DISPLAY_SUCCESS,
            OrderService::STATUS_FAILED => self::DISPLAY_FAILED,
            OrderService::STATUS_EXPIRED => self::DISPLAY_EXPIRED,
            OrderService::STATUS_CLOSED => self::DISPLAY_CLOSED,
            default => self::hasPendingConfirmSignal($row)
                ? self::DISPLAY_PENDING_CONFIRM
                : self::DISPLAY_PENDING,
        };

        $callback = self::callbackState($row);

        return array_merge(self::statusPayload($displayCode, true), [
            'raw_status_code' => $rawStatus,
            'payment_status_key' => self::keyByCode($displayCode),
            'payment_status_label' => self::checkoutLabelByCode($displayCode),
            'payment_status_code' => $displayCode,
            'callback_status_key' => (string)$callback['key'],
            'callback_status_label' => (string)$callback['label'],
            'callback_status_code' => (int)$callback['code'],
            'callback_status_theme' => (string)($callback['theme'] ?? 'warning'),
            'callback_status_hint' => (string)($callback['hint'] ?? ''),
            'refund_amount' => number_format(self::refundAmount($row), 2, '.', ''),
        ]);
    }

    public static function labelByCode(int $statusCode): string
    {
        return self::operationsLabelByCode($statusCode);
    }

    public static function keyByCode(int $statusCode): string
    {
        return match ($statusCode) {
            self::DISPLAY_SUCCESS => 'success',
            self::DISPLAY_FAILED => 'failed',
            self::DISPLAY_EXPIRED => 'expired',
            self::DISPLAY_CLOSED => 'closed',
            self::DISPLAY_PENDING_CONFIRM => 'pending_confirm',
            self::DISPLAY_CALLBACK_FAILED => 'callback_failed',
            self::DISPLAY_REFUNDED => 'refunded',
            default => 'pending',
        };
    }

    public static function themeByCode(int $statusCode): string
    {
        return match ($statusCode) {
            self::DISPLAY_SUCCESS => 'success',
            self::DISPLAY_FAILED => 'danger',
            self::DISPLAY_EXPIRED, self::DISPLAY_CLOSED, self::DISPLAY_REFUNDED => 'muted',
            self::DISPLAY_CALLBACK_FAILED, self::DISPLAY_PENDING_CONFIRM, self::DISPLAY_PENDING => 'warning',
            default => 'warning',
        };
    }

    public static function operationsLabelByCode(int $statusCode): string
    {
        return match ($statusCode) {
            self::DISPLAY_SUCCESS => '已支付',
            self::DISPLAY_FAILED => '支付失败',
            self::DISPLAY_EXPIRED => '已过期',
            self::DISPLAY_CLOSED => '已关闭',
            self::DISPLAY_PENDING_CONFIRM => '待确认',
            self::DISPLAY_CALLBACK_FAILED => '回调失败',
            self::DISPLAY_REFUNDED => '已退款',
            default => '待支付',
        };
    }

    public static function checkoutLabelByCode(int $statusCode): string
    {
        return match ($statusCode) {
            self::DISPLAY_SUCCESS => '支付成功',
            self::DISPLAY_FAILED => '支付失败',
            self::DISPLAY_EXPIRED => '订单已过期',
            self::DISPLAY_CLOSED => '订单已关闭',
            self::DISPLAY_PENDING_CONFIRM => '待确认',
            default => '等待支付',
        };
    }

    private static function statusPayload(int $statusCode, bool $checkoutView = false): array
    {
        return [
            'key' => self::keyByCode($statusCode),
            'label' => $checkoutView
                ? self::checkoutLabelByCode($statusCode)
                : self::operationsLabelByCode($statusCode),
            'code' => $statusCode,
            'theme' => self::themeByCode($statusCode),
        ];
    }

    private static function callbackState(array $row): array
    {
        $hasNotify = trim((string)($row['notify_url'] ?? '')) !== '';
        $status = (int)($row['callback_status'] ?? 0);

        if (!$hasNotify) {
            return [
                'key' => 'none',
                'label' => '无需回调',
                'code' => 0,
            ];
        }

        $notifyPayload = is_array($row['notify_payload'] ?? null) ? $row['notify_payload'] : [];
        $callbackPayload = is_array($notifyPayload['callback'] ?? null) ? $notifyPayload['callback'] : [];
        $state = CallbackService::describeCallbackState(
            $status,
            $callbackPayload,
            (int)($row['callback_count'] ?? 0),
            0
        );

        return [
            'key' => (string)($state['key'] ?? 'queued'),
            'label' => (string)($state['label'] ?? '待回调'),
            'code' => $status === 0 ? 0 : $status,
            'theme' => (string)($state['theme'] ?? 'warning'),
            'hint' => (string)($state['hint'] ?? ''),
        ];
    }

    private static function refundAmount(array $row): float
    {
        $merchantId = (int)($row['merchant_id'] ?? 0);
        $tradeNo = trim((string)($row['trade_no'] ?? ''));
        if ($merchantId <= 0 || $tradeNo === '') {
            return 0.0;
        }

        return (float)LocalTransferStore::sumRefundMoney($merchantId, $tradeNo);
    }

    private static function amountValue(array $row): float
    {
        $amount = (float)($row['amount'] ?? 0);
        if ($amount <= 0) {
            $amount = (float)($row['payable_amount'] ?? 0);
        }

        return $amount > 0 ? round($amount, 2) : 0.0;
    }

    private static function hasPendingConfirmSignal(array $row): bool
    {
        $notifyPayload = is_array($row['notify_payload'] ?? null) ? $row['notify_payload'] : [];
        $pluginQuery = is_array($notifyPayload['plugin_query'] ?? null) ? $notifyPayload['plugin_query'] : [];
        $raw = is_array($pluginQuery['raw'] ?? null) ? $pluginQuery['raw'] : [];

        $candidates = [
            (string)($pluginQuery['status_text'] ?? ''),
            (string)($pluginQuery['result'] ?? ''),
            (string)($pluginQuery['errmsg'] ?? ''),
            (string)($raw['status'] ?? ''),
            (string)($raw['status_text'] ?? ''),
            (string)($raw['trade_status'] ?? ''),
            (string)($raw['result'] ?? ''),
            (string)($raw['message'] ?? ''),
            (string)($raw['msg'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if (self::looksLikePendingConfirm($candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function looksLikePendingConfirm(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        foreach ([
            'pending_confirm',
            'wait_confirm',
            'await_confirm',
            'userpaying',
            'confirming',
            'processing',
            '待确认',
            '已扫码',
            '请确认',
            '待用户确认',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeOrder(array|object $order): array
    {
        if (is_array($order)) {
            return $order;
        }

        if (method_exists($order, 'toArray')) {
            $row = $order->toArray();
            if (is_array($row)) {
                return $row;
            }
        }

        $row = [];
        foreach (get_object_vars($order) as $key => $value) {
            $row[$key] = $value;
        }

        return $row;
    }
}
