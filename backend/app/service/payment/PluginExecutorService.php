<?php

declare(strict_types=1);

namespace app\service\payment;

use app\service\system\PluginCodeService;
use app\service\system\PluginRuntimeService;
use app\service\system\PluginSchemaService;
use plugins\payment\common\StaticPaymentPlugin;
use Throwable;

class PluginExecutorService
{
    public static function capability(string $pluginCode): array
    {
        $class = self::pluginClass($pluginCode);
        $exists = $class !== '' && class_exists($class);

        return [
            'plugin_code' => PluginCodeService::normalize($pluginCode),
            'class' => $class,
            'exists' => $exists,
            'query' => $exists && method_exists($class, 'query'),
            'refund' => $exists && method_exists($class, 'refund'),
            'refund_query' => $exists && (method_exists($class, 'refund_query') || method_exists($class, 'refundquery')),
            'transfer' => $exists && method_exists($class, 'transfer'),
            'transfer_query' => $exists && method_exists($class, 'transfer_query'),
            'balance_query' => $exists && method_exists($class, 'balance_query'),
        ];
    }

    public static function canExecute(array $channel, string $method): array
    {
        $method = strtolower(trim($method));
        $pluginCode = PluginCodeService::normalize((string)($channel['plugin_code'] ?? $channel['plugin'] ?? ''));
        if ($pluginCode === '' || $pluginCode === 'mock_fallback') {
            return self::unavailable('plugin_missing', '通道未绑定真实支付插件');
        }

        $capability = self::capability($pluginCode);
        if (!$capability['exists']) {
            return self::unavailable('plugin_class_missing', '支付插件 ' . $pluginCode . ' 未找到可执行类');
        }

        if (!($capability[$method] ?? false)) {
            return self::unavailable('unsupported_capability', '支付插件 ' . $pluginCode . ' 未实现 ' . $method . ' 能力');
        }

        $missing = self::missingRequiredSettings($pluginCode, $channel);
        if ($missing !== []) {
            return self::unavailable('plugin_config_missing', '支付插件 ' . $pluginCode . ' 缺少必填配置：' . implode('、', $missing), [
                'missing_required_settings' => $missing,
            ]);
        }

        return [
            'ok' => true,
            'result' => 'ready',
            'errmsg' => '',
            'plugin_code' => $pluginCode,
            'capability' => $capability,
        ];
    }

    public static function query(object $order): array
    {
        try {
            $channel = LegacyPaymentGatewayService::legacyChannelArray($order);
        } catch (Throwable $exception) {
            return self::unavailable('plugin_channel_error', $exception->getMessage()) + [
                'status' => 0,
                'raw' => [],
                'channel' => [],
            ];
        }

        $ready = self::canExecute($channel, 'query');
        if (!$ready['ok']) {
            return $ready + [
                'status' => 0,
                'raw' => [],
                'channel' => self::channelSummary($channel),
            ];
        }

        try {
            $raw = self::instantiate($channel)->query(LegacyPaymentGatewayService::legacyOrderArray($order));
        } catch (Throwable $exception) {
            return self::failed('plugin_exception', $exception->getMessage(), [], $channel);
        }

        return self::normalizeQueryResult((array)$raw, $channel, $order);
    }

    public static function refund(object $order, object $refund): array
    {
        $channel = LegacyPaymentGatewayService::legacyChannelArray($order);
        $ready = self::canExecute($channel, 'refund');
        if (!$ready['ok']) {
            return $ready + [
                'status' => 0,
                'raw' => [],
                'channel' => self::channelSummary($channel),
            ];
        }

        $legacyOrder = self::legacyRefundOrder($order, $refund);

        if ($legacyOrder['api_trade_no'] === '') {
            return self::unavailable(
                'channel_trade_no_missing',
                '原订单缺少第三方交易号，无法调用插件退款'
            ) + ['status' => 0, 'raw' => [], 'channel' => self::channelSummary($channel)];
        }

        try {
            $raw = self::instantiate($channel)->refund($legacyOrder);
        } catch (Throwable $exception) {
            return self::failed('plugin_exception', $exception->getMessage(), [], $channel);
        }

        return self::normalizeRefundResult((array)$raw, $channel);
    }

