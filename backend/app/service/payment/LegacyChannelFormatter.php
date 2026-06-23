<?php

declare(strict_types=1);

namespace app\service\payment;

use app\service\system\PaymentMetaService;
use app\service\system\PluginCodeService;
use app\service\system\PluginRuntimeService;

class LegacyChannelFormatter
{
    public static function snapshotFromResolvedChannel(array $channel): array
    {
        $merchantChannel = $channel['merchant_channel'] ?? null;
        $channelType = $channel['channel_type'] ?? null;
        $config = is_array($merchantChannel->config ?? null) ? $merchantChannel->config : [];

        return [
            'merchant_channel_id' => (int)($merchantChannel->id ?? 0),
            'merchant_id' => (int)($merchantChannel->merchant_id ?? 0),
            'channel_type_id' => (int)($channelType->id ?? 0),
            'channel_code' => (string)($channelType->code ?? ''),
            'channel_category' => (int)($channelType->category ?? 0),
            'config' => $config,
            'rate' => (float)($merchantChannel->rate ?? 0),
            'remark' => (string)($merchantChannel->remark ?? ''),
        ];
    }

    public static function fromMerchantChannel(object $merchantChannel, ?object $channelType = null): array
    {
        $config = is_array($merchantChannel->config ?? null) ? $merchantChannel->config : [];
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($config['method_code'] ?? $channelType->code ?? ''));

