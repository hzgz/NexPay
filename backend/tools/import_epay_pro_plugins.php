<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

use app\service\system\ConfigService;
use app\service\system\JsonStoreService;
use app\service\system\PluginService;
use app\service\system\PluginRuntimeService;

$sourceRoot = trim((string)(getenv('PLUGIN_SOURCE_ROOT') ?: ''));
foreach ($_SERVER['argv'] ?? [] as $argument) {
    if (!is_string($argument) || !str_starts_with($argument, '--source=')) {
        continue;
    }
    $sourceRoot = trim(substr($argument, 9));
}

if ($sourceRoot === '' || !is_dir($sourceRoot)) {
    fwrite(STDERR, "Source path not found. Use --source=/path/to/plugins/payment or set PLUGIN_SOURCE_ROOT.\n");
    exit(1);
}

$targetRoot = base_path() . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'pay';
if (!is_dir($targetRoot) && !mkdir($targetRoot, 0777, true) && !is_dir($targetRoot)) {
    fwrite(STDERR, "Unable to create target root: {$targetRoot}\n");
    exit(1);
}

$pluginRows = [];
$runtimeSettings = ConfigService::get('plugin_runtime_settings', []);
$runtimeSettings = is_array($runtimeSettings) ? $runtimeSettings : [];

$infoFiles = glob($sourceRoot . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'info.json') ?: [];
sort($infoFiles);

foreach ($infoFiles as $infoFile) {
    $pluginDir = dirname($infoFile);
    $meta = json_decode((string)file_get_contents($infoFile), true);
    if (!is_array($meta)) {
        continue;
    }

    $sourceName = trim((string)($meta['name'] ?? basename($pluginDir)));
    if ($sourceName === '') {
        continue;
    }

    $pluginSlug = normalize_plugin_slug($sourceName);
    $code = $pluginSlug;
    $title = trim((string)($meta['title'] ?? $sourceName));
    $version = trim((string)($meta['version'] ?? '1.0.0'));
    $description = trim((string)($meta['desc'] ?? ''));
    if ($description === '') {
        $description = $title . '，已从 epay_pro 支付插件目录迁移至 NexPay 插件体系。';
    }

    $payTypes = normalize_string_list($meta['pay_types'] ?? []);
    $paymentMethods = [];
    foreach ($payTypes as $payType) {
        $mapped = map_payment_method($payType);
        if ($mapped !== '') {
            $paymentMethods[] = $mapped;
        }
    }
    $paymentMethods = array_values(array_unique($paymentMethods));

    $kind = detect_kind($sourceName, $title, $payTypes, $meta);
    $capabilities = detect_capabilities($kind, $pluginDir);
    $targetDir = $targetRoot . DIRECTORY_SEPARATOR . $pluginSlug;

    ensure_dir($targetDir);
    remove_dir($targetDir . DIRECTORY_SEPARATOR . 'source');
    ensure_dir($targetDir . DIRECTORY_SEPARATOR . 'source');
    copy_dir($pluginDir, $targetDir . DIRECTORY_SEPARATOR . 'source');

    $namespace = 'plugins\\pay\\' . $pluginSlug;
    $settingsSchema = build_settings_schema($meta);
    $defaultSettings = build_default_settings($meta, $kind, $pluginSlug);
    $manifest = [
        'code' => $code,
        'name' => $title,
        'version' => $version,
        'description' => $description,
        'group' => 'pay',
        'kind' => $kind,
        'payment_methods' => $paymentMethods,
        'capabilities' => $capabilities,
        'provider' => 'plugins\\pay\\' . $pluginSlug . '\\PluginProvider',
    ];

    file_put_contents(
        $targetDir . DIRECTORY_SEPARATOR . 'plugin.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );

    $configPhp = build_config_php($kind, $capabilities, $paymentMethods, $defaultSettings, $settingsSchema, $meta, $sourceName, $title);
    file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'config.php', $configPhp);

    $providerPhp = <<<PHP
<?php

namespace {$namespace};

use app\plugin\AbstractPluginProvider;

class PluginProvider extends AbstractPluginProvider
{
}
PHP;
    file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'PluginProvider.php', $providerPhp . PHP_EOL);

    $routesPhp = <<<PHP