    public static function queryRefund(object $order, object $refund): array
    {
        $channel = LegacyPaymentGatewayService::legacyChannelArray($order);
        $ready = self::canExecute($channel, 'refund_query');
        if (!$ready['ok']) {
            return $ready + [
                'status' => 0,
                'raw' => [],
                'channel' => self::channelSummary($channel),
            ];
        }

        $legacyOrder = self::legacyRefundOrder($order, $refund);
        $class = self::pluginClass((string)($channel['plugin_code'] ?? $channel['plugin'] ?? ''));
        $method = method_exists($class, 'refund_query') ? 'refund_query' : 'refundquery';

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $raw = self::instantiate($channel)->$method($legacyOrder);
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        } catch (Throwable $exception) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            return self::failed('plugin_exception', $exception->getMessage(), [], $channel);
        }

        return self::normalizeRefundResult((array)$raw, $channel);
    }

    public static function transfer(array $channel, object $transfer): array
    {
        $ready = self::canExecute($channel, 'transfer');
        if (!$ready['ok']) {
            return $ready + [
                'status' => 0,
                'raw' => [],
                'channel' => self::channelSummary($channel),
            ];
        }

        $bizParam = [
            'biz_no' => (string)($transfer->biz_no ?? ''),
            'out_biz_no' => (string)($transfer->out_biz_no ?? ''),
            'type' => (string)($transfer->type ?? ''),
            'money' => self::formatMoney($transfer->money ?? 0),
            'payee_account' => (string)($transfer->account ?? ''),
            'payee_real_name' => (string)($transfer->name ?? ''),
            'transfer_name' => 'NexPay 代付',
            'transfer_desc' => 'NexPay transfer ' . (string)($transfer->out_biz_no ?? ''),
            'orderid' => (string)($transfer->channel_order_no ?? $transfer->biz_no ?? ''),
        ];

        try {
            $raw = self::instantiate($channel)->transfer($bizParam);
        } catch (Throwable $exception) {
            return self::failed('plugin_exception', $exception->getMessage(), [], $channel);
        }

        return self::normalizeTransferResult((array)$raw, $channel);
    }

    public static function queryTransfer(array $channel, object $transfer): array
    {
        $ready = self::canExecute($channel, 'transfer_query');
        if (!$ready['ok']) {
            return $ready + [
                'status' => 0,
                'raw' => [],
                'channel' => self::channelSummary($channel),
            ];
        }

        $bizParam = [
            'biz_no' => (string)($transfer->biz_no ?? ''),
            'out_biz_no' => (string)($transfer->out_biz_no ?? ''),
            'type' => (string)($transfer->type ?? ''),
            'money' => self::formatMoney($transfer->money ?? 0),
            'payee_account' => (string)($transfer->account ?? ''),
            'payee_real_name' => (string)($transfer->name ?? ''),
            'orderid' => (string)($transfer->channel_order_no ?? $transfer->biz_no ?? ''),
        ];

        try {
            $raw = self::instantiate($channel)->transfer_query($bizParam);
        } catch (Throwable $exception) {
            return self::failed('plugin_exception', $exception->getMessage(), [], $channel);
        }

        return self::normalizeTransferResult((array)$raw, $channel);
    }

    private static function normalizeQueryResult(array $raw, array $channel, object $order): array
    {
        if (array_key_exists('code', $raw) && (int)$raw['code'] !== 0) {
            return self::failed('plugin_failed', self::errorMessage($raw), $raw, $channel);
        }

        $status = self::normalizeQueryStatus($raw);
        $amount = self::formatMoney($raw['money'] ?? $raw['amount'] ?? $raw['total_amount'] ?? $raw['pay_amt'] ?? 0);
        if ($status === 1 && (float)$amount > 0 && round((float)$amount, 2) !== round((float)($order->amount ?? 0), 2)) {
            return self::failed(
                'plugin_amount_mismatch',
                '插件查单金额与本地订单金额不一致',
                $raw,
                $channel
            );
        }

        return [
            'ok' => true,
            'status' => $status,
            'result' => match ($status) {
                1 => 'plugin_paid',
                2 => 'plugin_trade_failed',
                default => 'plugin_unpaid',
            },
            'errmsg' => $status === 2 ? self::errorMessage($raw) : (string)($raw['errmsg'] ?? $raw['msg'] ?? ''),
            'api_trade_no' => trim((string)($raw['api_trade_no'] ?? $raw['trade_no'] ?? $raw['transaction_id'] ?? $raw['channel_trade_no'] ?? '')),
            'bill_trade_no' => trim((string)($raw['bill_trade_no'] ?? $raw['bill_no'] ?? '')),
            'bill_mch_trade_no' => trim((string)($raw['bill_mch_trade_no'] ?? '')),
            'buyer' => trim((string)($raw['buyer'] ?? $raw['buyer_user_id'] ?? $raw['openid'] ?? $raw['open_id'] ?? '')),
            'paid_at' => self::normalizeDateTimeString($raw['endtime'] ?? $raw['pay_time'] ?? $raw['paydate'] ?? $raw['success_time'] ?? ''),
            'amount' => $amount,
            'raw' => $raw,
            'channel' => self::channelSummary($channel),
        ];
    }

    private static function instantiate(array $channel): object
    {
        $class = self::pluginClass((string)($channel['plugin_code'] ?? $channel['plugin'] ?? ''));
        return new $class($channel);
    }

    private static function legacyRefundOrder(object $order, object $refund): array
    {
        $legacyOrder = LegacyPaymentGatewayService::legacyOrderArray($order);
        $legacyOrder['refund_no'] = (string)($refund->refund_no ?? '');
        $legacyOrder['out_refund_no'] = (string)($refund->out_refund_no ?? $legacyOrder['refund_no']);
        $legacyOrder['refundmoney'] = self::formatMoney($refund->reducemoney ?? $refund->money ?? 0);
        $legacyOrder['reducemoney'] = $legacyOrder['refundmoney'];
        $legacyOrder['realmoney'] = self::formatMoney($order->amount ?? 0);
        $legacyOrder['money'] = self::formatMoney($order->amount ?? 0);
        $legacyOrder['api_trade_no'] = trim((string)($legacyOrder['api_trade_no'] ?? $order->txid ?? ''));
        $legacyOrder['channel_refund_no'] = (string)($refund->channel_order_no ?? '');
        $legacyOrder['channel_trade_no'] = (string)($refund->channel_trade_no ?? '');

        return $legacyOrder;
    }

    private static function pluginClass(string $pluginCode): string
    {
        $normalizedCode = PluginCodeService::normalize($pluginCode);
        $pluginDir = legacy_plugin_directory_name($normalizedCode);
        if ($pluginDir === '') {
            return '';
        }

        $classBase = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $pluginDir)));
        $className = 'plugins\\payment\\' . $pluginDir . '\\' . $classBase . 'Plugin';
        if (class_exists($className)) {
            return $className;
        }

        $definition = self::pluginDefinition($normalizedCode);
        $kind = strtolower(trim((string)($definition['kind'] ?? '')));

        return in_array($kind, ['qrcode', 'ck', 'app'], true) ? StaticPaymentPlugin::class : '';
    }

    private static function pluginDefinition(string $pluginCode): array
    {
        $definitions = PluginRuntimeService::discoverMap();
        if (isset($definitions[$pluginCode]) && is_array($definitions[$pluginCode])) {
            return $definitions[$pluginCode];
        }

        foreach ($definitions as $code => $definition) {
            if (PluginCodeService::normalize((string)$code) === $pluginCode && is_array($definition)) {
                return $definition;
            }
        }

        return [];
    }

    private static function missingRequiredSettings(string $pluginCode, array $channel): array
    {
        $definitions = PluginRuntimeService::discoverMap();
        $definition = $definitions[$pluginCode] ?? [];
        if ($definition === []) {
            foreach ($definitions as $code => $candidate) {
                if (PluginCodeService::normalize((string)$code) === $pluginCode) {
                    $definition = $candidate;
                    break;
                }
            }
        }
        $schema = is_array($definition['settings_schema'] ?? null) ? $definition['settings_schema'] : [];
        if ($schema === []) {
            return [];
        }

        $settings = array_replace(
            is_array($definition['default_settings'] ?? null) ? $definition['default_settings'] : [],
            PluginRuntimeService::settingsFor($pluginCode),
            is_array($channel['plugin_config'] ?? null) ? $channel['plugin_config'] : [],
            $channel
        );

        $missing = [];
        foreach ($schema as $field) {
            if (!is_array($field) || !self::truthy($field['required'] ?? false)) {
                continue;
            }

            if (!PluginSchemaService::isFieldVisible($field, '', $settings, (array)($definition['payment_methods'] ?? []))) {
                continue;
            }

            $key = trim((string)($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $value = $settings[$key] ?? null;
            if (is_array($value)) {
                $isMissing = $value === [];
            } else {
                $isMissing = trim((string)$value) === '';
            }

            if ($isMissing) {
                $missing[] = trim((string)($field['label'] ?? $key)) ?: $key;
            }
        }

        return array_values(array_unique($missing));
    }

    private static function normalizeRefundResult(array $raw, array $channel): array
    {
        $code = (int)($raw['code'] ?? -1);
        if ($code !== 0) {
            return self::failed('plugin_failed', self::errorMessage($raw), $raw, $channel);
        }

        $status = self::normalizeRefundStatus($raw);

        return [
            'ok' => true,
            'status' => $status,
            'result' => match ($status) {
                1 => 'plugin_refunded',
                2 => 'plugin_refund_failed',
                default => 'plugin_refund_pending',
            },
            'errmsg' => $status === 2 ? self::errorMessage($raw) : (string)($raw['errmsg'] ?? $raw['msg'] ?? ''),
            'channel_order_no' => (string)($raw['refund_no'] ?? $raw['trade_no'] ?? $raw['orderid'] ?? ''),
            'channel_trade_no' => (string)($raw['trade_no'] ?? $raw['api_trade_no'] ?? ''),
            'amount' => self::formatMoney($raw['refund_fee'] ?? $raw['amount'] ?? 0),
            'raw' => $raw,
            'channel' => self::channelSummary($channel),
        ];
    }

    private static function normalizeTransferResult(array $raw, array $channel): array
    {
        $code = (int)($raw['code'] ?? -1);
        if ($code !== 0) {
            return self::failed('plugin_failed', self::errorMessage($raw), $raw, $channel);
        }

        $status = (int)($raw['status'] ?? 1);
        if (!in_array($status, [0, 1, 2], true)) {
            $status = 0;
        }

        return [
            'ok' => true,
            'status' => $status,
            'result' => match ($status) {
                1 => 'plugin_transferred',
                2 => 'plugin_transfer_failed',
                default => 'plugin_transfer_pending',
            },
            'errmsg' => (string)($raw['errmsg'] ?? $raw['msg'] ?? ''),
            'channel_order_no' => (string)($raw['orderid'] ?? $raw['channel_order_no'] ?? $raw['trade_no'] ?? ''),
            'channel_trade_no' => (string)($raw['trade_no'] ?? $raw['channel_trade_no'] ?? $raw['orderid'] ?? ''),
            'paydate' => (string)($raw['paydate'] ?? ''),
            'amount' => self::formatMoney($raw['amount'] ?? 0),
            'raw' => $raw,
            'channel' => self::channelSummary($channel),
        ];
    }

    private static function failed(string $result, string $message, array $raw, array $channel): array
    {
        return [
            'ok' => false,
            'status' => 0,
            'result' => $result,
            'errmsg' => $message !== '' ? $message : '支付插件执行失败',
            'raw' => $raw,
            'channel' => self::channelSummary($channel),
        ];
    }

    private static function unavailable(string $result, string $message, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'result' => $result,
            'errmsg' => $message,
        ], $extra);
    }

    private static function errorMessage(array $raw): string
    {
        $message = trim((string)($raw['msg'] ?? $raw['errmsg'] ?? $raw['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $errCode = trim((string)($raw['errcode'] ?? $raw['sub_code'] ?? ''));
        return $errCode !== '' ? '插件返回错误：' . $errCode : '支付插件返回失败';
    }

    private static function channelSummary(array $channel): array
    {
        return [
            'id' => (int)($channel['id'] ?? 0),
            'plugin_code' => PluginCodeService::normalize((string)($channel['plugin_code'] ?? $channel['plugin'] ?? '')),
            'type' => (string)($channel['type'] ?? $channel['channel_code'] ?? ''),
            'name' => (string)($channel['name'] ?? ''),
        ];
    }

    private static function normalizeQueryStatus(array $raw): int
    {
        $status = $raw['status'] ?? null;
        if (is_numeric($status)) {
            return in_array((int)$status, [0, 1, 2], true) ? (int)$status : 0;
        }

        $text = strtoupper(trim((string)($status ?? $raw['trade_status'] ?? $raw['result'] ?? '')));
        if (in_array($text, ['1', 'SUCCESS', 'PAID', 'TRADE_SUCCESS', 'TRADE_FINISHED', 'PAY_SUCCESS'], true)) {
            return 1;
        }

        if (in_array($text, ['2', 'FAILED', 'FAIL', 'TRADE_CLOSED', 'CLOSED', 'CANCELLED', 'CANCELED'], true)) {
            return 2;
        }

        return 0;
    }

    private static function normalizeRefundStatus(array $raw): int
    {
        if (array_key_exists('status', $raw)) {
            $status = $raw['status'];
            if (is_numeric($status)) {
                return in_array((int)$status, [0, 1, 2], true) ? (int)$status : 0;
            }

            $text = strtoupper(trim((string)$status));
            if (in_array($text, ['SUCCESS', 'REFUND_SUCCESS', 'REFUNDED', 'FINISHED'], true)) {
                return 1;
            }
            if (in_array($text, ['FAILED', 'FAIL', 'REFUND_FAILED', 'CLOSED'], true)) {
                return 2;
            }
            if ($text !== '') {
                return 0;
            }
        }

        $text = strtoupper(trim((string)($raw['refund_status'] ?? $raw['trade_status'] ?? $raw['result'] ?? '')));
        if (in_array($text, ['SUCCESS', 'REFUND_SUCCESS', 'REFUNDED', 'FINISHED'], true)) {
            return 1;
        }
        if (in_array($text, ['FAILED', 'FAIL', 'REFUND_FAILED', 'CLOSED'], true)) {
            return 2;
        }
        if ($text !== '') {
            return 0;
        }

        return 1;
    }

    private static function normalizeDateTimeString(mixed $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        $timestamp = strtotime($text);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : $text;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function formatMoney(mixed $amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }
}
