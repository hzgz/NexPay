<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\model\ChannelType;
use app\model\Order;
use app\model\MerchantChannel;
use app\service\payment\LocalOrderStore;
use app\service\payment\PluginExecutorService;
use Throwable;

/**
 * Merchant channel management.
 *
 * Each merchant channel keeps its own business limits and plugin config payload.
 */
class MerchantChannelService
{
    private const STORE_KEY = 'merchant_channels';
    private const DEFAULT_PAYMENT_TEMPLATE = 'nexpay-standard';
    private const PAYMENT_TEMPLATE_ALIAS_MAP = [
        'classic-blue' => 'nexpay-standard',
        'hg-pay-1' => 'nexpay-center',
        'hg-pay-2' => 'nexpay-dialog',
        'modern-float' => 'nexpay-float',
    ];
    private const PAYMENT_VARIABLE_ALIAS_MAP = [
        '[平台订单号]' => '{{platform_order_no}}',
        '[商户订单号]' => '{{merchant_order_no}}',
        '[商品名称]' => '{{product_name}}',
        '[实付价格]' => '{{paid_amount}}',
        '[订单价格]' => '{{order_amount}}',
        '[收款方式]' => '{{payment_method}}',
        '{{平台订单号}}' => '{{platform_order_no}}',
        '{{商户订单号}}' => '{{merchant_order_no}}',
        '{{商品名称}}' => '{{product_name}}',
        '{{实付价格}}' => '{{paid_amount}}',
        '{{订单价格}}' => '{{order_amount}}',
        '{{收款方式}}' => '{{payment_method}}',
    ];

