<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\OrderService;

class SystemCleanupService
{
    private const STORE_DEFINITIONS = [
        'orders_local' => [
            'label' => '订单记录',
            'copy' => '清理超过保留天数的订单主记录。',
            'date_fields' => ['created_at', 'updated_at'],
            'default_days' => 30,
        ],
        'order_events_local' => [
            'label' => '订单事件流水',
            'copy' => '清理订单创建、支付成功等生命周期事件流水。',
            'date_fields' => ['event_time', 'created_at'],
            'default_days' => 30,
        ],
        'callback_queue_local' => [
            'label' => '回调记录',
            'copy' => '清理历史回调队列与回调结果记录。',
            'date_fields' => ['updated_at', 'created_at'],
            'default_days' => 30,
        ],
        'refunds_local' => [
            'label' => '退款记录',
            'copy' => '清理超过保留天数的退款记录。',
            'date_fields' => ['updated_at', 'created_at'],
            'default_days' => 30,
        ],
        'payout_events_local' => [
            'label' => '退款代付事件流水',
            'copy' => '清理退款、代付创建、待处理、成功、失败等生命周期事件流水。',
            'date_fields' => ['event_time', 'created_at'],
            'default_days' => 30,
        ],
        'settlements_local' => [
            'label' => '提现记录',
            'copy' => '清理历史提现申请与审核记录。',
            'date_fields' => ['updated_at', 'created_at'],
            'default_days' => 30,
        ],
        'transfers_local' => [
            'label' => '代付记录',
            'copy' => '清理超过保留天数的代付记录。',
            'date_fields' => ['updated_at', 'created_at'],
            'default_days' => 30,
        ],
        'fund_flows_local' => [
            'label' => '资金明细',
            'copy' => '清理历史余额变动与流水明细。',
            'date_fields' => ['created_at', 'updated_at'],
            'default_days' => 30,
        ],
        'merchant_operation_logs' => [
            'label' => '商户操作日志',
            'copy' => '清理后台处理商户业务时留下的操作日志。',
            'date_fields' => ['created_at', 'updated_at'],
            'default_days' => 30,
        ],
        'admin_operation_logs' => [
            'label' => '管理员操作日志',
            'copy' => '清理后台人工补偿、同步和运营处理留下的管理员操作日志。',
            'date_fields' => ['created_at', 'updated_at'],
            'default_days' => 30,
        ],
        'plugin_notify_logs' => [
            'label' => '插件通知日志',
            'copy' => '清理插件回调、通知和网关联调日志。',
            'date_fields' => ['created_at'],
            'default_days' => 30,
        ],
        'provider_test_logs' => [
            'label' => '服务商测试日志',
            'copy' => '清理邮件、短信等 provider 测试发送日志。',
            'date_fields' => ['created_at'],
            'default_days' => 30,
        ],
        'realname_audit_logs' => [
            'label' => '实名审核日志',
            'copy' => '清理实名认证审核过程中的历史记录。',
            'date_fields' => ['created_at', 'updated_at'],
            'default_days' => 30,
        ],
        'tickets' => [
            'label' => '工单记录',
            'copy' => '清理历史工单内容与处理记录。',
            'date_fields' => ['updated_at', 'created_at'],
            'default_days' => 90,
        ],
        'task_runs' => [
            'label' => '任务执行日志',
            'copy' => '清理计划任务执行结果与运行日志。',
            'date_fields' => ['executed_at', 'created_at', 'updated_at'],
            'default_days' => 30,
        ],
    ];

    private const BUNDLE_DEFINITIONS = [
        'trade_records' => [
            'label' => '交易记录',
            'copy' => '订单、回调、退款、提现、代付和资金明细。',
            'targets' => [
                'orders_local',
                'order_events_local',
                'callback_queue_local',
                'refunds_local',
                'payout_events_local',
                'settlements_local',
                'transfers_local',
                'fund_flows_local',
            ],
            'default_days' => 30,
        ],
        'runtime_logs' => [
            'label' => '运行日志',
            'copy' => '商户操作、插件通知、服务商测试、实名审核和任务运行日志。',
            'targets' => [
                'merchant_operation_logs',
                'admin_operation_logs',
                'plugin_notify_logs',
                'provider_test_logs',
                'realname_audit_logs',
                'task_runs',
            ],
            'default_days' => 30,
        ],
    ];

