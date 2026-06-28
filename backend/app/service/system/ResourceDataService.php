<?php

namespace app\service\system;

use app\model\Merchant;
use app\model\MerchantBalance;
use app\model\MerchantUser;
use app\model\Order;
use app\service\payment\CallbackService;
use app\service\payment\LocalFundStore;
use app\service\payment\LocalOrderEventStore;
use app\service\payment\LocalOrderStore;
use app\service\payment\LocalPayoutEventStore;
use app\service\payment\LocalSettlementStore;
use app\service\payment\OpenApiGuardService;
use app\service\payment\OrderStatusService;
use app\service\payment\OrderService;
use app\service\payment\LocalTransferStore;
use app\service\payment\PluginNotifyLogService;
use Throwable;

class ResourceDataService
{
    public static function adminMerchants(): array
    {
        $groups = MerchantGroupService::all()['items'] ?? [];
        $items = [];
        $seenMerchantIds = [];
        $runtimeRows = self::merchantRuntimeRows();

        if (\database_available()) {
            try {
                $balances = [];
                foreach (MerchantBalance::select()->toArray() as $balance) {
                    $balances[(int)($balance['merchant_id'] ?? 0)] = self::row($balance);
                }

                $users = [];
                foreach (MerchantUser::select()->toArray() as $user) {
                    $userRow = self::row($user);
                    $users[(int)($userRow['merchant_id'] ?? 0)] = $userRow;
                }

                foreach (Merchant::order('id', 'desc')->select()->toArray() as $merchant) {
                    $row = self::row($merchant);
                    $merchantId = (int)($row['id'] ?? 0);
                    $balance = self::effectiveBalanceRow($merchantId, $balances[$merchantId] ?? []);
                    $user = $users[$merchantId] ?? [];
                    $username = (string)($user['username'] ?? '');
                    $row = array_replace($row, self::runtimeMerchantMeta($runtimeRows, $merchantId, $username));
                    if ($username !== '') {
                        $row['username'] = $username;
                    }

                    $items[] = self::adminMerchantRow($row, $balance, $groups, $merchantId, (string)($row['created_at'] ?? ''));
                    $seenMerchantIds[$merchantId] = true;
                }
            } catch (Throwable) {
            }
        }

        foreach (JsonStoreService::load('merchant_accounts', []) as $merchant) {
            if (!is_array($merchant)) {
                continue;
            }

            $merchantId = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0);
            if ($merchantId <= 0 || isset($seenMerchantIds[$merchantId])) {
                continue;
            }

            $credential = AccountService::merchantCredentialById($merchantId);
            $row = array_replace($merchant, is_array($credential) ? $credential : [], self::runtimeMerchantMeta($runtimeRows, $merchantId, (string)($merchant['username'] ?? '')));
            $balance = LocalFundStore::balanceForMerchant($merchantId);
            $items[] = self::adminMerchantRow($row, $balance, $groups, $merchantId, (string)($row['created_at'] ?? $row['registered_at'] ?? ''));
            $seenMerchantIds[$merchantId] = true;
        }

