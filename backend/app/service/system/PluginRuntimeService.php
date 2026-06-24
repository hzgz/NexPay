<?php

namespace app\service\system;

use app\plugin\PluginProviderInterface;
use think\facade\Db;
use Throwable;

class PluginRuntimeService
{
    private const PLUGIN_ROOT = 'plugins';

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $definitionCache = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $definitionMapCache = null;

    /**
     * @var array<string, bool>
     */
    private static array $bootedProviders = [];

    /**
     * @var array<string, bool>
     */
    private static array $loadedRoutes = [];

    public static function refreshDiscoveryCache(): void
    {
        self::$definitionCache = null;
        self::$definitionMapCache = null;
    }

    public static function discoverDefinitions(bool $forceReload = false): array
    {
        if ($forceReload) {
            self::refreshDiscoveryCache();
        }

        if (is_array(self::$definitionCache)) {
            return self::$definitionCache;
        }

        $root = base_path() . DIRECTORY_SEPARATOR . self::PLUGIN_ROOT;
        if (!is_dir($root)) {
            return self::$definitionCache = [];
        }

        $items = [];
        $groups = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        foreach ($groups as $groupDir) {
            $plugins = glob($groupDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
            foreach ($plugins as $pluginDir) {
                $manifestPath = $pluginDir . DIRECTORY_SEPARATOR . 'plugin.json';
                if (!is_file($manifestPath)) {
                    continue;
                }

                $manifest = self::readJsonFile($manifestPath);
                if ($manifest === []) {
                    continue;
                }

                $group = trim((string)($manifest['group'] ?? basename($groupDir)));
                if ($group !== 'pay') {
                    continue;
                }

                $basename = basename($pluginDir);

                $configPath = $pluginDir . DIRECTORY_SEPARATOR . 'config.php';
                $routePath = $pluginDir . DIRECTORY_SEPARATOR . 'routes.php';
                $providerPath = $pluginDir . DIRECTORY_SEPARATOR . 'PluginProvider.php';
                $sourceInfoPath = $pluginDir . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR . 'info.json';
                $config = self::readConfigFile($configPath);
                $sourceConfig = is_array($config['source'] ?? null) ? $config['source'] : [];
                $sourceInfo = self::readJsonFile($sourceInfoPath);

                $code = PluginCodeService::normalize(trim((string)($manifest['code'] ?? $basename)));
                $canonicalDir = legacy_plugin_directory_name($code);
                if ($canonicalDir !== '' && $canonicalDir !== $basename) {
                    continue;
                }

                $name = trim((string)EncodingRepairService::repair((string)($manifest['name'] ?? $code)));
                if ($code === '' || $name === '') {
                    continue;
                }

                $capabilities = self::normalizeList($manifest['capabilities'] ?? ($config['capabilities'] ?? []));
                $runtimeCapabilities = self::runtimeCapabilities($code);
                $capabilities = array_values(array_unique(array_merge($capabilities, $runtimeCapabilities)));
                $paymentMethods = self::normalizeList(
                    $manifest['payment_methods']
                    ?? ($config['payment_methods']
                    ?? ($sourceConfig['pay_types']
                    ?? ($sourceInfo['pay_types'] ?? [])))
                );
                $kind = trim((string)($manifest['kind'] ?? (string)($config['kind'] ?? 'general')));
                $configuredDefaults = is_array($config['default_settings'] ?? null) ? $config['default_settings'] : [];
                $defaultSettings = array_replace(
                    self::defaultSettingsFromSourceInfo($sourceInfo),
                    $configuredDefaults
                );
                $settingsSchema = self::normalizeSettingsSchema(
                    self::resolveSettingsSchema(
                        $config['settings_schema'] ?? [],
                        $sourceInfo,
                        $defaultSettings,
                        $kind
                    ),
                    $defaultSettings,
                    $kind
                );

                $items[] = [
                    'code' => $code,
                    'name' => $name,
                    'version' => trim((string)($manifest['version'] ?? '1.0.0')),
                    'description' => trim((string)EncodingRepairService::repair((string)($manifest['description'] ?? ''))),
                    'group' => $group,
                    'kind' => $kind,
                    'provider' => trim((string)($manifest['provider'] ?? '')),
                    'provider_file' => is_file($providerPath) ? self::relativePath($providerPath) : '',
                    'config_file' => is_file($configPath) ? self::relativePath($configPath) : '',
                    'route_file' => is_file($routePath) ? self::relativePath($routePath) : '',
                    'developer' => trim((string)EncodingRepairService::repair((string)($manifest['author'] ?? $sourceConfig['author'] ?? $sourceInfo['author'] ?? ''))),
                    'capabilities' => $capabilities,
                    'payment_methods' => $paymentMethods,
                    'display_payment_methods' => self::normalizeList(
                        $manifest['display_payment_methods'] ?? ($config['display_payment_methods'] ?? $paymentMethods)
                    ),
                    'transfer_methods' => self::runtimeTransferMethods(
                        $code,
                        self::normalizeList($manifest['transfer_methods'] ?? ($config['transfer_methods'] ?? [])),
                        $paymentMethods
                    ),
                    'default_settings' => $defaultSettings,
                    'settings_schema' => $settingsSchema,
                    'status' => '停用',
                    'status_code' => 0,
                    'installed_at' => date('Y-m-d H:i:s', @filemtime($manifestPath) ?: time()),
                ];
            }
        }

        self::$definitionMapCache = null;

        return self::$definitionCache = $items;
    }

    public static function discoverMap(bool $forceReload = false): array
    {
        if ($forceReload) {
            self::refreshDiscoveryCache();
        }

        if (is_array(self::$definitionMapCache)) {
            return self::$definitionMapCache;
        }

        $map = [];
        foreach (self::discoverDefinitions() as $definition) {
            $code = (string)($definition['code'] ?? '');
            if ($code !== '') {
                $map[$code] = $definition;
            }
        }

        return self::$definitionMapCache = $map;
    }

    public static function enabledDefinitions(): array
    {
        $enabledCodes = self::enabledPluginCodes();
        if ($enabledCodes === []) {
            return [];
        }

        return array_values(array_filter(
            self::discoverDefinitions(),
            static fn(array $definition): bool => in_array((string)($definition['code'] ?? ''), $enabledCodes, true)
        ));
    }

    public static function ensureSettingsStorage(?array $definitions = null): void
    {
        $definitions = $definitions ?? self::discoverDefinitions();
        if ($definitions === []) {
            return;
        }

        $stored = ConfigService::get('plugin_runtime_settings', []);
        $settings = is_array($stored) ? $stored : [];
        $allowedCodes = [];
        foreach ($definitions as $definition) {
            $code = trim((string)($definition['code'] ?? ''));
            if ($code !== '') {
                $allowedCodes[$code] = true;
            }
        }

        $dirty = false;

        foreach (array_keys($settings) as $code) {
            if (!isset($allowedCodes[$code])) {
                unset($settings[$code]);
                $dirty = true;
            }
        }

        foreach ($definitions as $definition) {
            $code = trim((string)($definition['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $defaults = is_array($definition['default_settings'] ?? null) ? $definition['default_settings'] : [];
            $current = is_array($settings[$code] ?? null) ? $settings[$code] : [];
            $merged = array_replace($defaults, $current);

            if (!array_key_exists($code, $settings) || $merged !== $current) {
                $settings[$code] = $merged;
                $dirty = true;
            }
        }

        if ($dirty) {
            ConfigService::save(['plugin_runtime_settings' => $settings]);
        }
    }

    public static function settingsFor(string $code): array
    {
        $definitions = self::discoverMap();
        $defaults = is_array($definitions[$code]['default_settings'] ?? null)
            ? $definitions[$code]['default_settings']
            : [];

        $stored = ConfigService::get('plugin_runtime_settings', []);
        $settings = is_array($stored) && is_array($stored[$code] ?? null) ? $stored[$code] : [];

        return array_replace($defaults, $settings);
    }

    public static function saveSettings(string $code, array $settings): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }

        $definitions = self::discoverMap();
        if (!isset($definitions[$code])) {
            return [];
        }

        $all = ConfigService::get('plugin_runtime_settings', []);
        $payload = is_array($all) ? $all : [];
        $payload[$code] = array_replace(self::settingsFor($code), $settings);
        ConfigService::save(['plugin_runtime_settings' => $payload]);

        return $payload[$code];
    }

    public static function bootEnabledProviders(): void
    {
        foreach (self::enabledDefinitions() as $definition) {
            $code = trim((string)($definition['code'] ?? ''));
            $providerClass = trim((string)($definition['provider'] ?? ''));
            if ($code === '' || $providerClass === '' || isset(self::$bootedProviders[$code])) {
                continue;
            }

            $providerFile = self::absolutePath((string)($definition['provider_file'] ?? ''));
            if ($providerFile !== '' && is_file($providerFile)) {
                require_once $providerFile;
            }

            if (!class_exists($providerClass)) {
                continue;
            }

            $provider = new $providerClass($definition, self::settingsFor($code));
            if ($provider instanceof PluginProviderInterface) {
                $provider->boot();
                self::$bootedProviders[$code] = true;
            }
        }
    }

    public static function loadRoutes(): void
    {
        foreach (self::enabledDefinitions() as $definition) {
            $code = trim((string)($definition['code'] ?? ''));
            $routeFile = self::absolutePath((string)($definition['route_file'] ?? ''));
            if ($code === '' || $routeFile === '' || !is_file($routeFile) || isset(self::$loadedRoutes[$code])) {
                continue;
            }

            require_once $routeFile;
            self::$loadedRoutes[$code] = true;
        }
    }

    /**
     * @return array<int, string>
     */
    private static function enabledPluginCodes(): array
    {
        try {
            return Db::table('plugins')
                ->where('status', 1)
                ->column('code');
        } catch (Throwable) {
            $stored = JsonStoreService::load('plugins', []);
            $codes = [];
            foreach ($stored as $item) {
                if (!is_array($item) || (int)($item['status_code'] ?? 0) !== 1) {
                    continue;
                }

                $code = trim((string)($item['code'] ?? ''));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            return $codes;
        }
    }

    private static function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = (string)file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function readConfigFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $payload = include $path;
        return is_array($payload) ? $payload : [];
    }

    private static function defaultSettingsFromSourceInfo(array $sourceInfo): array
    {
        $defaults = [];
        $inputs = is_array($sourceInfo['inputs'] ?? null) ? $sourceInfo['inputs'] : [];
        foreach ($inputs as $key => $input) {
            $name = trim((string)$key);
            if ($name === '' || !is_array($input)) {
                continue;
            }

            if (!array_key_exists('value', $input)) {
                continue;
            }

            $defaults[$name] = $input['value'];
        }

        return $defaults;
    }

    private static function resolveSettingsSchema(
        mixed $schema,
        array $sourceInfo,
        array $defaultSettings = [],
        string $kind = ''
    ): array {
        $configured = self::schemaFieldList($schema);
        $defaultDerived = self::derivedSchemaFromDefaults($defaultSettings, $kind);
        $sourceDerived = self::schemaFromSourceInfo($sourceInfo, $defaultSettings);

        return self::mergeSchemaSources($configured, $defaultDerived, $sourceDerived);
    }

    private static function schemaFieldList(mixed $schema): array
    {
        if (is_array($schema['fields'] ?? null)) {
            $schema = $schema['fields'];
        }

        if (!is_array($schema)) {
            return [];
        }

        return array_values(array_filter($schema, static fn(mixed $field): bool => is_array($field)));
    }

    private static function schemaFromSourceInfo(array $sourceInfo, array $defaultSettings = []): array
    {
        $inputs = is_array($sourceInfo['inputs'] ?? null) ? $sourceInfo['inputs'] : [];
        $fields = [];

        foreach ($inputs as $key => $input) {
            $fieldKey = trim((string)$key);
            if ($fieldKey === '' || !is_array($input)) {
                continue;
            }

            $type = self::sourceInputTypeToSchemaType($fieldKey, $input);
            $field = [
                'key' => $fieldKey,
                'label' => trim((string)EncodingRepairService::repair((string)($input['name'] ?? $fieldKey))),
                'type' => $type,
            ];

            if (array_key_exists('required', $input)) {
                $field['required'] = (bool)$input['required'];
            }

            $note = trim((string)EncodingRepairService::repair((string)($input['note'] ?? '')));
            if ($note !== '') {
                $field['note'] = $note;
            }

            $placeholder = trim((string)EncodingRepairService::repair((string)($input['placeholder'] ?? '')));
            if ($placeholder !== '') {
                $field['placeholder'] = $placeholder;
            }

            $show = trim((string)($input['show'] ?? ''));
            if ($show !== '') {
                $field['show'] = $show;
            }

            $accept = trim((string)($input['accept'] ?? ''));
            if ($accept !== '') {
                $field['accept'] = $accept;
            }

            $options = $input['options'] ?? null;
            if (is_array($options) && $options !== []) {
                $field['options'] = self::normalizeFieldOptions($options, $fieldKey, $defaultSettings, '');
            }

            $fields[] = $field;
        }

        return $fields;
    }

    private static function sourceInputTypeToSchemaType(string $fieldKey, array $input): string
    {
        $type = strtolower(trim((string)($input['type'] ?? 'input')));

        return match ($type) {
            'textarea' => 'textarea',
            'password' => 'password',
            'select' => 'select',
            'radio' => 'radio',
            'checkbox', 'switch' => 'checkbox',
            'upload', 'file' => self::inferUploadSchemaType($fieldKey, $input),
            default => 'text',
        };
    }

    private static function inferUploadSchemaType(string $fieldKey, array $input): string
    {
        $accept = strtolower(trim((string)($input['accept'] ?? '')));
        $fieldKey = strtolower(trim($fieldKey));

        if (
            str_contains($accept, '.png')
            || str_contains($accept, '.jpg')
            || str_contains($accept, '.jpeg')
            || str_contains($accept, '.gif')
            || str_contains($accept, '.webp')
            || str_contains($fieldKey, 'image')
            || str_contains($fieldKey, 'qrcode')
        ) {
            return 'image';
        }

        return 'file';
    }

    private static function derivedSchemaFromDefaults(array $defaultSettings, string $kind = ''): array
    {
        $fields = [];
        $kind = strtolower(trim($kind));

        foreach ($defaultSettings as $key => $value) {
            $fieldKey = trim((string)$key);
            if ($fieldKey === '') {
                continue;
            }

            $field = match ($fieldKey) {
                'confirmations' => [
                    'key' => 'confirmations',
                    'label' => '确认次数',
                    'type' => 'number',
                    'required' => true,
                    'placeholder' => (string)($value !== '' ? $value : '2'),
                    'note' => '链上到账达到该确认次数后，订单才会确认为支付成功。',
                ],
                'listener' => [
                    'key' => 'listener',
                    'label' => '监听方式',
                    'type' => 'select',
                    'required' => true,
                    'options' => self::listenerFieldOptions((string)$value),
                ],
                'address_strategy' => [
                    'key' => 'address_strategy',
                    'label' => '地址策略',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        ['label' => '单地址收款', 'value' => (string)($value !== '' ? $value : 'single')],
                    ],
                ],
                'appurl' => $kind === 'chain' ? [
                    'key' => 'appurl',
                    'label' => '订单有效期(秒)',
                    'type' => 'number',
                    'required' => true,
                    'placeholder' => (string)($value !== '' ? $value : '360'),
                    'note' => '默认 360 秒，即 6 分钟有效期。',
                ] : null,
                'xiaoshu' => [
                    'key' => 'xiaoshu',
                    'label' => '金额小数位',
                    'type' => 'number',
                    'required' => true,
                    'placeholder' => (string)($value !== '' ? $value : '2'),
                    'note' => '建议设置 2 到 6 位，避免链上金额重复。',
                ],
                'botid' => [
                    'key' => 'botid',
                    'label' => 'Telegram 用户 ID',
                    'type' => 'text',
                    'placeholder' => '可选',
                    'note' => '选填，用于接收链上订单提醒。',
                ],
                'bottoken' => [
                    'key' => 'bottoken',
                    'label' => 'Telegram Bot Token',
                    'type' => 'text',
                    'placeholder' => '可选',
                    'note' => '选填，配合 Telegram 用户 ID 使用。',
                ],
                default => null,
            };

            if (is_array($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private static function listenerFieldOptions(string $defaultValue): array
    {
        $normalized = strtolower(trim($defaultValue));
        if ($normalized === 'manual') {
            return [
                ['label' => '手动监听', 'value' => 'manual'],
            ];
        }

        if ($normalized === 'mock-listener' || $normalized === 'mock') {
            return [
                ['label' => '模拟监听', 'value' => $defaultValue !== '' ? $defaultValue : 'mock-listener'],
            ];
        }

        if ($defaultValue !== '') {
            return [
                ['label' => $defaultValue, 'value' => $defaultValue],
            ];
        }

        return [
            ['label' => '模拟监听', 'value' => 'mock-listener'],
        ];
    }

    private static function mergeSchemaSources(array ...$sources): array
    {
        if ($sources === []) {
            return [];
        }

        $lists = array_map([self::class, 'schemaFieldList'], $sources);
        $fallbackByKey = [];

        for ($index = 1; $index < count($lists); $index++) {
            foreach ($lists[$index] as $field) {
                $key = trim((string)($field['key'] ?? ''));
                if ($key === '' || isset($fallbackByKey[$key])) {
                    continue;
                }

                $fallbackByKey[$key] = $field;
            }
        }

        $merged = [];
        $seen = [];

        foreach ($lists[0] as $field) {
            $key = trim((string)($field['key'] ?? ''));
            if ($key !== '' && isset($fallbackByKey[$key])) {
                $field = array_replace($fallbackByKey[$key], $field);
            }

            if ($key !== '') {
                $seen[$key] = true;
            }

            $merged[] = $field;
        }

        for ($index = 1; $index < count($lists); $index++) {
            foreach ($lists[$index] as $field) {
                $key = trim((string)($field['key'] ?? ''));
                if ($key !== '' && isset($seen[$key])) {
                    continue;
                }

                if ($key !== '') {
                    $seen[$key] = true;
                }

                $merged[] = $field;
            }
        }

        return $merged;
    }

    private static function normalizeSettingsSchema(array $schema, array $defaultSettings = [], string $kind = ''): array
    {
        $normalized = [];
        foreach (self::schemaFieldList($schema) as $field) {

            $normalized[] = self::normalizeSettingsField($field, $defaultSettings, $kind);
        }

        return array_values($normalized);
    }

    private static function normalizeSettingsField(array $field, array $defaultSettings = [], string $kind = ''): array
    {
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $key = trim((string)($field['key'] ?? ''));
        foreach (['label', 'name', 'title', 'placeholder', 'note', 'help', 'description', 'tip', 'remark'] as $textKey) {
            if (isset($field[$textKey]) && is_string($field[$textKey])) {
                $field[$textKey] = trim((string)EncodingRepairService::repair($field[$textKey]));
            }
        }
        $field['type'] = $type !== '' ? $type : 'text';

        if (in_array($field['type'], ['select', 'radio'], true)) {
            $field['options'] = self::normalizeFieldOptions(
                $field['options'] ?? [],
                $key,
                $defaultSettings,
                $kind
            );
        } elseif ($field['type'] === 'checkbox' && !isset($field['options'])) {
            $field['options'] = [
                ['label' => '否', 'value' => '0'],
                ['label' => '是', 'value' => '1'],
            ];
        }

        return $field;
    }

    private static function normalizeFieldOptions(
        mixed $options,
        string $fieldKey,
        array $defaultSettings = [],
        string $kind = ''
    ): array {
        if (is_array($options) && $options !== []) {
            $normalized = [];
            foreach ($options as $key => $item) {
                if (is_array($item)) {
                    $value = self::normalizeOptionScalar($item['value'] ?? $item['key'] ?? $item['id'] ?? $key);
                    $label = trim((string)EncodingRepairService::repair(
                        (string)($item['label'] ?? $item['name'] ?? $item['text'] ?? $value)
                    ));
                    if ($label === '' && $value === '') {
                        continue;
                    }

                    $normalized[] = [
                        'label' => $label !== '' ? $label : $value,
                        'value' => $value,
                    ];
                    continue;
                }

                $label = trim((string)EncodingRepairService::repair((string)$item));
                $value = self::normalizeOptionScalar($key);

                if (is_int($key)) {
                    $defaultValue = self::normalizeOptionScalar($defaultSettings[$fieldKey] ?? '');
                    if ($defaultValue !== '' && $defaultValue === self::normalizeOptionScalar((string)$key)) {
                        $value = $defaultValue;
                    }
                }

                if ($label === '' && $value === '') {
                    continue;
                }

                $normalized[] = [
                    'label' => $label !== '' ? $label : $value,
                    'value' => $value,
                ];
            }

            if ($normalized !== []) {
                return array_values($normalized);
            }
        }

        return self::defaultFieldOptions($fieldKey, $defaultSettings, $kind);
    }

    private static function defaultFieldOptions(string $fieldKey, array $defaultSettings = [], string $kind = ''): array
    {
        $defaultValue = self::normalizeOptionScalar($defaultSettings[$fieldKey] ?? '');
        $kind = strtolower(trim($kind));

        return match ($fieldKey) {
            'mode' => self::defaultModeOptions($kind, $defaultValue),
            'device' => self::defaultDeviceOptions($defaultValue),
            'listener' => self::listenerFieldOptions($defaultValue),
            'address_strategy' => [
                ['label' => '单地址收款', 'value' => $defaultValue !== '' ? $defaultValue : 'single'],
            ],
            default => [],
        };
    }

    private static function defaultModeOptions(string $kind, string $defaultValue): array
    {
        return match ($kind) {
            'app' => [
                ['label' => 'APP 拉起', 'value' => $defaultValue !== '' ? $defaultValue : 'app'],
            ],
            'qrcode' => [
                ['label' => '二维码', 'value' => $defaultValue !== '' ? $defaultValue : 'native'],
            ],
            default => $defaultValue !== '' ? [[
                'label' => $defaultValue,
                'value' => $defaultValue,
            ]] : [],
        };
    }

    private static function defaultDeviceOptions(string $defaultValue): array
    {
        if ($defaultValue === 'mobile') {
            return [
                ['label' => '手机/H5', 'value' => 'mobile'],
            ];
        }

        return [
            ['label' => 'PC/网页', 'value' => 'pc'],
            ['label' => '手机/H5', 'value' => 'mobile'],
        ];
    }

    private static function normalizeOptionScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        return trim((string)$value);
    }

    private static function normalizeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return array_values(array_unique($items));
    }

    private static function runtimeCapabilities(string $pluginCode): array
    {
        $class = self::paymentPluginClass($pluginCode);
        if ($class === '' || !class_exists($class)) {
            return [];
        }

        $capabilities = [];
        foreach (['query', 'refund', 'transfer', 'transfer_query', 'balance_query'] as $method) {
            if (method_exists($class, $method)) {
                $capabilities[] = $method;
            }
        }

        return $capabilities;
    }

    private static function runtimeTransferMethods(string $pluginCode, array $declaredMethods, array $paymentMethods): array
    {
        $class = self::paymentPluginClass($pluginCode);
        if ($class === '' || !class_exists($class) || !method_exists($class, 'transfer')) {
            return $declaredMethods;
        }

        if ($declaredMethods !== []) {
            return $declaredMethods;
        }

        $supported = [];
        foreach ($paymentMethods as $method) {
            $normalized = PaymentMetaService::normalizeMethodCode((string)$method);
            if (in_array($normalized, ['alipay', 'wxpay', 'qqpay', 'bank'], true)) {
                $supported[$normalized] = $normalized;
            }
        }

        return array_values($supported);
    }

    private static function paymentPluginClass(string $pluginCode): string
    {
        $pluginDir = legacy_plugin_directory_name($pluginCode);
        if ($pluginDir === '') {
            return '';
        }

        $classBase = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $pluginDir)));
        return 'plugins\\payment\\' . $pluginDir . '\\' . $classBase . 'Plugin';
    }

    private static function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, $base . '/')) {
            return substr($normalized, strlen($base) + 1);
        }

        return ltrim($normalized, '/');
    }

    private static function absolutePath(string $relativePath): string
    {
        $relativePath = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
        if ($relativePath === '') {
            return '';
        }

        return base_path() . DIRECTORY_SEPARATOR . $relativePath;
    }
}