    public static function catalog(): array
    {
        $quickActions = [
            [
                'key' => 'runtime',
                'label' => '系统运行缓存',
                'copy' => '清理 runtime/cache 和 runtime/views 下的运行缓存。',
                'action' => 'runtime',
            ],
            [
                'key' => 'assets',
                'label' => '旧前端静态资源',
                'copy' => '清理后台与商户前端未被当前入口页引用的历史构建文件。',
                'action' => 'assets',
            ],
            [
                'key' => 'trade_records',
                'label' => '30 天前交易记录',
                'copy' => self::BUNDLE_DEFINITIONS['trade_records']['copy'],
                'action' => 'bundle',
                'bundle' => 'trade_records',
                'default_days' => self::BUNDLE_DEFINITIONS['trade_records']['default_days'],
            ],
            [
                'key' => 'runtime_logs',
                'label' => '30 天前运行日志',
                'copy' => self::BUNDLE_DEFINITIONS['runtime_logs']['copy'],
                'action' => 'bundle',
                'bundle' => 'runtime_logs',
                'default_days' => self::BUNDLE_DEFINITIONS['runtime_logs']['default_days'],
            ],
        ];

        $stores = [];
        foreach (self::STORE_DEFINITIONS as $store => $definition) {
            $stores[] = [
                'store' => $store,
                'label' => $definition['label'],
                'copy' => $definition['copy'],
                'default_days' => $definition['default_days'],
            ];
        }

        return [
            'quick_actions' => $quickActions,
            'stores' => $stores,
        ];
    }

    public static function execute(array $payload): array
    {
        $action = trim((string)($payload['action'] ?? ''));

        return match ($action) {
            'runtime' => self::clearRuntimeCache(),
            'assets' => self::clearStaticAssets(),
            'workspace' => self::clearWorkspaceCache(),
            'bundle' => self::cleanupBundle(
                trim((string)($payload['bundle'] ?? '')),
                self::normalizeDays($payload['days'] ?? null, 30)
            ),
            'store' => self::cleanupStore(
                trim((string)($payload['store'] ?? '')),
                self::normalizeDays($payload['days'] ?? null, 30)
            ),
            default => throw new BusinessException('不支持的清理类型', StatusCode::VALIDATION_ERROR),
        };
    }

    public static function clearRuntimeCache(): array
    {
        $targets = [
            runtime_path() . DIRECTORY_SEPARATOR . 'cache',
            runtime_path() . DIRECTORY_SEPARATOR . 'views',
        ];

        $removed = 0;
        foreach ($targets as $target) {
            $removed += self::removeDirectoryContents($target);
        }

        ConfigService::invalidateCache();
        OrderService::flushOrderLookupCache();

        return [
            'action' => 'runtime',
            'label' => '系统运行缓存',
            'removed_count' => $removed,
            'cleared_at' => date('Y-m-d H:i:s'),
        ];
    }

    public static function clearStaticAssets(): array
    {
        $sections = [
            'admin' => public_path('admin'),
            'user' => public_path('user'),
        ];

        $removed = 0;
        $details = [];

        foreach ($sections as $section => $rootPath) {
            $detail = self::clearStaticAssetsForBuild($section, $rootPath);
            $removed += (int)($detail['removed_count'] ?? 0);
            $details[] = $detail;
        }

        return [
            'action' => 'assets',
            'label' => '旧前端静态资源',
            'removed_count' => $removed,
            'details' => $details,
            'cleared_at' => date('Y-m-d H:i:s'),
        ];
    }

    public static function clearWorkspaceCache(): array
    {
        $runtime = self::clearRuntimeCache();
        $assets = self::clearStaticAssets();

        return [
            'action' => 'workspace',
            'label' => '全部运维缓存',
            'removed_count' => (int)($runtime['removed_count'] ?? 0) + (int)($assets['removed_count'] ?? 0),
            'sections' => [
                $runtime,
                $assets,
            ],
            'cleared_at' => date('Y-m-d H:i:s'),
        ];
    }

    public static function cleanupBundle(string $bundle, int $days): array
    {
        $definition = self::BUNDLE_DEFINITIONS[$bundle] ?? null;
        if ($definition === null) {
            throw new BusinessException('清理分组不存在', StatusCode::VALIDATION_ERROR);
        }

        $details = [];
        $removed = 0;
        $remaining = 0;

        foreach ($definition['targets'] as $target) {
            $detail = self::cleanupStore($target, $days);
            $removed += (int)($detail['removed_count'] ?? 0);
            $remaining += (int)($detail['remaining_count'] ?? 0);
            $details[] = $detail;
        }

        return [
            'action' => 'bundle',
            'bundle' => $bundle,
            'label' => $definition['label'],
            'days' => $days,
            'removed_count' => $removed,
            'remaining_count' => $remaining,
            'details' => $details,
            'cleared_at' => date('Y-m-d H:i:s'),
        ];
    }

