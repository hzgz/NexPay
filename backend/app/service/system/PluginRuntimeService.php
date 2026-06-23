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

                $configPath = $pluginDir . DIRECTORY_SEPARATOR . 'config.php';
                $routePath = $pluginDir . DIRECTORY_SEPARATOR . 'routes.php';
                $providerPath = $pluginDir . DIRECTORY_SEPARATOR . 'PluginProvider.php';
                $sourceInfoPath = $pluginDir . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR . 'info.json';
                $config = self::readConfigFile($configPath);
                $sourceConfig = is_array($config['source'] ?? null) ? $config['source'] : [];
                $sourceInfo = self::readJsonFile($sourceInfoPath);

                $code = trim((string)($manifest['code'] ?? basename($pluginDir)));
                $name = trim((string)($manifest['name'] ?? $code));
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
                $defaultSettings = is_array($config['default_settings'] ?? null) ? $config['default_settings'] : [];
                $settingsSchema = self::normalizeSettingsSchema(
                    is_array($config['settings_schema'] ?? null) ? $config['settings_schema'] : [],
                    $defaultSettings,
                    $kind
                );

                $items[] = [
                    'code' => $code,
                    'name' => $name,
                    'version' => trim((string)($manifest['version'] ?? '1.0.0')),
                    'description' => trim((string)($manifest['description'] ?? '')),
                    'group' => $group,
                    'kind' => $kind,
                    'provider' => trim((string)($manifest['provider'] ?? '')),
                    'provider_file' => is_file($providerPath) ? self::relativePath($providerPath) : '',
                    'config_file' => is_file($configPath) ? self::relativePath($configPath) : '',
                    'route_file' => is_file($routePath) ? self::relativePath($routePath) : '',
                    'developer' => trim((string)($manifest['author'] ?? $sourceConfig['author'] ?? $sourceInfo['author'] ?? '')),
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

    private static function normalizeSettingsSchema(array $schema, array $defaultSettings = [], string $kind = ''): array
    {
        if (is_array($schema['fields'] ?? null)) {
            $schema = $schema['fields'];
        }

        $normalized = [];
        foreach ($schema as $field) {
            if (!is_array($field)) {
                continue;
            }

            $normalized[] = self::normalizeSettingsField($field, $defaultSettings, $kind);
        }

        return array_values($normalized);
    }

    private static function normalizeSettingsField(array $field, array $defaultSettings = [], string $kind = ''): array
    {
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $key = trim((string)($field['key'] ?? ''));
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
                    $label = trim((string)($item['label'] ?? $item['name'] ?? $item['text'] ?? $value));
                    if ($label === '' && $value === '') {
                        continue;
                    }

                    $normalized[] = [
                        'label' => $label !== '' ? $label : $value,
                        'value' => $value,
                    ];
                    continue;
                }

                $label = trim((string)$item);
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
            'listener' => [
                ['label' => '模拟监听', 'value' => $defaultValue !== '' ? $defaultValue : 'mock-listener'],
            ],
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