<?php

// {$title} 预留路由入口，源代码已归档至 source 目录。
PHP;
    file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'routes.php', $routesPhp . PHP_EOL);

    $runtimeSettings[$code] = array_replace($defaultSettings, is_array($runtimeSettings[$code] ?? null) ? $runtimeSettings[$code] : []);

    $pluginRows[$code] = [
        'code' => $code,
        'name' => $title,
        'version' => $version,
        'status' => '停用',
        'status_code' => 0,
        'description' => $description,
        'installed_at' => date('Y-m-d H:i:s'),
        'group' => 'pay',
        'kind' => $kind,
        'provider' => $manifest['provider'],
        'provider_file' => 'plugins/pay/' . $pluginSlug . '/PluginProvider.php',
        'config_file' => 'plugins/pay/' . $pluginSlug . '/config.php',
        'route_file' => 'plugins/pay/' . $pluginSlug . '/routes.php',
        'capabilities' => $capabilities,
        'payment_methods' => $paymentMethods,
        'default_settings' => $defaultSettings,
        'settings_schema' => $settingsSchema,
    ];
}

$orderedRows = array_values($pluginRows);
JsonStoreService::save('plugins', $orderedRows);
ConfigService::save(['plugin_runtime_settings' => $runtimeSettings]);

$scan = PluginService::scan();
$methods = PluginService::methods();