    public static function cleanupStore(string $store, int $days): array
    {
        $definition = self::STORE_DEFINITIONS[$store] ?? null;
        if ($definition === null) {
            throw new BusinessException('清理项目不存在', StatusCode::VALIDATION_ERROR);
        }

        $items = JsonStoreService::load($store, []);
        if (!is_array($items)) {
            $items = [];
        }

        $cutoff = strtotime(date('Y-m-d 00:00:00', strtotime("-{$days} days")));
        $removed = 0;
        $next = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                $next[] = $item;
                continue;
            }

            $timestamp = self::resolveTimestamp($item, $definition['date_fields']);
            if ($timestamp !== null && $timestamp < $cutoff) {
                $removed++;
                continue;
            }

            $next[] = $item;
        }

        JsonStoreService::save($store, array_values($next));

        return [
            'action' => 'store',
            'store' => $store,
            'label' => $definition['label'],
            'days' => $days,
            'removed_count' => $removed,
            'remaining_count' => count($next),
            'cleared_at' => date('Y-m-d H:i:s'),
        ];
    }

    private static function clearStaticAssetsForBuild(string $section, string $rootPath): array
    {
        $indexPath = $rootPath . DIRECTORY_SEPARATOR . 'index.html';
        $assetPath = $rootPath . DIRECTORY_SEPARATOR . 'assets';

        if (!is_file($indexPath) || !is_dir($assetPath)) {
            return [
                'section' => $section,
                'removed_count' => 0,
                'kept_count' => 0,
            ];
        }

        $keep = self::collectReferencedAssets($indexPath, $assetPath);
        $removed = 0;
        $kept = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $fullPath = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($fullPath);
                continue;
            }

            $relative = self::normalizePath(substr($fullPath, strlen($assetPath) + 1));
            if (isset($keep[$relative])) {
                $kept++;
                continue;
            }

            if (@unlink($fullPath)) {
                $removed++;
            }
        }

        return [
            'section' => $section,
            'removed_count' => $removed,
            'kept_count' => $kept,
        ];
    }

    private static function collectReferencedAssets(string $indexPath, string $assetPath): array
    {
        $keep = [];
        $queue = self::extractAssetReferences((string)file_get_contents($indexPath));

        while ($queue !== []) {
            $relative = array_shift($queue);
            if ($relative === '' || isset($keep[$relative])) {
                continue;
            }

            $keep[$relative] = true;
            $fullPath = $assetPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($fullPath) || !self::isTextAsset($fullPath)) {
                continue;
            }

            $currentDir = dirname($relative);
            foreach (self::extractAssetReferences((string)file_get_contents($fullPath), $currentDir) as $nested) {
                if (!isset($keep[$nested])) {
                    $queue[] = $nested;
                }
            }
        }

        return $keep;
    }

    private static function extractAssetReferences(string $contents, string $currentDir = ''): array
    {
        $references = [];

        if (preg_match_all('~assets/([A-Za-z0-9._/-]+\.[A-Za-z0-9]+)~', $contents, $matches)) {
            foreach ($matches[1] as $match) {
                $relative = self::normalizePath($match);
                if ($relative !== '') {
                    $references[$relative] = true;
                }
            }
        }

        if (preg_match_all('~(?:(?:\./)|(?:\.\./))[A-Za-z0-9._/-]+\.[A-Za-z0-9]+~', $contents, $relativeMatches)) {
            foreach ($relativeMatches[0] as $match) {
                $relative = self::resolveRelativeAssetPath($currentDir, $match);
                if ($relative !== '') {
                    $references[$relative] = true;
                }
            }
        }

        return array_keys($references);
    }

    private static function resolveRelativeAssetPath(string $currentDir, string $reference): string
    {
        $segments = [];
        $base = trim(self::normalizePath($currentDir), '/');
        if ($base !== '' && $base !== '.') {
            $segments = explode('/', $base);
        }

        foreach (explode('/', self::normalizePath($reference)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private static function isTextAsset(string $path): bool
    {
        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['js', 'mjs', 'css', 'html', 'json', 'map', 'svg', 'txt'], true);
    }

    private static function resolveTimestamp(array $item, array $fields): ?int
    {
        foreach ($fields as $field) {
            $raw = trim((string)($item[$field] ?? ''));
            if ($raw === '') {
                continue;
            }

            $timestamp = strtotime($raw);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    private static function normalizeDays(mixed $value, int $fallback): int
    {
        $days = (int)($value ?? 0);
        if ($days <= 0) {
            $days = $fallback;
        }

        return max(1, min(3650, $days));
    }

    private static function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    private static function removeDirectoryContents(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $removed = 0;
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) {
                $removed += self::removeDirectoryContents($fullPath);
                if (@rmdir($fullPath)) {
                    $removed++;
                }
                continue;
            }

            if (@unlink($fullPath)) {
                $removed++;
            }
        }

        return $removed;
    }
}