    public static function all(int $merchantId): array
    {
        $items = self::channelItemsForRotation($merchantId);
        usort($items, static function (array $left, array $right): int {
            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        return [
            'items' => $items,
            'rotation' => self::rotationConfig($merchantId, $items),
            'payment_settings' => self::paymentSettings($merchantId),
            'methods' => self::methodOptions(),
            'plugins' => self::pluginOptions(),
        ];
    }

    protected static function channelItemsForRotation(int $merchantId): array
    {
        $items = self::canUseDatabase()
            ? self::itemsFromDatabase($merchantId)
            : self::itemsFromJsonStore($merchantId);

        return array_values(array_filter(
            $items,
            static fn(array $item): bool => !self::isSeedChannelItem($item)
        ));
    }

    public static function isSeedChannelPayload(array $item): bool
    {
        return self::isSeedChannelItem($item);
    }

    public static function saveItem(int $merchantId, array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        $existing = $id > 0 ? self::findSerializedItem($merchantId, $id) : null;
        if ($id > 0 && $existing === null) {
            throw new BusinessException('通道不存在', StatusCode::NOT_FOUND);
        }

        $methodCode = PaymentMetaService::normalizeMethodCode((string)($payload['method_code'] ?? $payload['channel'] ?? $existing['method_code'] ?? ''));
        $pluginCode = PluginCodeService::normalize((string)($payload['plugin_code'] ?? $existing['plugin_code'] ?? ''));
        $displayValue = trim((string)($payload['display_value'] ?? $payload['address'] ?? $existing['display_value'] ?? ''));
        $rate = self::normalizeRate($payload['rate'] ?? $existing['rate'] ?? '0.85');
        $remark = trim((string)($payload['remark'] ?? $existing['remark'] ?? ''));
        $statusCode = array_key_exists('status_code', $payload)
            ? ((int)$payload['status_code'] === 1 ? 1 : 0)
            : ((int)($existing['status_code'] ?? 1) === 1 ? 1 : 0);
        $pluginConfig = array_key_exists('plugin_config', $payload)
            ? self::normalizePluginConfig($payload['plugin_config'])
            : (is_array($existing['plugin_config'] ?? null) ? $existing['plugin_config'] : []);
        $limits = self::normalizeBusinessLimits($payload, $existing ?? []);
        $channelName = trim((string)($payload['channel_name'] ?? $payload['name'] ?? $existing['channel_name'] ?? ''));

        if ($methodCode === '') {
            throw new BusinessException('请选择支付方式', StatusCode::VALIDATION_ERROR);
        }

        if ($pluginCode === '') {
            throw new BusinessException('请选择支付插件', StatusCode::VALIDATION_ERROR);
        }

        $method = self::findMethod($methodCode);
        if ($method === null) {
            PluginService::saveMethod([
                'code' => $methodCode,
                'name' => PaymentMetaService::friendlyMethodName($methodCode),
                'category' => PaymentMetaService::categoryLabel(PaymentMetaService::normalizeCategory('', $methodCode)),
                'settlement' => PaymentMetaService::defaultSettlementByCode($methodCode),
            ]);
            $method = self::findMethod($methodCode);
        }

        if ($method === null) {
            throw new BusinessException('支付方式不存在', StatusCode::NOT_FOUND);
        }

        if ((int)($method['status_code'] ?? 0) !== 1) {
            throw new BusinessException('支付方式已停用', StatusCode::VALIDATION_ERROR);
        }

        $plugin = self::findPlugin($pluginCode);
        if ($plugin === null) {
            throw new BusinessException('支付插件不存在', StatusCode::NOT_FOUND);
        }

        if ((int)($plugin['status_code'] ?? 0) !== 1) {
            throw new BusinessException('支付插件已停用', StatusCode::VALIDATION_ERROR);
        }

        if (!self::pluginSupportsMethod($plugin, $methodCode)) {
            throw new BusinessException('该插件不支持所选支付方式', StatusCode::VALIDATION_ERROR);
        }

        if (self::toBool($payload['validate_plugin_config'] ?? false)) {
            self::validatePluginConfig($plugin, $methodCode, $pluginConfig);
        }

        if ($channelName === '') {
            $channelName = (string)($method['name'] ?? PaymentMetaService::friendlyMethodName($methodCode));
        }

        $channelConfig = self::buildChannelConfig($method, $plugin, $pluginConfig, $displayValue, $channelName, $limits);

        if (self::canUseDatabase()) {
            $channelType = ChannelType::where('code', $methodCode)->find();
            if (!$channelType) {
                throw new BusinessException('支付方式不存在', StatusCode::NOT_FOUND);
            }

            $record = $id > 0
                ? MerchantChannel::where('merchant_id', $merchantId)->where('id', $id)->find()
                : null;

            if (!$record) {
                $record = new MerchantChannel();
                $record->merchant_id = $merchantId;
                $record->channel_type_id = (int)$channelType->id;
            }

            $record->config = $channelConfig;
            $record->rate = $rate;
            $record->daily_limit = (float)$limits['daily_limit'];
            $record->remark = $remark;
            $record->status = $statusCode;
            $record->save();

            return self::all($merchantId);
        }

        $records = self::loadRecords();
        $index = self::findRecordIndex($records, $merchantId);
        $record = $records[$index];
        $updated = false;

        foreach ($record['items'] as &$item) {
            $sameId = $id > 0 && (int)($item['id'] ?? 0) === $id;
            if (!$sameId) {
                continue;
            }

            $item = self::buildJsonItem(
                (int)($item['id'] ?? 0),
                $method,
                $plugin,
                $channelConfig,
                $rate,
                $remark,
                $statusCode
            );
            $updated = true;
            break;
        }
        unset($item);

        if ($id > 0 && !$updated) {
            throw new BusinessException('通道不存在', StatusCode::NOT_FOUND);
        }

        if (!$updated) {
            $record['items'][] = self::buildJsonItem(
                self::nextItemId($record['items']),
                $method,
                $plugin,
                $channelConfig,
                $rate,
                $remark,
                $statusCode
            );
        }

        $records[$index] = $record;
        JsonStoreService::save(self::STORE_KEY, $records);

        return self::all($merchantId);
    }

    public static function toggleItem(int $merchantId, int $id, int $statusCode): array
    {
        if (self::canUseDatabase()) {
            $record = MerchantChannel::where('merchant_id', $merchantId)->where('id', $id)->find();
            if (!$record) {
                throw new BusinessException('通道不存在', StatusCode::NOT_FOUND);
            }

            $record->status = $statusCode === 1 ? 1 : 0;
            $record->save();

            return self::all($merchantId);
        }

        $records = self::loadRecords();
        $index = self::findRecordIndex($records, $merchantId);
        $record = $records[$index];
        $found = false;

        foreach ($record['items'] as &$item) {
            if ((int)($item['id'] ?? 0) !== $id) {
                continue;
            }

            $item['status_code'] = $statusCode === 1 ? 1 : 0;
            $item['status'] = $item['status_code'] === 1 ? '启用' : '停用';
            $found = true;
            break;
        }
        unset($item);

        if (!$found) {
            throw new BusinessException('通道不存在', StatusCode::NOT_FOUND);
        }

        $records[$index] = $record;
        JsonStoreService::save(self::STORE_KEY, $records);

        return self::all($merchantId);
    }

    public static function deleteItem(int $merchantId, int $id): array
    {
        if (self::canUseDatabase()) {
            $deleted = MerchantChannel::where('merchant_id', $merchantId)->where('id', $id)->delete();
            if ($deleted === 0) {
                throw new BusinessException('通道不存在', StatusCode::NOT_FOUND);
            }

            return self::all($merchantId);
        }

        $records = self::loadRecords();
        $index = self::findRecordIndex($records, $merchantId);
        $record = $records[$index];
        $next = array_values(array_filter(
            $record['items'],
            static fn(array $item): bool => (int)($item['id'] ?? 0) !== $id
        ));

        if (count($next) === count($record['items'])) {
            throw new BusinessException('通道不存在', StatusCode::NOT_FOUND);
        }

        $record['items'] = $next;
        $records[$index] = $record;
        JsonStoreService::save(self::STORE_KEY, $records);

        return self::all($merchantId);
    }

    public static function saveRotation(int $merchantId, array $payload): array
    {
        $channelItems = self::channelItemsForRotation($merchantId);
        $current = self::rotationConfig($merchantId, $channelItems);
        $methods = self::methodOptions();
        $fallback = array_key_exists('fallback_channel', $payload)
            ? PaymentMetaService::normalizeMethodCode((string)($payload['fallback_channel'] ?? ''))
            : (string)($current['fallback_channel'] ?? '');
        if ($fallback !== '' && !self::methodExists($methods, $fallback)) {
            throw new BusinessException('兜底支付方式不存在', StatusCode::VALIDATION_ERROR);
        }

        $next = self::normalizeRotationConfig([
            'enabled' => array_key_exists('enabled', $payload)
                ? self::toBool($payload['enabled'])
                : (bool)($current['enabled'] ?? false),
            'strategy' => array_key_exists('strategy', $payload)
                ? self::normalizeRotationStrategy((string)($payload['strategy'] ?? 'priority'))
                : (string)($current['strategy'] ?? 'priority'),
            'fallback_channel' => $fallback,
            'remark' => array_key_exists('remark', $payload)
                ? trim((string)($payload['remark'] ?? ''))
                : (string)($current['remark'] ?? ''),
            'pools' => array_key_exists('pools', $payload)
                ? (array)$payload['pools']
                : (array)($current['pools'] ?? []),
        ], $merchantId, $channelItems, true, (array)($current['pools'] ?? []));

        if (self::canUseDatabase()) {
            self::saveMerchantMeta($merchantId, ['rotation' => $next]);
            return self::all($merchantId);
        }

        $records = self::loadRecords();
        $index = self::findRecordIndex($records, $merchantId);
        $record = $records[$index];
        $record['rotation'] = $next;
        $records[$index] = $record;
        JsonStoreService::save(self::STORE_KEY, $records);

        return self::all($merchantId);
    }

    public static function savePaymentSettings(int $merchantId, array $payload): array
    {
        $current = self::paymentSettings($merchantId);
        $next = self::normalizePaymentSettings(array_replace($current, [
            'template' => trim((string)($payload['template'] ?? $current['template'])),
            'auto_redirect' => self::toBool($payload['auto_redirect'] ?? $current['auto_redirect']),
            'voice_enabled' => self::toBool($payload['voice_enabled'] ?? $current['voice_enabled']),
            'voice_content' => (string)($payload['voice_content'] ?? $current['voice_content']),
            'cashier_notice' => (string)($payload['cashier_notice'] ?? $current['cashier_notice']),
        ]));

        if (self::canUseDatabase()) {
            self::saveMerchantMeta($merchantId, ['payment_settings' => $next]);
            return self::all($merchantId);
        }

        $records = self::loadRecords();
        $index = self::findRecordIndex($records, $merchantId);
        $record = $records[$index];
        $record['payment_settings'] = $next;
        $records[$index] = $record;
        JsonStoreService::save(self::STORE_KEY, $records);

        return self::all($merchantId);
    }

    public static function testItem(int $merchantId, int $id, array $payload = []): array
    {
        $channelPayload = self::all($merchantId);
        $item = null;
        foreach (($channelPayload['items'] ?? []) as $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                $item = $row;
                break;
            }
        }

        if (!is_array($item)) {
            throw new BusinessException('Channel not found', StatusCode::NOT_FOUND);
        }

        if ((int)($item['status_code'] ?? 0) !== 1) {
            throw new BusinessException('Channel is disabled', StatusCode::VALIDATION_ERROR);
        }

        $plugin = self::findPlugin((string)($item['plugin_code'] ?? ''));
        if ($plugin !== null) {
            self::validatePluginConfig(
                $plugin,
                PaymentMetaService::normalizeMethodCode((string)($item['method_code'] ?? '')),
                is_array($item['plugin_config'] ?? null) ? $item['plugin_config'] : []
            );
        }

        $methodCode = PaymentMetaService::normalizeMethodCode((string)($item['method_code'] ?? ''));
        $tradeNo = 'TST' . date('YmdHis') . random_int(100000, 999999);
        $amount = number_format((float)($payload['amount'] ?? '1.00'), 2, '.', '');
        self::assertAmountWithinLimits($item, (float)$amount);
        $subject = trim((string)($payload['subject'] ?? ($item['method_name'] ?? 'Channel test order')));
        $baseUrl = rtrim(ConfigService::gatewayBaseUrl(), '/');
        $notifyUrl = $baseUrl . '/callback/success';
        $returnUrl = $baseUrl . '/pay/checkout/' . $tradeNo;
        $displayValue = self::testOrderSourceValue($item);
        $channelCategory = PaymentMetaService::normalizeCategory((string)($item['category'] ?? ''), $methodCode);

        $orderPayload = [
            'trade_no' => $tradeNo,
            'out_trade_no' => 'test-' . $tradeNo,
            'merchant_id' => $merchantId,
            'merchant_channel_id' => $id,
            'channel_code' => $methodCode,
            'channel_category' => $channelCategory,
            'subject' => $subject,
            'amount' => $amount,
            'payable_amount' => $amount,
            'status' => 0,
            'payment_address' => $displayValue,
            'txid' => '',
            'confirmations' => 0,
            'expire_time' => date('Y-m-d H:i:s', time() + 600),
            'pay_time' => null,
            'platform_fee' => '0.00000000',
            'fee_deducted' => 0,
            'callback_status' => 0,
            'callback_count' => 0,
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'client_ip' => '127.0.0.1',
            'param' => 'channel-test',
            'request_payload' => ['_meta' => ['source_protocol' => 'channel_test']],
            'notify_payload' => [],
            'remark' => 'Channel test order',
        ];

        if (self::canUseDatabase()) {
            $order = new Order();
            $order->save($orderPayload);
        } else {
            LocalOrderStore::createOrder($orderPayload);
        }

        return [
            'pay_url' => $returnUrl,
            'trade_no' => $tradeNo,
            'method_code' => $methodCode,
            'method_name' => (string)($item['method_name'] ?? ''),
        ];
    }

    protected static function canUseDatabase(): bool
    {
        if (!database_available()) {
            return false;
        }

        try {
            MerchantChannel::where('id', '>', 0)->limit(1)->select();
            ChannelType::where('id', '>', 0)->limit(1)->select();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected static function itemsFromDatabase(int $merchantId): array
    {
        $plugins = self::pluginMap();
        $records = MerchantChannel::alias('mc')
            ->join('channel_types ct', 'ct.id = mc.channel_type_id')
            ->where('mc.merchant_id', $merchantId)
            ->field('mc.*,ct.code as type_code,ct.name as type_name,ct.category as type_category')
            ->order('mc.id', 'asc')
            ->select()
            ->toArray();

        return array_map(static function (array $item) use ($plugins): array {
            $config = is_array($item['config'] ?? null) ? $item['config'] : [];
            $methodCode = PaymentMetaService::normalizeMethodCode((string)($item['type_code'] ?? $config['method_code'] ?? ''));
            $pluginCode = PluginCodeService::normalize((string)($config['plugin_code'] ?? ''));
            $plugin = is_array($plugins[$pluginCode] ?? null) ? $plugins[$pluginCode] : [];
            $statusCode = (int)($item['status'] ?? 0);
            $limits = self::limitsFromConfig($config);
            $limits['daily_limit'] = self::formatAmount($item['daily_limit'] ?? $limits['daily_limit']);

            return self::serializeChannelItem(
                (int)($item['id'] ?? 0),
                [
                    'code' => $methodCode,
                    'name' => PaymentMetaService::safeMethodName((string)($item['type_name'] ?? ''), $methodCode),
                    'category' => self::categoryLabel((int)($item['type_category'] ?? 2)),
                    'settlement' => PaymentMetaService::defaultSettlementByCode($methodCode),
                ],
                $plugin,
                $config,
                (float)($item['rate'] ?? 0),
                (string)($item['remark'] ?? ''),
                $statusCode,
                $limits,
                (string)($config['channel_name'] ?? '')
            );
        }, $records);
    }

    protected static function itemsFromJsonStore(int $merchantId): array
    {
        $records = self::loadRecords();
        $record = self::findRecord($records, $merchantId);
        $plugins = self::pluginMap();

        return array_map(static function (array $item) use ($plugins): array {
            $methodCode = PaymentMetaService::normalizeMethodCode((string)($item['method_code'] ?? $item['code'] ?? $item['channel'] ?? ''));
            $pluginCode = PluginCodeService::normalize((string)($item['plugin_code'] ?? ''));
            $config = is_array($item['config'] ?? null) ? $item['config'] : [];
            if ($config === []) {
                $config = [
                    'method_code' => $methodCode,
                    'method_name' => (string)($item['method_name'] ?? $item['channel'] ?? PaymentMetaService::friendlyMethodName($methodCode)),
                    'plugin_code' => $pluginCode,
                    'plugin_name' => (string)($item['plugin_name'] ?? ''),
                    'plugin_kind' => (string)($item['plugin_kind'] ?? ''),
                    'display_value' => (string)($item['display_value'] ?? $item['address'] ?? ''),
                    'payment_address' => (string)($item['display_value'] ?? $item['address'] ?? ''),
                    'plugin_config' => is_array($item['plugin_config'] ?? null) ? $item['plugin_config'] : [],
                ];
            }

            $plugin = is_array($plugins[$pluginCode] ?? null) ? $plugins[$pluginCode] : [];

            return self::serializeChannelItem(
                (int)($item['id'] ?? 0),
                [
                    'code' => $methodCode,
                    'name' => PaymentMetaService::safeMethodName(
                        (string)($item['method_name'] ?? $item['channel'] ?? ''),
                        $methodCode
                    ),
                    'category' => PaymentMetaService::safeCategoryLabel(
                        (string)($item['category'] ?? PaymentMetaService::categoryLabel(PaymentMetaService::normalizeCategory('', $methodCode))),
                        $methodCode
                    ),
                    'settlement' => PaymentMetaService::safeSettlementLabel(
                        (string)($item['settlement'] ?? PaymentMetaService::defaultSettlementByCode($methodCode)),
                        $methodCode
                    ),
                ],
                $plugin,
                $config,
                self::normalizeRate($item['rate'] ?? 0),
                (string)($item['remark'] ?? ''),
                (int)($item['status_code'] ?? 0),
                self::normalizeBusinessLimits($item, $config),
                (string)($item['channel_name'] ?? $config['channel_name'] ?? '')
            );
        }, $record['items'] ?? []);
    }

    protected static function rotationConfig(int $merchantId, ?array $channelItems = null): array
    {
        if (!self::canUseDatabase()) {
            $records = self::loadRecords();
            $record = self::findRecord($records, $merchantId);
            return self::normalizeRotationConfig(
                is_array($record['rotation'] ?? null) ? $record['rotation'] : [],
                $merchantId,
                $channelItems
            );
        }

        $meta = self::merchantMetaMap();
        $stored = is_array($meta[$merchantId]['rotation'] ?? null) ? $meta[$merchantId]['rotation'] : [];

        return self::normalizeRotationConfig($stored, $merchantId, $channelItems);
    }

    protected static function normalizeRotationConfig(
        array $rotation,
        int $merchantId,
        ?array $channelItems = null,
        bool $strict = false,
        array $existingPools = []
    ): array {
        $defaults = self::emptyRecord($merchantId)['rotation'];
        $methods = self::methodOptions();
        $resolvedItems = $channelItems ?? self::channelItemsForRotation($merchantId);
        $fallback = PaymentMetaService::normalizeMethodCode((string)($rotation['fallback_channel'] ?? ''));

        if ($fallback !== '' && !self::methodExists($methods, $fallback)) {
            $fallback = '';
        }

        return [
            'enabled' => self::toBool($rotation['enabled'] ?? $defaults['enabled']),
            'strategy' => self::normalizeRotationStrategy((string)($rotation['strategy'] ?? $defaults['strategy'])),
            'fallback_channel' => $fallback,
            'remark' => trim((string)($rotation['remark'] ?? $defaults['remark'])),
            'pools' => self::normalizeRotationPools(
                (array)($rotation['pools'] ?? []),
                $methods,
                $resolvedItems,
                $existingPools,
                $strict
            ),
        ];
    }

    protected static function normalizeRotationPools(
        array $pools,
        array $methods,
        array $channelItems,
        array $existingPools = [],
        bool $strict = false
    ): array {
        $channelMap = [];
        foreach ($channelItems as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                $channelMap[$id] = $item;
            }
        }

        $usedIds = [];
        $nextId = 1;
        foreach (array_merge($existingPools, $pools) as $pool) {
            $poolId = (int)($pool['id'] ?? 0);
            if ($poolId > 0 && $poolId >= $nextId) {
                $nextId = $poolId + 1;
            }
        }

        $normalized = [];
        foreach ($pools as $pool) {
            if (!is_array($pool)) {
                continue;
            }

            $methodCode = PaymentMetaService::normalizeMethodCode((string)($pool['method_code'] ?? ''));
            if ($methodCode === '' || !self::methodExists($methods, $methodCode)) {
                if ($strict) {
                    throw new BusinessException('请选择有效的支付方式', StatusCode::VALIDATION_ERROR);
                }
                continue;
            }

            $poolId = (int)($pool['id'] ?? 0);
            if ($poolId <= 0 || isset($usedIds[$poolId])) {
                $poolId = $nextId++;
            }
            $usedIds[$poolId] = true;

            $method = self::findMethod($methodCode);
            $poolName = trim((string)($pool['pool_name'] ?? $pool['name'] ?? ''));
            if ($poolName === '') {
                $poolName = (string)($method['name'] ?? PaymentMetaService::friendlyMethodName($methodCode)) . '轮询池';
            }

            $items = self::normalizeRotationPoolItems(
                (array)($pool['items'] ?? []),
                $channelMap,
                $methodCode
            );

            $normalized[] = [
                'id' => $poolId,
                'pool_name' => $poolName,
                'method_code' => $methodCode,
                'method_name' => (string)($method['name'] ?? PaymentMetaService::friendlyMethodName($methodCode)),
                'strategy' => self::normalizeRotationStrategy((string)($pool['strategy'] ?? 'sequential')),
                'strategy_label' => self::rotationStrategyLabel((string)($pool['strategy'] ?? 'sequential')),
                'status_code' => (int)($pool['status_code'] ?? 0) === 1 ? 1 : 0,
                'status' => (int)($pool['status_code'] ?? 0) === 1 ? '启用' : '关闭',
                'items' => $items,
                'channel_count' => count($items),
            ];
        }

        return array_values($normalized);
    }

    protected static function normalizeRotationPoolItems(array $items, array $channelMap, string $methodCode): array
    {
        $normalized = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $channelId = (int)($item['channel_id'] ?? $item['id'] ?? 0);
            if ($channelId <= 0 || isset($seen[$channelId])) {
                continue;
            }

            $channel = is_array($channelMap[$channelId] ?? null) ? $channelMap[$channelId] : null;
            if (!$channel) {
                continue;
            }

            $channelMethodCode = PaymentMetaService::normalizeMethodCode((string)($channel['method_code'] ?? ''));
            if ($channelMethodCode !== $methodCode) {
                continue;
            }

            $seen[$channelId] = true;
            $normalized[] = [
                'channel_id' => $channelId,
                'channel_name' => (string)($channel['channel_name'] ?? $channel['method_name'] ?? ('通道 #' . $channelId)),
                'weight' => max(1, (int)($item['weight'] ?? 50)),
            ];
        }

        return array_values($normalized);
    }

    protected static function normalizeRotationStrategy(string $strategy): string
    {
        $normalized = strtolower(trim($strategy));

        return match ($normalized) {
            'round_robin', 'round-robin', 'round', 'rotate' => 'sequential',
            'weighted', 'weighted_random', 'weight_random', 'random' => 'weighted_random',
            'priority', 'sequence', 'sequential', 'order' => 'sequential',
            default => 'sequential',
        };
    }

    protected static function rotationStrategyLabel(string $strategy): string
    {
        return self::normalizeRotationStrategy($strategy) === 'weighted_random' ? '随机' : '顺序';
    }

    protected static function paymentSettings(int $merchantId): array
    {
        $defaults = self::emptyRecord($merchantId)['payment_settings'];

        if (!self::canUseDatabase()) {
            $records = self::loadRecords();
            $record = self::findRecord($records, $merchantId);
            return self::normalizePaymentSettings(array_replace(
                $defaults,
                is_array($record['payment_settings'] ?? null) ? $record['payment_settings'] : []
            ));
        }

        $meta = self::merchantMetaMap();
        $stored = is_array($meta[$merchantId]['payment_settings'] ?? null)
            ? $meta[$merchantId]['payment_settings']
            : [];

        return self::normalizePaymentSettings(array_replace($defaults, $stored));
    }

    protected static function normalizePaymentSettings(array $settings): array
    {
        $template = self::normalizePaymentTemplate((string)($settings['template'] ?? self::DEFAULT_PAYMENT_TEMPLATE));

        $voiceContent = self::normalizePaymentVariableText(trim((string)($settings['voice_content'] ?? '')));
        $cashierNotice = self::normalizePaymentVariableText((string)($settings['cashier_notice'] ?? ''));

        return [
            'template' => $template,
            'auto_redirect' => self::toBool($settings['auto_redirect'] ?? false),
            'voice_enabled' => self::toBool($settings['voice_enabled'] ?? true),
            'voice_content' => $voiceContent,
            'cashier_notice' => $cashierNotice,
        ];
    }

    protected static function merchantMetaMap(): array
    {
        $records = ConfigService::get('merchant_channel_meta', []);
        return is_array($records) ? $records : [];
    }

    protected static function saveMerchantMeta(int $merchantId, array $payload): void
    {
        $records = self::merchantMetaMap();
        $records[$merchantId] = array_replace_recursive(
            is_array($records[$merchantId] ?? null) ? $records[$merchantId] : [],
            $payload
        );
        ConfigService::save(['merchant_channel_meta' => $records]);
    }

    protected static function normalizeRate(mixed $rate): float
    {
        $raw = str_replace('%', '', trim((string)$rate));
        if ($raw === '') {
            return 0.0;
        }

        return round((float)$raw, 2);
    }

    protected static function formatRate(mixed $rate): string
    {
        return number_format((float)$rate, 2, '.', '') . '%';
    }

    protected static function categoryLabel(int $category): string
    {
        return match ($category) {
            1 => 'On-chain Pay',
            3 => 'International Pay',
            4 => 'QR Pay',
            default => 'Aggregate Pay',
        };
    }

    protected static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function findSerializedItem(int $merchantId, int $id): ?array
    {
        foreach (self::all($merchantId)['items'] ?? [] as $item) {
            if ((int)($item['id'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }

    private static function limitsFromConfig(array $config): array
    {
        return self::normalizeBusinessLimits($config, []);
    }

    private static function normalizeBusinessLimits(array $primary, array $fallback = []): array
    {
        return [
            'daily_limit' => self::formatAmount(self::firstValue($primary, $fallback, ['daily_limit', 'day_limit'])),
            'daily_count_limit' => max(0, (int)self::firstValue($primary, $fallback, ['daily_count_limit', 'daily_count', 'day_count_limit', 'daily_limit_count'])),
            'single_min_amount' => self::formatAmount(self::firstValue($primary, $fallback, ['single_min_amount', 'single_min', 'min_amount', 'min'])),
            'single_max_amount' => self::formatAmount(self::firstValue($primary, $fallback, ['single_max_amount', 'single_max', 'max_amount', 'max'])),
        ];
    }

    private static function firstValue(array $primary, array $fallback, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $primary) && trim((string)$primary[$key]) !== '') {
                return $primary[$key];
            }
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $fallback) && trim((string)$fallback[$key]) !== '') {
                return $fallback[$key];
            }
        }

        return '0';
    }