echo json_encode([
    'imported_plugins' => count($orderedRows),
    'discovered_plugins' => $scan['scanned'] ?? 0,
    'registered_plugins' => count($scan['items'] ?? []),
    'payment_methods' => count($methods),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function normalize_plugin_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');

    return $value !== '' ? $value : 'plugin_' . substr(md5($value), 0, 8);
}

function normalize_string_list(mixed $value): array
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

function map_payment_method(string $value): string
{
    $normalized = strtolower(trim($value));

    return match ($normalized) {
        'wxpay', 'wechat', 'wechatpay' => 'wechat',
        'alipay' => 'alipay',
        'qqpay', 'qq', 'qqwallet' => 'qqpay',
        'bank', 'unionpay', 'yinlian' => 'unionpay',
        'yunshanfu', 'cloudquickpass' => 'cloudquickpass',
        'douyinpay', 'douyin' => 'douyin',
        'jdpay' => 'jdpay',
        'paypal' => 'paypal',
        'ecny' => 'ecny',
        'usdttrc20', 'trc20', 'usdt-trc20' => 'trc20',
        'usdtpolygon', 'polygon', 'matic' => 'polygon',
        'usdtaptos', 'aptos' => 'aptos',
        'trx' => 'trx',
        default => PluginService::normalizeMethodCode($normalized),
    };
}

function detect_kind(string $sourceName, string $title, array $payTypes, array $meta): string
{
    $text = strtolower($sourceName . ' ' . $title . ' ' . (string)($meta['note'] ?? ''));

    foreach ($payTypes as $payType) {
        if (PluginService::isChainMethodCode(map_payment_method($payType))) {
            return 'chain';
        }
    }

    if (str_contains($text, '免签') || str_contains($text, '码支付') || str_contains($text, 'qrcode')) {
        return 'qrcode';
    }
    if (str_contains($text, '官方') || str_contains($text, 'v3') || str_contains($text, 'app支付')) {
        return 'gateway';
    }
    if (str_contains($text, '国际') || str_contains($text, 'paypal') || str_contains($text, 'stripe') || str_contains($text, 'airwallex')) {
        return 'international';
    }

    return 'gateway';
}

function detect_capabilities(string $kind, string $pluginDir): array
{
    $capabilities = ['create', 'query', 'notify'];
    if ($kind === 'chain') {
        $capabilities = ['address_match', 'chain_confirm', 'notify'];
    }
    if (is_file($pluginDir . DIRECTORY_SEPARATOR . 'server.php')) {
        $capabilities[] = 'daemon';
    }
    if (glob($pluginDir . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . '*.html')) {
        $capabilities[] = 'render';
    }

    return array_values(array_unique($capabilities));
}

function build_settings_schema(array $meta): array
{
    $inputs = is_array($meta['inputs'] ?? null) ? $meta['inputs'] : [];
    $schema = [];

    foreach ($inputs as $key => $input) {
        if (!is_array($input)) {
            continue;
        }

        $fieldType = normalize_field_type((string)($input['type'] ?? 'input'));
        $field = [
            'key' => (string)$key,
            'label' => trim((string)($input['name'] ?? $key)),
            'type' => $fieldType,
        ];

        if (array_key_exists('required', $input)) {
            $field['required'] = in_array((string)$input['required'], ['1', 'true'], true) || $input['required'] === true;
        }
        if (!empty($input['note'])) {
            $field['note'] = trim((string)$input['note']);
        }
        if (!empty($input['show'])) {
            $field['show'] = trim((string)$input['show']);
        }
        if (!empty($input['accept'])) {
            $field['accept'] = trim((string)$input['accept']);
        }
        if (isset($input['options']) && is_array($input['options'])) {
            $field['options'] = array_values(array_map(static fn($item): string => trim((string)$item), $input['options']));
        }

        $schema[] = $field;
    }

    return $schema;
}

function build_default_settings(array $meta, string $kind, string $pluginSlug): array
{
    $defaults = [
        'enabled' => true,
        'source_plugin' => (string)($meta['name'] ?? $pluginSlug),
    ];

    if ($kind === 'chain') {
        $defaults['confirmations'] = 2;
        $defaults['listener'] = 'manual';
    } else {
        $defaults['mode'] = $kind;
        $defaults['notify_retry'] = 5;
    }

    $inputs = is_array($meta['inputs'] ?? null) ? $meta['inputs'] : [];
    foreach ($inputs as $key => $input) {
        if (!is_array($input)) {
            continue;
        }

        $default = $input['value'] ?? '';
        if (is_array($default) || is_object($default)) {
            continue;
        }
        $defaults[(string)$key] = is_bool($default) ? $default : trim((string)$default);
    }

    return $defaults;
}

function normalize_field_type(string $type): string
{
    $normalized = strtolower(trim($type));

    return match ($normalized) {
        'textarea' => 'textarea',
        'radio', 'select' => 'select',
        'upload', 'file' => 'file',
        'number' => 'number',
        default => 'text',
    };
}

function build_config_php(
    string $kind,
    array $capabilities,
    array $paymentMethods,
    array $defaultSettings,
    array $settingsSchema,
    array $meta,
    string $sourceName,
    string $title
): string {
    $config = [
        'kind' => $kind,
        'capabilities' => $capabilities,
        'payment_methods' => $paymentMethods,
        'default_settings' => $defaultSettings,
        'settings_schema' => $settingsSchema,
        'source' => [
            'vendor' => 'epay_pro',
            'plugin' => $sourceName,
            'title' => $title,
            'link' => trim((string)($meta['link'] ?? '')),
            'note' => trim((string)($meta['note'] ?? '')),
        ],
    ];

    $export = var_export($config, true);
    $export = preg_replace('/^([ ]*)array \(/m', '$1[', $export) ?? $export;
    $export = preg_replace('/\)(,?)$/m', ']$1', $export) ?? $export;

    return "<?php\n\nreturn {$export};\n";
}

function ensure_dir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

function remove_dir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            remove_dir($full);
            @rmdir($full);
            continue;
        }

        @unlink($full);
    }

    @rmdir($path);
}

function copy_dir(string $source, string $target): void
{
    ensure_dir($target);
    $items = scandir($source) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $targetPath = $target . DIRECTORY_SEPARATOR . $item;
        if (is_dir($sourcePath)) {
            copy_dir($sourcePath, $targetPath);
            continue;
        }

        copy($sourcePath, $targetPath);
    }
}
