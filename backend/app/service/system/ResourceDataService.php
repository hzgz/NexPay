<?php

namespace app\service\system;

use app\model\Merchant;
use app\model\MerchantBalance;
use app\model\MerchantUser;
use app\model\Order;
use app\service\payment\CallbackService;
use app\service\payment\LocalFundStore;
use app\service\payment\LocalOrderStore;
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

        if (\database_available()) {
            try {
                $merchants = [];
                foreach (Merchant::select()->toArray() as $merchant) {
                    $row = self::row($merchant);
                    $merchants[(int)($row['id'] ?? 0)] = $row;
                }

                foreach (Order::order('id', 'desc')->limit(200)->select()->toArray() as $order) {
                    $row = self::row($order);
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
            } catch (Throwable) {
            }
        }

        foreach (LocalOrderStore::allOrders() as $order) {
            $row = self::row($order);
            if (self::isDeletedOrderRow($row)) {
                continue;
            }
            $bucket = self::adminOrderBucket($row);
            if ($bucket === '') {
                continue;
            }

            self::appendAdminOrderBucketRow(
                $bucket,
                $row,
                '',
                $items,
                $rechargeOrders,
                $packageOrders,
                $seenTradeNos
            );
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });
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
            'flow_stats' => self::merchantFundStats($effectiveBalance, $settlements, count($systemOrders)),
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

        return array_slice($rows, 0, 100);
    }

    private static function merchantFundFlows(int $merchantId, ?array $systemOrderRows = null, ?array $orderLookup = null): array
    {
        $flows = [];
        $orderLookup ??= self::merchantFlowOrderLookup($merchantId);

        foreach (LocalFundStore::businessFlowsForMerchant($merchantId) as $row) {
            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $tradeNo = self::firstNonEmptyString(
                $meta['trade_no'] ?? '',
                in_array((string)($row['ref_type'] ?? ''), ['recharge', 'order_income'], true) ? (string)($row['ref_no'] ?? '') : ''
            );
            $outTradeNo = self::firstNonEmptyString($meta['out_trade_no'] ?? '');
            $orderRow = self::lookupMerchantOrderRow($merchantId, $orderLookup, $tradeNo, $outTradeNo);
            $methodCode = self::firstNonEmptyString($meta['channel_code'] ?? '', self::orderMethodCode($orderRow));
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
                'method_code' => $methodCode,
                'method_name' => self::fundFlowMethodName((string)($row['ref_type'] ?? ''), $meta, $orderRow, $methodCode),
                'amount' => (string)($row['amount'] ?? '0.00'),
                'balance_after' => self::amount((string)($row['balance_after'] ?? '0.00')),
                'status' => self::fundFlowStatusLabel((string)($row['status'] ?? 'success')),
                'created_at' => (string)($row['created_at'] ?? ''),
                'remark' => self::fundFlowRemark($row, $orderRow),
            ];
        }

        foreach (LocalTransferStore::businessTransfers($merchantId) as $transfer) {
            $row = self::row($transfer);
            if ((int)($row['status'] ?? 0) === 1) {
                continue;
            }

            $transferStatus = match ((int)($row['status'] ?? 0)) {
                2 => '已驳回',
                default => (trim((string)($row['last_error'] ?? '')) !== '' ? '未执行' : '处理中'),
            };
            $transferRemark = trim((string)($row['last_error'] ?? ''));
            if ($transferRemark === '') {
                $transferRemark = (string)($row['result'] ?? $row['biz_no'] ?? '');
            }

            $flows[] = [
                'row_key' => 'transfer:' . (string)($row['biz_no'] ?? md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
                'type' => '转账',
                'trade_no' => '',
                'out_trade_no' => (string)($row['out_biz_no'] ?? ''),
                'method_code' => '',
                'method_name' => '余额代付',
                'amount' => '-' . self::amount((string)($row['money'] ?? '0.00')),
                'balance_after' => '',
                'status' => $transferStatus,
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

            $flows[] = [
                'row_key' => 'refund:' . (string)($row['refund_no'] ?? md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
                'type' => '退款',
                'trade_no' => (string)($row['trade_no'] ?? ''),
                'out_trade_no' => self::firstNonEmptyString((string)($row['out_trade_no'] ?? ''), (string)($orderRow['out_trade_no'] ?? '')),
                'method_code' => $methodCode,
                'method_name' => self::fundFlowMethodName('refund', [], $orderRow, $methodCode),
                'amount' => '-' . self::amount((string)($row['reducemoney'] ?? $row['money'] ?? '0.00')),
                'balance_after' => '',
                'status' => (int)($row['status'] ?? 0) === 2 ? '失败' : '处理中',
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

    private static function merchantFundOrderRows(int $merchantId): array
    {
        return self::merchantFundOrderRowsFromOrders($merchantId, self::loadMerchantFundOrders($merchantId));
    }

    private static function merchantFundOrderRowsFromOrders(int $merchantId, array $orders): array
    {
        $items = [];

        foreach ($orders as $row) {
            $business = self::systemFundOrderBusiness($row);
            if ($business === '') {
                continue;
            }

            $tradeNo = trim((string)($row['trade_no'] ?? ''));
            $statusCode = (int)($row['status'] ?? OrderService::STATUS_PENDING);

            if (
                $business === 'merchant_recharge'
                && $statusCode === OrderService::STATUS_SUCCESS
                && $tradeNo !== ''
                && LocalFundStore::findFlowByReference($merchantId, 'recharge', $tradeNo) !== null
            ) {
                continue;
            }

            $items[] = [
                'row_key' => 'system-order:' . ($tradeNo !== '' ? $tradeNo : md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
                'type' => self::systemFundOrderTypeLabel($business),
                'trade_no' => $tradeNo,
                'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
                'method_code' => self::orderMethodCode($row),
                'method_name' => self::orderMethodName($row),
                'amount' => self::amount((string)($row['amount'] ?? '0.00')),
                'balance_after' => '',
                'status' => self::orderStatusLabel($statusCode),
                'created_at' => (string)($row['created_at'] ?? ''),
                'remark' => self::systemFundOrderRemark($row, $business),
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

        return array_slice($rows, 0, 100);
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

        return in_array($business, ['merchant_recharge', 'merchant_package_purchase'], true) ? $business : '';
    }

    private static function adminEarningBusiness(array $row): string
    {
        $business = self::orderSourceKey($row);

        return in_array($business, [
            'merchant_recharge',
            'merchant_package_purchase',
            'merchant_register_fee',
            'homepage_payment_test',
            'channel_test',
            'software_compat_test',
        ], true) ? $business : '';
    }

    private static function systemFundOrderTypeLabel(string $business): string
    {
        return match ($business) {
            'merchant_recharge' => '在线充值订单',
            'merchant_package_purchase' => '套餐购买订单',
            default => '系统订单',
        };
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
            $row = self::row($refund);
            if ((int)($row['status'] ?? 0) !== 0) {
                continue;
            }

            $items[] = [
                'category' => 'refund',
                'category_label' => '退款',
                'biz_no' => (string)($row['refund_no'] ?? ''),
                'out_biz_no' => (string)($row['out_refund_no'] ?? ''),
                'trade_no' => (string)($row['trade_no'] ?? ''),
                'amount' => self::amount((string)($row['reducemoney'] ?? $row['money'] ?? '0.00')),
                'status_code' => 0,
                'status' => self::refundStatusLabel(0, (string)($row['result'] ?? ''), (string)($row['last_error'] ?? '')),
                'result' => (string)($row['result'] ?? ''),
                'errmsg' => (string)($row['last_error'] ?? ''),
                'proof_no' => (string)($row['proof_no'] ?? ''),
                'operator' => (string)($row['operator'] ?? ''),
                'channel_plugin_code' => (string)($row['channel_plugin_code'] ?? ''),
                'mode' => trim((string)($row['result'] ?? '')) === 'manual_refund_pending' ? 'manual' : 'auto',
                'mode_label' => trim((string)($row['result'] ?? '')) === 'manual_refund_pending' ? '人工处理' : '自动同步',
                'created_at' => (string)($row['created_at'] ?? ''),
                'finished_at' => (string)($row['finished_at'] ?? ''),
            ];
        }

        foreach (LocalTransferStore::businessTransfers($merchantId) as $transfer) {
            $row = self::row($transfer);
            if ((int)($row['status'] ?? 0) !== 0) {
                continue;
            }

            $items[] = [
                'category' => 'transfer',
                'category_label' => '代付',
                'biz_no' => (string)($row['biz_no'] ?? ''),
                'out_biz_no' => (string)($row['out_biz_no'] ?? ''),
                'trade_no' => '',
                'amount' => self::amount((string)($row['money'] ?? '0.00')),
                'status_code' => 0,
                'status' => self::transferStatusLabel(0, (string)($row['result'] ?? ''), (string)($row['last_error'] ?? '')),
                'result' => (string)($row['result'] ?? ''),
                'errmsg' => (string)($row['last_error'] ?? ''),
                'proof_no' => (string)($row['proof_no'] ?? $row['channel_order_no'] ?? ''),
                'operator' => (string)($row['operator'] ?? ''),
                'channel_plugin_code' => (string)($row['channel_plugin_code'] ?? ''),
                'mode' => trim((string)($row['result'] ?? '')) === 'manual_transfer_pending' ? 'manual' : 'auto',
                'mode_label' => trim((string)($row['result'] ?? '')) === 'manual_transfer_pending' ? '人工处理' : '自动同步',
                'created_at' => (string)($row['created_at'] ?? ''),
                'finished_at' => (string)($row['finished_at'] ?? ''),
            ];
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
            $row = self::row($refund);
            $items[] = [
                'refund_no' => (string)($row['refund_no'] ?? ''),
                'out_refund_no' => (string)($row['out_refund_no'] ?? ''),
                'trade_no' => (string)($row['trade_no'] ?? ''),
                'merchant' => self::merchantDisplayName((int)($row['merchant_id'] ?? 0)),
                'amount' => self::amount((string)($row['reducemoney'] ?? $row['money'] ?? '0.00')),
                'status_code' => (int)($row['status'] ?? 0),
                'status' => self::refundStatusLabel((int)($row['status'] ?? 0), (string)($row['result'] ?? ''), (string)($row['last_error'] ?? '')),
                'result' => (string)($row['result'] ?? ''),
                'mode' => trim((string)($row['result'] ?? '')) === 'manual_refund_pending' ? 'manual' : 'auto',
                'errmsg' => (string)($row['last_error'] ?? ''),
                'proof_no' => (string)($row['proof_no'] ?? ''),
                'channel_order_no' => (string)($row['channel_order_no'] ?? ''),
                'channel_trade_no' => (string)($row['channel_trade_no'] ?? ''),
                'channel_plugin_code' => (string)($row['channel_plugin_code'] ?? ''),
                'operator' => (string)($row['operator'] ?? ''),
                'finished_at' => (string)($row['finished_at'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }

        return $items;
    }

    private static function adminTransfers(): array
    {
        $items = [];
        foreach (LocalTransferStore::businessTransfers() as $transfer) {
            $row = self::row($transfer);
            $statusCode = (int)($row['status'] ?? 0);
            $items[] = [
                'biz_no' => (string)($row['biz_no'] ?? ''),
                'out_biz_no' => (string)($row['out_biz_no'] ?? ''),
                'merchant' => self::merchantDisplayName((int)($row['merchant_id'] ?? 0)),
                'type' => (string)($row['type'] ?? ''),
                'account' => self::maskAccount((string)($row['account'] ?? '')),
                'name' => (string)($row['name'] ?? ''),
                'amount' => self::amount((string)($row['money'] ?? '0.00')),
                'status_code' => $statusCode,
                'status' => self::transferStatusLabel($statusCode, (string)($row['result'] ?? ''), (string)($row['last_error'] ?? '')),
                'result' => (string)($row['result'] ?? ''),
                'mode' => trim((string)($row['result'] ?? '')) === 'manual_transfer_pending' ? 'manual' : 'auto',
                'errmsg' => (string)($row['last_error'] ?? ''),
                'proof_no' => (string)($row['proof_no'] ?? $row['channel_order_no'] ?? ''),
                'channel_order_no' => (string)($row['channel_order_no'] ?? ''),
                'channel_trade_no' => (string)($row['channel_trade_no'] ?? ''),
                'channel_plugin_code' => (string)($row['channel_plugin_code'] ?? ''),
                'operator' => (string)($row['operator'] ?? ''),
                'finished_at' => (string)($row['finished_at'] ?? ''),
                'rejected_at' => (string)($row['rejected_at'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }

        return $items;
    }

    private static function adminEarnings(): array
    {
        $items = [];
        $seenBusinessKeys = [];
        foreach (self::loadAdminOrderRecords() as $row) {
            $statusCode = (int)($row['status'] ?? OrderService::STATUS_PENDING);
            $merchantId = (int)($row['merchant_id'] ?? 0);
            $business = self::adminEarningBusiness($row);
            $tradeNo = (string)($row['trade_no'] ?? '');
            $methodCode = self::orderMethodCode($row);
            $methodName = self::orderMethodName($row, $methodCode);
            $subject = self::orderProductName($row, $methodCode);
            $createdAt = self::firstNonEmptyString(
                (string)($row['created_at'] ?? ''),
                (string)($row['pay_time'] ?? ''),
                (string)($row['updated_at'] ?? '')
            );

            if ($business !== '') {
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
                    'amount' => self::amount((string)($row['amount'] ?? '0.00')),
                    'status' => self::orderStatusLabel($statusCode),
                    'status_code' => $statusCode,
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

            $fee = (float)($row['platform_fee'] ?? 0);
            if ($fee <= 0) {
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
                'status' => self::orderStatusLabel($statusCode),
                'status_code' => $statusCode,
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

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return array_slice($items, 0, 200);
    }

    private static function adminSystemFlowEarningsFallback(): array
    {
        $items = [];
        foreach (LocalFundStore::businessFlowsForMerchant(0, 0) as $flow) {
            $row = self::row($flow);
            $refType = trim((string)($row['ref_type'] ?? ''));
            $business = match ($refType) {
                'recharge' => 'merchant_recharge',
                'package_purchase' => 'merchant_package_purchase',
                default => '',
            };
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
            $methodCode = self::firstNonEmptyString(
                $meta['channel_code'] ?? '',
                self::pluginNotifyMethodCodeByTradeNo($tradeNo)
            );
            $methodName = self::firstNonEmptyString(
                $meta['method_name'] ?? '',
                self::fundFlowMethodName($refType, $meta, [], $methodCode),
                $methodCode !== '' ? PaymentMetaService::friendlyMethodName($methodCode) : ''
            );
            $subject = self::firstNonEmptyString(
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
            $referenceNo = self::firstNonEmptyString($tradeNo, $outTradeNo, (string)($row['ref_no'] ?? ''));

            $items[] = [
                'row_key' => 'earning-flow:' . $business . ':' . ($referenceNo !== '' ? $referenceNo : (string)($row['id'] ?? md5((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))),
                'business_key' => self::adminEarningBusinessKey($business, $merchantId, $referenceNo),
                'type' => self::adminSystemEarningTypeLabel($business),
                'merchant' => self::merchantDisplayName($merchantId),
                'trade_no' => $tradeNo,
                'out_trade_no' => $outTradeNo,
                'source_label' => self::adminSystemEarningSourceLabel($business),
                'method_name' => $methodName,
                'subject' => $subject,
                'amount' => self::amount((string)abs((float)($row['amount'] ?? 0))),
                'status' => self::fundFlowStatusLabel((string)($row['status'] ?? 'success')),
                'status_code' => (string)($row['status'] ?? 'success') === 'success' ? OrderService::STATUS_SUCCESS : OrderService::STATUS_PENDING,
                'remark' => $subject,
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }

        return $items;
    }

    private static function adminSystemEarningTypeLabel(string $business): string
    {
        return match (strtolower(trim($business))) {
            'merchant_recharge' => '商户充值订单',
            'merchant_package_purchase' => '套餐购买订单',
            'merchant_register_fee' => '商户注册收费订单',
            'homepage_payment_test' => '首页测试订单',
            'channel_test' => '通道测试订单',
            'software_compat_test' => '监控软件测试订单',
            default => '系统业务订单',
        };
    }

    private static function adminEarningMerchantLabel(int $merchantId, string $business): string
    {
        return match (strtolower(trim($business))) {
            'homepage_payment_test' => '游客',
            default => $merchantId > 0 ? self::merchantDisplayName($merchantId) : '游客',
        };
    }

    private static function adminSystemEarningSourceLabel(string $business): string
    {
        return match (strtolower(trim($business))) {
            'merchant_recharge' => '在线充值',
            'merchant_package_purchase' => '套餐购买',
            'merchant_register_fee' => '注册收费',
            'homepage_payment_test' => '首页测试',
            'channel_test' => '通道测试',
            'software_compat_test' => '监控软件测试',
            default => '系统业务',
        };
    }

    private static function adminEarningBusinessKey(string $business, int $merchantId, string ...$values): string
    {
        $business = strtolower(trim($business));
        $referenceNo = self::firstNonEmptyString(...$values);
        if ($business === '' || $referenceNo === '') {
            return '';
        }

        return $business . ':' . ($merchantId > 0 ? (string)$merchantId : 'guest') . ':' . $referenceNo;
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

        return array_slice($rows, 0, $limit);
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
        $statusCode = (int)($row['status'] ?? 0);
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
            'status' => self::orderStatusLabel($statusCode),
            'status_code' => $statusCode,
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
                ? self::merchantOrderActionHint($row, $isDeleted, $statusCode, $notifyUrl, $sourceKey, $manualAction)
                : self::adminOrderActionHint(
                    $isDeleted,
                    $isMerchantOrder,
                    $statusCode,
                    $notifyUrl,
                    $sourceKey
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

        if ($statusCode === OrderService::STATUS_SUCCESS) {
            return 'retry';
        }

        if ($statusCode !== OrderService::STATUS_PENDING) {
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
        string $sourceKey
    ): string {
        if ($isDeleted) {
            return '已删除';
        }

        if ($isMerchantOrder) {
            return '商户订单';
        }

        return match (strtolower(trim($sourceKey))) {
            'homepage_payment_test' => '游客测试订单',
            'channel_test' => '通道测试订单',
            'software_compat_test' => '监控软件测试订单',
            default => '',
        };
    }

    private static function merchantOrderActionHint(
        array $row,
        bool $isDeleted,
        int $statusCode,
        string $notifyUrl,
        string $sourceKey,
        string $manualAction
    ): string {
        if ($isDeleted) {
            return '已删除';
        }

        if ($notifyUrl === '') {
            return '未配置回调地址';
        }

        if ($manualAction === 'retry') {
            return '可手动重发回调';
        }

        if ($manualAction === 'confirm') {
            return '可人工确认成功并立即回调';
        }

        if ($statusCode === OrderService::STATUS_EXPIRED) {
            return '订单已过期';
        }

        if ($statusCode === OrderService::STATUS_CLOSED) {
            return '订单已关闭';
        }

        if ($statusCode === OrderService::STATUS_FAILED) {
            return '订单已失败';
        }

        $expireTime = trim((string)($row['expire_time'] ?? ''));
        if ($statusCode === OrderService::STATUS_PENDING && $expireTime !== '') {
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
        $subject = trim((string)($row['subject'] ?? ''));

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
                $subject !== '' ? $subject : self::systemFundOrderTypeLabel($business)
            ),
            'amount' => self::amount((string)($row['amount'] ?? '0.00')),
            'status' => self::orderStatusLabel((int)($row['status'] ?? 0)),
            'status_code' => (int)($row['status'] ?? 0),
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
        if ($business !== '') {
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
            'software_compat_test' => '监控软件测试',
            'v1' => '易支付 V1',
            'v2' => '易支付 V2',
            'mapi-test' => 'MAPI 测试',
            'submit-test' => '提交测试',
            default => '普通订单',
        };
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

        if (\database_available()) {
            try {
                $query = Order::where('merchant_id', $merchantId);
                if ($tradeNo !== '') {
                    $query->where('trade_no', $tradeNo);
                } else {
                    $query->where('out_trade_no', $outTradeNo);
                }

                $order = $query->find();
                if ($order) {
                    $row = self::row($order);
                    self::rememberOrderLookupRow($lookup, $row);
                    return $row;
                }
            } catch (Throwable) {
            }
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

        if ($sourceKey === 'channel_test') {
            if (
                $subject === ''
                || self::looksLikeUnknownDisplayText($subject)
                || $subject === $methodName
                || in_array(strtolower($subject), ['product', 'test order', 'pay test', 'payment test'], true)
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
                || strcasecmp($subject, 'NexPay 支付测试订单') === 0
            ) {
                return '首页支付测试订单';
            }
        }

        if ($sourceKey === 'software_compat_test') {
            $subjectKey = strtolower($subject);
            return match ($subjectKey) {
                'pcnotify test order' => '监控回调测试订单',
                'report test order' => '监控上报测试订单',
                default => ($subject !== '' && !self::looksLikeUnknownDisplayText($subject)) ? $subject : '监控软件测试订单',
            };
        }

        if ($subject !== '' && !self::looksLikeUnknownDisplayText($subject)) {
            return $subject;
        }

        return self::orderSourceLabel($row);
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
            'settlement_reject' => '余额退回',
            'transfer' => '余额代付',
            'refund' => '原路退款',
            default => $methodCode !== '' ? PaymentMetaService::friendlyMethodName($methodCode) : '',
        };
    }

    private static function fundFlowRemark(array $row, array $orderRow = []): string
    {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];

        return self::firstNonEmptyString(
            $meta['remark'] ?? '',
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

        if (\database_available()) {
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
        return match ($status) {
            1 => '成功',
            2 => '失败',
            3 => '已过期',
            4 => '已关闭',
            default => '待支付',
        };
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

    private static function transferStatusLabel(int $status, string $result = '', string $lastError = ''): string
    {
        return match ($status) {
            1 => '代付成功',
            2 => trim($result) === 'manual_rejected' ? '已驳回' : '代付失败',
            default => match (trim($result)) {
                'manual_transfer_pending' => '人工待代付',
                'plugin_transfer_pending' => '自动同步中',
                default => trim($lastError) !== '' ? '待处理' : '待处理',
            },
        };
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
