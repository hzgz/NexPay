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
            'contact_name' => (string)($row['contact_name'] ?? $row['name'] ?? ''),
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
                    if (!LocalOrderStore::isBusinessOrder($row)) {
                        continue;
                    }

                    $merchant = $merchants[(int)($row['merchant_id'] ?? 0)] ?? [];
                    $items[] = self::orderRow($row, (string)($merchant['name'] ?? ''), false);
                    $seenTradeNos[(string)($row['trade_no'] ?? '')] = true;
                }
            } catch (Throwable) {
            }
        }

        foreach (LocalOrderStore::businessOrders() as $order) {
            $row = self::row($order);
            $tradeNo = (string)($row['trade_no'] ?? '');
            if ($tradeNo !== '' && isset($seenTradeNos[$tradeNo])) {
                continue;
            }

            $items[] = self::orderRow($row, '', false);
            $seenTradeNos[$tradeNo] = true;
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return [
            'items' => array_slice($items, 0, 200),
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
            $items[] = self::orderRow(self::row($order), '', true);
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
            'settlements' => SettlementService::merchantSettlements($merchantId),
            'flows' => self::merchantFundFlows($merchantId),
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
        $baseUrl = ConfigService::gatewayBaseUrl();
        $platformPublicKey = MerchantApiService::compactPublicKey(ConfigService::platformPublicKey());
        $merchantPublicKey = MerchantApiService::compactPublicKey((string)($merchant['rsa_public_key'] ?? ''));
        $merchantPrivateKey = MerchantApiService::compactPrivateKey((string)($merchant['rsa_private_key'] ?? ''));
        if ($merchantPublicKey === '' && $merchantPrivateKey !== '') {
            $merchantPublicKey = MerchantApiService::derivePublicKey($merchantPrivateKey);
        }
        $signMode = MerchantApiService::signMode($merchantId);

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
        $seenTradeNos = [];

        if (\database_available()) {
            try {
                foreach (Order::where('merchant_id', $merchantId)->order('id', 'desc')->limit(100)->select()->toArray() as $row) {
                    if (!LocalOrderStore::isBusinessOrder($row)) {
                        continue;
                    }

                    $rows[] = $row;
                    $seenTradeNos[(string)($row['trade_no'] ?? '')] = true;
                }
            } catch (Throwable) {
            }
        }

        foreach (LocalOrderStore::businessOrdersByMerchant($merchantId) as $order) {
            $row = self::row($order);
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

    private static function merchantFundFlows(int $merchantId): array
    {
        $flows = [];

        foreach (LocalFundStore::businessFlowsForMerchant($merchantId) as $row) {
            $flows[] = [
                'type' => (string)($row['type'] ?? ''),
                'amount' => (string)($row['amount'] ?? '0.00'),
                'balance_after' => self::amount((string)($row['balance_after'] ?? '0.00')),
                'status' => (string)($row['status'] ?? 'success'),
                'created_at' => (string)($row['created_at'] ?? ''),
                'remark' => (string)($row['ref_no'] ?? ''),
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
                'type' => '转账',
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

            $flows[] = [
                'type' => '退款',
                'amount' => '-' . self::amount((string)($row['reducemoney'] ?? $row['money'] ?? '0.00')),
                'balance_after' => '',
                'status' => (int)($row['status'] ?? 0) === 2 ? '失败' : '处理中',
                'created_at' => (string)($row['created_at'] ?? ''),
                'remark' => (string)($row['last_error'] ?? $row['refund_no'] ?? ''),
            ];
        }

        usort($flows, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return array_slice($flows, 0, 100);
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
        foreach (LocalOrderStore::businessOrders() as $order) {
            if ((int)($order->status ?? 0) !== 1) {
                continue;
            }

            $fee = (float)($order->platform_fee ?? 0);
            if ($fee <= 0) {
                continue;
            }

            $items[] = [
                'type' => '订单手续费',
                'merchant' => self::merchantDisplayName((int)($order->merchant_id ?? 0)),
                'amount' => self::amount((string)$fee),
                'remark' => (string)($order->trade_no ?? ''),
                'created_at' => (string)($order->pay_time ?? $order->updated_at ?? $order->created_at ?? ''),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return $items;
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

        return [
            'trade_no' => (string)($row['trade_no'] ?? ''),
            'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
            'merchant' => self::orderMerchantName($row, $merchantName, $merchantView),
            'channel_code' => (string)($row['channel_code'] ?? ''),
            'amount' => self::amount((string)($row['amount'] ?? '0.00')),
            'status' => self::orderStatusLabel($statusCode),
            'status_code' => $statusCode,
            'txid' => (string)($row['txid'] ?? $row['api_trade_no'] ?? ''),
            'pay_time' => (string)($row['pay_time'] ?? $row['endtime'] ?? ''),
            'callback_status' => (int)($row['callback_status'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
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

    private static function orderMerchantName(array $row, string $merchantName, bool $merchantView): string
    {
        $merchantId = (int)($row['merchant_id'] ?? 0);
        if (!$merchantView && trim($merchantName) !== '') {
            return AccountService::cleanMerchantDisplayName($merchantName, '', $merchantId);
        }

        $credential = $merchantId > 0 ? AccountService::merchantCredentialById($merchantId) : null;
        if (is_array($credential)) {
            return self::merchantName($credential, $merchantId);
        }

        return self::merchantName($row, $merchantId);
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