        usort($items, static function (array $left, array $right): int {
            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        return [
            'summary' => [
                'total' => count($items),
                'active' => count(array_filter($items, static fn(array $item): bool => (int)($item['status_code'] ?? 0) === 1)),
                'pending' => count(array_filter($items, static fn(array $item): bool => (int)($item['status_code'] ?? 0) === 0)),
                'disabled' => count(array_filter($items, static fn(array $item): bool => (int)($item['status_code'] ?? 0) !== 0 && (int)($item['status_code'] ?? 0) !== 1)),
            ],
            'items' => $items,
            'groups' => $groups,
        ];
    }

    private static function adminMerchantRow(array $row, array $balance, array $groups, int $merchantId, string $registeredAt): array
    {
        $statusCode = (int)($row['status'] ?? 0);

        return array_replace([
            'id' => $merchantId,
            'name' => self::merchantName($row, $merchantId),
            'username' => (string)($row['username'] ?? ''),
            'merchant_no' => (string)$merchantId,
            'appid' => (string)$merchantId,
            'group_name' => self::merchantGroupName($row, $groups),
            'contact_name' => self::merchantContactName($row, $merchantId),
            'email' => (string)($row['email'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'balance' => self::amount((string)($balance['balance'] ?? $balance['available'] ?? $row['balance'] ?? '0.00')),
            'rate' => self::rate((float)($row['platform_rate'] ?? 0)),
            'status' => self::merchantStatusLabel($statusCode),
            'status_code' => $statusCode,
            'registered_at' => $registeredAt,
        ], self::merchantAuditFields($row, $statusCode), self::merchantRealnameFields($row));
    }

    private static function merchantRuntimeRows(): array
    {
        $byId = [];
        $byUsername = [];

        foreach (JsonStoreService::load('merchant_accounts', []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            self::indexRuntimeMerchantRow($row, $byId, $byUsername);
        }

        foreach (JsonStoreService::load('merchant_auth_users', []) as $username => $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['username'] = (string)($row['username'] ?? $username);
            self::indexRuntimeMerchantRow($row, $byId, $byUsername);
        }

        return [
            'by_id' => $byId,
            'by_username' => $byUsername,
        ];
    }

    private static function indexRuntimeMerchantRow(array $row, array &$byId, array &$byUsername): void
    {
        $merchantId = (int)($row['merchant_id'] ?? $row['id'] ?? 0);
        $username = trim((string)($row['username'] ?? ''));

        if ($merchantId > 0) {
            $byId[$merchantId] = array_replace($byId[$merchantId] ?? [], $row);
        }

        if ($username !== '') {
            $byUsername[$username] = array_replace($byUsername[$username] ?? [], $row);
        }
    }

    private static function runtimeMerchantMeta(array $runtimeRows, int $merchantId, string $username = ''): array
    {
        if ($merchantId > 0 && is_array($runtimeRows['by_id'][$merchantId] ?? null)) {
            return $runtimeRows['by_id'][$merchantId];
        }

        $username = trim($username);
        if ($username !== '' && is_array($runtimeRows['by_username'][$username] ?? null)) {
            return $runtimeRows['by_username'][$username];
        }

        return [];
    }

    private static function merchantAuditFields(array $row, int $statusCode): array
    {
        $auditStatus = trim((string)($row['audit_status'] ?? ''));
        if ($auditStatus === '') {
            $auditStatus = match ($statusCode) {
                1 => 'approved',
                2 => 'disabled',
                default => 'pending',
            };
        }

        return [
            'audit_status' => $auditStatus,
            'register_fee_status' => (string)($row['register_fee_status'] ?? 'none'),
            'register_fee_amount' => self::amount((string)($row['register_fee_amount'] ?? '0.00')),
            'register_fee_trade_no' => (string)($row['register_fee_trade_no'] ?? ''),
            'register_fee_pay_url' => (string)($row['register_fee_pay_url'] ?? ''),
            'register_fee_paid_at' => (string)($row['register_fee_paid_at'] ?? ''),
            'audit_reason' => (string)($row['audit_reason'] ?? ''),
            'audited_at' => (string)($row['audited_at'] ?? ''),
            'audited_by' => (string)($row['audited_by'] ?? ''),
        ];
    }

    private static function merchantRealnameFields(array $row): array
    {
        $realname = is_array($row['realname'] ?? null) ? $row['realname'] : [];
        $status = trim((string)($realname['status'] ?? $row['realname_status'] ?? ''));
        if ($status === '') {
            $status = 'unsubmitted';
        }

        return [
            'realname_status' => $status,
            'realname_status_label' => self::realnameStatusLabel($status),
            'realname_real_name' => (string)($realname['real_name'] ?? ''),
            'realname_id_card' => (string)($realname['id_card'] ?? ''),
            'realname_provider' => (string)($realname['provider'] ?? ''),
            'realname_result' => (string)($realname['result'] ?? $row['realname_result'] ?? ''),
            'realname_last_error' => (string)($realname['last_error'] ?? ''),
            'realname_submitted_at' => (string)($realname['submitted_at'] ?? $row['realname_submitted_at'] ?? ''),
            'realname_reviewed_at' => (string)($realname['reviewed_at'] ?? $row['realname_reviewed_at'] ?? ''),
            'realname_reviewed_by' => (string)($realname['reviewed_by'] ?? $row['realname_reviewed_by'] ?? ''),
            'realname_review_reason' => (string)($realname['review_reason'] ?? ''),
        ];
    }

    public static function adminOrders(): array
    {
        $items = [];
        $rechargeOrders = [];
        $packageOrders = [];
        $seenTradeNos = [];
        $merchants = [];

        if (\database_available()) {
            try {
                foreach (Merchant::select()->toArray() as $merchant) {
                    $row = self::row($merchant);
                    $merchants[(int)($row['id'] ?? 0)] = $row;
                }
            } catch (Throwable) {
            }
        }

        foreach (self::loadAdminOrderRecords(200) as $row) {
            if (self::isDeletedOrderRow($row)) {
                continue;
            }
            $bucket = self::adminOrderBucket($row);
            if ($bucket === '') {
                continue;
            }

            $merchant = $merchants[(int)($row['merchant_id'] ?? 0)] ?? [];
            self::appendAdminOrderBucketRow(
                $bucket,
                $row,
                (string)($merchant['name'] ?? ''),
                $items,
                $rechargeOrders,
                $packageOrders,
                $seenTradeNos
            );
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });
        self::appendAdminSystemFundOrders($items, $rechargeOrders, $packageOrders, $seenTradeNos, $merchants);
        usort($rechargeOrders, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });
        usort($packageOrders, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return [
            'items' => array_slice($items, 0, 200),
            'recharge_orders' => array_slice($rechargeOrders, 0, 200),
            'package_orders' => array_slice($packageOrders, 0, 200),
            'refunds' => self::adminRefunds(),
            'transfers' => self::adminTransfers(),
            'payout_summary' => OrderService::payoutSummary(),
            'earnings' => self::adminEarnings(),
            'settlements' => SettlementService::adminSettlements(),
        ];
    }

    public static function adminFees(): array
    {
        $merchantRates = self::merchantRateRows();
        $rules = [];
        $seen = [];

        foreach ($merchantRates as $row) {
            $rate = (string)($row['rate'] ?? '0.00%');
            if (isset($seen[$rate])) {
                continue;
            }

            $seen[$rate] = true;
            $rules[] = [
                'name' => '商户基础费率 ' . $rate,
                'type' => '订单手续费',
                'rate' => $rate,
                'status' => '启用',
                'desc' => '来自商户资料中的 platform_rate 配置',
            ];
        }

        return [
            'rules' => $rules,
            'merchant_rates' => $merchantRates,
        ];
    }

    public static function adminLogs(): array
    {
        $adminLogs = CompensationAuditLogService::adminLogs();

        foreach (TaskService::runs() as $run) {
            $adminLogs[] = [
                'operator' => trim((string)($run['operator'] ?? 'system')) ?: 'system',
                'action' => '执行任务：' . (string)($run['task_name'] ?? $run['task_key'] ?? ''),
                'ip' => '',
                'created_at' => (string)($run['executed_at'] ?? ''),
                'summary' => trim((string)($run['result'] ?? '')),
                'detail' => [
                    'task_key' => (string)($run['task_key'] ?? ''),
                    'status' => (string)($run['status'] ?? ''),
                    'result' => (string)($run['result'] ?? ''),
                ],
            ];
        }

        usort($adminLogs, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return [
            'admin_logs' => $adminLogs,
            'merchant_logs' => CompensationAuditLogService::merchantLogs(),
            'callback_summary' => CallbackService::summary(),
            'callback_logs' => CallbackService::logs(),
            'callback_events' => self::callbackEventLogs(),
            'payout_events' => self::payoutEventLogs(),
            'provider_logs' => ProviderRuntimeService::testLogs(),
            'realname_logs' => RealnameRuntimeService::logs(),
            'plugin_notify_logs' => PluginNotifyLogService::logs(),
        ];
    }

    public static function merchantOrders(int $merchantId): array
    {
        $items = [];
        foreach (self::loadMerchantOrders($merchantId) as $order) {
            $row = self::row($order);
            if (self::isDeletedOrderRow($row)) {
                continue;
            }

            $items[] = self::orderRow($row, '', true);
        }

        return [
            'items' => $items,
            'callback_summary' => CallbackService::summary($merchantId),
            'callback_logs' => CallbackService::logs($merchantId),
            'callback_events' => self::callbackEventLogs($merchantId),
            'payout_events' => self::payoutEventLogs($merchantId),
        ];
    }

    public static function merchantFunds(int $merchantId): array
    {
        $balance = null;
        if (\database_available()) {
            try {
                $balance = MerchantBalance::where('merchant_id', $merchantId)->find();
            } catch (Throwable) {
                $balance = null;
            }
        }

        $effectiveBalance = self::effectiveBalanceRow($merchantId, [
            'balance' => (string)($balance?->balance ?? '0.00'),
            'frozen_balance' => (string)($balance?->frozen_balance ?? '0.00'),
            'total_recharge' => (string)($balance?->total_recharge ?? '0.00'),
            'total_consumption' => (string)($balance?->total_consumption ?? '0.00'),
        ]);
        $settlements = SettlementService::merchantSettlements($merchantId);
        $systemOrders = self::loadMerchantFundOrders($merchantId);
        $orderLookup = self::merchantFlowOrderLookup($merchantId, $systemOrders);
        $systemOrderRows = self::merchantFundOrderRowsFromOrders($merchantId, $systemOrders);
        $flows = self::merchantFundFlows($merchantId, $systemOrderRows, $orderLookup);
        $systemOrderCount = self::merchantFundOrderCount($merchantId, $systemOrders);

        return [
            'balance' => [
                'available' => self::amount((string)($effectiveBalance['balance'] ?? $effectiveBalance['available'] ?? '0.00')),
                'frozen' => self::amount((string)($effectiveBalance['frozen_balance'] ?? $effectiveBalance['frozen'] ?? '0.00')),
                'total_recharge' => self::amount((string)($effectiveBalance['total_recharge'] ?? '0.00')),
                'total_consumption' => self::amount((string)($effectiveBalance['total_consumption'] ?? '0.00')),
            ],
            'recharge_options' => MerchantFundService::rechargeOptions($merchantId),
            'withdraw_options' => SettlementService::withdrawOptions($merchantId),
            'payout_summary' => OrderService::payoutSummary($merchantId),
            'pending_payouts' => self::merchantPendingPayouts($merchantId),
            'settlements' => $settlements,
            'flow_stats' => self::merchantFundStats($effectiveBalance, $settlements, $systemOrderCount),
            'flows' => $flows,
        ];
    }

    public static function merchantApiInfo(int $merchantId, int $userId = 0): array
    {
        $merchant = self::merchantProfile($merchantId, $userId);
        $merchant['mch_key'] = MerchantApiService::ensureMd5Key(
            $merchantId,
            $userId,
            (string)($merchant['mch_key'] ?? '')
        );
        $baseUrl = self::resolveMerchantApiBaseUrl();
        $platformPublicKey = MerchantApiService::compactPublicKey(ConfigService::platformPublicKey());
        $merchantPublicKey = MerchantApiService::compactPublicKey((string)($merchant['rsa_public_key'] ?? ''));
        $merchantPrivateKey = MerchantApiService::compactPrivateKey((string)($merchant['rsa_private_key'] ?? ''));
        if ($merchantPublicKey === '' && $merchantPrivateKey !== '') {
            $merchantPublicKey = MerchantApiService::derivePublicKey($merchantPrivateKey);
        }
        $signMode = MerchantApiService::signMode($merchantId);
        $monitorPayload = [
            'site' => $baseUrl,
            'pid' => $merchantId,
            'key' => (string)($merchant['mch_key'] ?? ''),
        ];
        $monitorPayloadJson = (string)json_encode($monitorPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $monitorSignExample = OpenApiGuardService::compatSignExample('/api/report/' . $merchantId);

        return [
            'pid' => (string)$merchantId,
            'user_id' => $merchantId,
            'merchant_uid' => $merchantId,
            'site_url' => $baseUrl,
            'mch_key' => (string)($merchant['mch_key'] ?? ''),
            'rsa_public_key' => $merchantPublicKey,
            'rsa_private_key' => $merchantPrivateKey,
            'base_url' => $baseUrl,
            'gateway_v1' => $baseUrl . '/mapi.php',
            'gateway_v2' => $baseUrl . '/api/pay/create',
            'interface_url' => $baseUrl,
            'monitor_site_url' => $baseUrl,
            'monitor_payload' => $monitorPayload,
            'monitor_payload_json' => $monitorPayloadJson,
            'monitor_payload_base64' => base64_encode($monitorPayloadJson),
            'monitor_sign_example' => $monitorSignExample,
            'monitor_verify_url' => $baseUrl . '/api/Software/verify',
            'monitor_heartbeat_url' => $baseUrl . '/api/Software/heartbeat',
            'monitor_check_order_url' => $baseUrl . '/api/Software/checkOrder',
            'monitor_pc_notify_url' => $baseUrl . '/api/Software/PCNotify',
            'monitor_report_url' => $baseUrl . '/api/report/' . $merchantId,
            'platform_public_key' => $platformPublicKey,
            'merchant_public_key' => $merchantPublicKey,
            'merchant_private_key' => $merchantPrivateKey,
            'doc_v1_url' => '/doc',
            'doc_v2_url' => '/doc',
            'sign_mode' => $signMode,
            'sign_mode_options' => [
                ['value' => 'md5_rsa', 'label' => 'MD5 + RSA 签名', 'description' => '兼容模式'],
                ['value' => 'rsa_only', 'label' => '仅 RSA 签名', 'description' => '安全模式'],
            ],
            'interface_sections' => [
                [
                    'title' => 'V1',
                    'items' => [
                        ['method' => 'POST', 'path' => '/mapi.php'],
                        ['method' => 'POST', 'path' => '/api.php'],
                    ],
                ],
                [
                    'title' => 'V2',
                    'items' => [
                        ['method' => 'POST', 'path' => '/api/pay/create'],
                        ['method' => 'POST', 'path' => '/api/pay/query'],
                        ['method' => 'POST', 'path' => '/api/pay/refund'],
                        ['method' => 'POST', 'path' => '/api/transfer/*'],
                    ],
                ],
            ],
            'merchant_id' => $merchantId,
            'merchant_name' => (string)($merchant['merchant_name'] ?? $merchant['name'] ?? ''),
            'contact_name' => (string)($merchant['contact_name'] ?? ''),
        ];
    }

    public static function dashboardLatestOrderRow(array $row, string $merchantName = ''): array
    {
        $normalized = self::orderRow($row, $merchantName, false);

        return [
            'trade_no' => (string)($normalized['trade_no'] ?? ''),
            'out_trade_no' => (string)($normalized['out_trade_no'] ?? ''),
            'merchant' => (string)($normalized['merchant'] ?? ''),
            'channel_code' => (string)($normalized['channel_code'] ?? ''),
            'method_name' => (string)($normalized['method_name'] ?? ''),
            'subject' => (string)($normalized['subject'] ?? ''),
            'amount' => (string)($normalized['amount'] ?? '0.00'),
            'status' => (string)($normalized['status'] ?? ''),
            'status_code' => (int)($normalized['status_code'] ?? 0),
            'created_at' => (string)($normalized['created_at'] ?? ''),
        ];
    }

    public static function merchantPackages(int $merchantId = 0): array
    {
        $market = array_map(static function (array $item): array {
            return [
                'id' => (int)($item['id'] ?? 0),
                'name' => (string)($item['name'] ?? ''),
                'price' => (string)($item['price'] ?? '0.00'),
                'duration' => (int)($item['duration_days'] ?? 0) . ' 天',
                'benefits' => array_values(array_filter(array_map('strval', (array)($item['benefits'] ?? [])))),
            ];
        }, PackageService::activeBusinessPackages());

        return [
            'market' => $market,
            'my_packages' => PackageService::merchantPackages($merchantId),
            'payment_methods' => SettingsService::frontendPaymentMethodOptions('system_checkout'),
        ];
    }

    public static function merchantTelegram(): array
    {
        return [
            'bot' => JsonStoreService::load('telegram_bot', []),
            'bindings' => JsonStoreService::load('merchant_telegram_bindings', []),
        ];
    }

    private static function resolveMerchantApiBaseUrl(): string
    {
        $request = request();
        if ($request) {
            $host = trim((string)$request->host());
            if ($host !== '') {
                return (is_https() ? 'https://' : 'http://') . $host;
            }
        }

        return rtrim((string)ConfigService::gatewayBaseUrl(), '/');
    }

    private static function merchantProfile(int $merchantId, int $userId = 0): array
    {
        $dbProfile = [];
        $apiProfile = MerchantApiService::credentialProfile($merchantId);
        if (\database_available()) {
            try {
                $merchant = Merchant::find($merchantId);
                if ($merchant) {
                    $dbProfile = self::row($merchant);
                }
            } catch (Throwable) {
            }
        }

        $credential = AccountService::merchantCredentialById($merchantId);
        if (is_array($credential)) {
            $merged = array_replace($dbProfile, $credential, $apiProfile);
            if ($dbProfile !== []) {
                $merged = self::preferDatabaseMerchantFields($merged, $dbProfile);
            }

            return $merged;
        }

        try {
            return array_replace(
                AccountService::merchantProfile($userId > 0 ? $userId : $merchantId),
                $apiProfile
            );
        } catch (Throwable) {
        }

        return array_replace($dbProfile, $apiProfile);
    }

    private static function effectiveBalanceRow(int $merchantId, array $balance = []): array
    {
        if ($merchantId > 0 && LocalFundStore::hasBusinessFlowsForMerchant($merchantId)) {
            $local = LocalFundStore::balanceForMerchant($merchantId);

            return array_replace($balance, [
                'balance' => (string)($local['available'] ?? '0.00'),
                'available' => (string)($local['available'] ?? '0.00'),
                'frozen_balance' => (string)($local['frozen'] ?? '0.00'),
                'frozen' => (string)($local['frozen'] ?? '0.00'),
                'total_recharge' => (string)($local['total_recharge'] ?? '0.00'),
                'total_consumption' => (string)($local['total_consumption'] ?? '0.00'),
            ]);
        }

        return $balance;
    }

    private static function preferDatabaseMerchantFields(array $merged, array $dbProfile): array
    {
        foreach ([
            'id',
            'uid',
            'appid',
            'mch_key',
            'rsa_private_key',
            'rsa_public_key',
            'status',
            'platform_rate',
            'daily_limit',
            'registered_ip',
            'last_login_ip',
            'last_login_time',
            'email',
            'phone',
            'contact_name',
            'notify_url',
            'return_url',
            'white_ip',
            'name',
        ] as $field) {
            if (array_key_exists($field, $dbProfile)) {
                $merged[$field] = $dbProfile[$field];
            }
        }

        if (trim((string)($dbProfile['name'] ?? '')) !== '') {
            $merged['merchant_name'] = (string)$dbProfile['name'];
        }

        return $merged;
    }

    private static function loadMerchantOrders(int $merchantId): array
    {
        $rows = [];
        $seenKeys = [];

        if (\database_available()) {
            try {
                foreach (Order::where('merchant_id', $merchantId)->order('id', 'desc')->limit(100)->select()->toArray() as $row) {
                    if (!self::shouldIncludeMerchantOrderList($row)) {
                        continue;
                    }

                    $rows[] = $row;
                    $seenKeys[self::orderDedupKey($row)] = true;
                }
            } catch (Throwable) {
            }
        }

        foreach (LocalOrderStore::ordersByMerchant($merchantId) as $order) {
            $row = self::row($order);
            if (!self::shouldIncludeMerchantOrderList($row)) {
                continue;
            }

            $dedupKey = self::orderDedupKey($row);
            if (isset($seenKeys[$dedupKey])) {
                continue;
            }

            $rows[] = $row;
            $seenKeys[$dedupKey] = true;
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return self::normalizeLoadedOrderRowsForRead(
            array_slice($rows, 0, 100),
            'resource-merchant-orders-read',
            16
        );
    }

    private static function merchantFundFlows(int $merchantId, ?array $systemOrderRows = null, ?array $orderLookup = null): array
    {
        $flows = [];
        $orderLookup ??= self::merchantFlowOrderLookup($merchantId);

        foreach (LocalFundStore::businessFlowsForMerchant($merchantId) as $row) {
            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $refType = (string)($row['ref_type'] ?? '');
            $tradeNo = self::firstNonEmptyString(
                $meta['trade_no'] ?? '',
                in_array((string)($row['ref_type'] ?? ''), ['recharge', 'order_income'], true) ? (string)($row['ref_no'] ?? '') : ''
            );
            $outTradeNo = self::firstNonEmptyString($meta['out_trade_no'] ?? '');
            $orderRow = self::lookupMerchantOrderRow($merchantId, $orderLookup, $tradeNo, $outTradeNo);
            $methodCode = self::firstNonEmptyString($meta['channel_code'] ?? '', self::orderMethodCode($orderRow));
            $statusInfo = self::displayFundFlowStatusMeta($row);
            $displayTradeNo = self::firstNonEmptyString($tradeNo, (string)($orderRow['trade_no'] ?? ''));
            if ($displayTradeNo === '') {
                $displayTradeNo = self::firstNonEmptyString((string)($row['ref_no'] ?? ''), $outTradeNo, (string)($orderRow['out_trade_no'] ?? ''));
            }
            $displayOutTradeNo = self::firstNonEmptyString($outTradeNo, (string)($orderRow['out_trade_no'] ?? ''));
            if ($displayOutTradeNo !== '' && $displayOutTradeNo === $displayTradeNo) {
                $displayOutTradeNo = '';
            }

            $flows[] = [
                'row_key' => 'flow:' . (string)($row['id'] ?? md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
                'type' => (string)($row['type'] ?? ''),
                'trade_no' => $displayTradeNo,
                'out_trade_no' => $displayOutTradeNo,
                'source_label' => self::fundFlowSourceLabel($refType, $orderRow),
                'subject' => self::fundFlowSubject((string)($row['type'] ?? ''), $refType, $meta, $orderRow),
                'method_code' => $methodCode,
                'method_name' => self::fundFlowMethodName((string)($row['ref_type'] ?? ''), $meta, $orderRow, $methodCode),
                'amount' => self::displayFlowAmount($row),
                'balance_after' => self::amount((string)($row['balance_after'] ?? '0.00')),
                'status' => (string)($statusInfo['label'] ?? '成功'),
                'status_code' => (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_SUCCESS),
                'status_key' => (string)($statusInfo['key'] ?? 'success'),
                'status_theme' => (string)($statusInfo['theme'] ?? 'success'),
                'created_at' => (string)($row['created_at'] ?? ''),
                'remark' => self::fundFlowRemark($row, $orderRow),
            ];
        }

        foreach (LocalTransferStore::businessTransfers($merchantId) as $transfer) {
            $row = self::row($transfer);
            if ((int)($row['status'] ?? 0) === 1) {
                continue;
            }

            $transferRemark = trim((string)($row['last_error'] ?? ''));
            if ($transferRemark === '') {
                $transferRemark = (string)($row['result'] ?? $row['biz_no'] ?? '');
            }
            $statusInfo = self::transferStatusMeta(
                (int)($row['status'] ?? 0),
                (string)($row['result'] ?? ''),
                (string)($row['last_error'] ?? '')
            );

            $flows[] = [
                'row_key' => 'transfer:' . (string)($row['biz_no'] ?? md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
                'type' => '转账',
                'trade_no' => '',
                'out_trade_no' => (string)($row['out_biz_no'] ?? ''),
                'source_label' => '余额代付',
                'subject' => '余额代付',
                'method_code' => '',
                'method_name' => '余额代付',
                'amount' => '-' . self::amount((string)($row['money'] ?? '0.00')),
                'balance_after' => '',
                'status' => (string)($statusInfo['label'] ?? '处理中'),
                'status_code' => (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING),
                'status_key' => (string)($statusInfo['key'] ?? 'pending'),
                'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
                'created_at' => (string)($row['created_at'] ?? ''),
                'remark' => $transferRemark,
            ];
        }

        foreach (LocalTransferStore::businessRefunds($merchantId) as $refund) {
            $row = self::row($refund);
            if ((int)($row['status'] ?? 0) === 1) {
                continue;
            }

            $orderRow = self::lookupMerchantOrderRow(
                $merchantId,
                $orderLookup,
                (string)($row['trade_no'] ?? ''),
                (string)($row['out_trade_no'] ?? '')
            );
            $methodCode = self::orderMethodCode($orderRow);
            $statusInfo = self::refundStatusMeta(
                (int)($row['status'] ?? 0),
                (string)($row['result'] ?? ''),
                (string)($row['last_error'] ?? '')
            );

            $flows[] = [
                'row_key' => 'refund:' . (string)($row['refund_no'] ?? md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
                'type' => '退款',
                'trade_no' => (string)($row['trade_no'] ?? ''),
                'out_trade_no' => self::firstNonEmptyString((string)($row['out_trade_no'] ?? ''), (string)($orderRow['out_trade_no'] ?? '')),
                'source_label' => self::firstNonEmptyString(self::orderSourceLabel($orderRow), '原路退款'),
                'subject' => self::fundFlowSubject('退款', 'refund', [], $orderRow),
                'method_code' => $methodCode,
                'method_name' => self::fundFlowMethodName('refund', [], $orderRow, $methodCode),
                'amount' => '-' . self::amount((string)($row['reducemoney'] ?? $row['money'] ?? '0.00')),
                'balance_after' => '',
                'status' => (string)($statusInfo['label'] ?? '未执行'),
                'status_code' => (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING),
                'status_key' => (string)($statusInfo['key'] ?? 'pending'),
                'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
                'created_at' => (string)($row['created_at'] ?? ''),
                'remark' => (string)($row['last_error'] ?? $row['refund_no'] ?? ''),
            ];
        }

        foreach ($systemOrderRows ?? self::merchantFundOrderRows($merchantId) as $row) {
            $flows[] = $row;
        }

        usort($flows, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return array_slice($flows, 0, 100);
    }

    private static function merchantFundStats(array $effectiveBalance, array $settlements, int $systemOrderCount): array
    {
        $withdrawAmount = 0.0;
        foreach ($settlements as $settlement) {
            if ((int)($settlement['status_code'] ?? 0) === 2) {
                continue;
            }

            $withdrawAmount += max(0, (float)($settlement['money'] ?? 0));
        }

        return [
            'available_balance' => self::amount((string)($effectiveBalance['balance'] ?? $effectiveBalance['available'] ?? '0.00')),
            'withdraw_amount' => self::amount((string)$withdrawAmount),
            'order_count' => $systemOrderCount,
        ];
    }

    private static function merchantFundOrderCount(int $merchantId, array $orders): int
    {
        $seenKeys = [];
        $count = 0;

        foreach (LocalFundStore::businessFlowsForMerchant($merchantId, 0) as $flow) {
            $row = self::row($flow);
            $refType = strtolower(trim((string)($row['ref_type'] ?? '')));
            $business = match ($refType) {
                'recharge' => 'merchant_recharge',
                'register_fee' => 'merchant_register_fee',
                'package_purchase' => 'merchant_package_purchase',
                default => '',
            };
            if ($business === '') {
                continue;
            }

            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $referenceNo = self::firstNonEmptyString(
                (string)($meta['trade_no'] ?? ''),
                (string)($row['ref_no'] ?? ''),
                (string)($meta['out_trade_no'] ?? '')
            );
            $dedupKey = self::merchantFundOrderCountKey($business, $referenceNo, $row);
            if (isset($seenKeys[$dedupKey])) {
                continue;
            }

            $seenKeys[$dedupKey] = true;
            $count++;
        }

        foreach ($orders as $row) {
            $business = self::systemFundOrderBusiness($row);
            if ($business === '') {
                continue;
            }

            if (self::systemFundOrderHasBusinessFlow($merchantId, $business, $row)) {
                continue;
            }

            $tradeNo = trim((string)($row['trade_no'] ?? ''));
            $referenceNo = self::firstNonEmptyString($tradeNo, (string)($row['out_trade_no'] ?? ''));
            $dedupKey = self::merchantFundOrderCountKey($business, $referenceNo, $row);
            if (isset($seenKeys[$dedupKey])) {
                continue;
            }

            $seenKeys[$dedupKey] = true;
            $count++;
        }

        return $count;
    }

    private static function merchantFundOrderCountKey(string $business, string $referenceNo, array $row): string
    {
        if ($business === '') {
            return '';
        }

        if ($referenceNo !== '') {
            return $business . ':' . $referenceNo;
        }

        return $business . ':row:' . md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function merchantFundOrderRows(int $merchantId): array
    {
        return self::merchantFundOrderRowsFromOrders($merchantId, self::loadMerchantFundOrders($merchantId));
    }

    private static function merchantFundOrderRowsFromOrders(int $merchantId, array $orders): array
    {
        $items = [];
        $orderLookup = self::merchantFlowOrderLookup($merchantId, $orders);

        foreach ($orders as $row) {
            $business = self::systemFundOrderBusiness($row);
            if ($business === '') {
                continue;
            }

            if (self::systemFundOrderHasBusinessFlow($merchantId, $business, $row)) {
                continue;
            }

            $resolvedRow = self::resolveSystemFundOrderRow($merchantId, $orderLookup, $row, $business);
            $tradeNo = trim((string)($row['trade_no'] ?? ''));
            $statusInfo = OrderStatusService::forOperations($resolvedRow);
            $statusCode = (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING);

            $items[] = [
                'row_key' => 'system-order:' . ($tradeNo !== '' ? $tradeNo : md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
                'type' => self::systemFundOrderTypeLabel($business),
                'trade_no' => $tradeNo,
                'out_trade_no' => (string)($resolvedRow['out_trade_no'] ?? ''),
                'source_label' => self::orderSourceLabel($resolvedRow),
                'subject' => self::orderProductName($resolvedRow),
                'method_code' => self::orderMethodCode($resolvedRow),
                'method_name' => self::orderMethodName($resolvedRow),
                'amount' => self::systemFundOrderDisplayAmount($resolvedRow),
                'balance_after' => '',
                'status' => (string)($statusInfo['label'] ?? '待支付'),
                'status_code' => $statusCode,
                'status_key' => (string)($statusInfo['key'] ?? 'pending'),
                'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
                'created_at' => (string)($resolvedRow['created_at'] ?? ''),
                'remark' => self::systemFundOrderRemark($resolvedRow, $business),
            ];
        }

        return $items;
    }

    private static function loadMerchantFundOrders(int $merchantId): array
    {
        $rows = [];
        $seenTradeNos = [];

        if (\database_available()) {
            try {
                foreach (Order::where('merchant_id', $merchantId)->order('id', 'desc')->limit(100)->select()->toArray() as $row) {
                    if (!self::isSystemFundOrder($row)) {
                        continue;
                    }

                    $rows[] = $row;
                    $seenTradeNos[(string)($row['trade_no'] ?? '')] = true;
                }
            } catch (Throwable) {
            }
        }

        foreach (LocalOrderStore::ordersByMerchant($merchantId) as $order) {
            $row = self::row($order);
            if (!self::isSystemFundOrder($row)) {
                continue;
            }

            $tradeNo = (string)($row['trade_no'] ?? '');
            if ($tradeNo !== '' && isset($seenTradeNos[$tradeNo])) {
                continue;
            }

            $rows[] = $row;
            $seenTradeNos[$tradeNo] = true;
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return self::normalizeLoadedOrderRowsForRead(
            array_slice($rows, 0, 100),
            'resource-merchant-fund-orders-read',
            12
        );
    }

    private static function isSystemFundOrder(array $row): bool
    {
        return self::systemFundOrderBusiness($row) !== '';
    }

    private static function orderBusiness(array $row): string
    {
        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];

        return strtolower(trim((string)($meta['business'] ?? '')));
    }

    private static function systemFundOrderBusiness(array $row): string
    {
        $business = self::orderBusiness($row);

        return self::isSystemFundBusiness($business) ? $business : '';
    }

    private static function adminEarningBusiness(array $row): string
    {
        $business = self::orderBusiness($row);
        if (self::isSystemEarningBusiness($business)) {
            return $business;
        }

        $sourceKey = self::orderSourceKey($row);

        return self::isSystemEarningBusiness($sourceKey) ? $sourceKey : '';
    }

    private static function systemFundOrderTypeLabel(string $business): string
    {
        return SystemBusinessPaymentService::fundOrderTypeLabel($business);
    }

    private static function systemFundOrderRemark(array $row, string $business): string
    {
        $subject = trim((string)($row['subject'] ?? ''));
        $tradeNo = trim((string)($row['trade_no'] ?? ''));
        $outTradeNo = trim((string)($row['out_trade_no'] ?? ''));

        $parts = [];
        if ($subject !== '') {
            $parts[] = $subject;
        } else {
            $parts[] = self::systemFundOrderTypeLabel($business);
        }

        if ($tradeNo !== '') {
            $parts[] = $tradeNo;
        } elseif ($outTradeNo !== '') {
            $parts[] = $outTradeNo;
        }

        return implode(' / ', $parts);
    }

    private static function merchantPendingPayouts(int $merchantId): array
    {
        $items = [];

        foreach (LocalTransferStore::businessRefunds($merchantId) as $refund) {
            $payload = self::refundReadPayload(self::row($refund));
            if ((int)($payload['raw_status'] ?? 0) !== 0 || !((bool)($payload['is_pending'] ?? false))) {
                continue;
            }
            $items[] = $payload;
        }

        foreach (LocalTransferStore::businessTransfers($merchantId) as $transfer) {
            $payload = self::transferReadPayload(self::row($transfer));
            if ((int)($payload['raw_status'] ?? 0) !== 0 || !((bool)($payload['is_pending'] ?? false))) {
                continue;
            }
            $items[] = $payload;
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return array_slice($items, 0, 30);
    }

    private static function adminRefunds(): array
    {
        $items = [];
        foreach (LocalTransferStore::businessRefunds() as $refund) {
            $items[] = self::refundReadPayload(self::row($refund), true);
        }

        return $items;
    }

    private static function systemFundOrderHasBusinessFlow(int $merchantId, string $business, array $row): bool
    {
        if ($merchantId <= 0) {
            return false;
        }

        $refType = self::systemFundRefType($business);
        if ($refType === '') {
            return false;
        }

        $tradeNo = trim((string)($row['trade_no'] ?? ''));
        $outTradeNo = trim((string)($row['out_trade_no'] ?? ''));
        if ($tradeNo !== '' && LocalFundStore::findFlowByReference($merchantId, $refType, $tradeNo) !== null) {
            return true;
        }

        if ($outTradeNo !== '' && LocalFundStore::findFlowByReference($merchantId, $refType, $outTradeNo) !== null) {
            return true;
        }

        return false;
    }

    private static function resolveSystemFundOrderRow(int $merchantId, array &$orderLookup, array $row, string $business): array
    {
        $tradeNo = trim((string)($row['trade_no'] ?? ''));
        $outTradeNo = trim((string)($row['out_trade_no'] ?? ''));
        if ($merchantId <= 0 || ($tradeNo === '' && $outTradeNo === '')) {
            return $row;
        }

        $resolved = self::lookupMerchantOrderRow($merchantId, $orderLookup, $tradeNo, $outTradeNo);
        if ($resolved === []) {
            return $row;
        }

        if (self::systemFundOrderBusiness($resolved) === '') {
            $requestPayload = is_array($resolved['request_payload'] ?? null) ? $resolved['request_payload'] : [];
            $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
            if ($business !== '') {
                $meta['business'] = $business;
                $requestPayload['_meta'] = $meta;
                $resolved['request_payload'] = $requestPayload;
            }
        }

        return array_replace($row, $resolved);
    }

    private static function adminTransfers(): array
    {
        $items = [];
        foreach (LocalTransferStore::businessTransfers() as $transfer) {
            $items[] = self::transferReadPayload(self::row($transfer), true);
        }

        return $items;
    }

    private static function adminEarnings(): array
    {
        $items = [];
        $seenBusinessKeys = [];
        foreach (self::loadAdminOrderRecords() as $row) {
            if (self::isDeletedOrderRow($row)) {
                continue;
            }

            $statusInfo = OrderStatusService::forOperations($row);
            $statusCode = (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING);
            $merchantId = (int)($row['merchant_id'] ?? 0);
            $business = self::adminEarningBusiness($row);
            $tradeNo = (string)($row['trade_no'] ?? '');
            $methodCode = self::orderMethodCode($row);
            $methodName = self::orderMethodName($row, $methodCode);
            $subject = self::orderProductName($row, $methodCode);
            $createdAt = self::firstNonEmptyString(
                (string)($row['pay_time'] ?? ''),
                (string)($row['created_at'] ?? ''),
                (string)($row['updated_at'] ?? '')
            );

            if ($business !== '') {
                if (self::systemFundOrderHasBusinessFlow($merchantId, $business, $row)) {
                    continue;
                }

                $referenceNo = self::firstNonEmptyString($tradeNo, (string)($row['out_trade_no'] ?? ''));
                $businessKey = self::adminEarningBusinessKey($business, $merchantId, $referenceNo);
                if ($businessKey !== '' && isset($seenBusinessKeys[$businessKey])) {
                    continue;
                }

                $rowKey = 'earning-system:' . ($referenceNo !== '' ? $referenceNo : md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
                $items[] = [
                    'row_key' => $rowKey,
                    'type' => self::adminSystemEarningTypeLabel($business),
                    'merchant' => self::adminEarningMerchantLabel($merchantId, $business),
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
                    'source_label' => self::orderSourceLabel($row),
                    'method_name' => $methodName,
                    'subject' => $subject,
                    'amount' => self::systemFundOrderDisplayAmount($row),
                    'status' => (string)($statusInfo['label'] ?? '待支付'),
                    'status_code' => $statusCode,
                    'status_key' => (string)($statusInfo['key'] ?? 'pending'),
                    'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
                    'callback_status_label' => (string)($statusInfo['callback_status_label'] ?? ''),
                    'callback_status_key' => (string)($statusInfo['callback_status_key'] ?? ''),
                    'callback_status_theme' => (string)($statusInfo['callback_status_theme'] ?? 'warning'),
                    'callback_status_hint' => (string)($statusInfo['callback_status_hint'] ?? ''),
                    'remark' => $subject,
                    'created_at' => $createdAt,
                ];
                if ($businessKey !== '') {
                    $seenBusinessKeys[$businessKey] = true;
                }
                continue;
            }

            if (!LocalOrderStore::isBusinessOrder($row)) {
                continue;
            }

            $businessKey = self::adminEarningBusinessKey('merchant_order', $merchantId, $tradeNo, (string)($row['out_trade_no'] ?? ''));
            if ($businessKey !== '' && isset($seenBusinessKeys[$businessKey])) {
                continue;
            }

            $rowKey = 'earning-order:' . ($tradeNo !== '' ? $tradeNo : md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
            $items[] = [
                'row_key' => $rowKey,
                'type' => '商户消费订单',
                'merchant' => self::merchantDisplayName($merchantId),
                'trade_no' => $tradeNo,
                'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
                'source_label' => self::orderSourceLabel($row),
                'method_name' => $methodName,
                'subject' => $subject,
                'amount' => self::amount((string)($row['amount'] ?? '0.00')),
                'status' => (string)($statusInfo['label'] ?? '待支付'),
                'status_code' => $statusCode,
                'status_key' => (string)($statusInfo['key'] ?? 'pending'),
                'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
                'callback_status_label' => (string)($statusInfo['callback_status_label'] ?? ''),
                'callback_status_key' => (string)($statusInfo['callback_status_key'] ?? ''),
                'callback_status_theme' => (string)($statusInfo['callback_status_theme'] ?? 'warning'),
                'callback_status_hint' => (string)($statusInfo['callback_status_hint'] ?? ''),
                'remark' => $subject,
                'created_at' => $createdAt,
            ];
            if ($businessKey !== '') {
                $seenBusinessKeys[$businessKey] = true;
            }
            continue;

            $fee = (float)($row['platform_fee'] ?? 0);
            if ($fee <= 0) {
                continue;
            }
            if (self::amount((string)$fee) === '0.00') {
                continue;
            }

            $businessKey = self::adminEarningBusinessKey('order_fee', $merchantId, $tradeNo, (string)($row['out_trade_no'] ?? ''));
            if ($businessKey !== '' && isset($seenBusinessKeys[$businessKey])) {
                continue;
            }

            $rowKey = 'earning-fee:' . ($tradeNo !== '' ? $tradeNo : md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
            $items[] = [
                'row_key' => $rowKey,
                'type' => '订单手续费',
                'merchant' => self::merchantDisplayName($merchantId),
                'trade_no' => $tradeNo,
                'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
                'source_label' => self::orderSourceLabel($row),
                'method_name' => $methodName,
                'subject' => $subject,
                'amount' => self::amount((string)$fee),
                'status' => (string)($statusInfo['label'] ?? '待支付'),
                'status_code' => $statusCode,
                'status_key' => (string)($statusInfo['key'] ?? 'pending'),
                'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
                'callback_status_label' => (string)($statusInfo['callback_status_label'] ?? ''),
                'callback_status_key' => (string)($statusInfo['callback_status_key'] ?? ''),
                'callback_status_theme' => (string)($statusInfo['callback_status_theme'] ?? 'warning'),
                'callback_status_hint' => (string)($statusInfo['callback_status_hint'] ?? ''),
                'remark' => $subject,
                'created_at' => $createdAt,
            ];
            if ($businessKey !== '') {
                $seenBusinessKeys[$businessKey] = true;
            }
        }

        foreach (self::adminSystemFlowEarningsFallback() as $fallbackRow) {
            $businessKey = (string)($fallbackRow['business_key'] ?? '');
            if ($businessKey !== '' && isset($seenBusinessKeys[$businessKey])) {
                continue;
            }

            unset($fallbackRow['business_key']);
            $items[] = $fallbackRow;
            if ($businessKey !== '') {
                $seenBusinessKeys[$businessKey] = true;
            }
        }

        foreach (self::adminEventEarningsFallback() as $eventRow) {
            $businessKey = (string)($eventRow['business_key'] ?? '');
            if ($businessKey !== '' && isset($seenBusinessKeys[$businessKey])) {
                continue;
            }

            unset($eventRow['business_key']);
            $items[] = $eventRow;
            if ($businessKey !== '') {
                $seenBusinessKeys[$businessKey] = true;
            }
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return array_slice($items, 0, 200);
    }

    private static function adminSystemFlowEarningsFallback(): array
    {
        $items = [];
        $orderLookups = [];
        $adminLookup = self::adminOrderLookup();
        foreach (LocalFundStore::businessFlowsForMerchant(0, 0) as $flow) {
            $row = self::row($flow);
            $refType = trim((string)($row['ref_type'] ?? ''));
            $business = self::systemFundBusinessByRefType($refType);
            if ($business === '') {
                continue;
            }

            if (trim((string)($row['status'] ?? '')) !== 'success') {
                continue;
            }

            $merchantId = (int)($row['merchant_id'] ?? 0);
            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $tradeNo = self::firstNonEmptyString($meta['trade_no'] ?? '', (string)($row['ref_no'] ?? ''));
            $outTradeNo = self::firstNonEmptyString($meta['out_trade_no'] ?? '');
            if (!isset($orderLookups[$merchantId])) {
                $orderLookups[$merchantId] = $merchantId > 0
                    ? self::merchantFlowOrderLookup($merchantId)
                    : ['trade_no' => [], 'out_trade_no' => []];
            }
            $orderLookup = $orderLookups[$merchantId];
            $orderRow = self::resolveAdminEarningOrderRow($merchantId, $orderLookup, $adminLookup, $tradeNo, $outTradeNo, $business);
            $orderLookups[$merchantId] = $orderLookup;
            $methodCode = self::firstNonEmptyString(
                self::orderMethodCode($orderRow),
                $meta['channel_code'] ?? '',
                self::pluginNotifyMethodCodeByTradeNo($tradeNo)
            );
            $methodName = self::firstNonEmptyString(
                self::orderMethodName($orderRow, $methodCode),
                $meta['method_name'] ?? '',
                self::fundFlowMethodName($refType, $meta, $orderRow, $methodCode),
                $methodCode !== '' ? PaymentMetaService::friendlyMethodName($methodCode) : ''
            );
            $sourceLabel = self::fallbackOrderSourceLabel($orderRow, $business);
            $subject = self::firstNonEmptyString(
                self::fallbackOrderProductName($orderRow, $business, $methodCode),
                self::normalizeDisplayText($meta['name'] ?? ''),
                self::normalizeDisplayText($meta['subject'] ?? '')
            );
            if (
                $subject === ''
                || self::looksLikeUnknownDisplayText($subject)
                || $subject === $outTradeNo
                || $subject === $tradeNo
            ) {
                $subject = self::systemFundOrderTypeLabel($business);
            }
            $tradeNo = self::firstNonEmptyString($tradeNo, (string)($orderRow['trade_no'] ?? ''));
            $outTradeNo = self::firstNonEmptyString($outTradeNo, (string)($orderRow['out_trade_no'] ?? ''));
            $referenceNo = self::firstNonEmptyString($tradeNo, $outTradeNo, (string)($row['ref_no'] ?? ''));
            $amount = self::firstNonEmptyString(
                self::displayBusinessAmountFromFlow($row),
                self::fallbackSystemFundOrderDisplayAmount($orderRow),
                self::amount((string)abs((float)($row['amount'] ?? 0)))
            );
            $statusInfo = $orderRow !== []
                ? OrderStatusService::forOperations($orderRow)
                : self::fundFlowStatusMeta((string)($row['status'] ?? 'success'));

            $items[] = [
                'row_key' => 'earning-flow:' . $business . ':' . ($referenceNo !== '' ? $referenceNo : (string)($row['id'] ?? md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))),
                'business_key' => self::adminEarningBusinessKey($business, $merchantId, $referenceNo),
                'type' => self::adminSystemEarningTypeLabel($business),
                'merchant' => self::merchantDisplayName($merchantId),
                'trade_no' => $tradeNo,
                'out_trade_no' => $outTradeNo,
                'source_label' => $sourceLabel,
                'method_name' => $methodName,
                'subject' => $subject,
                'amount' => $amount,
                'status' => (string)($statusInfo['label'] ?? '成功'),
                'status_code' => (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_SUCCESS),
                'status_key' => (string)($statusInfo['key'] ?? 'success'),
                'status_theme' => (string)($statusInfo['theme'] ?? 'success'),
                'callback_status_label' => (string)($statusInfo['callback_status_label'] ?? ''),
                'callback_status_key' => (string)($statusInfo['callback_status_key'] ?? ''),
                'callback_status_theme' => (string)($statusInfo['callback_status_theme'] ?? 'muted'),
                'callback_status_hint' => (string)($statusInfo['callback_status_hint'] ?? ''),
                'remark' => $subject,
                'created_at' => self::firstNonEmptyString(
                    (string)($orderRow['created_at'] ?? ''),
                    (string)($row['created_at'] ?? '')
                ),
            ];
        }

        return $items;
    }

    private static function adminEventEarningsFallback(): array
    {
        $items = [];
        $adminLookup = self::adminOrderLookup();
        $merchantLookups = [];
        foreach (LocalOrderEventStore::all(400) as $event) {
            $row = self::row($event);
            if (strtolower(trim((string)($row['event_type'] ?? ''))) !== 'order_created') {
                continue;
            }

            $business = strtolower(trim((string)($row['business'] ?? '')));
            if (!self::isSystemEarningBusiness($business)) {
                continue;
            }

            $tradeNo = trim((string)($row['trade_no'] ?? ''));
            $outTradeNo = trim((string)($row['out_trade_no'] ?? ''));
            $referenceNo = self::firstNonEmptyString($tradeNo, $outTradeNo);
            if ($referenceNo === '') {
                continue;
            }

            $merchantId = (int)($row['merchant_id'] ?? 0);
            if (!isset($merchantLookups[$merchantId])) {
                $merchantLookups[$merchantId] = $merchantId > 0
                    ? self::merchantFlowOrderLookup($merchantId)
                    : ['trade_no' => [], 'out_trade_no' => []];
            }
            $merchantLookup = $merchantLookups[$merchantId];
            $orderRow = self::resolveAdminEarningOrderRow($merchantId, $merchantLookup, $adminLookup, $tradeNo, $outTradeNo, $business);
            $merchantLookups[$merchantId] = $merchantLookup;

            $methodCode = self::firstNonEmptyString(
                self::orderMethodCode($orderRow),
                PaymentMetaService::normalizeMethodCode((string)($row['channel_code'] ?? ''))
            );
            $methodName = self::firstNonEmptyString(
                self::orderMethodName($orderRow, $methodCode),
                $methodCode !== '' ? PaymentMetaService::friendlyMethodName($methodCode) : ''
            );
            $sourceLabel = self::fallbackOrderSourceLabel($orderRow, $business);
            $subject = self::firstNonEmptyString(
                self::fallbackOrderProductName($orderRow, $business, $methodCode),
                self::normalizeDisplayText((string)($row['subject'] ?? ''))
            );
            if ($subject === '' || self::looksLikeUnknownDisplayText($subject) || $subject === $referenceNo) {
                $subject = self::adminSystemEarningTypeLabel($business);
            }
            $tradeNo = self::firstNonEmptyString($tradeNo, (string)($orderRow['trade_no'] ?? ''));
            $outTradeNo = self::firstNonEmptyString($outTradeNo, (string)($orderRow['out_trade_no'] ?? ''));
            $referenceNo = self::firstNonEmptyString($tradeNo, $outTradeNo);

            $statusInfo = $orderRow !== []
                ? OrderStatusService::forOperations($orderRow)
                : OrderStatusService::forOperations($row);
            $statusCode = (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING);
            $items[] = [
                'row_key' => 'earning-event:' . $business . ':' . $referenceNo,
                'business_key' => self::adminEarningBusinessKey($business, $merchantId, $referenceNo),
                'type' => self::adminSystemEarningTypeLabel($business),
                'merchant' => self::adminEarningMerchantLabel($merchantId, $business),
                'trade_no' => $tradeNo,
                'out_trade_no' => $outTradeNo,
                'source_label' => $sourceLabel,
                'method_name' => $methodName,
                'subject' => $subject,
                'amount' => self::amount((string)self::firstNonEmptyString(
                    self::fallbackSystemFundOrderDisplayAmount($orderRow),
                    (string)($row['amount'] ?? '0.00')
                )),
                'status' => (string)($statusInfo['label'] ?? '待支付'),
                'status_code' => $statusCode,
                'status_key' => (string)($statusInfo['key'] ?? 'pending'),
                'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
                'callback_status_label' => (string)($statusInfo['callback_status_label'] ?? ''),
                'callback_status_key' => (string)($statusInfo['callback_status_key'] ?? ''),
                'callback_status_theme' => (string)($statusInfo['callback_status_theme'] ?? 'warning'),
                'callback_status_hint' => (string)($statusInfo['callback_status_hint'] ?? ''),
                'remark' => $subject,
                'created_at' => self::firstNonEmptyString(
                    (string)($orderRow['created_at'] ?? ''),
                    (string)($row['event_time'] ?? ''),
                    (string)($row['created_at'] ?? '')
                ),
            ];
        }

        return $items;
    }

    private static function callbackEventLogs(int $merchantId = 0, int $limit = 200): array
    {
        $items = [];
        foreach (LocalOrderEventStore::all($limit * 3) as $event) {
            $row = self::row($event);
            $eventType = strtolower(trim((string)($row['event_type'] ?? '')));
            if (!in_array($eventType, ['callback_enqueued', 'callback_retry', 'callback_success', 'callback_failed'], true)) {
                continue;
            }

            $currentMerchantId = (int)($row['merchant_id'] ?? 0);
            if ($merchantId > 0 && $currentMerchantId !== $merchantId) {
                continue;
            }

            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $callbackStatus = (int)($meta['status'] ?? 0);
            $state = \app\service\payment\CallbackService::describeCallbackState(
                $callbackStatus,
                $meta,
                (int)($meta['retry_count'] ?? 0),
                (int)($meta['max_retry'] ?? 0)
            );
            $items[] = [
                'event_key' => (string)($row['event_key'] ?? ''),
                'event_type' => $eventType,
                'trade_no' => (string)($row['trade_no'] ?? ''),
                'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
                'merchant_id' => $currentMerchantId,
                'notify_url' => (string)($meta['notify_url'] ?? ''),
                'status' => (string)($state['status'] ?? 'pending'),
                'result' => (string)($state['label'] ?? '待回调'),
                'result_key' => (string)($state['key'] ?? 'queued'),
                'result_theme' => (string)($state['theme'] ?? 'warning'),
                'result_hint' => (string)($state['hint'] ?? ''),
                'response' => (string)($meta['response'] ?? ''),
                'retry_count' => (int)($meta['retry_count'] ?? 0),
                'max_retry' => (int)($meta['max_retry'] ?? 0),
                'manual_retry' => (bool)($meta['manual_retry'] ?? false),
                'runtime_exception' => (bool)($meta['runtime_exception'] ?? false),
                'rejected' => (bool)($meta['rejected'] ?? false),
                'canceled' => (bool)($meta['canceled'] ?? false),
                'retry_exhausted' => (bool)($state['retry_exhausted'] ?? false),
                'status_code' => $callbackStatus,
                'updated_at' => self::firstNonEmptyString(
                    (string)($row['event_time'] ?? ''),
                    (string)($row['created_at'] ?? '')
                ),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['updated_at'] ?? ''), (string)($left['updated_at'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    private static function payoutEventLogs(int $merchantId = 0, int $limit = 200): array
    {
        $items = [];
        foreach (LocalPayoutEventStore::all($limit * 4) as $event) {
            $row = self::row($event);
            $currentMerchantId = (int)($row['merchant_id'] ?? 0);
            if ($merchantId > 0 && $currentMerchantId !== $merchantId) {
                continue;
            }

            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $items[] = [
                'event_key' => (string)($row['event_key'] ?? ''),
                'event_type' => (string)($row['event_type'] ?? ''),
                'kind' => (string)($row['kind'] ?? ''),
                'reference_no' => (string)($row['reference_no'] ?? ''),
                'trade_no' => (string)($row['trade_no'] ?? ''),
                'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
                'out_refund_no' => (string)($row['out_refund_no'] ?? ''),
                'out_biz_no' => (string)($row['out_biz_no'] ?? ''),
                'merchant_id' => $currentMerchantId,
                'amount' => self::amount((string)($row['amount'] ?? '0.00')),
                'status_code' => (int)($row['status'] ?? 0),
                'result' => (string)($row['result'] ?? ''),
                'channel_plugin_code' => (string)($row['channel_plugin_code'] ?? ''),
                'channel_order_no' => (string)($meta['channel_order_no'] ?? ''),
                'channel_trade_no' => (string)($meta['channel_trade_no'] ?? ''),
                'proof_no' => (string)($meta['proof_no'] ?? ''),
                'operator' => (string)($meta['operator'] ?? ''),
                'remark' => (string)($meta['remark'] ?? ''),
                'errmsg' => (string)($meta['last_error'] ?? ''),
                'updated_at' => self::firstNonEmptyString(
                    (string)($row['event_time'] ?? ''),
                    (string)($row['created_at'] ?? '')
                ),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['updated_at'] ?? ''), (string)($left['updated_at'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    private static function adminSystemEarningTypeLabel(string $business): string
    {
        return SystemBusinessPaymentService::businessTypeLabel($business) ?: '系统业务订单';
    }

    private static function adminEarningMerchantLabel(int $merchantId, string $business): string
    {
        return match (self::normalizeBusinessKey($business)) {
            'homepage_payment_test' => '游客',
            default => $merchantId > 0 ? self::merchantDisplayName($merchantId) : '游客',
        };
    }

    private static function adminSystemEarningSourceLabel(string $business): string
    {
        return SystemBusinessPaymentService::businessSourceLabel($business) ?: '系统业务';
    }

    private static function adminOrderLookup(): array
    {
        $lookup = [
            'trade_no' => [],
            'out_trade_no' => [],
        ];

        foreach (self::loadAdminOrderRecords() as $order) {
            self::rememberOrderLookupRow($lookup, self::row($order));
        }

        return $lookup;
    }

    private static function adminEarningBusinessKey(string $business, int $merchantId, string ...$values): string
    {
        $business = self::normalizeBusinessKey($business);
        $referenceNo = self::firstNonEmptyString(...$values);
        if ($business === '' || $referenceNo === '') {
            return '';
        }

        return $business . ':' . ($merchantId > 0 ? (string)$merchantId : 'guest') . ':' . $referenceNo;
    }

    private static function displayFlowAmount(array $row): string
    {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        if (!empty($meta['business_only'])) {
            $amount = self::firstNonEmptyString(
                (string)($meta['order_amount'] ?? ''),
                (string)($meta['gross_amount'] ?? '')
            );
            if ($amount !== '') {
                return self::amount($amount);
            }
        }

        return self::amount((string)($row['amount'] ?? '0.00'));
    }

    private static function displayBusinessAmountFromFlow(array $row): string
    {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $amount = self::firstNonEmptyString(
            (string)($meta['order_amount'] ?? ''),
            (string)($meta['gross_amount'] ?? '')
        );

        return $amount !== '' ? self::amount($amount) : '';
    }

    private static function fallbackSystemFundOrderDisplayAmount(array $row): string
    {
        if ($row === []) {
            return '';
        }

        $amount = self::systemFundOrderDisplayAmount($row);

        return $amount === '0.00' && self::firstNonEmptyString(
            (string)($row['amount'] ?? ''),
            (string)($row['payable_amount'] ?? '')
        ) === '' ? '' : $amount;
    }

    private static function fallbackOrderSourceLabel(array $row, string $business): string
    {
        if ($row === []) {
            return self::adminSystemEarningSourceLabel($business);
        }

        return self::orderSourceLabel($row);
    }

    private static function fallbackOrderProductName(array $row, string $business, string $fallbackCode = ''): string
    {
        if ($row === []) {
            return '';
        }

        $subject = self::orderProductName($row, $fallbackCode);

        if ($subject === self::orderSourceLabel($row) && self::adminEarningBusiness($row) === '') {
            return '';
        }

        return $subject;
    }

    private static function systemFundOrderDisplayAmount(array $row): string
    {
        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $business = self::orderBusiness($row);

        $amount = self::firstNonEmptyString(
            (string)($meta['order_amount'] ?? ''),
            (string)($row['amount'] ?? ''),
            (string)($row['payable_amount'] ?? '')
        );

        if ($amount === '') {
            return '0.00';
        }

        return self::amount($amount);
    }

    private static function loadAdminOrderRecords(int $limit = 400): array
    {
        $rows = [];
        $seenTradeNos = [];

        if (\database_available()) {
            try {
                foreach (Order::order('id', 'desc')->limit($limit)->select()->toArray() as $order) {
                    $row = self::row($order);
                    $tradeNo = trim((string)($row['trade_no'] ?? ''));
                    $rows[] = $row;
                    if ($tradeNo !== '') {
                        $seenTradeNos[$tradeNo] = true;
                    }
                }
            } catch (Throwable) {
            }
        }

        foreach (LocalOrderStore::allOrders() as $order) {
            $row = self::row($order);
            $tradeNo = trim((string)($row['trade_no'] ?? ''));
            if ($tradeNo !== '' && isset($seenTradeNos[$tradeNo])) {
                continue;
            }

            $rows[] = $row;
            if ($tradeNo !== '') {
                $seenTradeNos[$tradeNo] = true;
            }
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return self::normalizeLoadedOrderRowsForRead(
            array_slice($rows, 0, $limit),
            'resource-admin-orders-read',
            min(24, max(6, (int)floor($limit / 10)))
        );
    }

    private static function normalizeLoadedOrderRowsForRead(array $rows, string $source, int $maxNormalize = 12): array
    {
        if ($rows === [] || $maxNormalize <= 0) {
            return $rows;
        }

        $normalized = $rows;
        $seenTradeNos = [];
        $processed = 0;

        foreach ($normalized as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $tradeNo = trim((string)($row['trade_no'] ?? ''));
            if ($tradeNo === '' || isset($seenTradeNos[$tradeNo])) {
                continue;
            }

            $seenTradeNos[$tradeNo] = true;

            $status = (int)($row['status'] ?? OrderService::STATUS_PENDING);
            if (!in_array($status, [OrderService::STATUS_PENDING, OrderService::STATUS_SUCCESS], true)) {
                continue;
            }

            try {
                $normalizedOrder = OrderService::findByTradeNoForRead($tradeNo, [
                    'source' => $source,
                ]);
                $normalized[$index] = self::row($normalizedOrder);
                $processed++;
            } catch (Throwable) {
                continue;
            }

            if ($processed >= $maxNormalize) {
                break;
            }
        }

        return $normalized;
    }

    private static function merchantRateRows(): array
    {
        $rows = [];

        if (\database_available()) {
            try {
                foreach (Merchant::order('id', 'asc')->select()->toArray() as $merchant) {
                    $row = self::row($merchant);
                    $merchantId = (int)($row['id'] ?? 0);
                    $rate = self::rate((float)($row['platform_rate'] ?? 0));
                    $rows[] = [
                        'merchant' => self::merchantName($row, $merchantId),
                        'rule' => '商户基础费率 ' . $rate,
                        'rate' => $rate,
                        'effective_time' => (string)($row['created_at'] ?? ''),
                    ];
                }
            } catch (Throwable) {
            }
        }

        if ($rows !== []) {
            return $rows;
        }

        foreach (JsonStoreService::load('merchant_accounts', []) as $merchant) {
            if (!is_array($merchant)) {
                continue;
            }

            $merchantId = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0);
            $rate = self::rate((float)($merchant['platform_rate'] ?? 0));
            $rows[] = [
                'merchant' => self::merchantName($merchant, $merchantId),
                'rule' => '商户基础费率 ' . $rate,
                'rate' => $rate,
                'effective_time' => (string)($merchant['created_at'] ?? ''),
            ];
        }

        return $rows;
    }

    private static function orderRow(array $row, string $merchantName = '', bool $merchantView = false): array
    {
        $statusInfo = OrderStatusService::forOperations($row);
        $statusCode = (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING);
        $methodCode = self::orderMethodCode($row);
        $sourceKey = self::orderSourceKey($row);
        $sourceLabel = self::orderSourceLabel($row);
        $rawPaymentReference = self::orderPaymentReferenceRaw($row);
        $notifyUrl = trim((string)($row['notify_url'] ?? ''));
        $isDeleted = self::isDeletedOrderRow($row);
        $isMerchantOrder = LocalOrderStore::isBusinessOrder($row);
        $manualAction = $merchantView
            ? self::merchantOrderManualAction($row, $isDeleted, $statusCode, $notifyUrl, $sourceKey)
            : 'none';
        $canManualCallback = $merchantView && $manualAction !== 'none';
        $canDelete = !$isDeleted;

        return [
            'trade_no' => (string)($row['trade_no'] ?? ''),
            'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
            'merchant_id' => (int)($row['merchant_id'] ?? 0),
            'merchant' => self::orderMerchantName($row, $merchantName, $merchantView),
            'channel_id' => (int)($row['merchant_channel_id'] ?? 0),
            'channel_code' => $methodCode !== '' ? $methodCode : (string)($row['channel_code'] ?? ''),
            'method_name' => self::orderMethodName($row, $methodCode),
            'subject' => self::orderProductName($row, $methodCode),
            'source_key' => $sourceKey,
            'source_label' => $sourceLabel,
            'amount' => self::amount((string)($row['amount'] ?? '0.00')),
            'status' => (string)($statusInfo['label'] ?? '待支付'),
            'status_code' => $statusCode,
            'status_key' => (string)($statusInfo['key'] ?? 'pending'),
            'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
            'payment_status_label' => (string)($statusInfo['payment_status_label'] ?? ''),
            'payment_status_code' => (int)($statusInfo['payment_status_code'] ?? 0),
            'payment_status_key' => (string)($statusInfo['payment_status_key'] ?? ''),
            'callback_status_label' => (string)($statusInfo['callback_status_label'] ?? ''),
            'callback_status_code' => (int)($statusInfo['callback_status_code'] ?? 0),
            'callback_status_key' => (string)($statusInfo['callback_status_key'] ?? ''),
            'callback_status_theme' => (string)($statusInfo['callback_status_theme'] ?? 'warning'),
            'callback_status_hint' => (string)($statusInfo['callback_status_hint'] ?? ''),
            'refund_amount' => (string)($statusInfo['refund_amount'] ?? '0.00'),
            'txid' => self::orderPaymentReference($row),
            'txid_raw' => $rawPaymentReference,
            'api_trade_no' => $rawPaymentReference,
            'expire_time' => (string)($row['expire_time'] ?? ''),
            'pay_time' => (string)($row['pay_time'] ?? $row['endtime'] ?? ''),
            'callback_status' => (int)($row['callback_status'] ?? 0),
            'notify_url' => $notifyUrl,
            'deleted_at' => (string)($row['deleted_at'] ?? ''),
            'is_deleted' => $isDeleted,
            'is_merchant_order' => $isMerchantOrder,
            'can_manual_callback' => $canManualCallback,
            'manual_action' => $manualAction,
            'can_delete' => $canDelete,
            'action_hint' => $merchantView
                ? self::merchantOrderActionHint(
                    $row,
                    $isDeleted,
                    $statusCode,
                    $notifyUrl,
                    $sourceKey,
                    $manualAction,
                    (string)($statusInfo['callback_status_key'] ?? ''),
                    (string)($statusInfo['callback_status_label'] ?? ''),
                    (string)($statusInfo['callback_status_hint'] ?? '')
                )
                : self::adminOrderActionHint(
                    $isDeleted,
                    $isMerchantOrder,
                    $statusCode,
                    $notifyUrl,
                    $sourceKey,
                    (string)($statusInfo['callback_status_key'] ?? ''),
                    (string)($statusInfo['callback_status_label'] ?? ''),
                    (string)($statusInfo['callback_status_hint'] ?? '')
                ),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    private static function merchantOrderManualAction(
        array $row,
        bool $isDeleted,
        int $statusCode,
        string $notifyUrl,
        string $sourceKey
    ): string {
        if ($isDeleted || $notifyUrl === '') {
            return 'none';
        }

        if ($statusCode === OrderStatusService::DISPLAY_SUCCESS || $statusCode === OrderStatusService::DISPLAY_CALLBACK_FAILED) {
            return 'retry';
        }

        if ($statusCode !== OrderStatusService::DISPLAY_PENDING && $statusCode !== OrderStatusService::DISPLAY_PENDING_CONFIRM) {
            return 'none';
        }

        if ($sourceKey !== 'channel_test') {
            return 'none';
        }

        $expireTime = trim((string)($row['expire_time'] ?? ''));
        if ($expireTime !== '') {
            $expireAt = strtotime($expireTime);
            if ($expireAt !== false && $expireAt < time()) {
                return 'none';
            }
        }

        return 'confirm';
    }

    private static function adminOrderActionHint(
        bool $isDeleted,
        bool $isMerchantOrder,
        int $statusCode,
        string $notifyUrl,
        string $sourceKey,
        string $callbackStatusKey = '',
        string $callbackStatusLabel = '',
        string $callbackStatusHint = ''
    ): string {
        if ($isDeleted) {
            return '已删除';
        }

        $callbackHint = self::orderCallbackActionHint($statusCode, $callbackStatusKey, $callbackStatusLabel, $callbackStatusHint);
        if ($callbackHint !== '') {
            return $callbackHint;
        }

        if ($isMerchantOrder) {
            return '商户订单';
        }

        return match (strtolower(trim($sourceKey))) {
            'homepage_payment_test' => '游客测试订单',
            'channel_test' => '通道测试订单',
            default => '',
        };
    }

    private static function merchantOrderActionHint(
        array $row,
        bool $isDeleted,
        int $statusCode,
        string $notifyUrl,
        string $sourceKey,
        string $manualAction,
        string $callbackStatusKey = '',
        string $callbackStatusLabel = '',
        string $callbackStatusHint = ''
    ): string {
        if ($isDeleted) {
            return '已删除';
        }

        if ($notifyUrl === '') {
            return '未配置回调地址';
        }

        if ($manualAction === 'confirm') {
            return '可人工确认成功并立即回调';
        }

        $callbackHint = self::orderCallbackActionHint($statusCode, $callbackStatusKey, $callbackStatusLabel, $callbackStatusHint);
        if ($manualAction === 'retry') {
            return $callbackHint !== ''
                ? $callbackHint . '，可手动重发回调'
                : '可手动重发回调';
        }

        if ($statusCode === OrderStatusService::DISPLAY_EXPIRED) {
            return '订单已过期';
        }

        if ($statusCode === OrderStatusService::DISPLAY_CLOSED) {
            return '订单已关闭';
        }

        if ($statusCode === OrderStatusService::DISPLAY_FAILED) {
            return '支付失败';
        }

        if ($statusCode === OrderStatusService::DISPLAY_REFUNDED) {
            return '订单已退款';
        }

        if ($callbackHint !== '') {
            return $callbackHint;
        }

        if ($statusCode === OrderStatusService::DISPLAY_PENDING_CONFIRM) {
            return '订单已拉起，等待用户确认支付';
        }

        $expireTime = trim((string)($row['expire_time'] ?? ''));
        if ($statusCode === OrderStatusService::DISPLAY_PENDING && $expireTime !== '') {
            $expireAt = strtotime($expireTime);
            if ($expireAt !== false && $expireAt < time()) {
                return '订单已过期';
            }
        }

        if ($sourceKey === 'channel_test') {
            return '通道测试订单待支付';
        }

        return '待支付订单暂不支持手动回调';
    }

    private static function orderCallbackActionHint(
        int $statusCode,
        string $callbackStatusKey,
        string $callbackStatusLabel,
        string $callbackStatusHint
    ): string {
        $key = strtolower(trim($callbackStatusKey));
        $label = trim($callbackStatusLabel);
        $hint = trim($callbackStatusHint);

        if ($key === '' || $key === 'none' || $key === 'success') {
            return '';
        }

        if ($statusCode === OrderStatusService::DISPLAY_CALLBACK_FAILED) {
            if ($hint !== '') {
                return '支付已成功，' . $hint;
            }

            if ($label !== '') {
                return '支付已成功，' . $label;
            }

            return '支付已成功，但回调失败';
        }

        if ($statusCode !== OrderStatusService::DISPLAY_SUCCESS) {
            return $hint !== '' ? $hint : '';
        }

        if ($hint !== '') {
            return '支付已成功，' . $hint;
        }

        if ($key === 'queued') {
            return '支付已成功，等待异步回调';
        }

        if ($label !== '') {
            return '支付已成功，' . $label;
        }

        return '';
    }

    private static function appendAdminOrderBucketRow(
        string $bucket,
        array $row,
        string $merchantName,
        array &$items,
        array &$rechargeOrders,
        array &$packageOrders,
        array &$seenKeys
    ): void {
        $dedupKey = self::orderDedupKey($row);
        if ($dedupKey !== '' && isset($seenKeys[$bucket][$dedupKey])) {
            return;
        }

        if ($bucket === 'recharge' || $bucket === 'package') {
            $business = self::systemFundOrderBusiness($row);
            if ($bucket === 'recharge') {
                $rechargeOrders[] = self::adminSystemOrderRow($row, $merchantName, $business);
            } else {
                $packageOrders[] = self::adminSystemOrderRow($row, $merchantName, $business);
            }

            if ($dedupKey === '' || !isset($seenKeys['orders'][$dedupKey])) {
                $items[] = self::orderRow($row, $merchantName, false);
                if ($dedupKey !== '') {
                    $seenKeys['orders'][$dedupKey] = true;
                }
            }
        } else {
            $items[] = self::orderRow($row, $merchantName, false);
        }

        if ($dedupKey !== '') {
            $seenKeys[$bucket][$dedupKey] = true;
        }
    }

    private static function adminOrderBucket(array $row): string
    {
        $business = self::systemFundOrderBusiness($row);
        if ($business === 'merchant_recharge') {
            return 'recharge';
        }
        if ($business === 'merchant_package_purchase') {
            return 'package';
        }

        $tradeNo = trim((string)($row['trade_no'] ?? ''));
        $outTradeNo = trim((string)($row['out_trade_no'] ?? ''));
        if ($tradeNo === '' && $outTradeNo === '') {
            return '';
        }

        return 'orders';
    }

    private static function appendAdminSystemFundOrders(
        array &$items,
        array &$rechargeOrders,
        array &$packageOrders,
        array &$seenKeys,
        array $merchants
    ): void {
        $adminLookup = self::adminOrderLookup();
        $merchantLookups = [];

        foreach (LocalFundStore::businessFlowsForMerchant(0, 0) as $flow) {
            $row = self::row($flow);
            if (trim((string)($row['status'] ?? '')) !== 'success') {
                continue;
            }

            $refType = strtolower(trim((string)($row['ref_type'] ?? '')));
            $business = self::systemFundBusinessByRefType($refType);
            if (!SystemBusinessPaymentService::isFundBusiness($business)) {
                continue;
            }

            $merchantId = (int)($row['merchant_id'] ?? 0);
            if (!isset($merchantLookups[$merchantId])) {
                $merchantLookups[$merchantId] = $merchantId > 0
                    ? self::merchantFlowOrderLookup($merchantId)
                    : ['trade_no' => [], 'out_trade_no' => []];
            }

            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $tradeNo = self::firstNonEmptyString(
                (string)($meta['trade_no'] ?? ''),
                (string)($row['ref_no'] ?? '')
            );
            $outTradeNo = self::firstNonEmptyString((string)($meta['out_trade_no'] ?? ''));

            $merchantLookup = $merchantLookups[$merchantId];
            $orderRow = self::resolveAdminEarningOrderRow($merchantId, $merchantLookup, $adminLookup, $tradeNo, $outTradeNo, $business);
            $merchantLookups[$merchantId] = $merchantLookup;

            if ($orderRow === []) {
                $orderRow = self::buildSystemFundFallbackOrderRow($row, $business, $merchants[$merchantId] ?? []);
            }

            if ($orderRow === []) {
                continue;
            }

            $bucket = match ($business) {
                'merchant_recharge' => 'recharge',
                'merchant_package_purchase' => 'package',
                default => 'orders',
            };
            self::appendAdminOrderBucketRow(
                $bucket,
                $orderRow,
                (string)(($merchants[$merchantId]['name'] ?? '') ?: ''),
                $items,
                $rechargeOrders,
                $packageOrders,
                $seenKeys
            );
        }
    }

    private static function buildSystemFundFallbackOrderRow(array $flow, string $business, array $merchant = []): array
    {
        $meta = is_array($flow['meta'] ?? null) ? $flow['meta'] : [];
        $tradeNo = self::firstNonEmptyString(
            (string)($meta['trade_no'] ?? ''),
            (string)($flow['ref_no'] ?? '')
        );
        $outTradeNo = self::firstNonEmptyString((string)($meta['out_trade_no'] ?? ''));
        $merchantId = (int)($flow['merchant_id'] ?? 0);
        $merchantName = self::firstNonEmptyString(
            self::normalizeDisplayText($merchant['merchant_name'] ?? ''),
            self::normalizeDisplayText($merchant['name'] ?? '')
        );
        if ($merchantName === '' && $merchantId > 0) {
            $merchantName = self::merchantDisplayName($merchantId);
        }
        $subject = self::firstNonEmptyString(
            self::normalizeDisplayText($meta['subject'] ?? ''),
            self::normalizeDisplayText($meta['name'] ?? ''),
            self::systemFundOrderTypeLabel($business)
        );
        $methodCode = PaymentMetaService::normalizeMethodCode(self::firstNonEmptyString(
            (string)($meta['channel_code'] ?? ''),
            self::pluginNotifyMethodCodeByTradeNo($tradeNo)
        ));
        $methodName = self::firstNonEmptyString(
            self::normalizeDisplayText($meta['method_name'] ?? ''),
            self::pluginNotifyMethodNameByTradeNo($tradeNo),
            $methodCode !== '' ? PaymentMetaService::friendlyMethodName($methodCode) : ''
        );
        $amount = self::firstNonEmptyString(
            (string)($meta['order_amount'] ?? ''),
            (string)($meta['gross_amount'] ?? ''),
            (string)($flow['amount'] ?? '0.00')
        );

        return [
            'trade_no' => $tradeNo,
            'out_trade_no' => $outTradeNo,
            'merchant_id' => $merchantId,
            'merchant_name' => $merchantName,
            'amount' => $amount,
            'payable_amount' => $amount,
            'subject' => $subject,
            'name' => $merchantName,
            'channel_code' => $methodCode,
            'method_name' => $methodName,
            'status' => OrderService::STATUS_SUCCESS,
            'pay_status' => 1,
            'pay_time' => (string)($flow['created_at'] ?? ''),
            'created_at' => (string)($flow['created_at'] ?? ''),
            'request_payload' => [
                '_meta' => [
                    'business' => $business,
                    'source_protocol' => $business,
                    'channel_code' => $methodCode,
                    'method_name' => $methodName,
                    'order_amount' => $amount,
                    'subject' => $subject,
                    'name' => $merchantName,
                    'merchant_name' => $merchantName,
                ],
            ],
        ];
    }

    private static function shouldIncludeMerchantOrderList(array $row): bool
    {
        if (self::systemFundOrderBusiness($row) !== '') {
            return false;
        }

        return LocalOrderStore::isBusinessOrder($row) || self::isChannelTestOrder($row);
    }

    private static function isChannelTestOrder(array $row): bool
    {
        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $business = strtolower(trim((string)($meta['business'] ?? '')));
        if ($business === 'channel_test') {
            return true;
        }

        $sourceProtocol = strtolower(trim((string)($meta['source_protocol'] ?? '')));
        if ($sourceProtocol === 'channel_test') {
            return true;
        }

        $param = strtolower(trim((string)($row['param'] ?? '')));
        if ($param === 'channel-test') {
            return true;
        }

        return str_starts_with(strtoupper(trim((string)($row['trade_no'] ?? ''))), 'TST');
    }

    private static function orderDedupKey(array $row): string
    {
        $tradeNo = trim((string)($row['trade_no'] ?? ''));
        if ($tradeNo !== '') {
            return 'trade:' . $tradeNo;
        }

        $outTradeNo = trim((string)($row['out_trade_no'] ?? ''));
        if ($outTradeNo !== '') {
            return 'out:' . $outTradeNo;
        }

        return md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function isDeletedOrderRow(array $row): bool
    {
        return trim((string)($row['deleted_at'] ?? '')) !== '';
    }

    private static function adminSystemOrderRow(array $row, string $merchantName, string $business): array
    {
        $methodCode = self::orderMethodCode($row);
        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $subject = self::firstNonEmptyString(
            self::orderProductName($row, $methodCode),
            self::normalizeDisplayText($meta['package_name'] ?? ''),
            self::normalizeDisplayText($row['subject'] ?? ''),
            self::systemFundOrderTypeLabel($business)
        );
        $statusInfo = OrderStatusService::forOperations($row);

        return [
            'trade_no' => (string)($row['trade_no'] ?? ''),
            'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
            'merchant_id' => (int)($row['merchant_id'] ?? 0),
            'merchant' => self::orderMerchantName($row, $merchantName, false),
            'channel_code' => $methodCode !== '' ? $methodCode : (string)($row['channel_code'] ?? ''),
            'method_name' => self::orderMethodName($row, $methodCode),
            'payment_type' => self::orderMethodName($row, $methodCode),
            'package_name' => self::firstNonEmptyString(
                $meta['package_name'] ?? '',
                $subject
            ),
            'amount' => self::amount((string)($row['amount'] ?? '0.00')),
            'status' => (string)($statusInfo['label'] ?? '待支付'),
            'status_code' => (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING),
            'status_key' => (string)($statusInfo['key'] ?? 'pending'),
            'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
            'source_key' => self::orderSourceKey($row),
            'source_label' => self::orderSourceLabel($row),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    private static function orderSourceKey(array $row): string
    {
        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $business = strtolower(trim((string)($meta['business'] ?? '')));
        if ($business !== '' && $business !== 'merchant_order') {
            return $business;
        }

        if (self::isChannelTestOrder($row)) {
            return 'channel_test';
        }

        $sourceProtocol = strtolower(trim((string)($meta['source_protocol'] ?? '')));
        if ($sourceProtocol !== '') {
            return $sourceProtocol;
        }

        $param = strtolower(trim((string)($row['param'] ?? '')));
        if ($param !== '') {
            return $param;
        }

        return 'order';
    }

    private static function orderSourceLabel(array $row): string
    {
        return match (self::orderSourceKey($row)) {
            'merchant_recharge' => '在线充值',
            'merchant_package_purchase' => '套餐购买',
            'merchant_register_fee' => '注册收费',
            'homepage_payment_test' => '首页测试',
            'channel_test' => '通道测试',
            'v1' => '易支付 V1',
            'v2' => '易支付 V2',
            'mapi-test' => 'MAPI 测试',
            'submit-test' => '提交测试',
            default => '普通订单',
        };
    }

    private static function normalizeBusinessKey(string $business): string
    {
        return strtolower(trim($business));
    }

    private static function isSystemFundBusiness(string $business): bool
    {
        return SystemBusinessPaymentService::isFundBusiness($business);
    }

    private static function systemFundRefType(string $business): string
    {
        return SystemBusinessPaymentService::fundRefType($business);
    }

    private static function systemFundBusinessByRefType(string $refType): string
    {
        return SystemBusinessPaymentService::businessByFundRefType($refType);
    }

    private static function isSystemEarningBusiness(string $business): bool
    {
        $normalized = self::normalizeBusinessKey($business);
        if (SystemBusinessPaymentService::isFundBusiness($normalized) || SystemBusinessPaymentService::isTestSyncBusiness($normalized)) {
            return true;
        }

        return SystemBusinessPaymentService::businessMeta($normalized) !== [];
    }

    private static function systemEarningBusinessMeta(string $business): array
    {
        return SystemBusinessPaymentService::businessMeta($business);
    }

    private static function merchantFlowOrderLookup(int $merchantId, array $systemOrders = []): array
    {
        $lookup = [
            'trade_no' => [],
            'out_trade_no' => [],
        ];

        foreach ($systemOrders as $order) {
            self::rememberOrderLookupRow($lookup, self::row($order));
        }

        foreach (self::loadMerchantOrders($merchantId) as $order) {
            self::rememberOrderLookupRow($lookup, self::row($order));
        }

        return $lookup;
    }

    private static function rememberOrderLookupRow(array &$lookup, array $row): void
    {
        if ($row === []) {
            return;
        }

        $tradeNo = trim((string)($row['trade_no'] ?? ''));
        if ($tradeNo !== '' && !isset($lookup['trade_no'][$tradeNo])) {
            $lookup['trade_no'][$tradeNo] = $row;
        }

        $outTradeNo = trim((string)($row['out_trade_no'] ?? ''));
        if ($outTradeNo !== '' && !isset($lookup['out_trade_no'][$outTradeNo])) {
            $lookup['out_trade_no'][$outTradeNo] = $row;
        }
    }

    private static function lookupMerchantOrderRow(int $merchantId, array &$lookup, string $tradeNo = '', string $outTradeNo = ''): array
    {
        $tradeNo = trim($tradeNo);
        $outTradeNo = trim($outTradeNo);

        if ($tradeNo !== '' && isset($lookup['trade_no'][$tradeNo]) && is_array($lookup['trade_no'][$tradeNo])) {
            return $lookup['trade_no'][$tradeNo];
        }

        if ($outTradeNo !== '' && isset($lookup['out_trade_no'][$outTradeNo]) && is_array($lookup['out_trade_no'][$outTradeNo])) {
            return $lookup['out_trade_no'][$outTradeNo];
        }

        if ($merchantId <= 0 || ($tradeNo === '' && $outTradeNo === '')) {
            return [];
        }

        try {
            $order = OrderService::gatewayMerchantOrderForRead(
                $merchantId,
                $tradeNo !== '' ? $tradeNo : null,
                $outTradeNo !== '' ? $outTradeNo : null,
                [
                    'source' => 'resource-merchant-flow-order-read',
                ]
            );
            $row = self::row($order);
            self::rememberOrderLookupRow($lookup, $row);
            return $row;
        } catch (Throwable) {
        }

        $localOrder = LocalOrderStore::findByMerchantOrder(
            $merchantId,
            $tradeNo !== '' ? $tradeNo : null,
            $outTradeNo !== '' ? $outTradeNo : null
        );
        if ($localOrder !== null) {
            $row = self::row($localOrder);
            self::rememberOrderLookupRow($lookup, $row);
            return $row;
        }

        return [];
    }

    private static function resolveAdminEarningOrderRow(
        int $merchantId,
        array &$merchantLookup,
        array &$adminLookup,
        string $tradeNo = '',
        string $outTradeNo = '',
        string $business = ''
    ): array {
        $tradeNo = trim($tradeNo);
        $outTradeNo = trim($outTradeNo);

        $resolved = self::lookupMerchantOrderRow($merchantId, $merchantLookup, $tradeNo, $outTradeNo);
        if ($resolved !== []) {
            self::rememberOrderLookupRow($adminLookup, $resolved);
            return $business !== '' ? self::mergeResolvedEarningBusiness($resolved, $business) : $resolved;
        }

        if ($tradeNo !== '' && isset($adminLookup['trade_no'][$tradeNo]) && is_array($adminLookup['trade_no'][$tradeNo])) {
            $resolved = $adminLookup['trade_no'][$tradeNo];
            return $business !== '' ? self::mergeResolvedEarningBusiness($resolved, $business) : $resolved;
        }

        if ($outTradeNo !== '' && isset($adminLookup['out_trade_no'][$outTradeNo]) && is_array($adminLookup['out_trade_no'][$outTradeNo])) {
            $resolved = $adminLookup['out_trade_no'][$outTradeNo];
            return $business !== '' ? self::mergeResolvedEarningBusiness($resolved, $business) : $resolved;
        }

        if ($tradeNo !== '') {
            try {
                $order = OrderService::findByTradeNoForRead($tradeNo, [
                    'source' => 'resource-admin-earning-order-read',
                ]);
                $resolved = self::row($order);
                self::rememberOrderLookupRow($adminLookup, $resolved);
                self::rememberOrderLookupRow($merchantLookup, $resolved);
                return $business !== '' ? self::mergeResolvedEarningBusiness($resolved, $business) : $resolved;
            } catch (Throwable) {
            }

            $localOrder = LocalOrderStore::findByTradeNo($tradeNo);
            if ($localOrder !== null) {
                $resolved = self::row($localOrder);
                self::rememberOrderLookupRow($adminLookup, $resolved);
                self::rememberOrderLookupRow($merchantLookup, $resolved);
                return $business !== '' ? self::mergeResolvedEarningBusiness($resolved, $business) : $resolved;
            }
        }

        return [];
    }

    private static function mergeResolvedEarningBusiness(array $row, string $business): array
    {
        if ($row === []) {
            return [];
        }

        if (self::adminEarningBusiness($row) !== '') {
            return $row;
        }

        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $meta['business'] = $business;
        $requestPayload['_meta'] = $meta;
        $row['request_payload'] = $requestPayload;

        return $row;
    }

    private static function orderMethodCode(array $row): string
    {
        if ($row === []) {
            return '';
        }

        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $legacyChannel = is_array($requestPayload['_legacy_channel'] ?? null) ? $requestPayload['_legacy_channel'] : [];
        $legacyConfig = is_array($legacyChannel['config'] ?? null) ? $legacyChannel['config'] : [];

        return PaymentMetaService::normalizeMethodCode((string)(
            $meta['method_code']
            ?? $meta['channel_code']
            ?? $legacyConfig['method_code']
            ?? $legacyChannel['channel_code']
            ?? $row['channel_code']
            ?? ''
        ));
    }

    private static function orderMethodName(array $row, string $fallbackCode = ''): string
    {
        if ($row === []) {
            return '';
        }

        $requestPayload = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        $legacyChannel = is_array($requestPayload['_legacy_channel'] ?? null) ? $requestPayload['_legacy_channel'] : [];
        $legacyConfig = is_array($legacyChannel['config'] ?? null) ? $legacyChannel['config'] : [];

        $name = self::firstNonEmptyString(
            $meta['method_name'] ?? '',
            $legacyConfig['method_name'] ?? '',
            $legacyConfig['channel_name'] ?? '',
            $row['method_name'] ?? '',
            $row['channel_name'] ?? ''
        );
        $code = $fallbackCode !== '' ? $fallbackCode : self::orderMethodCode($row);
        if ($name !== '') {
            return PaymentMetaService::safeMethodName(self::normalizeDisplayText($name), $code);
        }

        return $code !== '' ? PaymentMetaService::friendlyMethodName($code) : '';
    }

    private static function orderProductName(array $row, string $fallbackCode = ''): string
    {
        $subject = self::firstNonEmptyString(
            self::normalizeDisplayText($row['subject'] ?? ''),
            self::normalizeDisplayText($row['name'] ?? '')
        );
        $sourceKey = self::orderSourceKey($row);
        $methodName = self::orderMethodName($row, $fallbackCode);
        $subject = self::normalizeKnownOrderSubject($subject);

        if ($sourceKey === 'channel_test') {
            $subject = self::normalizeChannelTestSubject($subject, $methodName);
            if (
                $subject === ''
                || self::looksLikeUnknownDisplayText($subject)
                || $subject === $methodName
                || in_array(strtolower($subject), ['product', 'test order', 'pay test', 'payment test'], true)
                || in_array($subject, ['测试订单', '支付测试订单'], true)
                || strcasecmp($subject, 'Channel test order') === 0
            ) {
                return '通道测试订单';
            }
        }

        if ($sourceKey === 'homepage_payment_test') {
            if (
                $subject === ''
                || self::looksLikeUnknownDisplayText($subject)
                || in_array(strtolower($subject), ['product', 'test order', 'pay test', 'payment test'], true)
                || in_array($subject, ['测试订单', '支付测试订单'], true)
                || strcasecmp($subject, 'NexPay 支付测试订单') === 0
            ) {
                return '首页支付测试订单';
            }
        }

        if ($subject !== '' && !self::looksLikeUnknownDisplayText($subject)) {
            return $subject;
        }

        return self::orderSourceLabel($row);
    }

    private static function normalizeChannelTestSubject(string $subject, string $methodName = ''): string
    {
        $subject = self::normalizeDisplayText($subject);
        if ($subject === '') {
            return '';
        }

        $subject = preg_replace('/^\d+\s+/u', '', $subject) ?: $subject;
        $subject = trim($subject);
        if ($subject === '') {
            return '';
        }

        $normalizedMethod = self::normalizeDisplayText($methodName);
        if ($normalizedMethod !== '') {
            foreach ([
                $normalizedMethod . ' 测试订单',
                $normalizedMethod . '赞赏码测试订单',
                $normalizedMethod . '收款码测试订单',
            ] as $pattern) {
                if (mb_strtolower($subject, 'UTF-8') === mb_strtolower($pattern, 'UTF-8')) {
                    return '通道测试订单';
                }
            }
        }

        foreach ([
            '/^.+?\s+赞赏码\s+测试订单$/u',
            '/^.+?\s+收款码\s+测试订单$/u',
            '/^.+?\s+测试订单$/u',
        ] as $pattern) {
            if (preg_match($pattern, $subject) === 1) {
                return '通道测试订单';
            }
        }

        return $subject;
    }

    private static function normalizeKnownOrderSubject(string $subject): string
    {
        $subject = self::normalizeDisplayText($subject);
        if ($subject === '') {
            return '';
        }

        return match (strtolower($subject)) {
            'callback event verify' => '监控回调校验订单',
            'heartbeat test order' => '监控心跳测试订单',
            'checkorder test order' => '监控查单测试订单',
            'pcnotify test order' => '监控回调测试订单',
            'report test order' => '监控上报测试订单',
            default => $subject,
        };
    }

    private static function fundFlowMethodName(string $refType, array $meta, array $orderRow, string $methodCode = ''): string
    {
        $name = self::firstNonEmptyString(
            $meta['method_name'] ?? '',
            $meta['channel_name'] ?? '',
            self::pluginNotifyMethodNameByTradeNo(self::firstNonEmptyString($meta['trade_no'] ?? '', (string)($orderRow['trade_no'] ?? ''))),
            self::orderMethodName($orderRow, $methodCode)
        );
        if ($name !== '') {
            return $name;
        }

        return match (trim($refType)) {
            'package_purchase' => '余额支付',
            'settlement_withdraw' => '余额提现',
            'settlement_reject' => '提现退回',
            'transfer' => '余额代付',
            'refund' => '原路退款',
            default => $methodCode !== '' ? PaymentMetaService::friendlyMethodName($methodCode) : '',
        };
    }

    private static function fundFlowSourceLabel(string $refType, array $orderRow = []): string
    {
        if ($orderRow !== []) {
            $sourceLabel = self::orderSourceLabel($orderRow);
            if ($sourceLabel !== '' && !($sourceLabel === '普通订单' && self::systemFundBusinessByRefType($refType) !== '')) {
                return $sourceLabel;
            }
        }

        $business = self::systemFundBusinessByRefType($refType);
        if ($business !== '') {
            return self::adminSystemEarningSourceLabel($business);
        }

        return match (trim($refType)) {
            'settlement_withdraw' => '提现申请',
            'settlement_reject' => '提现退回',
            'transfer' => '余额代付',
            'refund' => '原路退款',
            default => '',
        };
    }

    private static function fundFlowSubject(string $type, string $refType, array $meta, array $orderRow = []): string
    {
        $methodCode = self::orderMethodCode($orderRow);
        $business = self::systemFundBusinessByRefType($refType);
        $subject = self::firstNonEmptyString(
            self::fallbackOrderProductName($orderRow, $business, $methodCode),
            self::normalizeDisplayText($meta['subject'] ?? ''),
            self::normalizeDisplayText($meta['name'] ?? '')
        );
        if ($refType !== '' && $orderRow === [] && $business === '' && self::isPayoutFlowRefType($refType)) {
            $subject = '';
        }
        if ($subject !== '' && !self::looksLikeUnknownDisplayText($subject)) {
            return $subject;
        }

        if ($business !== '') {
            return self::systemFundOrderTypeLabel($business);
        }

        return match (trim($refType)) {
            'settlement_withdraw' => '提现申请',
            'settlement_reject' => '提现退回',
            'transfer' => '余额代付',
            'refund' => '订单退款',
            default => trim($type) !== '' ? trim($type) : '资金流水',
        };
    }

    private static function fundFlowRemark(array $row, array $orderRow = []): string
    {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $refType = strtolower(trim((string)($row['ref_type'] ?? '')));
        $payoutRemark = self::payoutFlowRemark($refType, $meta);
        if ($payoutRemark !== '') {
            return $payoutRemark;
        }

        return self::firstNonEmptyString(
            $meta['remark'] ?? '',
            $meta['reason'] ?? '',
            $meta['proof_no'] ?? '',
            $meta['channel_trade_no'] ?? '',
            $meta['channel_order_no'] ?? '',
            $meta['subject'] ?? '',
            $meta['name'] ?? '',
            $orderRow['subject'] ?? '',
            $row['ref_no'] ?? ''
        );
    }

    private static function pluginNotifyMethodCodeByTradeNo(string $tradeNo): string
    {
        $tradeNo = trim($tradeNo);
        if ($tradeNo === '') {
            return '';
        }

        foreach (PluginNotifyLogService::logs(500) as $log) {
            if (!is_array($log)) {
                continue;
            }

            if (trim((string)($log['trade_no'] ?? '')) !== $tradeNo) {
                continue;
            }

            $methodCode = PaymentMetaService::normalizeMethodCode((string)($log['method_code'] ?? ''));
            if ($methodCode !== '') {
                return $methodCode;
            }
        }

        return '';
    }

    private static function pluginNotifyMethodNameByTradeNo(string $tradeNo): string
    {
        $methodCode = self::pluginNotifyMethodCodeByTradeNo($tradeNo);
        return $methodCode !== '' ? PaymentMetaService::friendlyMethodName($methodCode) : '';
    }

    private static function merchantName(array $row, int $merchantId): string
    {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') {
            return AccountService::cleanMerchantDisplayName($name, (string)($row['username'] ?? ''), $merchantId);
        }

        $name = trim((string)($row['merchant_name'] ?? ''));
        if ($name !== '') {
            return AccountService::cleanMerchantDisplayName($name, (string)($row['username'] ?? ''), $merchantId);
        }

        return AccountService::cleanMerchantDisplayName('', (string)($row['username'] ?? ''), $merchantId);
    }

    private static function merchantContactName(array $row, int $merchantId): string
    {
        return AccountService::cleanMerchantDisplayName(
            (string)($row['contact_name'] ?? $row['name'] ?? ''),
            (string)($row['username'] ?? ''),
            $merchantId
        );
    }

    private static function orderMerchantName(array $row, string $merchantName, bool $merchantView): string
    {
        $merchantId = (int)($row['merchant_id'] ?? 0);
        if (!$merchantView && self::orderSourceKey($row) === 'homepage_payment_test') {
            return '游客';
        }

        if (!$merchantView && trim($merchantName) !== '') {
            return self::normalizeOrderMerchantName(
                AccountService::cleanMerchantDisplayName($merchantName, '', $merchantId),
                $row,
                $merchantId
            );
        }

        $credential = $merchantId > 0 ? AccountService::merchantCredentialById($merchantId) : null;
        if (is_array($credential)) {
            return self::normalizeOrderMerchantName(self::merchantName($credential, $merchantId), $row, $merchantId);
        }

        return self::normalizeOrderMerchantName(self::merchantName($row, $merchantId), $row, $merchantId);
    }

    private static function merchantDisplayName(int $merchantId): string
    {
        if ($merchantId <= 0) {
            return '未知商户';
        }

        if (database_available()) {
            try {
                $merchant = Merchant::find($merchantId);
                if ($merchant) {
                    return self::merchantName(self::row($merchant), $merchantId);
                }
            } catch (Throwable) {
            }
        }

        $credential = AccountService::merchantCredentialById($merchantId);
        if ($credential !== null) {
            return self::merchantName($credential, $merchantId);
        }

        return '商户' . $merchantId;
    }

    private static function merchantGroupName(array $merchant, array $groups): string
    {
        $name = trim((string)($merchant['group_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return (string)($groups[0]['name'] ?? '基础组');
    }

    private static function merchantStatusLabel(int $status): string
    {
        return match ($status) {
            1 => '正常',
            2 => '停用',
            default => '待审核',
        };
    }

    private static function realnameStatusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'approved', 'success', '已认证' => '已认证',
            'pending', 'reviewing', '审核中' => '待审核',
            'failed', 'rejected', 'denied' => '未通过',
            default => '未提交',
        };
    }

    private static function orderStatusLabel(int $status): string
    {
        return OrderStatusService::labelByCode($status);
    }

    private static function refundStatusLabel(int $status, string $result = '', string $lastError = ''): string
    {
        return match ($status) {
            1 => '成功',
            2 => '失败',
            default => match (trim($result)) {
                'manual_refund_pending' => '人工待退款',
                'plugin_refund_pending' => '自动同步中',
                default => trim($lastError) !== '' ? '待处理' : '未执行',
            },
        };
    }

    private static function refundStatusMeta(int $status, string $result = '', string $lastError = ''): array
    {
        $label = self::refundStatusLabel($status, $result, $lastError);

        return match ($label) {
            '成功' => self::statusMetaPayload(OrderStatusService::DISPLAY_SUCCESS, $label),
            '失败' => self::statusMetaPayload(OrderStatusService::DISPLAY_FAILED, $label),
            default => self::statusMetaPayload(OrderStatusService::DISPLAY_PENDING, $label),
        };
    }

    private static function transferStatusLabel(int $status, string $result = '', string $lastError = ''): string
    {
        return match ($status) {
            1 => '代付成功',
            2 => trim($result) === 'manual_rejected' ? '已驳回' : '代付失败',
            default => match (trim($result)) {
                'manual_transfer_pending' => '人工待代付',
                'plugin_transfer_pending' => '自动同步中',
                default => trim($lastError) !== '' ? '待处理' : '处理中',
            },
        };
    }

    private static function transferStatusMeta(int $status, string $result = '', string $lastError = ''): array
    {
        $label = self::transferStatusLabel($status, $result, $lastError);

        return match ($label) {
            '代付成功' => self::statusMetaPayload(OrderStatusService::DISPLAY_SUCCESS, $label),
            '代付失败' => self::statusMetaPayload(OrderStatusService::DISPLAY_FAILED, $label),
            '已驳回' => self::statusMetaPayload(OrderStatusService::DISPLAY_CLOSED, $label),
            default => self::statusMetaPayload(OrderStatusService::DISPLAY_PENDING, $label),
        };
    }

    private static function settlementStatusMeta(int $status, string $result = '', string $lastError = ''): array
    {
        $normalizedResult = strtolower(trim($result));
        $hasError = trim($lastError) !== '';

        if ($status === 1 || $normalizedResult === 'manual_approved') {
            return self::statusMetaPayload(OrderStatusService::DISPLAY_SUCCESS, '已通过');
        }

        if (
            $status === 2
            || $normalizedResult === 'manual_rejected'
            || $normalizedResult === 'rejected'
        ) {
            return self::statusMetaPayload(OrderStatusService::DISPLAY_CLOSED, '已驳回');
        }

        if ($hasError) {
            return self::statusMetaPayload(OrderStatusService::DISPLAY_PENDING, '待处理');
        }

        return self::statusMetaPayload(OrderStatusService::DISPLAY_PENDING, '待审核');
    }

    private static function isPayoutFlowRefType(string $refType): bool
    {
        return in_array(strtolower(trim($refType)), ['refund', 'transfer', 'settlement_withdraw', 'settlement_reject'], true);
    }

    private static function displayFundFlowStatusMeta(array $row): array
    {
        $refType = strtolower(trim((string)($row['ref_type'] ?? '')));
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        return match ($refType) {
            'refund' => self::refundStatusMeta(
                1,
                (string)($meta['result'] ?? ''),
                self::firstNonEmptyString($meta['reason'] ?? '', $meta['remark'] ?? '')
            ),
            'transfer' => self::transferStatusMeta(
                strtolower(trim((string)($meta['payout_status'] ?? ''))) === 'rejected' ? 2 : 1,
                (string)($meta['result'] ?? ''),
                self::firstNonEmptyString($meta['reason'] ?? '', $meta['remark'] ?? '')
            ),
            'settlement_withdraw', 'settlement_reject' => self::settlementFlowStatusMeta($row, $meta),
            default => self::fundFlowStatusMeta((string)($row['status'] ?? 'success')),
        };
    }

    private static function settlementFlowStatusMeta(array $row, array $meta = []): array
    {
        $settleNo = self::firstNonEmptyString(
            (string)($row['ref_no'] ?? ''),
            $meta['reference_no'] ?? ''
        );
        if ($settleNo !== '') {
            $settlement = LocalSettlementStore::find($settleNo);
            if ($settlement) {
                return self::settlementStatusMeta(
                    (int)($settlement->status ?? 0),
                    (string)($settlement->result ?? ''),
                    (string)($settlement->last_error ?? '')
                );
            }
        }

        $payoutStatus = strtolower(trim((string)($meta['payout_status'] ?? '')));
        $fallbackStatus = match ($payoutStatus) {
            'rejected' => 2,
            'approved', 'success' => 1,
            default => 0,
        };

        return self::settlementStatusMeta(
            $fallbackStatus,
            (string)($meta['result'] ?? ''),
            self::firstNonEmptyString($meta['reason'] ?? '', $meta['remark'] ?? '')
        );
    }

    private static function payoutFlowLookup(int $merchantId, string $refType, string $referenceNo): array
    {
        $merchantId = max(0, $merchantId);
        $refType = trim($refType);
        $referenceNo = trim($referenceNo);
        if ($merchantId <= 0 || $refType === '' || $referenceNo === '') {
            return ['row' => [], 'meta' => []];
        }

        $flow = LocalFundStore::findFlowByReference($merchantId, $refType, $referenceNo);
        $row = self::row($flow);
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        return [
            'row' => $row,
            'meta' => $meta,
        ];
    }

    private static function payoutFlowRemark(string $refType, array $meta): string
    {
        return match (strtolower(trim($refType))) {
            'refund' => self::firstNonEmptyString(
                $meta['remark'] ?? '',
                $meta['proof_no'] ?? '',
                $meta['channel_trade_no'] ?? '',
                $meta['channel_order_no'] ?? '',
                $meta['result'] ?? ''
            ),
            'transfer' => self::firstNonEmptyString(
                $meta['remark'] ?? '',
                $meta['proof_no'] ?? '',
                $meta['channel_trade_no'] ?? '',
                $meta['channel_order_no'] ?? '',
                self::joinDisplayParts([
                    $meta['target_name'] ?? '',
                    $meta['target_account'] ?? '',
                ]),
                $meta['result'] ?? ''
            ),
            'settlement_withdraw' => self::firstNonEmptyString(
                $meta['remark'] ?? '',
                self::joinDisplayParts([
                    $meta['account_name'] ?? '',
                    $meta['account'] ?? '',
                ]),
                $meta['result'] ?? ''
            ),
            'settlement_reject' => self::firstNonEmptyString(
                $meta['reason'] ?? '',
                $meta['remark'] ?? '',
                self::joinDisplayParts([
                    $meta['account_name'] ?? '',
                    $meta['account'] ?? '',
                ]),
                $meta['result'] ?? ''
            ),
            default => '',
        };
    }

    private static function refundReadPayload(array $row, bool $adminView = false): array
    {
        $merchantId = (int)($row['merchant_id'] ?? 0);
        $flowLookup = self::payoutFlowLookup($merchantId, 'refund', (string)($row['refund_no'] ?? ''));
        $flowRow = is_array($flowLookup['row'] ?? null) ? $flowLookup['row'] : [];
        $flowMeta = is_array($flowLookup['meta'] ?? null) ? $flowLookup['meta'] : [];
        $result = self::firstNonEmptyString($row['result'] ?? '', $flowMeta['result'] ?? '');
        $errmsg = self::firstNonEmptyString(
            $row['last_error'] ?? '',
            $flowMeta['reason'] ?? '',
            $flowMeta['remark'] ?? ''
        );
        $effectiveStatus = (int)($row['status'] ?? 0);
        if ($effectiveStatus === 0 && $flowRow !== []) {
            $effectiveStatus = 1;
        }
        $statusInfo = self::refundStatusMeta($effectiveStatus, $result, $errmsg);

        return [
            'category' => 'refund',
            'category_label' => '退款',
            'refund_no' => (string)($row['refund_no'] ?? ''),
            'out_refund_no' => (string)($row['out_refund_no'] ?? ''),
            'biz_no' => (string)($row['refund_no'] ?? ''),
            'out_biz_no' => (string)($row['out_refund_no'] ?? ''),
            'trade_no' => self::firstNonEmptyString((string)($row['trade_no'] ?? ''), $flowMeta['trade_no'] ?? ''),
            'amount' => self::amount((string)self::firstNonEmptyString(
                (string)($row['reducemoney'] ?? $row['money'] ?? ''),
                (string)($flowMeta['refund_amount'] ?? '0.00')
            )),
            'status_code' => (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING),
            'status_key' => (string)($statusInfo['key'] ?? 'pending'),
            'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
            'status' => (string)($statusInfo['label'] ?? '未执行'),
            'result' => $result,
            'errmsg' => $errmsg,
            'proof_no' => self::firstNonEmptyString(
                (string)($row['proof_no'] ?? ''),
                $flowMeta['proof_no'] ?? '',
                $flowMeta['channel_trade_no'] ?? '',
                $flowMeta['channel_order_no'] ?? ''
            ),
            'operator' => self::firstNonEmptyString((string)($row['operator'] ?? ''), $flowMeta['operator'] ?? ''),
            'channel_plugin_code' => self::firstNonEmptyString((string)($row['channel_plugin_code'] ?? ''), $flowMeta['plugin_code'] ?? ''),
            'channel_order_no' => self::firstNonEmptyString((string)($row['channel_order_no'] ?? ''), $flowMeta['channel_order_no'] ?? ''),
            'channel_trade_no' => self::firstNonEmptyString((string)($row['channel_trade_no'] ?? ''), $flowMeta['channel_trade_no'] ?? ''),
            'mode' => trim($result) === 'manual_refund_pending' ? 'manual' : 'auto',
            'mode_label' => trim($result) === 'manual_refund_pending' ? '人工处理' : '自动同步',
            'remark' => self::firstNonEmptyString((string)($row['remark'] ?? ''), self::payoutFlowRemark('refund', $flowMeta)),
            'created_at' => (string)($row['created_at'] ?? ''),
            'finished_at' => self::firstNonEmptyString((string)($row['finished_at'] ?? ''), (string)($flowRow['created_at'] ?? '')),
            'raw_status' => (int)($row['status'] ?? 0),
            'effective_status' => $effectiveStatus,
            'is_pending' => (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING) === OrderStatusService::DISPLAY_PENDING,
            'merchant' => $adminView ? self::merchantDisplayName($merchantId) : '',
        ];
    }

    private static function transferReadPayload(array $row, bool $adminView = false): array
    {
        $merchantId = (int)($row['merchant_id'] ?? 0);
        $flowLookup = self::payoutFlowLookup($merchantId, 'transfer', (string)($row['biz_no'] ?? ''));
        $flowRow = is_array($flowLookup['row'] ?? null) ? $flowLookup['row'] : [];
        $flowMeta = is_array($flowLookup['meta'] ?? null) ? $flowLookup['meta'] : [];
        $result = self::firstNonEmptyString($row['result'] ?? '', $flowMeta['result'] ?? '');
        $errmsg = self::firstNonEmptyString(
            $row['last_error'] ?? '',
            $flowMeta['reason'] ?? '',
            $flowMeta['remark'] ?? ''
        );
        $effectiveStatus = (int)($row['status'] ?? 0);
        if ($effectiveStatus === 0 && $flowRow !== []) {
            $effectiveStatus = strtolower(trim((string)($flowMeta['payout_status'] ?? ''))) === 'rejected' ? 2 : 1;
        }
        $statusInfo = self::transferStatusMeta($effectiveStatus, $result, $errmsg);

        return [
            'category' => 'transfer',
            'category_label' => '代付',
            'biz_no' => (string)($row['biz_no'] ?? ''),
            'out_biz_no' => self::firstNonEmptyString((string)($row['out_biz_no'] ?? ''), $flowMeta['out_biz_no'] ?? ''),
            'trade_no' => '',
            'type' => self::firstNonEmptyString((string)($row['type'] ?? ''), $flowMeta['type'] ?? $flowMeta['target_type'] ?? ''),
            'account' => self::firstNonEmptyString((string)($row['account'] ?? ''), $flowMeta['target_account'] ?? '', $flowMeta['account'] ?? ''),
            'name' => self::firstNonEmptyString((string)($row['name'] ?? ''), $flowMeta['target_name'] ?? '', $flowMeta['name'] ?? ''),
            'amount' => self::amount((string)self::firstNonEmptyString(
                (string)($row['money'] ?? ''),
                (string)($flowMeta['transfer_amount'] ?? '0.00')
            )),
            'status_code' => (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING),
            'status_key' => (string)($statusInfo['key'] ?? 'pending'),
            'status_theme' => (string)($statusInfo['theme'] ?? 'warning'),
            'status' => (string)($statusInfo['label'] ?? '处理中'),
            'result' => $result,
            'errmsg' => $errmsg,
            'proof_no' => self::firstNonEmptyString(
                (string)($row['proof_no'] ?? ''),
                $flowMeta['proof_no'] ?? '',
                $flowMeta['channel_trade_no'] ?? '',
                $flowMeta['channel_order_no'] ?? ''
            ),
            'operator' => self::firstNonEmptyString((string)($row['operator'] ?? ''), $flowMeta['operator'] ?? ''),
            'channel_plugin_code' => self::firstNonEmptyString((string)($row['channel_plugin_code'] ?? ''), $flowMeta['plugin_code'] ?? ''),
            'channel_order_no' => self::firstNonEmptyString((string)($row['channel_order_no'] ?? ''), $flowMeta['channel_order_no'] ?? ''),
            'channel_trade_no' => self::firstNonEmptyString((string)($row['channel_trade_no'] ?? ''), $flowMeta['channel_trade_no'] ?? ''),
            'mode' => trim($result) === 'manual_transfer_pending' ? 'manual' : 'auto',
            'mode_label' => trim($result) === 'manual_transfer_pending' ? '人工处理' : '自动同步',
            'remark' => self::firstNonEmptyString((string)($row['remark'] ?? ''), self::payoutFlowRemark('transfer', $flowMeta)),
            'created_at' => (string)($row['created_at'] ?? ''),
            'finished_at' => self::firstNonEmptyString((string)($row['finished_at'] ?? ''), (string)($flowRow['created_at'] ?? '')),
            'rejected_at' => (string)($row['rejected_at'] ?? ''),
            'raw_status' => (int)($row['status'] ?? 0),
            'effective_status' => $effectiveStatus,
            'is_pending' => (int)($statusInfo['code'] ?? OrderStatusService::DISPLAY_PENDING) === OrderStatusService::DISPLAY_PENDING,
            'merchant' => $adminView ? self::merchantDisplayName($merchantId) : '',
        ];
    }

    private static function joinDisplayParts(array $parts, string $separator = ' / '): string
    {
        $items = [];
        foreach ($parts as $part) {
            $text = self::normalizeDisplayText($part);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items === [] ? '' : implode($separator, $items);
    }

    private static function fundFlowStatusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'success', 'succeeded' => '成功',
            'failed', 'fail', 'error' => '失败',
            'closed' => '已关闭',
            'expired' => '已过期',
            'processing', 'pending' => '处理中',
            default => trim($status) !== '' ? $status : '成功',
        };
    }

    private static function fundFlowStatusMeta(string $status): array
    {
        $normalized = strtolower(trim($status));
        $label = self::fundFlowStatusLabel($status);

        return match ($normalized) {
            'success', 'succeeded' => self::statusMetaPayload(OrderStatusService::DISPLAY_SUCCESS, $label),
            'failed', 'fail', 'error' => self::statusMetaPayload(OrderStatusService::DISPLAY_FAILED, $label),
            'closed' => self::statusMetaPayload(OrderStatusService::DISPLAY_CLOSED, $label),
            'expired' => self::statusMetaPayload(OrderStatusService::DISPLAY_EXPIRED, $label),
            'processing', 'pending' => self::statusMetaPayload(OrderStatusService::DISPLAY_PENDING, $label),
            default => self::statusMetaPayload(OrderStatusService::DISPLAY_SUCCESS, $label),
        };
    }

    private static function statusMetaPayload(int $code, string $label): array
    {
        return [
            'code' => $code,
            'key' => OrderStatusService::keyByCode($code),
            'theme' => OrderStatusService::themeByCode($code),
            'label' => $label,
        ];
    }

    private static function maskAccount(string $account): string
    {
        $account = trim($account);
        if ($account === '') {
            return '';
        }

        if (str_contains($account, '@')) {
            [$name, $domain] = explode('@', $account, 2);
            return substr($name, 0, 2) . '***@' . $domain;
        }

        if (strlen($account) <= 7) {
            return substr($account, 0, 1) . '***' . substr($account, -1);
        }

        return substr($account, 0, 3) . '****' . substr($account, -4);
    }

    private static function amount(string $value): string
    {
        $normalized = (float)str_replace([',', ' '], '', $value);
        return number_format($normalized, 2, '.', '');
    }

    private static function orderPaymentReference(array $row): string
    {
        $reference = self::orderPaymentReferenceRaw($row);
        if ($reference === '') {
            return '';
        }

        $upper = strtoupper($reference);
        if (str_starts_with($upper, 'MANUAL-TX-')) {
            return '人工确认流水 ' . substr($reference, strlen('MANUAL-TX-'));
        }

        if (str_starts_with($upper, 'SOFTWARE_REPORT_')) {
            return '监控上报流水 ' . substr($reference, strlen('SOFTWARE_REPORT_'));
        }

        if (str_starts_with($upper, 'SOFTWARE_PCNOTIFY_')) {
            return '监控回调流水 ' . substr($reference, strlen('SOFTWARE_PCNOTIFY_'));
        }

        if (str_starts_with($upper, 'MOCK-')) {
            return '模拟支付流水 ' . substr($reference, strlen('MOCK-'));
        }

        return $reference;
    }

    private static function orderPaymentReferenceRaw(array $row): string
    {
        return self::normalizeDisplayText($row['txid'] ?? $row['api_trade_no'] ?? '');
    }

    private static function firstNonEmptyString(mixed ...$values): string
    {
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private static function normalizeDisplayText(mixed $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        $repaired = EncodingRepairService::repair($text);
        if (is_string($repaired)) {
            $text = trim($repaired);
        }

        $collapsed = preg_replace('/\s+/u', ' ', $text);
        return is_string($collapsed) ? trim($collapsed) : $text;
    }

    private static function looksLikeUnknownDisplayText(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }

        if (preg_match('/^\?{2,}$/u', $value) === 1) {
            return true;
        }

        return AccountService::cleanMerchantDisplayName($value, '__probe__') === '__probe__'
            || PaymentMetaService::containsMojibake($value);
    }

    private static function normalizeOrderMerchantName(string $name, array $row, int $merchantId): string
    {
        $name = self::normalizeDisplayText($name);
        if (preg_match('/^verify merchant(?:\s+(\d+))?$/i', $name, $matches) === 1) {
            $suffix = trim((string)($matches[1] ?? ''));
            return $suffix !== '' ? '测试商户 ' . $suffix : '测试商户';
        }

        if (self::orderSourceKey($row) === 'homepage_payment_test') {
            return $name !== '' && !self::looksLikeUnknownDisplayText($name) ? $name : '游客';
        }

        if ($name !== '' && !self::looksLikeUnknownDisplayText($name)) {
            return $name;
        }

        return $merchantId > 0 ? '商户' . $merchantId : '游客';
    }

    private static function rate(float $value): string
    {
        return number_format($value, 2, '.', '') . '%';
    }

    private static function row(mixed $record): array
    {
        if (is_array($record)) {
            return $record;
        }

        if (is_object($record) && method_exists($record, 'toArray')) {
            return $record->toArray();
        }

        if (is_object($record)) {
            return json_decode((string)json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?: [];
        }

        return [];
    }
}