        return self::fromConfig(
            (int)($merchantChannel->id ?? 0),
            (int)($merchantChannel->merchant_id ?? 0),
            $methodCode,
            $config,
            (float)($merchantChannel->rate ?? 0),
            (string)($merchantChannel->remark ?? '')
        );
    }

    public static function fromSerializedItem(int $merchantId, array $item): array
    {
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($item['method_code'] ?? $item['code'] ?? $item['channel'] ?? ''));
        $config = is_array($item['config'] ?? null) ? $item['config'] : [];
        if ($config === []) {
            $displayValue = self::sourceValue($item);
            $config = [
                'method_code' => $methodCode,
                'plugin_code' => (string)($item['plugin_code'] ?? ''),
                'plugin_name' => (string)($item['plugin_name'] ?? ''),
                'plugin_kind' => (string)($item['plugin_kind'] ?? ''),
                'payment_address' => $displayValue,
                'display_value' => $displayValue,
                'plugin_config' => is_array($item['plugin_config'] ?? null) ? $item['plugin_config'] : [],
            ];
        }

        return self::fromConfig(
            (int)($item['id'] ?? 0),
            $merchantId,
            $methodCode,
            $config,
            self::normalizeRate($item['rate'] ?? 0),
            (string)($item['remark'] ?? '')
        );
    }

    public static function fromTransferRecord(mixed $record, int $merchantId, string $fallbackType): array
    {
        if (is_array($record)) {
            $merchantChannel = $record['merchant_channel'] ?? null;
            $channelType = $record['channel_type'] ?? null;
            $recordCode = (string)($record['code'] ?? '');
            $recordId = self::recordId($record);
        } else {
            $merchantChannel = $record;
            $channelType = null;
            $recordCode = is_object($record) ? (string)($record->code ?? '') : '';
            $recordId = self::recordId($record);
        }

        if (!is_object($merchantChannel)) {
            return [];
        }

        $config = is_array($merchantChannel->config ?? null) ? $merchantChannel->config : [];
        $pluginCode = PluginCodeService::normalize((string)($config['plugin_code'] ?? ''));
        if ($pluginCode === '') {
            return [];
        }

        $methodCode = PaymentMetaService::normalizeMethodCode(
            (string)($config['method_code'] ?? $channelType->code ?? $recordCode ?? $fallbackType)
        );

        return self::fromConfig(
            $recordId,
            $merchantId,
            $methodCode,
            $config,
            (float)($merchantChannel->rate ?? 0),
            (string)($merchantChannel->remark ?? '')
        );
    }

    public static function fromConfig(
        int $channelId,
        int $merchantId,
        string $methodCode,
        array $config,
        float $rate,
        string $remark
    ): array {
        $pluginCode = PluginCodeService::normalize((string)($config['plugin_code'] ?? ''));
        if ($pluginCode === '') {
            throw new \RuntimeException('通道未绑定支付插件');
        }

        $runtimeSettings = PluginRuntimeService::settingsFor($pluginCode);
        $pluginConfig = is_array($config['plugin_config'] ?? null) ? $config['plugin_config'] : [];
        $merged = array_replace($runtimeSettings, $pluginConfig);
        $collectorMerchantId = trim((string)($pluginConfig['merchant_id'] ?? $merged['merchant_id'] ?? ''));
        $collectorChannelId = trim((string)($pluginConfig['channel_id'] ?? $merged['channel_id'] ?? ''));
        $display = self::displayValue($config);
        $resolvedPaymentAddress = trim((string)($config['payment_address'] ?? ''));
        if ($resolvedPaymentAddress === '') {
            $resolvedPaymentAddress = $display;
        }
        $resolvedQrUrl = trim((string)($pluginConfig['qrcode_url'] ?? $config['qrcode_url'] ?? ''));
        if ($resolvedQrUrl === '') {
            $resolvedQrUrl = $display;
        }
        $resolvedAddress = trim((string)($pluginConfig['address'] ?? $config['address'] ?? ''));
        if ($resolvedAddress === '') {
            $resolvedAddress = $display;
        }

        $resolvedAppId = trim((string)($merged['appid'] ?? ''));
        if ($resolvedAppId === '') {
            $resolvedAppId = $resolvedAddress !== '' ? $resolvedAddress : $resolvedPaymentAddress;
        }

        $methodName = trim((string)($config['method_name'] ?? ''));
        if ($methodName === '') {
            $methodName = PaymentMetaService::friendlyMethodName($methodCode);
        }

        $pluginKind = (string)($config['plugin_kind'] ?? $runtimeSettings['kind'] ?? '');

        return array_replace($merged, [
            'id' => $channelId,
            'merchant_id' => $merchantId,
            'plugin' => legacy_plugin_directory_name($pluginCode),
            'plugin_code' => $pluginCode,
            'plugin_config' => $pluginConfig,
            'kind' => $pluginKind,
            'plugin_kind' => $pluginKind,
            'type' => $methodCode,
            'channel_code' => $methodCode,
            'name' => (string)($config['plugin_name'] ?? $pluginCode),
            'showname' => $methodName,
            'appid' => $resolvedAppId,
            'appkey' => (string)($merged['appkey'] ?? ''),
            'appsecret' => (string)($merged['appsecret'] ?? ''),
            'appurl' => (string)($merged['appurl'] ?? $config['appurl'] ?? ''),
            'collector_merchant_id' => $collectorMerchantId,
            'custom_merchant_id' => $collectorMerchantId,
            'upstream_merchant_id' => $collectorMerchantId,
            'channel_id' => $collectorChannelId,
            'collector_channel_id' => $collectorChannelId,
            'appmchid' => (string)($merged['appmchid'] ?? ''),
            'appswitch' => (string)($merged['appswitch'] ?? '0'),
            'apptype' => $merged['apptype'] ?? ['1'],
            'bottoken' => (string)($merged['bottoken'] ?? ''),
            'botid' => (string)($merged['botid'] ?? ''),
            'xiaoshu' => (string)($merged['xiaoshu'] ?? ''),
            'apikey' => (string)($merged['apikey'] ?? ''),
            'productkey' => (string)($merged['productkey'] ?? ''),
            'payment_address' => $resolvedPaymentAddress,
            'display_value' => $display,
            'qrcode_url' => $resolvedQrUrl,
            'address' => $resolvedAddress,
            'remark' => $remark,
            'rate' => $rate,
        ]);
    }

    public static function identityForOrder(object $order): array
    {
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $snapshot = is_array($requestPayload['_legacy_channel'] ?? null) ? $requestPayload['_legacy_channel'] : [];

        return [
            'plugin_code' => PluginCodeService::normalize((string)($snapshot['plugin_code'] ?? $snapshot['plugin'] ?? '')),
            'channel_code' => PaymentMetaService::normalizeMethodCode(
                (string)($order->channel_code ?? $snapshot['channel_code'] ?? '')
            ),
        ];
    }

    private static function recordId(mixed $record): int
    {
        if (is_array($record)) {
            return (int)($record['id'] ?? 0);
        }

        return (int)($record->id ?? 0);
    }

    private static function displayValue(array $config): string
    {
        return self::sourceValue($config);
    }

    private static function sourceValue(array $payload, int $depth = 0): string
    {
        if ($depth > 2) {
            return '';
        }

        foreach ([
            'display_value',
            'payment_address',
            'qrcode_url',
            'address',
            'url',
            'link',
            'appreciate_qrcode_url',
            'qrcode_image',
            'appreciate_image',
            'resolved_qrcode_content',
        ] as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['plugin_config', 'config', 'channel'] as $key) {
            $nested = $payload[$key] ?? null;
            if (!is_array($nested)) {
                continue;
            }

            $value = self::sourceValue($nested, $depth + 1);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function normalizeRate(mixed $rate): float
    {
        return (float)str_replace('%', '', trim((string)$rate));
    }
}
