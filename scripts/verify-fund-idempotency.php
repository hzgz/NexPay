<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\service\payment\LocalFundStore;
use app\service\payment\LocalSettlementStore;
use app\service\system\JsonStoreService;
use app\service\system\SettlementService;

$flowStore = 'fund_flows_local';
$settlementStore = 'settlements_local';
$settingsStore = 'settings';
$originalFlows = JsonStoreService::load($flowStore, []);
$originalSettlements = JsonStoreService::load($settlementStore, []);
$originalSettings = JsonStoreService::load($settingsStore, []);

try {
    $testSettings = $originalSettings;
    $testSettings['merchant'] = is_array($testSettings['merchant'] ?? null) ? $testSettings['merchant'] : [];
    $testSettings['merchant']['require_realname_before_withdraw'] = false;
    JsonStoreService::save($settingsStore, $testSettings);

    $merchantId = 87000000 + random_int(100000, 899999);
    $now = date('Y-m-d H:i:s');
    $seedNo = 'IDEMPSEED' . date('YmdHis') . random_int(100000, 999999);
    $settleNo = 'SET' . date('YmdHis') . random_int(100000, 999999);
    $outSettleNo = 'OUT' . $settleNo;

    LocalFundStore::credit($merchantId, '200.00', '样本入账', 'fund_probe_seed', $seedNo, $now, [
        'scope' => 'idempotency_probe',
    ]);
    LocalFundStore::credit($merchantId, '999.00', '不同文案', 'fund_probe_seed', $seedNo, $now, [
        'scope' => 'idempotency_probe_repeat',
    ]);

    LocalFundStore::debit($merchantId, '50.00', '提现申请', 'settlement_withdraw', $settleNo, $now, [
        'out_settle_no' => $outSettleNo,
    ]);
    LocalFundStore::debit($merchantId, '50.00', '重复提现文案', 'settlement_withdraw', $settleNo, $now, [
        'out_settle_no' => $outSettleNo,
    ]);

    LocalSettlementStore::create([
        'merchant_id' => $merchantId,
        'settle_no' => $settleNo,
        'out_settle_no' => $outSettleNo,
        'type' => 'manual_withdraw',
        'account_type' => 'alipay',
        'account' => 'settle987654321@example.net',
        'account_name' => 'Probe User',
        'money' => '50.00',
        'fee' => '0.00',
        'real_money' => '50.00',
        'status' => 0,
        'result' => 'pending_manual_review',
        'remark' => 'idempotency probe',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    SettlementService::review($settleNo, 'reject', 'idempotency-probe', 'first reject');
    $secondReview = SettlementService::review($settleNo, 'reject', 'idempotency-probe', 'second reject');
    $replayedWithdraw = SettlementService::requestWithdraw($merchantId, 0, [
        'money' => '50.00',
        'account_type' => 'alipay',
        'account' => 'settle987654321@example.net',
        'account_name' => 'Probe User',
        'out_settle_no' => $outSettleNo,
    ]);

    $counts = [
        'seed_flow_count' => 0,
        'withdraw_flow_count' => 0,
        'reject_flow_count' => 0,
    ];
    foreach (JsonStoreService::load($flowStore, []) as $row) {
        if ((int)($row['merchant_id'] ?? 0) !== $merchantId) {
            continue;
        }

        if (($row['ref_type'] ?? '') === 'fund_probe_seed' && ($row['ref_no'] ?? '') === $seedNo) {
            $counts['seed_flow_count']++;
        }
        if (($row['ref_type'] ?? '') === 'settlement_withdraw' && ($row['ref_no'] ?? '') === $settleNo) {
            $counts['withdraw_flow_count']++;
        }
        if (($row['ref_type'] ?? '') === 'settlement_reject' && ($row['ref_no'] ?? '') === $settleNo) {
            $counts['reject_flow_count']++;
        }
    }

    $result = $counts + [
        'second_review_idempotent' => (bool)($secondReview['idempotent'] ?? false),
        'replayed_withdraw_idempotent' => (bool)($replayedWithdraw['idempotent'] ?? false),
        'balance_after_probe' => LocalFundStore::balanceForMerchant($merchantId),
    ];

    $ok = $result['seed_flow_count'] === 1
        && $result['withdraw_flow_count'] === 1
        && $result['reject_flow_count'] === 1
        && $result['second_review_idempotent'] === true
        && $result['replayed_withdraw_idempotent'] === true
        && ($result['balance_after_probe']['available'] ?? '') === '200.00';

    echo json_encode($result + ['ok' => $ok], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    JsonStoreService::save($flowStore, $originalFlows);
    JsonStoreService::save($settlementStore, $originalSettlements);
    JsonStoreService::save($settingsStore, $originalSettings);
}

exit(($ok ?? false) ? 0 : 1);
