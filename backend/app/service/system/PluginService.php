<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\model\MerchantChannel;
use app\model\ChannelType;
use app\service\payment\PluginNotifyLogService;
use app\service\payment\PluginExecutorService;
use app\service\payment\OrderService;
use think\facade\Db;
use Throwable;

/**
 * Database-first payment method and plugin registry.
 * Falls back to local JSON only when the database is unavailable.
 */
class PluginService
{
    private const STORE_KEY = 'plugins';
    private const METHOD_STORE_KEY = 'payment_methods';
    private const PLUGIN_ROOT = 'plugins';
    private const DELETED_PLUGIN_CODES_KEY = 'deleted_plugin_codes';
    private const DELETED_METHOD_CODES_KEY = 'deleted_payment_method_codes';

    public static function all(): array
    {
        self::ensureRuntimeCatalog();
        $items = self::plugins();

        return [
            'items' => $items,
            'methods' => self::methods(),
            'plugin_settings' => self::paymentPluginSettings(),
            'schema_audit_summary' => self::schemaAuditSummary($items),
        ];
    }

    public static function adminCatalog(): array
    {
        self::ensureRuntimeCatalog();

        return [
            'items' => array_map(
                static fn(array $item): array => self::pluginManagementRow($item),
                self::plugins()
            ),
            'methods' => self::methods(),
        ];
    }

    public static function scan(): array
    {
        PluginRuntimeService::refreshDiscoveryCache();

        $discovered = self::discoverPlugins();
        $existing = self::plugins();
        $merged = self::normalizePluginList(self::mergePlugins($existing, $discovered));
        PluginRuntimeService::ensureSettingsStorage($merged);
        self::syncPaymentMethodsFromPlugins($merged);

        if (self::canUsePluginTable()) {
            $allowedCodes = self::payPluginCodeMap();
            if ($allowedCodes !== []) {
                Db::table('plugins')->whereNotIn('code', array_keys($allowedCodes))->delete();
            }

            foreach ($merged as $item) {
                $code = trim((string)($item['code'] ?? ''));
                if ($code === '') {
                    continue;
                }

                $payload = [
                    'name' => trim((string)($item['name'] ?? $code)),
                    'version' => trim((string)($item['version'] ?? '1.0.0')),
                    'status' => (int)($item['status_code'] ?? 0) === 1 ? 1 : 0,
                ];

                $existingRecord = Db::table('plugins')->where('code', $code)->find();
                if ($existingRecord) {
                    Db::table('plugins')->where('code', $code)->update($payload);
                } else {
                    Db::table('plugins')->insert($payload + [
                        'code' => $code,
                        'installed_at' => trim((string)($item['installed_at'] ?? date('Y-m-d H:i:s'))),
                    ]);
                }

                self::savePluginDescription($code, trim((string)($item['description'] ?? '')));
            }
        }

        JsonStoreService::save(self::STORE_KEY, $merged);

        return [
            'scanned' => count($discovered),
            'items' => self::pluginManagementRows($merged),
        ];
    }