    private static function formatAmount(mixed $value): string
    {
        $raw = str_replace([',', ' '], '', trim((string)$value));
        if ($raw === '') {
            $raw = '0';
        }

        $amount = max(0, (float)$raw);
        return number_format($amount, 2, '.', '');
    }

    private static function assertAmountWithinLimits(array $item, float $amount): void
    {
        $min = (float)($item['single_min_amount'] ?? 0);
        $max = (float)($item['single_max_amount'] ?? 0);

        if ($min > 0 && $amount < $min) {
            throw new BusinessException('测试金额低于通道单笔最小限额', StatusCode::VALIDATION_ERROR);
        }

        if ($max > 0 && $amount > $max) {
            throw new BusinessException('测试金额超过通道单笔最大限额', StatusCode::VALIDATION_ERROR);
        }
    }

    private static function testOrderSourceValue(array $payload, int $depth = 0): string
    {
        if ($depth > 2) {
            return '';
        }

        foreach ([
            'display_value',
            'payment_address',
            'address',
            'qrcode_url',
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

            $value = self::testOrderSourceValue($nested, $depth + 1);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function methodOptions(): array
    {
        $deduped = [];
        foreach (PluginService::methods() as $item) {
            $code = PaymentMetaService::normalizeMethodCode((string)($item['code'] ?? ''));
            if ($code === '' || isset($deduped[$code])) {
                continue;
            }

            if ((int)($item['status_code'] ?? 0) !== 1) {
                continue;
            }

            $deduped[$code] = [
                'code' => $code,
                'name' => PaymentMetaService::safeMethodName((string)($item['name'] ?? ''), $code),
                'category' => PaymentMetaService::safeCategoryLabel((string)($item['category'] ?? ''), $code),
                'settlement' => PaymentMetaService::safeSettlementLabel((string)($item['settlement'] ?? ''), $code),
                'status' => '启用',
                'status_code' => 1,
            ];
        }

        return array_values($deduped);
    }

    private static function pluginOptions(): array
    {
        return array_values(array_filter(
            PluginService::plugins(),
            static fn(array $item): bool => ($item['group'] ?? 'pay') === 'pay'
        ));
    }

    private static function pluginMap(): array
    {
        $map = [];
        foreach (self::pluginOptions() as $plugin) {
            $code = PluginCodeService::normalize((string)($plugin['code'] ?? ''));
            if ($code !== '') {
                $plugin['code'] = $code;
                $map[$code] = $plugin;
            }
        }

        return $map;
    }

    private static function findMethod(string $code): ?array
    {
        foreach (self::methodOptions() as $item) {
            if (PaymentMetaService::normalizeMethodCode((string)($item['code'] ?? '')) === $code) {
                return $item;
            }
        }

        foreach (PluginService::methods() as $item) {
            if (PaymentMetaService::normalizeMethodCode((string)($item['code'] ?? '')) !== $code) {
                continue;
            }

            return [
                'code' => $code,
                'name' => PaymentMetaService::safeMethodName((string)($item['name'] ?? ''), $code),
                'category' => PaymentMetaService::safeCategoryLabel((string)($item['category'] ?? ''), $code),
                'settlement' => PaymentMetaService::safeSettlementLabel((string)($item['settlement'] ?? ''), $code),
                'status' => '启用',
                'status_code' => 1,
            ];
        }

        return null;
    }

    private static function findPlugin(string $code): ?array
    {
        foreach (self::pluginOptions() as $item) {
            if (PluginCodeService::normalize((string)($item['code'] ?? '')) === $code) {
                $item['code'] = $code;
                return $item;
            }
        }

        return null;
    }

    private static function methodExists(array $methods, string $code): bool
    {
        foreach ($methods as $method) {
            if (PaymentMetaService::normalizeMethodCode((string)($method['code'] ?? '')) === $code) {
                return true;
            }
        }

        return false;
    }

    private static function pluginSupportsMethod(array $plugin, string $methodCode): bool
    {
        foreach ((array)($plugin['payment_methods'] ?? []) as $code) {
            if (PaymentMetaService::normalizeMethodCode((string)$code) === $methodCode) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePluginConfig(mixed $config): array
    {
        if (!is_array($config)) {
            return [];
        }

        $normalized = [];
        foreach ($config as $key => $value) {
            $name = trim((string)$key);
            if ($name === '') {
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            if (is_bool($value)) {
                $normalized[$name] = $value;
                continue;
            }

            $normalized[$name] = trim((string)$value);
        }

        return $normalized;
    }

    private static function validatePluginConfig(array $plugin, string $methodCode, array $pluginConfig): void
    {
        foreach ((array)($plugin['settings_schema'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = trim((string)($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            if (!PluginSchemaService::isFieldVisible($field, $methodCode, $pluginConfig)) {
                continue;
            }

            if (!self::toBool($field['required'] ?? false)) {
                continue;
            }

            $value = $pluginConfig[$key] ?? null;
            if (is_bool($value)) {
                continue;
            }

            if (trim((string)$value) === '') {
                $label = trim((string)($field['label'] ?? $key));
                throw new BusinessException($label . ' 不能为空', StatusCode::VALIDATION_ERROR);
            }
        }
    }

    private static function buildChannelConfig(
        array $method,
        array $plugin,
        array $pluginConfig,
        string $displayValue,
        string $channelName,
        array $limits
    ): array
    {
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($method['code'] ?? ''));
        $methodName = (string)($method['name'] ?? PaymentMetaService::friendlyMethodName($methodCode));
        $display = $displayValue !== '' ? $displayValue : self::displayValueFromPluginConfig($pluginConfig);

        $normalizedPluginCode = PluginCodeService::normalize((string)($plugin['code'] ?? ''));

        $config = [
            'method_code' => $methodCode,
            'method_name' => $methodName,
            'channel_name' => $channelName !== '' ? $channelName : $methodName,
            'plugin_code' => $normalizedPluginCode,
            'plugin_name' => (string)($plugin['name'] ?? ''),
            'plugin_kind' => (string)($plugin['kind'] ?? ''),
            'display_value' => $display,
            'payment_address' => $display,
            'plugin_config' => $pluginConfig,
            'daily_limit' => self::formatAmount($limits['daily_limit'] ?? '0'),
            'daily_count_limit' => (int)($limits['daily_count_limit'] ?? 0),
            'single_min_amount' => self::formatAmount($limits['single_min_amount'] ?? '0'),
            'single_max_amount' => self::formatAmount($limits['single_max_amount'] ?? '0'),
        ];

        if (PaymentMetaService::isChainMethodCode($methodCode)) {
            $config['address'] = $display !== '' ? $display : (string)($pluginConfig['address'] ?? '');
        } else {
            $config['qrcode_url'] = $display !== '' ? $display : (string)($pluginConfig['qrcode_url'] ?? '');
        }

        return $config;
    }

    private static function displayValueFromPluginConfig(array $pluginConfig): string
    {
        foreach (['payment_address', 'display_value', 'qrcode_url', 'address', 'url', 'link'] as $key) {
            $value = trim((string)($pluginConfig[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function serializeChannelItem(
        int $id,
        array $method,
        array $plugin,
        array $config,
        float $rate,
        string $remark,
        int $statusCode,
        array $limits = [],
        string $channelName = ''
    ): array {
        $displayValue = self::displayValueFromStoredConfig($config);
        $pluginCode = PluginCodeService::normalize((string)($config['plugin_code'] ?? $plugin['code'] ?? ''));
        $pluginName = trim((string)($config['plugin_name'] ?? $plugin['name'] ?? ''));
        $pluginKind = trim((string)($config['plugin_kind'] ?? $plugin['kind'] ?? ''));
        $pluginConfig = is_array($config['plugin_config'] ?? null) ? $config['plugin_config'] : [];
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($method['code'] ?? ''));
        $methodName = PaymentMetaService::safeMethodName((string)($method['name'] ?? ''), $methodCode);
        $category = PaymentMetaService::safeCategoryLabel((string)($method['category'] ?? ''), $methodCode);
        $settlement = PaymentMetaService::safeSettlementLabel((string)($method['settlement'] ?? ''), $methodCode);
        $safeRemark = PaymentMetaService::safeRemark($remark, $methodCode);
        $limits = self::normalizeBusinessLimits($limits, $config);
        $channelName = trim($channelName);
        if ($channelName === '') {
            $channelName = trim((string)($config['channel_name'] ?? ''));
        }
        if ($channelName === '') {
            $channelName = $methodName;
        }

        $config['method_name'] = $methodName;
        $config['channel_name'] = $channelName;
        $config['daily_limit'] = $limits['daily_limit'];
        $config['daily_count_limit'] = $limits['daily_count_limit'];
        $config['single_min_amount'] = $limits['single_min_amount'];
        $config['single_max_amount'] = $limits['single_max_amount'];

        return [
            'id' => $id,
            'sequence' => $id,
            'channel' => $methodName,
            'channel_name' => $channelName,
            'method_code' => $methodCode,
            'method_name' => $methodName,
            'category' => $category,
            'settlement' => $settlement,
            'plugin_code' => $pluginCode,
            'plugin_name' => $pluginName,
            'plugin_kind' => $pluginKind,
            'plugin_config' => $pluginConfig,
            'plugin_schema' => is_array($plugin['settings_schema'] ?? null) ? $plugin['settings_schema'] : [],
            'payment_methods' => is_array($plugin['payment_methods'] ?? null) ? $plugin['payment_methods'] : [],
            'display_value' => $displayValue,
            'address' => $displayValue,
            'rate' => self::formatRate($rate),
            'daily_limit' => $limits['daily_limit'],
            'daily_count_limit' => $limits['daily_count_limit'],
            'single_min_amount' => $limits['single_min_amount'],
            'single_max_amount' => $limits['single_max_amount'],
            'status' => $statusCode === 1 ? '启用' : '停用',
            'status_code' => $statusCode === 1 ? 1 : 0,
            'remark' => $safeRemark,
            'config' => $config,
            'execution' => self::executionStatus($pluginCode, $plugin, $statusCode),
        ];
    }

    private static function executionStatus(string $pluginCode, array $plugin, int $statusCode): array
    {
        $pluginCode = PluginCodeService::normalize($pluginCode);
        $capability = PluginExecutorService::capability($pluginCode);
        $health = is_array($plugin['health'] ?? null) ? $plugin['health'] : [];
        $issues = is_array($health['issues'] ?? null) ? $health['issues'] : [];
        $level = (string)($health['level'] ?? '');
        $label = (string)($health['label'] ?? '');

        if ($statusCode !== 1) {
            $level = 'disabled';
            $label = '已停用';
            $issues[] = '通道已停用';
        } elseif ($pluginCode === '') {
            $level = 'blocked';
            $label = '不可执行';
            $issues[] = '通道未绑定支付插件';
        } elseif (!(bool)($capability['exists'] ?? false)) {
            $level = 'blocked';
            $label = '不可自动执行';
            $issues[] = in_array((string)($plugin['kind'] ?? ''), ['qrcode', 'app', 'ck'], true)
                ? '缺少可执行插件类，仅可展示静态收款信息'
                : '缺少可执行插件类';
        } elseif (!(bool)($capability['query'] ?? false)) {
            $level = 'blocked';
            $label = '不可自动查单';
            $issues[] = '支付插件未实现 query 方法';
        } elseif ($level === '') {
            $level = 'ready';
            $label = '可执行';
        }

        return [
            'level' => $level,
            'label' => $label !== '' ? $label : '可执行',
            'plugin_code' => $pluginCode,
            'class' => (string)($capability['class'] ?? ''),
            'class_exists' => (bool)($capability['exists'] ?? false),
            'query' => (bool)($capability['query'] ?? false),
            'refund' => (bool)($capability['refund'] ?? false),
            'transfer' => (bool)($capability['transfer'] ?? false),
            'issues' => array_values(array_unique(array_filter(array_map('strval', $issues)))),
        ];
    }

    private static function displayValueFromStoredConfig(array $config): string
    {
        foreach (['display_value', 'payment_address', 'address', 'qrcode_url'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function buildJsonItem(
        int $id,
        array $method,
        array $plugin,
        array $config,
        float $rate,
        string $remark,
        int $statusCode
    ): array {
        $item = self::serializeChannelItem($id, $method, $plugin, $config, $rate, $remark, $statusCode);
        $item['code'] = $item['method_code'];
        return $item;
    }

    private static function findRecord(array $records, int $merchantId): array
    {
        foreach ($records as $record) {
            if ((int)($record['merchant_id'] ?? 0) === $merchantId) {
                return $record;
            }
        }

        return self::emptyRecord($merchantId);
    }

    private static function loadRecords(): array
    {
        $records = JsonStoreService::load(self::STORE_KEY, self::defaults());
        $normalized = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $items = [];
            foreach ((array)($record['items'] ?? []) as $item) {
                if (!is_array($item) || self::isSeedChannelItem($item)) {
                    continue;
                }
                $items[] = $item;
            }

            $record['items'] = array_values($items);
            if (($record['rotation']['remark'] ?? '') === '微信不可用时切换到链上通道') {
                $record['rotation'] = self::emptyRecord((int)($record['merchant_id'] ?? 0))['rotation'];
            }

            $normalized[] = $record;
        }

        return $normalized;
    }

    private static function isSeedChannelItem(array $item): bool
    {
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($item['method_code'] ?? $item['code'] ?? $item['channel_code'] ?? ''));
        $pluginCode = PluginCodeService::normalize((string)($item['plugin_code'] ?? ''));
        $display = trim((string)($item['display_value'] ?? $item['address'] ?? $item['payment_address'] ?? ''));
        $remark = trim((string)($item['remark'] ?? ''));
        $config = is_array($item['config'] ?? null) ? $item['config'] : [];
        $pluginConfig = is_array($item['plugin_config'] ?? null) ? $item['plugin_config'] : [];

        if ($pluginCode === '') {
            $pluginCode = PluginCodeService::normalize((string)($config['plugin_code'] ?? ''));
        }

        foreach ([$config, $pluginConfig, is_array($config['plugin_config'] ?? null) ? $config['plugin_config'] : []] as $payload) {
            foreach (['display_value', 'payment_address', 'address', 'qrcode_url'] as $key) {
                $value = trim((string)($payload[$key] ?? ''));
                if ($display === '' && $value !== '') {
                    $display = $value;
                }
            }
        }

        if ($methodCode === 'wxpay') {
            return str_contains($display, 'example.com/qrcode/wechat')
                || ($pluginCode === 'wechat-qrcode' && $remark === '默认微信通道');
        }

        if ($methodCode === 'alipay' && str_contains($display, 'example.com/alipay-qrcode')) {
            return true;
        }

        if ($methodCode === 'usdttrc20' && str_contains($display, 'TRON_DEMO_COLLECTION_ADDRESS')) {
            return true;
        }

        if ($methodCode === 'usdttrc20' && $pluginCode === 'trc20') {
            return str_starts_with($display, 'TQ8JfS98Yx00000000000000000000')
                || $remark === '默认链上通道';
        }

        return false;
    }

    private static function findRecordIndex(array &$records, int $merchantId): int
    {
        foreach ($records as $index => $record) {
            if ((int)($record['merchant_id'] ?? 0) === $merchantId) {
                return $index;
            }
        }

        $records[] = self::emptyRecord($merchantId);
        return count($records) - 1;
    }

    private static function emptyRecord(int $merchantId): array
    {
        return [
            'merchant_id' => $merchantId,
            'items' => [],
            'rotation' => [
                'enabled' => false,
                'strategy' => 'priority',
                'fallback_channel' => '',
                'remark' => '',
                'pools' => [],
            ],
            'payment_settings' => [
                'template' => self::DEFAULT_PAYMENT_TEMPLATE,
                'auto_redirect' => false,
                'voice_enabled' => true,
                'voice_content' => '',
                'cashier_notice' => '',
            ],
        ];
    }

    protected static function normalizePaymentTemplate(string $template): string
    {
        $normalized = trim($template);
        if ($normalized === '') {
            return self::DEFAULT_PAYMENT_TEMPLATE;
        }

        return self::PAYMENT_TEMPLATE_ALIAS_MAP[$normalized] ?? $normalized;
    }

    protected static function normalizePaymentVariableText(string $content): string
    {
        if ($content === '') {
            return '';
        }

        return strtr($content, self::PAYMENT_VARIABLE_ALIAS_MAP);
    }

    private static function nextItemId(array $items): int
    {
        $ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function defaults(): array
    {
        return [];
    }

}
