<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\service\system\PluginService;

$forbiddenTopLevel = [
    'plugin_settings',
    'schema_audit_summary',
];

$forbiddenItemKeys = [
    'settings_schema',
    'default_settings',
    'schema_audit',
    'health',
    'capabilities',
    'runtime_supports',
    'settings',
    'channels',
    'tasks',
    'notify_logs',
];

$catalog = PluginService::adminCatalog();
$topLevelLeaks = array_values(array_intersect($forbiddenTopLevel, array_keys($catalog)));
$itemLeaks = [];

foreach ((array)($catalog['items'] ?? []) as $index => $item) {
    if (!is_array($item)) {
        $itemLeaks[] = ['index' => $index, 'keys' => ['<non-array>']];
        continue;
    }

    $leaked = array_values(array_intersect($forbiddenItemKeys, array_keys($item)));
    if ($leaked !== []) {
        $itemLeaks[] = [
            'index' => $index,
            'code' => (string)($item['code'] ?? ''),
            'keys' => $leaked,
        ];
    }
}

$ok = $topLevelLeaks === [] && $itemLeaks === [] && count((array)($catalog['items'] ?? [])) > 0;

echo json_encode([
    'items' => count((array)($catalog['items'] ?? [])),
    'methods' => count((array)($catalog['methods'] ?? [])),
    'top_level_leaks' => $topLevelLeaks,
    'item_leaks' => $itemLeaks,
    'ok' => $ok,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