    public static function save(array $payload): array
    {
        self::ensureRuntimeCatalog();

        $code = trim((string)($payload['code'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $version = trim((string)($payload['version'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));

        if ($code === '' || $name === '' || $version === '') {
            throw new BusinessException('插件编码、名称、版本不能为空', StatusCode::VALIDATION_ERROR);
        }

        if (!isset(self::payPluginCodeMap()[$code])) {
            throw new BusinessException('仅支持支付类插件', StatusCode::VALIDATION_ERROR);
        }

        self::markPluginDeleted($code, false);

        if (self::canUsePluginTable()) {
            $existing = Db::table('plugins')->where('code', $code)->find();
            if ($existing) {
                Db::table('plugins')->where('code', $code)->update([
                    'name' => $name,
                    'version' => $version,
                ]);
            } else {
                Db::table('plugins')->insert([
                    'code' => $code,
                    'name' => $name,
                    'version' => $version,
                    'status' => 0,
                    'installed_at' => date('Y-m-d H:i:s'),
                ]);
            }

            self::savePluginDescription($code, $description);
            return ['items' => self::pluginManagementRows(self::plugins())];
        }

        $items = self::localPluginRows();
        $updated = false;
        foreach ($items as &$item) {
            if (($item['code'] ?? '') === $code) {
                $item['name'] = $name;
                $item['version'] = $version;
                $item['description'] = $description !== '' ? $description : ($item['description'] ?? '');
                $updated = true;
                break;
            }
        }
        unset($item);

        if (!$updated) {
            $items[] = [
                'code' => $code,
                'name' => $name,
                'version' => $version,
                'status' => '停用',
                'status_code' => 0,
                'description' => $description !== '' ? $description : '新建插件',
                'installed_at' => date('Y-m-d H:i:s'),
            ];
        }

        JsonStoreService::save(self::STORE_KEY, $items);
        self::syncEnabledCodesSetting($code, false);
        return ['items' => self::pluginManagementRows(self::plugins())];
    }

    public static function saveRuntimeSettings(array $payload): array
    {
        self::ensureRuntimeCatalog();

        $code = PluginCodeService::normalize((string)($payload['code'] ?? ''));
        if ($code === '') {
            throw new BusinessException('缺少插件编码', StatusCode::VALIDATION_ERROR);
        }

        if (!isset(self::payPluginCodeMap()[$code])) {
            throw new BusinessException('支付插件不存在', StatusCode::NOT_FOUND);
        }

        $settings = $payload['settings'] ?? $payload['plugin_settings'] ?? [];
        if (!is_array($settings)) {
            throw new BusinessException('插件配置格式错误', StatusCode::VALIDATION_ERROR);
        }

        $definition = PluginRuntimeService::discoverMap()[$code] ?? null;
        if (!is_array($definition)) {
            throw new BusinessException('插件定义不存在', StatusCode::NOT_FOUND);
        }

        $allowedKeys = self::runtimeSettingAllowedKeyMap($definition);
        if ($allowedKeys === []) {
            throw new BusinessException('插件未声明可配置字段', StatusCode::VALIDATION_ERROR);
        }

        $sanitized = [];
        foreach ($settings as $key => $value) {
            $name = trim((string)$key);
            if ($name === '' || !isset($allowedKeys[$name])) {
                continue;
            }

            $sanitized[$name] = self::normalizeRuntimeSettingValue($value);
        }

        $saved = PluginRuntimeService::saveSettings($code, $sanitized);
        PluginRuntimeService::invalidateSettingsCache();
        return [
            'code' => $code,
            'settings' => $saved,
            'items' => self::plugins(),
        ];
    }

    public static function diagnosePlugin(string $code): array
    {
        self::ensureRuntimeCatalog();

        $code = PluginCodeService::normalize($code);
        if ($code === '') {
            throw new BusinessException('缺少插件编码', StatusCode::VALIDATION_ERROR);
        }

        $plugin = null;
        foreach (self::plugins() as $item) {
            if (PluginCodeService::normalize((string)($item['code'] ?? '')) === $code) {
                $plugin = $item;
                break;
            }
        }

        if ($plugin === null) {
            throw new BusinessException('支付插件不存在', StatusCode::NOT_FOUND);
        }

        $settings = PluginRuntimeService::settingsFor($code);
        $schema = is_array($plugin['settings_schema'] ?? null) ? $plugin['settings_schema'] : [];
        $schemaFields = self::schemaFields($schema);
        $missingRequired = self::missingRequiredSettings(
            $schema,
            $settings,
            is_array($plugin['payment_methods'] ?? null) ? $plugin['payment_methods'] : []
        );

        return [
            'plugin' => [
                'code' => (string)($plugin['code'] ?? ''),
                'name' => (string)($plugin['name'] ?? ''),
                'version' => (string)($plugin['version'] ?? ''),
                'status_code' => (int)($plugin['status_code'] ?? 0),
                'kind' => (string)($plugin['kind'] ?? ''),
                'payment_methods' => is_array($plugin['payment_methods'] ?? null) ? $plugin['payment_methods'] : [],
                'capabilities' => is_array($plugin['capabilities'] ?? null) ? $plugin['capabilities'] : [],
            ],
            'health' => is_array($plugin['health'] ?? null) ? $plugin['health'] : [],
            'schema_audit' => is_array($plugin['schema_audit'] ?? null) ? $plugin['schema_audit'] : [],
            'runtime_supports' => PluginExecutorService::capability($code),
            'settings' => [
                'field_count' => count($schemaFields),
                'configured_count' => count(array_filter($settings, static fn($value): bool => !self::settingValueMissing($value))),
                'missing_required' => $missingRequired,
                'masked_values' => self::maskedSettings($settings),
            ],
            'channels' => self::pluginChannelSummary($code),
            'tasks' => self::pluginTaskSummary($code),
            'notify_logs' => self::pluginNotifySummary($code),
        ];
    }

    public static function toggle(string $code, int $statusCode): array
    {
        self::ensureRuntimeCatalog();

        if ($code === '') {
            throw new BusinessException('缺少插件编码', StatusCode::VALIDATION_ERROR);
        }

        if (!isset(self::payPluginCodeMap()[$code])) {
            throw new BusinessException('仅支持支付类插件', StatusCode::VALIDATION_ERROR);
        }

        if (self::canUsePluginTable()) {
            $updated = Db::table('plugins')
                ->where('code', $code)
                ->update(['status' => $statusCode === 1 ? 1 : 0]);

            if ($updated === 0 && !Db::table('plugins')->where('code', $code)->find()) {
                throw new BusinessException('插件不存在', StatusCode::NOT_FOUND);
            }

            OrderService::flushMerchantChannelCache();
            return ['items' => self::pluginManagementRows(self::plugins())];
        }

        $items = self::localPluginRows();
        $found = false;
        foreach ($items as &$item) {
            if (($item['code'] ?? '') === $code) {
                $item['status_code'] = $statusCode === 1 ? 1 : 0;
                $item['status'] = $item['status_code'] === 1 ? '启用' : '停用';
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            throw new BusinessException('插件不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::STORE_KEY, $items);
        OrderService::flushMerchantChannelCache();
        return ['items' => self::pluginManagementRows(self::plugins())];
    }

    public static function delete(string $code): array
    {
        self::ensureRuntimeCatalog();

        if ($code === '') {
            throw new BusinessException('缺少插件编码', StatusCode::VALIDATION_ERROR);
        }

        if (!isset(self::payPluginCodeMap()[$code])) {
            throw new BusinessException('仅支持支付类插件', StatusCode::VALIDATION_ERROR);
        }

        if (self::canUsePluginTable()) {
            $deleted = Db::table('plugins')->where('code', $code)->delete();
            self::deletePluginDescription($code);
            self::clearPluginRuntimeSettings($code);
            PluginRuntimeService::invalidateSettingsCache();

            if ($deleted === 0) {
                throw new BusinessException('插件不存在', StatusCode::NOT_FOUND);
            }

            self::markPluginDeleted($code, true);

            return ['items' => self::pluginManagementRows(self::plugins())];
        }

        $items = self::localPluginRows();
        $next = array_values(array_filter($items, static fn(array $item): bool => ($item['code'] ?? '') !== $code));
        if (count($next) === count($items)) {
            throw new BusinessException('插件不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::STORE_KEY, $next);
        self::deletePluginDescription($code);
        self::clearPluginRuntimeSettings($code);
        PluginRuntimeService::invalidateSettingsCache();
        self::markPluginDeleted($code, true);
        return ['items' => self::pluginManagementRows(self::plugins())];
    }

    public static function saveMethod(array $payload): array
    {
        self::ensureRuntimeCatalog();

        $code = PaymentMetaService::normalizeMethodCode((string)($payload['code'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $categoryLabel = trim((string)($payload['category'] ?? ''));
        $settlement = trim((string)($payload['settlement'] ?? ''));

        if ($code === '' || $name === '') {
            throw new BusinessException('支付方式标识和名称不能为空', StatusCode::VALIDATION_ERROR);
        }

        self::markMethodDeleted($code, false);

        if (self::canUseChannelTypeTable()) {
            $category = PaymentMetaService::normalizeCategory($categoryLabel, $code);
            $existing = ChannelType::where('code', $code)->find();
            $record = [
                'code' => $code,
                'name' => $name,
                'category' => $category,
                'icon' => '',
                'status' => $existing ? (int)$existing->status : 1,
                'sort' => $existing ? (int)$existing->sort : self::nextChannelSort(),
                'config_schema' => self::buildConfigSchemaByCategory($category),
            ];

            if ($existing) {
                $existing->save($record);
            } else {
                $model = new ChannelType();
                $model->save($record);
            }

            self::saveMethodMeta($code, [
                'settlement' => $settlement !== '' ? $settlement : PaymentMetaService::defaultSettlementByCode($code),
            ]);

            return ['items' => self::methods()];
        }

        $items = self::localMethodRows();
        $updated = false;
        foreach ($items as &$item) {
            if (($item['code'] ?? '') === $code) {
                $item['name'] = $name;
                $item['category'] = $categoryLabel !== '' ? $categoryLabel : ($item['category'] ?? '聚合支付');
                $item['settlement'] = $settlement !== '' ? $settlement : ($item['settlement'] ?? 'T+0');
                $updated = true;
                break;
            }
        }
        unset($item);

        if (!$updated) {
            $items[] = [
                'code' => $code,
                'name' => $name,
                'category' => $categoryLabel !== '' ? $categoryLabel : PaymentMetaService::categoryLabel(PaymentMetaService::normalizeCategory('', $code)),
                'settlement' => $settlement !== '' ? $settlement : PaymentMetaService::defaultSettlementByCode($code),
                'status' => '启用',
                'status_code' => 1,
            ];
        }

        JsonStoreService::save(self::METHOD_STORE_KEY, $items);
        return ['items' => $items];
    }

    public static function toggleMethod(string $code, int $statusCode): array
    {
        self::ensureRuntimeCatalog();

        $code = PaymentMetaService::normalizeMethodCode($code);
        if ($code === '') {
            throw new BusinessException('缺少支付方式标识', StatusCode::VALIDATION_ERROR);
        }

        if (self::canUseChannelTypeTable()) {
            $updated = ChannelType::where('code', $code)->update(['status' => $statusCode === 1 ? 1 : 0]);
            if ($updated === 0 && !ChannelType::where('code', $code)->find()) {
                throw new BusinessException('支付方式不存在', StatusCode::NOT_FOUND);
            }

            return ['items' => self::methods()];
        }

        $items = self::localMethodRows();
        $found = false;
        foreach ($items as &$item) {
            if (($item['code'] ?? '') === $code) {
                $item['status_code'] = $statusCode === 1 ? 1 : 0;
                $item['status'] = $item['status_code'] === 1 ? '启用' : '停用';
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            throw new BusinessException('支付方式不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::METHOD_STORE_KEY, $items);
        return ['items' => $items];
    }

    public static function deleteMethod(string $code): array
    {
        self::ensureRuntimeCatalog();

        $code = PaymentMetaService::normalizeMethodCode($code);
        if ($code === '') {
            throw new BusinessException('缺少支付方式标识', StatusCode::VALIDATION_ERROR);
        }

        if (self::canUseChannelTypeTable()) {
            $deleted = ChannelType::where('code', $code)->delete();
            self::deleteMethodMeta($code);

            if ($deleted === 0) {
                throw new BusinessException('支付方式不存在', StatusCode::NOT_FOUND);
            }

            self::markMethodDeleted($code, true);

            return ['items' => self::methods()];
        }

        $items = self::localMethodRows();
        $next = array_values(array_filter($items, static fn(array $item): bool => ($item['code'] ?? '') !== $code));
        if (count($next) === count($items)) {
            throw new BusinessException('支付方式不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::METHOD_STORE_KEY, $next);
        self::deleteMethodMeta($code);
        self::markMethodDeleted($code, true);
        return ['items' => $next];
    }

    public static function methods(): array
    {
        self::ensureRuntimeCatalog();

        if (self::canUseChannelTypeTable()) {
            $metaMap = self::methodMetaMap();
            $records = ChannelType::order('sort', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();

            if ($records === []) {
                return self::storedMethods();
            }

            return self::dedupeMethods(array_map(function (array $item) use ($metaMap): array {
                $code = (string)($item['code'] ?? '');
                $statusCode = (int)($item['status'] ?? 0);
                $meta = $metaMap[$code] ?? [];

                return [
                    'code' => $code,
                    'name' => PaymentMetaService::safeMethodName((string)($item['name'] ?? ''), $code),
                    'category' => PaymentMetaService::safeCategoryLabel(
                        PaymentMetaService::categoryLabel((int)($item['category'] ?? 2)),
                        $code
                    ),
                    'settlement' => PaymentMetaService::safeSettlementLabel(
                        (string)($meta['settlement'] ?? PaymentMetaService::defaultSettlementByCode($code)),
                        $code
                    ),
                    'status' => $statusCode === 1 ? '启用' : '停用',
                    'status_code' => $statusCode,
                ];
            }, $records));
        }

        $items = self::localMethodRows();

        return self::dedupeMethods(array_map(function (array $item): array {
            $code = PaymentMetaService::normalizeMethodCode((string)($item['code'] ?? ''));
            $statusCode = (int)($item['status_code'] ?? 0) === 1 ? 1 : 0;

            return [
                'code' => $code,
                'name' => PaymentMetaService::safeMethodName((string)($item['name'] ?? ''), $code),
                'category' => PaymentMetaService::safeCategoryLabel((string)($item['category'] ?? ''), $code),
                'settlement' => PaymentMetaService::safeSettlementLabel((string)($item['settlement'] ?? ''), $code),
                'status' => $statusCode === 1 ? '启用' : '停用',
                'status_code' => $statusCode,
            ];
        }, $items));
    }

    public static function plugins(): array
    {
        self::ensureRuntimeCatalog();

        $allowedCodes = self::payPluginCodeMap();
        $deletedCodes = self::deletedPluginCodeMap();
        if (self::canUsePluginTable()) {
            $descriptionMap = self::pluginDescriptionMap();
            $records = Db::table('plugins')
                ->order('installed_at', 'desc')
                ->order('id', 'desc')
                ->get()
                ->toArray();

            $records = array_values(array_filter($records, static function ($item) use ($allowedCodes, $deletedCodes): bool {
                $code = (string)((array)$item)['code'] ?? '';
                return $code !== '' && isset($allowedCodes[$code]) && !isset($deletedCodes[$code]);
            }));

            if ($records === []) {
                return self::storedPlugins($allowedCodes, $deletedCodes);
            }

            $definitionMap = PluginRuntimeService::discoverMap();
            return self::normalizePluginList(array_map(function ($item) use ($descriptionMap, $definitionMap): array {
                $record = (array)$item;
                $code = (string)($record['code'] ?? '');
                $statusCode = (int)($record['status'] ?? 0);
                $definition = is_array($definitionMap[$code] ?? null) ? $definitionMap[$code] : [];

                return [
                    'code' => $code,
                    'name' => (string)($record['name'] ?? ''),
                    'version' => (string)($record['version'] ?? ''),
                    'status' => $statusCode === 1 ? '启用' : '停用',
                    'status_code' => $statusCode,
                    'description' => (string)($descriptionMap[$code] ?? ''),
                    'installed_at' => (string)($record['installed_at'] ?? ''),
                    'group' => (string)($definition['group'] ?? ''),
                    'kind' => (string)($definition['kind'] ?? ''),
                'developer' => PaymentMetaService::safeDeveloperName((string)($definition['developer'] ?? '')),
                'capabilities' => $definition['capabilities'] ?? [],
                'payment_methods' => PaymentMetaService::normalizeMethodList($definition['payment_methods'] ?? []),
                'config_panel' => trim((string)($definition['config_panel'] ?? 'generic')),
                'default_settings' => $definition['default_settings'] ?? [],
                'settings_schema' => $definition['settings_schema'] ?? [],
            ];
            }, $records));
        }

        return self::storedPlugins($allowedCodes, $deletedCodes);
    }

    protected static function ensureRuntimeCatalog(): void
    {
        static $bootstrapped = false;
        static $bootstrapping = false;

        if ($bootstrapped || $bootstrapping) {
            return;
        }

        $bootstrapping = true;

        try {
            $definitions = self::discoverPlugins();
            PluginRuntimeService::ensureSettingsStorage($definitions);

            $storedPlugins = JsonStoreService::load(self::STORE_KEY, []);
            if ($storedPlugins === [] && $definitions !== []) {
                self::scan();
            }

            $storedMethods = JsonStoreService::load(self::METHOD_STORE_KEY, []);
            if ($storedMethods === [] && $definitions !== []) {
                self::syncPaymentMethodsFromPlugins($definitions);
            }
            self::normalizeStoredPaymentMethods();
            $bootstrapped = true;
        } finally {
            $bootstrapping = false;
        }
    }

    protected static function storedMethods(): array
    {
        $items = self::localMethodRows();

        return self::dedupeMethods(array_map(function (array $item): array {
            $code = self::normalizeMethodCode((string)($item['code'] ?? ''));
            $statusCode = (int)($item['status_code'] ?? 0) === 1 ? 1 : 0;

            return [
                'code' => $code,
                'name' => PaymentMetaService::safeMethodName((string)($item['name'] ?? ''), $code),
                'category' => PaymentMetaService::safeCategoryLabel((string)($item['category'] ?? ''), $code),
                'settlement' => PaymentMetaService::safeSettlementLabel((string)($item['settlement'] ?? ''), $code),
                'status' => $statusCode === 1 ? '启用' : '停用',
                'status_code' => $statusCode,
            ];
        }, $items));
    }

    protected static function storedPlugins(array $allowedCodes, array $deletedCodes): array
    {
        $items = self::localPluginRows();
        $runtimeSettings = PluginRuntimeService::storedSettings();

        $items = array_map(static function (array $item) use ($runtimeSettings): array {
            $code = PluginCodeService::normalize((string)($item['code'] ?? ''));
            $settings = is_array($runtimeSettings[$code] ?? null) ? $runtimeSettings[$code] : [];

            if (
                $code !== ''
                && array_key_exists('enabled', $settings)
                && in_array(strtolower(trim((string)$settings['enabled'])), ['1', 'true', 'yes', 'on'], true)
            ) {
                $item['status'] = '启用';
                $item['status_code'] = 1;
            }

            return $item;
        }, $items);

        return self::normalizePluginList(array_values(array_filter($items, static function (array $item) use ($allowedCodes, $deletedCodes): bool {
            $code = (string)($item['code'] ?? '');
            return $code !== '' && isset($allowedCodes[$code]) && !isset($deletedCodes[$code]);
        })));
    }

    protected static function discoverPlugins(): array
    {
        $deletedCodes = self::deletedPluginCodeMap();

        return array_values(array_filter(
            PluginRuntimeService::discoverDefinitions(),
            static fn(array $definition): bool => ($definition['group'] ?? '') === 'pay'
                && !isset($deletedCodes[(string)($definition['code'] ?? '')])
        ));
    }

    protected static function localPluginRows(): array
    {
        $stored = JsonStoreService::load(self::STORE_KEY, []);
        $definitions = self::discoverPlugins();

        if ($stored !== []) {
            if ($definitions === []) {
                return $stored;
            }

            $rows = self::normalizePluginList(self::mergePlugins($stored, $definitions));
            if ($rows !== $stored) {
                JsonStoreService::save(self::STORE_KEY, $rows);
            }

            return $rows;
        }

        if ($definitions !== []) {
            $rows = self::normalizePluginList($definitions);
            JsonStoreService::save(self::STORE_KEY, $rows);
            return $rows;
        }

        return [];
    }

    protected static function localMethodRows(): array
    {
        $stored = JsonStoreService::load(self::METHOD_STORE_KEY, []);
        if ($stored !== []) {
            return $stored;
        }

        $methods = [];
        foreach (self::discoverPlugins() as $definition) {
            foreach ((array)($definition['payment_methods'] ?? []) as $methodCode) {
                $code = PaymentMetaService::normalizeMethodCode((string)$methodCode);
                if ($code === '') {
                    continue;
                }

                $methods[$code] = [
                    'code' => $code,
                    'name' => PaymentMetaService::friendlyMethodName($code),
                    'category' => PaymentMetaService::categoryLabel(PaymentMetaService::normalizeCategory('', $code)),
                    'settlement' => PaymentMetaService::defaultSettlementByCode($code),
                    'status' => '启用',
                    'status_code' => 1,
                ];
            }
        }

        $rows = array_values($methods);
        if ($rows !== []) {
            JsonStoreService::save(self::METHOD_STORE_KEY, $rows);
        }

        return $rows;
    }

    protected static function normalizeStoredPaymentMethods(): void
    {
        if (self::canUseChannelTypeTable()) {
            return;
        }

        $stored = JsonStoreService::load(self::METHOD_STORE_KEY, []);
        if ($stored === []) {
            return;
        }

        $normalized = [];
        $changed = false;

        foreach ($stored as $item) {
            if (!is_array($item)) {
                $changed = true;
                continue;
            }

            $code = PaymentMetaService::normalizeMethodCode((string)($item['code'] ?? ''));
            if ($code === '') {
                $changed = true;
                continue;
            }

            $statusCode = (int)($item['status_code'] ?? 0) === 1 ? 1 : 0;
            $next = [
                'code' => $code,
                'name' => PaymentMetaService::safeMethodName((string)($item['name'] ?? ''), $code),
                'category' => PaymentMetaService::safeCategoryLabel((string)($item['category'] ?? ''), $code),
                'settlement' => PaymentMetaService::safeSettlementLabel((string)($item['settlement'] ?? ''), $code),
                'status' => $statusCode === 1 ? '启用' : '停用',
                'status_code' => $statusCode,
            ];

            if ($next !== $item) {
                $changed = true;
            }

            $normalized[] = $next;
        }

        $deduped = self::dedupeMethods($normalized);
        if (!$changed && $deduped === $normalized) {
            return;
        }

        JsonStoreService::save(self::METHOD_STORE_KEY, $deduped);
    }

    protected static function mergePlugins(array $existing, array $discovered): array
    {
        $allowedCodes = self::payPluginCodeMap();
        $map = [];
        foreach ($existing as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code = (string)($item['code'] ?? '');
            if ($code === '' || !isset($allowedCodes[$code])) {
                continue;
            }

            $map[$code] = $item;
        }

        foreach ($discovered as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code === '') {
                continue;
            }

            if (isset($map[$code])) {
                $map[$code] = array_replace([
                    'code' => $code,
                    'name' => $code,
                    'version' => '1.0.0',
                    'status' => '停用',
                    'status_code' => 0,
                    'description' => '',
                    'installed_at' => date('Y-m-d H:i:s'),
                    'group' => '',
                    'kind' => 'general',
                    'developer' => '',
                    'capabilities' => [],
                    'payment_methods' => [],
                    'display_payment_methods' => [],
                    'transfer_methods' => [],
                    'config_panel' => 'generic',
                    'default_settings' => [],
                    'settings_schema' => [],
                ], $map[$code], [
                    'name' => $item['name'] !== '' ? $item['name'] : ($map[$code]['name'] ?? $code),
                    'version' => $item['version'] !== '' ? $item['version'] : ($map[$code]['version'] ?? '1.0.0'),
                    'description' => $item['description'] !== '' ? $item['description'] : ($map[$code]['description'] ?? ''),
                    'group' => $item['group'] ?? ($map[$code]['group'] ?? ''),
                    'kind' => $item['kind'] ?? ($map[$code]['kind'] ?? 'general'),
                    'developer' => $item['developer'] ?? ($map[$code]['developer'] ?? ''),
                    'capabilities' => $item['capabilities'] ?? ($map[$code]['capabilities'] ?? []),
                    'payment_methods' => $item['payment_methods'] ?? ($map[$code]['payment_methods'] ?? []),
                    'display_payment_methods' => $item['display_payment_methods'] ?? ($map[$code]['display_payment_methods'] ?? []),
                    'transfer_methods' => $item['transfer_methods'] ?? ($map[$code]['transfer_methods'] ?? []),
                    'config_panel' => $item['config_panel'] ?? ($map[$code]['config_panel'] ?? 'generic'),
                    'default_settings' => $item['default_settings'] ?? ($map[$code]['default_settings'] ?? []),
                    'settings_schema' => $item['settings_schema'] ?? ($map[$code]['settings_schema'] ?? []),
                ]);
                continue;
            }

            $map[$code] = $item;
        }

        return array_values($map);
    }

    protected static function payPluginCodeMap(): array
    {
        $map = [];
        foreach (PluginRuntimeService::discoverMap() as $code => $definition) {
            if (($definition['group'] ?? '') === 'pay') {
                $map[$code] = true;
            }
        }

        return $map;
    }

    protected static function paymentPluginSettings(): array
    {
        $settings = PluginRuntimeService::storedSettings();
        if (!is_array($settings)) {
            return [];
        }

        $allowed = self::payPluginCodeMap();
        $deletedCodes = self::deletedPluginCodeMap();
        $filtered = [];
        foreach ($settings as $code => $item) {
            if (isset($allowed[$code]) && !isset($deletedCodes[$code]) && is_array($item)) {
                $filtered[$code] = $item;
            }
        }

        return $filtered;
    }

    protected static function syncPaymentMethodsFromPlugins(array $definitions): void
    {
        $methods = [];
        $deletedCodes = self::deletedMethodCodeMap();
        foreach ($definitions as $definition) {
            if (!is_array($definition) || ($definition['group'] ?? '') !== 'pay') {
                continue;
            }

            foreach ((array)($definition['payment_methods'] ?? []) as $methodCode) {
                $code = PaymentMetaService::normalizeMethodCode((string)$methodCode);
                if ($code === '' || isset($deletedCodes[$code])) {
                    continue;
                }

                $methods[$code] = [
                    'code' => $code,
                    'name' => PaymentMetaService::friendlyMethodName($code),
                    'category' => PaymentMetaService::categoryLabel(PaymentMetaService::normalizeCategory('', $code)),
                    'settlement' => PaymentMetaService::defaultSettlementByCode($code),
                ];
            }
        }

        foreach ($methods as $payload) {
            self::saveMethod($payload);
        }
    }

    protected static function dedupeMethods(array $items): array
    {
        $deduped = [];
        $deletedCodes = self::deletedMethodCodeMap();

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code = PaymentMetaService::normalizeMethodCode((string)($item['code'] ?? ''));
            if ($code === '' || isset($deletedCodes[$code])) {
                continue;
            }

            $normalized = [
                'code' => $code,
                'name' => PaymentMetaService::safeMethodName((string)($item['name'] ?? ''), $code),
                'category' => PaymentMetaService::safeCategoryLabel((string)($item['category'] ?? ''), $code),
                'settlement' => PaymentMetaService::safeSettlementLabel((string)($item['settlement'] ?? ''), $code),
                'status' => (int)($item['status_code'] ?? 0) === 1 ? '启用' : '停用',
                'status_code' => (int)($item['status_code'] ?? 0) === 1 ? 1 : 0,
            ];

            if (!isset($deduped[$code])) {
                $deduped[$code] = $normalized;
                continue;
            }

            $current = $deduped[$code];
            if ($current['status_code'] !== 1 && $normalized['status_code'] === 1) {
                $deduped[$code] = $normalized;
                continue;
            }

            if (
                ($current['category'] ?? '') === '聚合支付'
                && ($normalized['category'] ?? '') !== '聚合支付'
            ) {
                $deduped[$code] = $normalized + ['status' => $current['status'], 'status_code' => $current['status_code']];
            }
        }

        return array_values($deduped);
    }

    protected static function normalizePluginList(array $items): array
    {
        $runtimeSettings = PluginRuntimeService::storedSettings();
        $runtimeSettings = is_array($runtimeSettings) ? $runtimeSettings : [];

        return array_values(array_map(function (array $item) use ($runtimeSettings): array {
            $statusCode = (int)($item['status_code'] ?? 0) === 1 ? 1 : 0;
            $capabilities = is_array($item['capabilities'] ?? null) ? array_values($item['capabilities']) : [];
            $paymentMethods = PaymentMetaService::normalizeMethodList($item['payment_methods'] ?? []);
            $displayPaymentMethods = PaymentMetaService::normalizeMethodList($item['display_payment_methods'] ?? $paymentMethods);
            $transferMethods = PaymentMetaService::normalizeMethodList($item['transfer_methods'] ?? []);
            $defaultSettings = is_array($item['default_settings'] ?? null) ? $item['default_settings'] : [];
            $settingsSchema = is_array($item['settings_schema'] ?? null) ? $item['settings_schema'] : [];

            $normalized = [
                'code' => trim((string)($item['code'] ?? '')),
                'name' => trim((string)($item['name'] ?? '')),
                'version' => trim((string)($item['version'] ?? '')),
                'status' => $statusCode === 1 ? '启用' : '停用',
                'status_code' => $statusCode,
                'description' => trim((string)($item['description'] ?? '')),
                'installed_at' => trim((string)($item['installed_at'] ?? '')),
                'group' => trim((string)($item['group'] ?? 'pay')),
                'kind' => trim((string)($item['kind'] ?? 'general')),
                'developer' => PaymentMetaService::safeDeveloperName((string)($item['developer'] ?? '')),
                'capabilities' => $capabilities,
                'payment_methods' => $paymentMethods,
                'display_payment_methods' => $displayPaymentMethods,
                'transfer_methods' => $transferMethods,
                'config_panel' => trim((string)($item['config_panel'] ?? 'generic')),
                'default_settings' => $defaultSettings,
                'settings_schema' => $settingsSchema,
            ];

            $normalized['schema_audit'] = self::pluginSchemaAudit($normalized, $runtimeSettings);
            $normalized['health'] = self::pluginHealth($normalized, $runtimeSettings);
            return $normalized;
        }, array_values(array_filter($items, static fn(array $item): bool => trim((string)($item['code'] ?? '')) !== ''))));
    }

    protected static function pluginManagementRow(array $item): array
    {
        return [
            'code' => trim((string)($item['code'] ?? '')),
            'name' => trim((string)($item['name'] ?? '')),
            'version' => trim((string)($item['version'] ?? '')),
            'status' => (string)($item['status'] ?? (((int)($item['status_code'] ?? 0) === 1) ? '启用' : '停用')),
            'status_code' => (int)($item['status_code'] ?? 0) === 1 ? 1 : 0,
            'description' => trim((string)($item['description'] ?? '')),
            'kind' => trim((string)($item['kind'] ?? 'general')),
            'developer' => PaymentMetaService::safeDeveloperName((string)($item['developer'] ?? '')),
            'payment_methods' => PaymentMetaService::normalizeMethodList($item['payment_methods'] ?? []),
            'display_payment_methods' => PaymentMetaService::normalizeMethodList(
                $item['display_payment_methods'] ?? $item['payment_methods'] ?? []
            ),
        ];
    }

    protected static function pluginManagementRows(array $items): array
    {
        return array_values(array_map(
            static fn(array $item): array => self::pluginManagementRow($item),
            $items
        ));
    }

    protected static function pluginSchemaAudit(array $plugin, array $runtimeSettings): array
    {
        $code = trim((string)($plugin['code'] ?? ''));
        $statusCode = (int)($plugin['status_code'] ?? 0) === 1 ? 1 : 0;
        $schema = is_array($plugin['settings_schema'] ?? null) ? $plugin['settings_schema'] : [];
        $fields = self::schemaFields($schema);
        $defaults = is_array($plugin['default_settings'] ?? null) ? $plugin['default_settings'] : [];
        $settings = array_replace($defaults, is_array($runtimeSettings[$code] ?? null) ? $runtimeSettings[$code] : []);
        $availableMethods = is_array($plugin['payment_methods'] ?? null) ? $plugin['payment_methods'] : [];

        $schemaKeys = [];
        $duplicateKeys = [];
        $fieldsWithoutKey = [];
        $unknownFieldTypes = [];
        $selectFieldsMissingOptions = [];
        $requiredKeys = [];
        $visibleRequiredKeys = [];
        $allowedTypes = self::schemaAllowedFieldTypes();

        foreach ($fields as $index => $field) {
            $key = trim((string)($field['key'] ?? ''));
            $label = trim((string)($field['label'] ?? $key));
            if ($key === '') {
                $fieldsWithoutKey[] = $label !== '' ? $label : ('#' . ($index + 1));
                continue;
            }

            if (isset($schemaKeys[$key])) {
                $duplicateKeys[$key] = $key;
            }
            $schemaKeys[$key] = $key;

            $type = strtolower(trim((string)($field['type'] ?? 'text')));
            if ($type === '') {
                $type = 'text';
            }
            if (!isset($allowedTypes[$type])) {
                $unknownFieldTypes[] = $key . ':' . $type;
            }
            if (in_array($type, ['select', 'radio', 'checkbox'], true) && !self::schemaFieldHasOptions($field)) {
                $selectFieldsMissingOptions[] = $key;
            }

            if (self::truthy($field['required'] ?? false)) {
                $requiredKeys[$key] = $key;
                if (PluginSchemaService::isFieldVisible($field, '', $settings, $availableMethods)) {
                    $visibleRequiredKeys[$key] = $key;
                }
            }
        }

        $defaultKeys = [];
        foreach ($defaults as $key => $_) {
            $name = trim((string)$key);
            if ($name !== '') {
                $defaultKeys[$name] = $name;
            }
        }

        $runtimeKeys = [];
        foreach ($settings as $key => $value) {
            $name = trim((string)$key);
            if ($name !== '' && !self::settingValueMissing($value)) {
                $runtimeKeys[$name] = $name;
            }
        }

        $internalDefaults = self::schemaInternalDefaultKeys();
        $defaultKeysNotInSchema = array_values(array_filter(
            array_diff(array_values($defaultKeys), array_values($schemaKeys)),
            static fn(string $key): bool => !isset($internalDefaults[$key])
        ));
        $ignoredDefaultKeys = array_values(array_filter(
            array_diff(array_values($defaultKeys), array_values($schemaKeys)),
            static fn(string $key): bool => isset($internalDefaults[$key])
        ));
        $schemaKeysMissingDefaults = array_values(array_diff(array_values($schemaKeys), array_values($defaultKeys)));
        $requiredKeysMissingDefaults = array_values(array_intersect($schemaKeysMissingDefaults, array_values($requiredKeys)));
        $missingRequiredSettings = self::missingRequiredSettings($schema, $settings, $availableMethods);

        $issues = [];
        if ($fields === []) {
            $issues[] = '未声明 settings_schema，无法生成标准配置表单';
        }
        if ($fieldsWithoutKey !== []) {
            $issues[] = '字段缺少 key: ' . implode('、', array_slice($fieldsWithoutKey, 0, 5));
        }
        if ($duplicateKeys !== []) {
            $issues[] = '字段 key 重复: ' . implode('、', array_slice(array_values($duplicateKeys), 0, 5));
        }
        if ($unknownFieldTypes !== []) {
            $issues[] = '未知字段类型: ' . implode('、', array_slice($unknownFieldTypes, 0, 5));
        }
        if ($selectFieldsMissingOptions !== []) {
            $issues[] = '选项字段缺少 options: ' . implode('、', array_slice($selectFieldsMissingOptions, 0, 5));
        }
        if ($missingRequiredSettings !== []) {
            $issues[] = '缺少必填运行配置: ' . implode('、', array_slice($missingRequiredSettings, 0, 5));
        }
        if ($requiredKeysMissingDefaults !== []) {
            $issues[] = '必填字段缺少默认键: ' . implode('、', array_slice($requiredKeysMissingDefaults, 0, 5));
        } elseif ($schemaKeysMissingDefaults !== []) {
            $issues[] = 'schema 字段缺少默认键: ' . implode('、', array_slice($schemaKeysMissingDefaults, 0, 5));
        }
        if ($defaultKeysNotInSchema !== []) {
            $issues[] = '默认键未在 schema 声明: ' . implode('、', array_slice($defaultKeysNotInSchema, 0, 5));
        }

        $level = 'ready';
        $label = 'Schema 已就绪';
        if ($fields === [] || $fieldsWithoutKey !== [] || $duplicateKeys !== []) {
            $level = 'blocked';
            $label = 'Schema 不完整';
        } elseif ($statusCode === 1 && $missingRequiredSettings !== []) {
            $level = 'blocked';
            $label = '缺少运行配置';
        } elseif ($missingRequiredSettings !== []) {
            $level = 'needs_config';
            $label = '待配置密钥';
        } elseif (
            $unknownFieldTypes !== []
            || $selectFieldsMissingOptions !== []
            || $schemaKeysMissingDefaults !== []
            || $defaultKeysNotInSchema !== []
        ) {
            $level = 'partial';
            $label = 'Schema 待规范';
        }

        return [
            'level' => $level,
            'label' => $label,
            'issues' => array_slice($issues, 0, 10),
            'field_count' => count($fields),
            'schema_key_count' => count($schemaKeys),
            'default_key_count' => count($defaultKeys),
            'configured_key_count' => count($runtimeKeys),
            'required_count' => count($requiredKeys),
            'visible_required_count' => count($visibleRequiredKeys),
            'missing_required_settings' => $missingRequiredSettings,
            'missing_required_defaults' => $requiredKeysMissingDefaults,
            'schema_keys_missing_defaults' => $schemaKeysMissingDefaults,
            'default_keys_not_in_schema' => $defaultKeysNotInSchema,
            'ignored_default_keys' => $ignoredDefaultKeys,
            'unknown_field_types' => array_values(array_unique($unknownFieldTypes)),
            'duplicate_keys' => array_values($duplicateKeys),
            'fields_without_key' => $fieldsWithoutKey,
            'select_fields_missing_options' => $selectFieldsMissingOptions,
            'empty_schema' => $fields === [],
        ];
    }

    protected static function schemaAuditSummary(array $items): array
    {
        $summary = [
            'total' => count($items),
            'ready' => 0,
            'needs_config' => 0,
            'partial' => 0,
            'blocked' => 0,
            'empty_schema' => 0,
            'missing_required_settings' => 0,
            'missing_required_defaults' => 0,
            'schema_keys_missing_defaults' => 0,
            'default_keys_not_in_schema' => 0,
            'unknown_field_types' => 0,
            'duplicate_keys' => 0,
            'fields_without_key' => 0,
            'select_fields_missing_options' => 0,
        ];

        foreach ($items as $item) {
            $audit = is_array($item['schema_audit'] ?? null) ? $item['schema_audit'] : [];
            $level = (string)($audit['level'] ?? 'partial');
            if (!array_key_exists($level, $summary)) {
                $level = 'partial';
            }
            $summary[$level]++;

            foreach ([
                'missing_required_settings',
                'missing_required_defaults',
                'schema_keys_missing_defaults',
                'default_keys_not_in_schema',
                'unknown_field_types',
                'duplicate_keys',
                'fields_without_key',
                'select_fields_missing_options',
            ] as $key) {
                if (($audit[$key] ?? []) !== []) {
                    $summary[$key]++;
                }
            }

            if ((bool)($audit['empty_schema'] ?? false)) {
                $summary['empty_schema']++;
            }
        }

        return $summary;
    }

    protected static function pluginHealth(array $plugin, array $runtimeSettings): array
    {
        $code = trim((string)($plugin['code'] ?? ''));
        $statusCode = (int)($plugin['status_code'] ?? 0) === 1 ? 1 : 0;
        $capabilities = self::normalizeCapabilityList($plugin['capabilities'] ?? []);
        $settings = array_replace(
            is_array($plugin['default_settings'] ?? null) ? $plugin['default_settings'] : [],
            is_array($runtimeSettings[$code] ?? null) ? $runtimeSettings[$code] : []
        );

        $supports = [
            'create' => in_array('create', $capabilities, true),
            'query' => in_array('query', $capabilities, true),
            'notify' => in_array('notify', $capabilities, true),
            'refund' => in_array('refund', $capabilities, true),
            'transfer' => in_array('transfer', $capabilities, true),
            'daemon' => in_array('daemon', $capabilities, true),
        ];
        $runtimeCapability = PluginExecutorService::capability($code);
        $runtimeSupports = [
            'class_exists' => (bool)($runtimeCapability['exists'] ?? false),
            'query' => (bool)($runtimeCapability['query'] ?? false),
            'checkout_static_fallback' => !(bool)($runtimeCapability['exists'] ?? false)
                && in_array((string)($plugin['kind'] ?? ''), ['qrcode', 'app', 'ck'], true),
            'refund' => (bool)($runtimeCapability['refund'] ?? false),
            'transfer' => (bool)($runtimeCapability['transfer'] ?? false),
            'transfer_query' => (bool)($runtimeCapability['transfer_query'] ?? false),
            'balance_query' => (bool)($runtimeCapability['balance_query'] ?? false),
        ];

        $missingCore = [];
        foreach (['create' => '建单', 'query' => '查单', 'notify' => '回调'] as $capability => $label) {
            if (!$supports[$capability]) {
                $missingCore[] = $label;
            }
        }

        $missingRequired = self::missingRequiredSettings(
            is_array($plugin['settings_schema'] ?? null) ? $plugin['settings_schema'] : [],
            $settings,
            is_array($plugin['payment_methods'] ?? null) ? $plugin['payment_methods'] : []
        );

        $issues = [];
        if ($missingCore !== []) {
            $issues[] = '缺少核心能力声明：' . implode('、', $missingCore);
        }
        if ($missingRequired !== []) {
            $issues[] = '缺少必填配置：' . implode('、', $missingRequired);
        }
        if (!$runtimeSupports['class_exists']) {
            $issues[] = $runtimeSupports['checkout_static_fallback']
                ? '缺少可执行插件类，仅可通过静态收银台展示收款信息'
                : '缺少可执行插件类';
        } elseif ($supports['query'] && !$runtimeSupports['query']) {
            $issues[] = '声明了查单能力，但源码未实现 query 方法';
        }
        if (!$supports['refund'] && !$runtimeSupports['refund']) {
            $issues[] = '未声明退款能力';
        } elseif (!$supports['refund'] && $runtimeSupports['refund']) {
            $issues[] = '源码存在退款方法，但 schema 未声明';
        }
        if (!$supports['transfer'] && !$runtimeSupports['transfer']) {
            $issues[] = '未声明代付能力';
        } elseif (!$supports['transfer'] && $runtimeSupports['transfer']) {
            $issues[] = '源码存在代付方法，但 schema 未声明';
        }
        if ($runtimeSupports['transfer'] && ($plugin['transfer_methods'] ?? []) === []) {
            $issues[] = '未声明代付方式';
        }
        if (($plugin['payment_methods'] ?? []) === []) {
            $issues[] = '未声明支付方式';
        }

        $level = 'ready';
        $label = '可执行';
        $runtimeBlocked = !$runtimeSupports['class_exists'] || ($supports['query'] && !$runtimeSupports['query']);
        if ($missingCore !== [] || $runtimeBlocked || ($statusCode === 1 && $missingRequired !== [])) {
            $level = 'blocked';
            $label = '不可执行';
        } elseif ($missingRequired !== []) {
            $level = 'needs_config';
            $label = '待配置';
        } elseif (!$supports['refund'] || !$supports['transfer']) {
            $level = 'partial';
            $label = '部分能力';
        }

        return [
            'level' => $level,
            'label' => $label,
            'supports' => $supports,
            'runtime_supports' => $runtimeSupports,
            'issues' => array_slice($issues, 0, 8),
            'missing_required_settings' => $missingRequired,
            'capabilities' => $capabilities,
        ];
    }

    protected static function missingRequiredSettings(array $schema, array $settings, array $availableMethods = []): array
    {
        $missing = [];
        foreach (self::schemaFields($schema) as $field) {
            if (!self::truthy($field['required'] ?? false)) {
                continue;
            }

            if (!PluginSchemaService::isFieldVisible($field, '', $settings, $availableMethods)) {
                continue;
            }

            $key = trim((string)($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $value = $settings[$key] ?? null;
            if (self::settingValueMissing($value)) {
                $missing[] = trim((string)($field['label'] ?? $key)) ?: $key;
            }
        }

        return array_values(array_unique($missing));
    }

    protected static function schemaFields(array $schema): array
    {
        if (is_array($schema['fields'] ?? null)) {
            $schema = $schema['fields'];
        }

        return array_values(array_filter($schema, static fn($field): bool => is_array($field)));
    }

    protected static function schemaAllowedFieldTypes(): array
    {
        return array_fill_keys([
            'text',
            'textarea',
            'password',
            'number',
            'select',
            'radio',
            'checkbox',
            'switch',
            'file',
            'image',
            'html',
            'url',
            'email',
        ], true);
    }

    protected static function schemaInternalDefaultKeys(): array
    {
        return array_fill_keys([
            'enabled',
            'source_plugin',
            'mode',
            'notify_retry',
            'debug',
            'sandbox',
            'environment',
        ], true);
    }

    protected static function schemaFieldHasOptions(array $field): bool
    {
        $options = $field['options'] ?? null;
        if (!is_array($options)) {
            return false;
        }

        return $options !== [];
    }

    protected static function settingValueMissing(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        if (is_bool($value)) {
            return false;
        }

        return trim((string)$value) === '';
    }

    protected static function runtimeSettingAllowedKeyMap(array $definition): array
    {
        $map = [];
        foreach (array_keys(is_array($definition['default_settings'] ?? null) ? $definition['default_settings'] : []) as $key) {
            $name = trim((string)$key);
            if ($name !== '') {
                $map[$name] = true;
            }
        }

        foreach (self::schemaFields(is_array($definition['settings_schema'] ?? null) ? $definition['settings_schema'] : []) as $field) {
            $key = trim((string)($field['key'] ?? ''));
            if ($key !== '') {
                $map[$key] = true;
            }
        }

        return $map;
    }

    protected static function normalizeRuntimeSettingValue(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || is_array($value)) {
            return $value;
        }

        if ($value === null) {
            return '';
        }

        return trim((string)$value);
    }

    protected static function maskedSettings(array $settings): array
    {
        $masked = [];
        foreach ($settings as $key => $value) {
            $name = trim((string)$key);
            if ($name === '') {
                continue;
            }

            $masked[$name] = [
                'configured' => !self::settingValueMissing($value),
                'value' => self::maskSettingValue($name, $value),
            ];
        }

        ksort($masked);
        return $masked;
    }

    protected static function maskSettingValue(string $key, mixed $value): string
    {
        if (self::settingValueMissing($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            return '[array:' . count($value) . ']';
        }

        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        $sensitive = preg_match('/(secret|key|token|private|password|passwd|cert|rsa|sign|appkey|appsecret)/i', $key) === 1;
        if (!$sensitive && strlen($text) <= 24) {
            return $text;
        }

        if (strlen($text) <= 8) {
            return str_repeat('*', strlen($text));
        }

        return substr($text, 0, 3) . '***' . substr($text, -3);
    }

    protected static function pluginChannelSummary(string $code): array
    {
        $summary = [
            'total' => 0,
            'enabled' => 0,
            'disabled' => 0,
            'merchants' => 0,
            'methods' => [],
            'recent' => [],
        ];
        $merchantIds = [];
        $methods = [];
        $recent = [];

        foreach (self::pluginChannelRows($code) as $row) {
            $summary['total']++;
            if ((int)($row['status_code'] ?? 0) === 1) {
                $summary['enabled']++;
            } else {
                $summary['disabled']++;
            }

            $merchantId = (int)($row['merchant_id'] ?? 0);
            if ($merchantId > 0) {
                $merchantIds[$merchantId] = true;
            }

            $method = PaymentMetaService::normalizeMethodCode((string)($row['method_code'] ?? ''));
            if ($method !== '') {
                $methods[$method] = ($methods[$method] ?? 0) + 1;
            }

            if (count($recent) < 8) {
                $recent[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'merchant_id' => $merchantId,
                    'method_code' => $method,
                    'method_name' => (string)($row['method_name'] ?? ''),
                    'status_code' => (int)($row['status_code'] ?? 0),
                ];
            }
        }

        $summary['merchants'] = count($merchantIds);
        $summary['methods'] = $methods;
        $summary['recent'] = $recent;
        return $summary;
    }

    protected static function pluginChannelRows(string $code): array
    {
        $rows = [];
        if (database_available()) {
            try {
                foreach (MerchantChannel::select()->toArray() as $row) {
                    $config = is_array($row['config'] ?? null) ? $row['config'] : [];
                    if (PluginCodeService::normalize((string)($config['plugin_code'] ?? '')) !== $code) {
                        continue;
                    }

                    $rows[] = [
                        'id' => (int)($row['id'] ?? 0),
                        'merchant_id' => (int)($row['merchant_id'] ?? 0),
                        'method_code' => (string)($config['method_code'] ?? ''),
                        'method_name' => (string)($config['method_name'] ?? ''),
                        'status_code' => (int)($row['status'] ?? 0) === 1 ? 1 : 0,
                    ];
                }
            } catch (Throwable) {
            }
        }

        foreach (JsonStoreService::load('merchant_channels', []) as $record) {
            if (!is_array($record)) {
                continue;
            }

            foreach ((array)($record['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $config = is_array($item['config'] ?? null) ? $item['config'] : [];
                if (PluginCodeService::normalize((string)($config['plugin_code'] ?? $item['plugin_code'] ?? '')) !== $code) {
                    continue;
                }

                $rows[] = [
                    'id' => (int)($item['id'] ?? 0),
                    'merchant_id' => (int)($record['merchant_id'] ?? 0),
                    'method_code' => (string)($config['method_code'] ?? $item['method_code'] ?? ''),
                    'method_name' => (string)($config['method_name'] ?? $item['method_name'] ?? ''),
                    'status_code' => (int)($item['status_code'] ?? 0) === 1 ? 1 : 0,
                ];
            }
        }

        return $rows;
    }

    protected static function pluginTaskSummary(string $code): array
    {
        $taskKey = 'plugin-daemon:' . $code;
        $definitions = [];
        foreach (PluginTaskService::taskDefinitions() as $definition) {
            if (($definition['key'] ?? '') === $taskKey) {
                $definitions[] = $definition;
            }
        }

        $runs = TaskService::runs($taskKey);

        return [
            'task_key' => $taskKey,
            'defined' => $definitions !== [],
            'definition' => $definitions[0] ?? null,
            'recent_runs' => array_slice(array_map(static fn(array $run): array => [
                'executed_at' => (string)($run['executed_at'] ?? ''),
                'status' => (string)($run['status'] ?? ''),
                'result' => (string)($run['result'] ?? ''),
                'operator' => (string)($run['operator'] ?? ''),
            ], $runs), 0, 5),
        ];
    }

    protected static function pluginNotifySummary(string $code): array
    {
        $logs = [];
        foreach (PluginNotifyLogService::logs(500) as $log) {
            if (!is_array($log)) {
                continue;
            }

            if (PluginCodeService::normalize((string)($log['plugin_code'] ?? '')) !== $code) {
                continue;
            }

            $logs[] = [
                'id' => (int)($log['id'] ?? 0),
                'action' => (string)($log['action'] ?? ''),
                'stage' => (string)($log['stage'] ?? ''),
                'trade_no' => (string)($log['trade_no'] ?? ''),
                'status' => (string)($log['status'] ?? ''),
                'message' => (string)($log['message'] ?? ''),
                'created_at' => (string)($log['created_at'] ?? ''),
            ];
        }

        return [
            'total_recent' => count($logs),
            'failed_recent' => count(array_filter($logs, static fn(array $log): bool => in_array((string)($log['status'] ?? ''), ['failed', 'error'], true))),
            'items' => array_slice($logs, 0, 10),
        ];
    }

    protected static function normalizeCapabilityList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $capability = strtolower(trim((string)$item));
            if ($capability !== '') {
                $items[] = $capability;
            }
        }

        return array_values(array_unique($items));
    }

    protected static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'required'], true);
    }

    protected static function canUseChannelTypeTable(): bool
    {
        if (!database_available()) {
            return false;
        }

        try {
            ChannelType::where('id', '>', 0)->limit(1)->select();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected static function canUsePluginTable(): bool
    {
        if (!database_available()) {
            return false;
        }

        try {
            Db::table('plugins')->limit(1)->get();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected static function methodMetaMap(): array
    {
        $records = ConfigService::get('payment_method_meta', []);
        return is_array($records) ? $records : [];
    }

    protected static function deletedPluginCodeMap(): array
    {
        return self::normalizeCodeMap(ConfigService::get(self::DELETED_PLUGIN_CODES_KEY, []));
    }

    protected static function deletedMethodCodeMap(): array
    {
        return self::normalizeCodeMap(ConfigService::get(self::DELETED_METHOD_CODES_KEY, []));
    }

    protected static function markPluginDeleted(string $code, bool $deleted): void
    {
        $codes = self::deletedPluginCodeMap();
        if ($deleted) {
            $codes[$code] = $code;
        } else {
            unset($codes[$code]);
        }

        ConfigService::save([self::DELETED_PLUGIN_CODES_KEY => array_values($codes)]);
    }

    protected static function markMethodDeleted(string $code, bool $deleted): void
    {
        $codes = self::deletedMethodCodeMap();
        if ($deleted) {
            $codes[$code] = $code;
        } else {
            unset($codes[$code]);
        }

        ConfigService::save([self::DELETED_METHOD_CODES_KEY => array_values($codes)]);
    }

    protected static function normalizeCodeMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $item) {
            $code = trim((string)$item);
            if ($code !== '') {
                $map[$code] = $code;
            }
        }

        return $map;
    }

    protected static function saveMethodMeta(string $code, array $payload): void
    {
        $meta = self::methodMetaMap();
        $meta[$code] = array_replace($meta[$code] ?? [], $payload);
        ConfigService::save(['payment_method_meta' => $meta]);
    }

    protected static function deleteMethodMeta(string $code): void
    {
        $meta = self::methodMetaMap();
        unset($meta[$code]);
        ConfigService::save(['payment_method_meta' => $meta]);
    }

    protected static function pluginDescriptionMap(): array
    {
        $records = ConfigService::get('plugin_descriptions', []);
        return is_array($records) ? $records : [];
    }

    protected static function savePluginDescription(string $code, string $description): void
    {
        $descriptions = self::pluginDescriptionMap();
        $descriptions[$code] = $description;
        ConfigService::save(['plugin_descriptions' => $descriptions]);
    }

    protected static function deletePluginDescription(string $code): void
    {
        $descriptions = self::pluginDescriptionMap();
        unset($descriptions[$code]);
        ConfigService::save(['plugin_descriptions' => $descriptions]);
    }

    protected static function clearPluginRuntimeSettings(string $code): void
    {
        PluginRuntimeService::removeSettings($code);
    }

    protected static function syncEnabledCodesSetting(string $code, bool $enabled): void
    {
        $settings = JsonStoreService::load('settings', SettingsService::defaults());
        $pluginSettings = is_array($settings['plugins'] ?? null) ? $settings['plugins'] : [];
        $enabledCodes = PluginCodeService::normalizeList($pluginSettings['enabled_codes'] ?? []);
        $enabledMap = array_fill_keys($enabledCodes, true);

        if ($enabled) {
            $enabledMap[$code] = true;
        } else {
            unset($enabledMap[$code]);
        }

        $pluginSettings['enabled_codes'] = array_values(array_keys($enabledMap));
        $settings['plugins'] = $pluginSettings;
        JsonStoreService::save('settings', $settings);
    }

    public static function friendlyMethodName(string $code): string
    {
        return PaymentMetaService::friendlyMethodName($code);
    }

    public static function isChainMethodCode(string $code): bool
    {
        return PaymentMetaService::isChainMethodCode($code);
    }

    public static function normalizeMethodCode(string $code): string
    {
        return PaymentMetaService::normalizeMethodCode($code);
    }

    protected static function buildConfigSchemaByCategory(int $category): array
    {
        if ($category === 1) {
            return [
                'fields' => [
                    ['key' => 'address', 'label' => '收款地址', 'type' => 'text'],
                ],
            ];
        }

        return [
            'fields' => [
                ['key' => 'qrcode_url', 'label' => '收款码链接', 'type' => 'image'],
            ],
        ];
    }

    protected static function nextChannelSort(): int
    {
        try {
            $max = (int)ChannelType::max('sort');
            return $max + 10;
        } catch (Throwable) {
            return 999;
        }
    }
}
