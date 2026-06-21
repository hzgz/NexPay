<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

use app\service\system\JsonStoreService;
use app\service\system\PluginRuntimeService;
use app\service\system\PluginService;

$definitions = PluginRuntimeService::discoverDefinitions();
$items = [];

foreach ($definitions as $definition) {
    $code = trim((string)($definition['code'] ?? ''));
    if ($code === '') {
        continue;
    }

    $items[] = [
        'code' => $code,
        'name' => trim((string)($definition['name'] ?? $code)),
        'version' => trim((string)($definition['version'] ?? '1.0.0')),
        'status' => '停用',
        'status_code' => 0,
        'description' => trim((string)($definition['description'] ?? '')),
        'installed_at' => trim((string)($definition['installed_at'] ?? date('Y-m-d H:i:s'))),
        'group' => trim((string)($definition['group'] ?? 'pay')),
        'kind' => trim((string)($definition['kind'] ?? 'general')),
        'provider' => trim((string)($definition['provider'] ?? '')),
        'provider_file' => trim((string)($definition['provider_file'] ?? '')),
        'config_file' => trim((string)($definition['config_file'] ?? '')),
        'route_file' => trim((string)($definition['route_file'] ?? '')),
        'link' => trim((string)($definition['link'] ?? '')),
        'developer' => trim((string)($definition['developer'] ?? '官方')),
        'source_vendor' => trim((string)($definition['source_vendor'] ?? '')),
        'capabilities' => $definition['capabilities'] ?? [],
        'payment_methods' => $definition['payment_methods'] ?? [],
        'display_payment_methods' => $definition['display_payment_methods'] ?? ($definition['payment_methods'] ?? []),
        'transfer_methods' => $definition['transfer_methods'] ?? [],
        'default_settings' => $definition['default_settings'] ?? [],
        'settings_schema' => $definition['settings_schema'] ?? [],
    ];
}

JsonStoreService::save('plugins', $items);

$scan = PluginService::scan();

echo json_encode([
    'plugins' => count($items),
    'scanned' => $scan['scanned'] ?? 0,
    'items' => count($scan['items'] ?? []),
    'methods' => count(PluginService::methods()),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
