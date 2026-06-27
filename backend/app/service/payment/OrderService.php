<?php

namespace app\service\payment;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\model\ChannelType;
use app\model\Merchant;
use app\model\MerchantBalance;
use app\model\MerchantChannel;
use app\model\Order;
use app\service\system\AccountService;
use app\service\system\AuthPolicyService;
use app\service\system\ConfigService;
use app\service\system\MerchantApiService;
use app\service\system\MerchantAuthService;
use app\service\system\MerchantChannelService;
use app\service\system\PackageService;
use app\service\system\PaymentMetaService;
use app\service\system\PluginService;
use app\service\system\SettlementService;
use app\service\system\CompensationAuditLogService;
use Throwable;
use think\facade\Db;

class OrderService
{
    public const DEFAULT_EXPIRE_SECONDS = 360;
    public const STATUS_PENDING = 0;
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAILED = 2;
    public const STATUS_EXPIRED = 3;
    public const STATUS_CLOSED = 4;
    private const CHANNEL_CACHE_TTL = 8;
    private const CHANNEL_CACHE_KEY = 'merchant_channel_route_cache';
    private const ORDER_LOOKUP_CACHE_TTL = 5;

    /**
     * @var array<string, array{order: object, cached_at: int}>
     */
    private static array $orderLookupCache = [];

    public static function expirePendingOrders(): int
    {
        if (self::canUseDatabase()) {
            $now = date('Y-m-d H:i:s');
            $orders = Order::where('status', self::STATUS_PENDING)
                ->where('expire_time', '<', $now)
                ->select();
            $changed = 0;
            foreach ($orders as $order) {
                $updated = self::saveOrder($order, [
                    'status' => self::STATUS_EXPIRED,
                    'updated_at' => $now,
                ]);
                LocalOrderEventStore::recordExpired($updated, [
                    'source' => 'db-expire-task',
                    'event_time' => $now,
                ]);
                $changed++;
            }

            return $changed;
        }

        return LocalOrderStore::expirePendingOrders();
    }

    public static function syncPendingOrders(int $limit = 20): array
    {
        $orders = self::canUseDatabase()
            ? Order::where('status', self::STATUS_PENDING)->order('id', 'asc')->limit($limit)->select()
            : LocalOrderStore::pendingOrders($limit);

        $candidates = is_countable($orders) ? count($orders) : 0;
        $checked = 0;
        $completed = 0;
        $deferred = 0;
        $failed = 0;
        $skipped = 0;
        $pluginErrors = 0;

        foreach ($orders as $order) {
            if ((int)($order->status ?? -1) !== self::STATUS_PENDING || self::isExpiredForSync($order)) {
                $skipped++;
                continue;
            }

            $checked++;

            try {
                $result = PluginExecutorService::query($order);
                $order = self::recordPluginQueryResult($order, $result);
            } catch (Throwable $exception) {
                $failed++;
                $pluginErrors++;
                self::recordPluginQueryResult($order, [
                    'ok' => false,
                    'status' => 0,
                    'result' => 'plugin_query_exception',
                    'errmsg' => $exception->getMessage(),
                    'raw' => [],
                    'channel' => [],
                ]);
                continue;
            }

            if (($result['ok'] ?? false) && (int)($result['status'] ?? 0) === 1) {
                try {
                    self::completeOrder($order, [
                        'source' => 'plugin-query',
                        'txid' => (string)($result['api_trade_no'] ?? ''),
                        'paid_at' => (string)($result['paid_at'] ?? ''),
                        'confirmations' => 1,
                        'buyer' => (string)($result['buyer'] ?? ''),
                        'bill_trade_no' => (string)($result['bill_trade_no'] ?? ''),
                        'bill_mch_trade_no' => (string)($result['bill_mch_trade_no'] ?? ''),
                    ]);
                    $completed++;
                } catch (Throwable $exception) {
                    $failed++;
                    $pluginErrors++;
                    self::recordPluginQueryResult($order, array_replace($result, [
                        'ok' => false,
                        'result' => 'plugin_complete_failed',
                        'errmsg' => $exception->getMessage(),
                    ]));
                }
                continue;
            }

            $deferred++;
            if (!($result['ok'] ?? false) || (int)($result['status'] ?? 0) === 2) {
                $failed++;
                $pluginErrors++;
            }
        }

        return [
            'candidates' => $candidates,
            'checked' => $checked,
            'completed' => $completed,
            'deferred' => $deferred,
            'failed' => $failed,
            'skipped' => $skipped,
            'plugin_errors' => $pluginErrors,
            'source' => 'plugin-daemon',
        ];
    }

    public static function syncPendingPayouts(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $refunds = array_slice(array_values(array_filter(
            LocalTransferStore::businessRefunds(0, 0),
            static fn(object $refund): bool => (int)($refund->status ?? -1) === 0
                && (string)($refund->result ?? '') === 'plugin_refund_pending'
        )), 0, $limit);

        $remaining = max(0, $limit - count($refunds));
        $transfers = $remaining > 0 ? array_slice(array_values(array_filter(
            LocalTransferStore::businessTransfers(0, 0),
            static fn(object $transfer): bool => (int)($transfer->status ?? -1) === 0
                && (string)($transfer->result ?? '') === 'plugin_transfer_pending'
        )), 0, $remaining) : [];

        $stats = [
            'candidates' => count($refunds) + count($transfers),
            'checked' => 0,
            'completed' => 0,
            'deferred' => 0,
            'failed' => 0,
            'manualized' => 0,
            'skipped' => 0,
            'plugin_errors' => 0,
            'refunds' => [
                'candidates' => count($refunds),
                'checked' => 0,
                'completed' => 0,
                'deferred' => 0,
                'failed' => 0,
                'manualized' => 0,
                'skipped' => 0,
            ],
            'transfers' => [
                'candidates' => count($transfers),
                'checked' => 0,
                'completed' => 0,
                'deferred' => 0,
                'failed' => 0,
                'manualized' => 0,
                'skipped' => 0,
            ],
            'source' => 'payout-sync',
        ];

        foreach ($refunds as $refund) {
            self::syncPendingRefundRecord($refund, $stats);
        }

        foreach ($transfers as $transfer) {
            self::syncPendingTransferRecord($transfer, $stats);
        }

        return $stats;
    }

    public static function syncPendingPayoutsWithSummary(int $limit = 20): array
    {
        $before = self::payoutSummary();
        $result = self::syncPendingPayouts($limit);
        $after = self::payoutSummary();

        return array_merge($result, [
            'refund_pending_before' => (int)($before['refunds']['plugin_pending'] ?? 0),
            'refund_manual_pending_before' => (int)($before['refunds']['manual_pending'] ?? 0),
            'transfer_pending_before' => (int)($before['transfers']['plugin_pending'] ?? 0),
            'transfer_manual_pending_before' => (int)($before['transfers']['manual_pending'] ?? 0),
            'payout_attention_before' => (int)($before['attention_total'] ?? 0),
            'refund_pending_after' => (int)($after['refunds']['plugin_pending'] ?? 0),
            'refund_manual_pending_after' => (int)($after['refunds']['manual_pending'] ?? 0),
            'transfer_pending_after' => (int)($after['transfers']['plugin_pending'] ?? 0),
            'transfer_manual_pending_after' => (int)($after['transfers']['manual_pending'] ?? 0),
            'payout_attention_after' => (int)($after['attention_total'] ?? 0),
        ]);
    }

    public static function syncPendingRefundsBatch(array $refundNos = [], int $limit = 20, string $operator = ''): array
    {
        $limit = max(1, min(100, $limit));
        $before = self::payoutSummary();
        $refunds = self::collectPendingRefundsForSync($refundNos, $limit);

        $stats = [
            'candidates' => count($refunds),
            'checked' => 0,
            'completed' => 0,
            'deferred' => 0,
            'failed' => 0,
            'manualized' => 0,
            'plugin_errors' => 0,
        ];

        foreach ($refunds as $refund) {
            $stats['checked']++;
            $result = self::syncPendingRefundStatusResult($refund);
            $bucket = (string)($result['bucket'] ?? 'failed');
            $stats[$bucket] = (int)($stats[$bucket] ?? 0) + 1;
            if (($result['plugin_error'] ?? false) === true) {
                $stats['plugin_errors']++;
            }
        }

        $after = self::payoutSummary();

        $response = array_merge($stats, [
            'summary_before' => $before['refunds'] ?? [],
            'summary_after' => $after['refunds'] ?? [],
            'plugin_pending_before' => (int)($before['refunds']['plugin_pending'] ?? 0),
            'manual_pending_before' => (int)($before['refunds']['manual_pending'] ?? 0),
            'attention_before' => (int)($before['refunds']['attention_total'] ?? 0),
            'plugin_pending_after' => (int)($after['refunds']['plugin_pending'] ?? 0),
            'manual_pending_after' => (int)($after['refunds']['manual_pending'] ?? 0),
            'attention_after' => (int)($after['refunds']['attention_total'] ?? 0),
        ]);

        self::appendRefundSyncAudit($operator, 'batch', $response, [
            'refund_nos' => array_keys(self::normalizePayoutKeys($refundNos)),
            'limit' => $limit,
            'count' => count($refunds),
            'merchant_id' => self::singleMerchantIdFromPayoutRecords($refunds, 'merchant_id'),
        ]);

        return $response;
    }

    public static function syncPendingTransfersBatch(array $bizNos = [], int $limit = 20, string $operator = ''): array
    {
        $limit = max(1, min(100, $limit));
        $before = self::payoutSummary();
        $transfers = self::collectPendingTransfersForSync($bizNos, $limit);

        $stats = [
            'candidates' => count($transfers),
            'checked' => 0,
            'completed' => 0,
            'deferred' => 0,
            'failed' => 0,
            'manualized' => 0,
            'plugin_errors' => 0,
        ];

        foreach ($transfers as $transfer) {
            $stats['checked']++;
            $result = self::syncPendingTransferStatusResult($transfer);
            $bucket = (string)($result['bucket'] ?? 'failed');
            $stats[$bucket] = (int)($stats[$bucket] ?? 0) + 1;
            if (($result['plugin_error'] ?? false) === true) {
                $stats['plugin_errors']++;
            }
        }

        $after = self::payoutSummary();

        $response = array_merge($stats, [
            'summary_before' => $before['transfers'] ?? [],
            'summary_after' => $after['transfers'] ?? [],
            'plugin_pending_before' => (int)($before['transfers']['plugin_pending'] ?? 0),
            'manual_pending_before' => (int)($before['transfers']['manual_pending'] ?? 0),
            'attention_before' => (int)($before['transfers']['attention_total'] ?? 0),
            'plugin_pending_after' => (int)($after['transfers']['plugin_pending'] ?? 0),
            'manual_pending_after' => (int)($after['transfers']['manual_pending'] ?? 0),
            'attention_after' => (int)($after['transfers']['attention_total'] ?? 0),
        ]);

        self::appendTransferSyncAudit($operator, 'batch', $response, [
            'biz_nos' => array_keys(self::normalizePayoutKeys($bizNos)),
            'limit' => $limit,
            'count' => count($transfers),
            'merchant_id' => self::singleMerchantIdFromPayoutRecords($transfers, 'merchant_id'),
        ]);

        return $response;
    }

    public static function payoutSummary(int $merchantId = 0): array
    {
        $refunds = self::summarizeRefunds(LocalTransferStore::businessRefunds($merchantId));
        $transfers = self::summarizeTransfers(LocalTransferStore::businessTransfers($merchantId));

        return [
            'refunds' => $refunds,
            'transfers' => $transfers,
            'attention_total' => (int)($refunds['attention_total'] ?? 0) + (int)($transfers['attention_total'] ?? 0),
        ];
    }

    public static function syncPendingRefundByNo(string $refundNo, string $operator = ''): array
    {
        $refundNo = trim($refundNo);
        if ($refundNo === '') {
            throw new BusinessException('退款单号不能为空', StatusCode::VALIDATION_ERROR);
        }

        $refund = LocalTransferStore::findRefundByNo($refundNo);
        if (!$refund || !LocalTransferStore::isBusinessRefund($refund)) {
            throw new BusinessException('退款记录不存在', StatusCode::NOT_FOUND);
        }
        if ((int)($refund->status ?? -1) !== 0) {
            throw new BusinessException('当前退款状态不需要同步', StatusCode::VALIDATION_ERROR);
        }

        $summaryBefore = self::payoutSummary();
        $result = self::syncPendingRefundStatusResult($refund);
        $updated = $result['refund'] ?? $refund;
        $summaryAfter = self::payoutSummary();

        $response = [
            'bucket' => (string)($result['bucket'] ?? 'failed'),
            'plugin_error' => (bool)($result['plugin_error'] ?? false),
            'refund_no' => (string)($updated->refund_no ?? $refundNo),
            'status' => (int)($updated->status ?? 0),
            'result' => (string)($updated->result ?? ''),
            'errmsg' => (string)($updated->last_error ?? ''),
            'summary_before' => $summaryBefore['refunds'] ?? [],
            'summary_after' => $summaryAfter['refunds'] ?? [],
        ];

        self::appendRefundSyncAudit($operator, 'single', $response, [
            'refund_no' => (string)($updated->refund_no ?? $refundNo),
            'merchant_id' => (int)($updated->merchant_id ?? $refund->merchant_id ?? 0),
        ]);

        return $response;
    }

    public static function syncPendingTransferByBizNo(string $bizNo, string $operator = ''): array
    {
        $bizNo = trim($bizNo);
        if ($bizNo === '') {
            throw new BusinessException('代付单号不能为空', StatusCode::VALIDATION_ERROR);
        }

        $transfer = LocalTransferStore::findTransferByBizNo($bizNo);
        if (!$transfer || !LocalTransferStore::isBusinessTransfer($transfer)) {
            throw new BusinessException('代付记录不存在', StatusCode::NOT_FOUND);
        }
        if ((int)($transfer->status ?? -1) !== 0) {
            throw new BusinessException('当前代付状态不需要同步', StatusCode::VALIDATION_ERROR);
        }

        $summaryBefore = self::payoutSummary();
        $result = self::syncPendingTransferStatusResult($transfer);
        $updated = $result['transfer'] ?? $transfer;
        $summaryAfter = self::payoutSummary();

        $response = [
            'bucket' => (string)($result['bucket'] ?? 'failed'),
            'plugin_error' => (bool)($result['plugin_error'] ?? false),
            'biz_no' => (string)($updated->biz_no ?? $bizNo),
            'status' => (int)($updated->status ?? 0),
            'result' => (string)($updated->result ?? ''),
            'errmsg' => (string)($updated->last_error ?? ''),
            'summary_before' => $summaryBefore['transfers'] ?? [],
            'summary_after' => $summaryAfter['transfers'] ?? [],
        ];

        self::appendTransferSyncAudit($operator, 'single', $response, [
            'biz_no' => (string)($updated->biz_no ?? $bizNo),
            'merchant_id' => (int)($updated->merchant_id ?? $transfer->merchant_id ?? 0),
        ]);

        return $response;
    }

    public static function syncPendingRefundStatus(object $refund): object
    {
        return self::syncPendingRefundStatusResult($refund)['refund'];
    }

    public static function createFromV1(array $payload): array
    {
        $merchant = self::resolveMerchantByPid((string)($payload['pid'] ?? ''));
        $signature = (string)($payload['sign'] ?? '');
        if (!SignService::verifyMd5($payload, (string)$merchant->mch_key, $signature)) {
            throw new BusinessException('V1 绛惧悕鏍￠獙澶辫触', StatusCode::UNAUTHORIZED);
        }

        $order = self::createGatewayOrder($merchant, self::normalizeMerchantOrderInput([
            'type' => $payload['type'] ?? '',
            'out_trade_no' => $payload['out_trade_no'] ?? '',
            'notify_url' => $payload['notify_url'] ?? '',
            'return_url' => $payload['return_url'] ?? '',
            'name' => $payload['name'] ?? '鏀粯璁㈠崟',
            'money' => $payload['money'] ?? '0',
            'clientip' => $payload['clientip'] ?? '',
            'param' => $payload['param'] ?? '',
            'source_protocol' => 'v1',
            'request_payload' => $payload,
        ]));

        return [
            'code' => 1,
            'msg' => '鎴愬姛',
            'trade_no' => $order->trade_no,
            'payurl' => self::checkoutUrl((string)$order->trade_no),
        ];
    }

    public static function createFromV1Fallback(array $payload): array
    {
        $tradeNo = trim((string)($payload['trade_no'] ?? ''));
        $pid = trim((string)($payload['pid'] ?? ''));
        $outTradeNo = trim((string)($payload['out_trade_no'] ?? ''));

        if ($tradeNo !== '') {
            $order = self::findByTradeNo($tradeNo);

            if ((int)$order->status === self::STATUS_SUCCESS && trim((string)$order->return_url) !== '') {
                return [
                    'code' => 1,
                    'msg' => 'success',
                    'trade_no' => $order->trade_no,
                    'payurl' => (string)$order->return_url,
                ];
            }

            return [
                'code' => 1,
                'msg' => 'success',
                'trade_no' => $order->trade_no,
                'payurl' => self::checkoutUrl((string)$order->trade_no),
            ];
        }

        if ($pid !== '' && $outTradeNo !== '') {
            $merchant = self::resolveMerchantByPid($pid);
            $order = self::findMerchantOrder((int)$merchant->id, null, $outTradeNo);

            if ((int)$order->status === self::STATUS_SUCCESS && trim((string)$order->return_url) !== '') {
                return [
                    'code' => 1,
                    'msg' => 'success',
                    'trade_no' => $order->trade_no,
                    'payurl' => (string)$order->return_url,
                ];
            }

            return [
                'code' => 1,
                'msg' => 'success',
                'trade_no' => $order->trade_no,
                'payurl' => self::checkoutUrl((string)$order->trade_no),
            ];
        }

        throw new BusinessException('支付重试缺少订单号', StatusCode::BAD_REQUEST);
    }

    public static function createFromV2(array $payload): array
    {
        $merchant = self::resolveMerchantByPid((string)($payload['pid'] ?? ''));
        $signature = (string)($payload['sign'] ?? '');
        if (!self::verifyV2Signature($payload, $merchant, $signature)) {
            throw new BusinessException('V2 绛惧悕鏍￠獙澶辫触', StatusCode::UNAUTHORIZED);
        }

        $order = self::createGatewayOrder($merchant, self::normalizeMerchantOrderInput([
            'type' => $payload['type'] ?? '',
            'method' => $payload['method'] ?? 'web',
            'device' => $payload['device'] ?? '',
            'out_trade_no' => $payload['out_trade_no'] ?? '',
            'notify_url' => $payload['notify_url'] ?? '',
            'return_url' => $payload['return_url'] ?? '',
            'name' => $payload['name'] ?? '鏀粯璁㈠崟',
            'money' => $payload['money'] ?? '0',
            'clientip' => $payload['clientip'] ?? '',
            'param' => $payload['param'] ?? '',
            'source_protocol' => 'v2',
            'request_payload' => $payload,
        ]));

        $response = [
            'code' => 0,
            'msg' => 'success',
            'trade_no' => $order->trade_no,
            'pay_type' => 'jump',
            'pay_info' => self::checkoutUrl((string)$order->trade_no),
            'timestamp' => (string)time(),
            'sign_type' => 'RSA',
        ];
        $response['sign'] = SignService::rsaSign($response, ConfigService::platformPrivateKey());

        return $response;
    }

    public static function queryForV1(array $query): array
    {
        return GatewayCompatService::queryForV1($query);
    }

    public static function queryForV2(array $payload): array
    {
        return GatewayCompatService::queryForV2($payload);
    }

    public static function closeForV2(array $payload): array
    {
        return GatewayCompatService::closeForV2($payload);
    }

    public static function refundForV2(array $payload): array
    {
        return GatewayCompatService::refundForV2($payload);
    }

    public static function findByTradeNo(string $tradeNo): object
    {
        $order = self::findByTradeNoOrNull($tradeNo);
        if (!$order) {
            throw new BusinessException('订单不存在', StatusCode::NOT_FOUND);
        }

        return $order;
    }

    public static function findByTradeNoOrNull(string $tradeNo): ?object
    {
        if ($tradeNo === '') {
            return null;
        }

        $cached = self::cachedOrderByTradeNo($tradeNo);
        if ($cached !== null) {
            return $cached;
        }

        if (self::canUseDatabase()) {
            $order = Order::where('trade_no', $tradeNo)->find();
            if ($order) {
                self::storeOrderLookupCache($order);
            }
            return $order;
        }

        $order = LocalOrderStore::findByTradeNo($tradeNo);
        if ($order) {
            self::storeOrderLookupCache($order);
        }
        return $order;
    }

    public static function findByIdOrNull(int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        if (self::canUseDatabase()) {
            return Order::find($orderId);
        }

        return LocalOrderStore::findById($orderId);
    }

    public static function completeByTradeNo(string $tradeNo, array $attributes = []): object
    {
        return self::completeOrder(self::findByTradeNo($tradeNo), $attributes);
    }

    public static function completeOrder(object $order, array $attributes = []): object
    {
        if ((int)$order->status === self::STATUS_SUCCESS) {
            return $order;
        }

        if ((int)$order->status !== self::STATUS_PENDING) {
            throw new BusinessException('仅待支付订单可标记为已支付', StatusCode::VALIDATION_ERROR);
        }

        $order = self::repairHomepageTestOrderMerchant($order);
        $merchant = self::resolveMerchantById((int)$order->merchant_id);
        if (!$merchant) {
            throw new BusinessException('商户不存在', StatusCode::NOT_FOUND);
        }

        $paidAt = self::normalizeDateTime((string)($attributes['paid_at'] ?? ''));
        $txid = trim((string)($attributes['txid'] ?? ''));
        $confirmations = max(0, (int)($attributes['confirmations'] ?? 0));
        $buyer = trim((string)($attributes['buyer'] ?? ''));
        $billTradeNo = trim((string)($attributes['bill_trade_no'] ?? ''));
        $billMchTradeNo = trim((string)($attributes['bill_mch_trade_no'] ?? ''));

        $notifyPayload = is_array($order->notify_payload) ? $order->notify_payload : [];
        $notifyPayload = array_replace($notifyPayload, [
            'trade_status' => 'TRADE_SUCCESS',
            'source' => (string)($attributes['source'] ?? 'system'),
            'confirmations' => $confirmations,
            'completed_at' => $paidAt,
        ]);
        if ($txid !== '') {
            $notifyPayload['api_trade_no'] = $txid;
        }
        foreach ([
            'buyer' => $buyer,
            'bill_trade_no' => $billTradeNo,
            'bill_mch_trade_no' => $billMchTradeNo,
        ] as $key => $value) {
            if ($value !== '') {
                $notifyPayload[$key] = $value;
            }
        }
        if (isset($attributes['manual_operator']) || isset($attributes['manual_remark'])) {
            $notifyPayload['manual_confirm'] = [
                'operator' => (string)($attributes['manual_operator'] ?? ''),
                'remark' => (string)($attributes['manual_remark'] ?? ''),
            ];
        }
        if (is_array($attributes['callback_trust'] ?? null) && $attributes['callback_trust'] !== []) {
            $notifyPayload['callback_trust'] = $attributes['callback_trust'];
        }

        $order = self::saveOrder($order, [
            'status' => self::STATUS_SUCCESS,
            'pay_time' => $paidAt,
            'txid' => $txid !== '' ? $txid : (string)$order->txid,
            'buyer' => $buyer !== '' ? $buyer : (string)($order->buyer ?? ''),
            'api_trade_no' => $txid !== '' ? $txid : (string)($order->api_trade_no ?? $order->txid ?? ''),
            'bill_trade_no' => $billTradeNo !== '' ? $billTradeNo : (string)($order->bill_trade_no ?? ''),
            'bill_mch_trade_no' => $billMchTradeNo !== '' ? $billMchTradeNo : (string)($order->bill_mch_trade_no ?? ''),
            'confirmations' => $confirmations,
            'platform_fee' => self::calculatePlatformFee($order),
            'fee_deducted' => (float)self::calculatePlatformFee($order) > 0 ? 1 : 0,
            'callback_status' => $order->notify_url ? 1 : 0,
            'notify_payload' => $notifyPayload,
        ]);

        MerchantAuthService::completeRegistrationPayment($order);
        PackageService::completePurchasePayment($order);
        LocalFundStore::recordOrderSuccess($order, $merchant);
        LocalOrderEventStore::recordPaid($order, [
            'source' => (string)($attributes['source'] ?? 'system'),
            'confirmations' => $confirmations,
            'buyer' => $buyer,
            'bill_trade_no' => $billTradeNo,
            'bill_mch_trade_no' => $billMchTradeNo,
        ]);
        CallbackService::enqueueOrder($order, $merchant);
        return $order;
    }

    public static function syncHomepageTestOrder(object $order, int $minIntervalSeconds = 2): object
    {
        $order = self::repairHomepageTestOrderMerchant($order);
        if ((int)($order->status ?? 0) !== self::STATUS_PENDING) {
            return $order;
        }

        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        if (($meta['business'] ?? '') !== 'homepage_payment_test') {
            return $order;
        }

        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $pluginQuery = is_array($notifyPayload['plugin_query'] ?? null) ? $notifyPayload['plugin_query'] : [];
        $checkedAt = trim((string)($pluginQuery['checked_at'] ?? ''));
        if ($checkedAt !== '') {
            $timestamp = strtotime($checkedAt);
            if ($timestamp !== false && (time() - $timestamp) < max(1, $minIntervalSeconds)) {
                return $order;
            }
        }

        $result = PluginExecutorService::query($order);
        $order = self::recordPluginQueryResult($order, $result);

        if (!(bool)($result['ok'] ?? false) || (int)($result['status'] ?? 0) !== 1) {
            return $order;
        }

        try {
            return self::completeOrder($order, [
                'source' => 'homepage-test-query',
                'txid' => (string)($result['api_trade_no'] ?? ''),
                'paid_at' => (string)($result['paid_at'] ?? ''),
                'confirmations' => 1,
                'buyer' => (string)($result['buyer'] ?? ''),
                'bill_trade_no' => (string)($result['bill_trade_no'] ?? ''),
                'bill_mch_trade_no' => (string)($result['bill_mch_trade_no'] ?? ''),
            ]);
        } catch (Throwable $exception) {
            return self::recordPluginQueryResult($order, array_replace($result, [
                'ok' => false,
                'result' => 'plugin_complete_failed',
                'errmsg' => $exception->getMessage(),
            ]));
        }
    }

    public static function protocolForOrder(object $order): string
    {
        $payload = is_array($order->request_payload) ? $order->request_payload : [];
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];

        $source = strtolower(trim((string)($meta['source_protocol'] ?? '')));
        if ($source !== '') {
            return $source;
        }

        $signType = strtoupper(trim((string)($payload['sign_type'] ?? '')));
        if ($signType === 'MD5') {
            return 'v1';
        }

        if ($signType === 'RSA' || isset($payload['timestamp']) || isset($payload['method'])) {
            return 'v2';
        }

        return 'v2';
    }

    public static function saveOrder(object $order, array $changes): object
    {
        foreach ($changes as $key => $value) {
            $order->$key = $value;
        }

        if (self::canUseDatabase()) {
            $order->save();
            self::storeOrderLookupCache($order);
            return $order;
        }

        $stored = LocalOrderStore::updateOrder((string)$order->trade_no, $changes);
        $resolved = $stored ?? $order;
        self::storeOrderLookupCache($resolved);
        return $resolved;
    }

    public static function createStoredRequestPayload(array $data): array
    {
        return self::buildStoredRequestPayload($data);
    }

    public static function nextTradeNo(): string
    {
        return self::generateTradeNo();
    }

    public static function persistCreatedOrder(array $payload, array $eventMeta = []): object
    {
        if (self::canUseDatabase()) {
            $order = Db::transaction(function () use ($payload) {
                $order = new Order();
                $order->save($payload);
                return $order;
            });
        } else {
            $order = LocalOrderStore::createOrder($payload);
        }

        self::storeOrderLookupCache($order);
        LocalOrderEventStore::recordCreated($order, $eventMeta);

        return $order;
    }

    public static function composeOrderPayload(array $data): array
    {
        $tradeNo = trim((string)($data['trade_no'] ?? ''));
        $createdAt = trim((string)($data['created_at'] ?? ''));
        $resolvedCreatedAt = $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s');

        return [
            'trade_no' => $tradeNo !== '' ? $tradeNo : self::generateTradeNo(),
            'out_trade_no' => trim((string)($data['out_trade_no'] ?? '')),
            'merchant_id' => (int)($data['merchant_id'] ?? 0),
            'merchant_channel_id' => (int)($data['merchant_channel_id'] ?? 0),
            'channel_code' => trim((string)($data['channel_code'] ?? '')),
            'channel_category' => (int)($data['channel_category'] ?? 2),
            'subject' => trim((string)($data['subject'] ?? '鏀粯璁㈠崟')),
            'amount' => self::formatMoney($data['amount'] ?? '0'),
            'payable_amount' => self::formatMoney($data['payable_amount'] ?? $data['amount'] ?? '0'),
            'status' => (int)($data['status'] ?? self::STATUS_PENDING),
            'notify_url' => trim((string)($data['notify_url'] ?? '')),
            'return_url' => trim((string)($data['return_url'] ?? '')),
            'client_ip' => trim((string)($data['client_ip'] ?? '')),
            'param' => trim((string)($data['param'] ?? '')),
            'expire_time' => trim((string)($data['expire_time'] ?? date('Y-m-d H:i:s', time() + self::DEFAULT_EXPIRE_SECONDS))),
            'request_payload' => is_array($data['request_payload'] ?? null) ? $data['request_payload'] : [],
            'notify_payload' => is_array($data['notify_payload'] ?? null) ? $data['notify_payload'] : [],
            'payment_address' => trim((string)($data['payment_address'] ?? '')),
            'created_at' => $resolvedCreatedAt,
            'updated_at' => trim((string)($data['updated_at'] ?? $resolvedCreatedAt)),
        ];
    }

    public static function buildPendingOrderPayload(array $data): array
    {
        $requestPayload = self::createStoredRequestPayload($data);
        $legacyChannel = is_array($data['legacy_channel_snapshot'] ?? null) ? $data['legacy_channel_snapshot'] : [];
        $tradeNo = trim((string)($data['trade_no'] ?? ''));
        $amount = self::formatMoney($data['amount'] ?? '0');
        $payableAmount = trim((string)($data['payable_amount'] ?? ''));

        if ($payableAmount === '' && $legacyChannel !== []) {
            $payableAmount = self::resolvePayableAmountForChannelSnapshot($legacyChannel, $amount, $tradeNo);
        }

        return self::composeOrderPayload([
            'trade_no' => $tradeNo,
            'out_trade_no' => $data['out_trade_no'] ?? '',
            'merchant_id' => (int)($data['merchant_id'] ?? 0),
            'merchant_channel_id' => (int)($data['merchant_channel_id'] ?? $legacyChannel['merchant_channel_id'] ?? 0),
            'channel_code' => $data['channel_code'] ?? ($legacyChannel['channel_code'] ?? ''),
            'channel_category' => (int)($data['channel_category'] ?? $legacyChannel['channel_category'] ?? 2),
            'subject' => $data['subject'] ?? '鏀粯璁㈠崟',
            'amount' => $amount,
            'payable_amount' => $payableAmount !== '' ? $payableAmount : $amount,
            'status' => (int)($data['status'] ?? self::STATUS_PENDING),
            'notify_url' => $data['notify_url'] ?? '',
            'return_url' => $data['return_url'] ?? '',
            'client_ip' => $data['client_ip'] ?? '',
            'param' => $data['param'] ?? '',
            'expire_time' => $data['expire_time'] ?? date('Y-m-d H:i:s', time() + self::DEFAULT_EXPIRE_SECONDS),
            'request_payload' => $requestPayload,
            'notify_payload' => is_array($data['notify_payload'] ?? null) ? $data['notify_payload'] : [],
            'payment_address' => self::resolvePendingOrderPaymentAddress($data, $tradeNo),
            'created_at' => $data['created_at'] ?? '',
            'updated_at' => $data['updated_at'] ?? '',
        ]);
    }

    public static function buildOrderCreationEventMeta(array $requestPayload, array $fallback = []): array
    {
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];

        return [
            'scene' => (string)($fallback['scene'] ?? ''),
            'source' => (string)($meta['source_protocol'] ?? $fallback['source'] ?? ''),
            'gateway_source' => (string)($meta['gateway_source'] ?? $fallback['gateway_source'] ?? ''),
            'business' => (string)($meta['business'] ?? $fallback['business'] ?? ''),
            'order_origin' => (string)($meta['order_origin'] ?? $fallback['order_origin'] ?? ''),
            'order_scene' => (string)($meta['order_scene'] ?? $fallback['order_scene'] ?? ''),
        ];
    }

    public static function buildOrderCreationContext(array $data, array $fallback = []): array
    {
        $requestPayload = self::createStoredRequestPayload(array_replace($fallback, $data));

        return [
            'request_payload' => $requestPayload,
            'event_meta' => self::buildOrderCreationEventMeta($requestPayload, $fallback),
        ];
    }

    public static function gatewayMerchantByPid(string $pid): object
    {
        return self::resolveMerchantByPid($pid);
    }

    public static function gatewayMerchantById(int $merchantId): ?object
    {
        return self::resolveMerchantById($merchantId);
    }

    public static function gatewayMerchantOrder(int $merchantId, ?string $tradeNo, ?string $outTradeNo): object
    {
        return self::findMerchantOrder($merchantId, $tradeNo, $outTradeNo);
    }

    public static function gatewayMerchantOrdersPage(int $merchantId, int $page, int $limit, ?int $statusFilter = null): array
    {
        return self::merchantOrdersPage($merchantId, $page, $limit, $statusFilter);
    }

    public static function gatewayMerchantOrdersPageV1(int $merchantId, int $page, int $limit): array
    {
        return self::merchantOrdersPageV1($merchantId, $page, $limit);
    }

    public static function gatewayMerchantOrderCount(int $merchantId): int
    {
        return self::countMerchantOrders($merchantId);
    }

    public static function gatewayMerchantOrderDayCount(int $merchantId, string $mode): int
    {
        return self::countMerchantOrdersToday($merchantId, $mode);
    }

    public static function gatewayMerchantBalance(object $merchant): string
    {
        return self::merchantBalance($merchant);
    }

    public static function gatewayMerchantPid(object $merchant): string
    {
        return (string)($merchant->id ?? $merchant->merchant_id ?? '');
    }

    public static function gatewayMerchantPidById(int $merchantId): string
    {
        return self::merchantPidValue($merchantId);
    }

    public static function gatewayVerifyV2Signature(array $payload, object $merchant, string $signature): bool
    {
        return self::verifyV2Signature($payload, $merchant, $signature);
    }

    public static function gatewayMerchantOrderDayAmount(int $merchantId, string $mode): string
    {
        return self::sumMerchantOrdersByDay($merchantId, $mode);
    }

    public static function createMerchantBusinessOrder(int $merchantId, array $data): object
    {
        $merchant = self::resolveMerchantById($merchantId);
        if (!$merchant) {
            throw new BusinessException('商户不存在或已停用', StatusCode::NOT_FOUND);
        }

        return self::createGatewayOrder($merchant, self::normalizeMerchantOrderInput($data));
    }

    public static function normalizeMerchantOrderInput(array $data): array
    {
        return [
            'type' => (string)($data['type'] ?? $data['channel_code'] ?? $data['method_code'] ?? ''),
            'method' => (string)($data['method'] ?? ''),
            'device' => (string)($data['device'] ?? ''),
            'out_trade_no' => (string)($data['out_trade_no'] ?? ''),
            'notify_url' => (string)($data['notify_url'] ?? ''),
            'return_url' => (string)($data['return_url'] ?? ''),
            'name' => (string)($data['name'] ?? $data['subject'] ?? '支付订单'),
            'money' => (string)($data['money'] ?? $data['amount'] ?? '0'),
            'clientip' => (string)($data['clientip'] ?? $data['client_ip'] ?? ''),
            'param' => (string)($data['param'] ?? ''),
            'source_protocol' => (string)($data['source_protocol'] ?? ''),
            'gateway_source' => (string)($data['gateway_source'] ?? ''),
            'order_origin' => (string)($data['order_origin'] ?? ''),
            'business' => (string)($data['business'] ?? ''),
            'order_scene' => (string)($data['order_scene'] ?? ''),
            'request_payload' => is_array($data['request_payload'] ?? null) ? $data['request_payload'] : [],
            'legacy_channel_snapshot' => is_array($data['legacy_channel_snapshot'] ?? null) ? $data['legacy_channel_snapshot'] : [],
        ] + $data;
    }

    public static function checkoutUrlForTradeNo(string $tradeNo): string
    {
        return self::checkoutUrl($tradeNo);
    }

    public static function gatewayTransferChannel(int $merchantId, string $type): ?array
    {
        return self::resolveTransferChannel($merchantId, $type);
    }

    public static function gatewayTransferMethodOptions(int $merchantId): array
    {
        return self::transferMethodOptions($merchantId);
    }

    public static function gatewaySavedTransferChannel(object $transfer): ?array
    {
        return self::resolveSavedTransferChannel($transfer);
    }

    public static function gatewayApplyTransferPluginResult(object $transfer, array $result, object $merchant): object
    {
        return self::applyTransferPluginResult($transfer, $result, $merchant);
    }

    public static function gatewayTransferCapabilityError(string $type = ''): string
    {
        return self::transferCapabilityError($type);
    }

    private static function recordPluginQueryResult(object $order, array $result): object
    {
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $channel = is_array($result['channel'] ?? null) ? $result['channel'] : [];
        $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];

        $notifyPayload['plugin_query'] = [
            'checked_at' => date('Y-m-d H:i:s'),
            'ok' => (bool)($result['ok'] ?? false),
            'status' => (int)($result['status'] ?? 0),
            'result' => (string)($result['result'] ?? ''),
            'errmsg' => (string)($result['errmsg'] ?? ''),
            'api_trade_no' => (string)($result['api_trade_no'] ?? ''),
            'bill_trade_no' => (string)($result['bill_trade_no'] ?? ''),
            'bill_mch_trade_no' => (string)($result['bill_mch_trade_no'] ?? ''),
            'buyer' => (string)($result['buyer'] ?? ''),
            'paid_at' => (string)($result['paid_at'] ?? ''),
            'amount' => (string)($result['amount'] ?? ''),
            'channel' => $channel,
            'raw' => $raw,
        ];

        return self::saveOrder($order, ['notify_payload' => $notifyPayload]);
    }

    private static function isExpiredForSync(object $order): bool
    {
        $expireTime = trim((string)($order->expire_time ?? ''));
        if ($expireTime === '') {
            return false;
        }

        $timestamp = strtotime($expireTime);
        return $timestamp !== false && $timestamp < time();
    }

    protected static function resolveMerchantByPid(string $pid): object
    {
        $pid = trim($pid);
        if ($pid === '' || !ctype_digit($pid)) {
            throw new BusinessException('商户不存在或已停用', StatusCode::NOT_FOUND);
        }

        $merchantId = (int)$pid;
        $merchant = null;
        if (self::canUseDatabase() && $merchantId > 0) {
            $merchant = Merchant::find($merchantId);
        }

        if ($merchant && (int)$merchant->status === 1) {
            AuthPolicyService::ensureMerchantRealnameAllowed((int)$merchant->id);
            return self::hydrateGatewayMerchantCredentials($merchant, $merchantId);
        }

        if ($merchant) {
            throw new BusinessException('商户不存在或已停用', StatusCode::NOT_FOUND);
        }

        $localMerchant = self::localMerchantByPid($pid);
        if ($localMerchant !== null) {
            if ((int)($localMerchant->status ?? 0) !== 1) {
                throw new BusinessException('商户不存在或已停用', StatusCode::NOT_FOUND);
            }

            AuthPolicyService::ensureMerchantRealnameAllowed((int)$localMerchant->id, (int)($localMerchant->merchant_id ?? $localMerchant->id));
            return self::hydrateGatewayMerchantCredentials($localMerchant, (int)($localMerchant->merchant_id ?? $localMerchant->id));
        }

        throw new BusinessException('商户不存在或已停用', StatusCode::NOT_FOUND);
    }

    protected static function resolveMerchantById(int $merchantId): ?object
    {
        if ($merchantId <= 0) {
            return null;
        }

        if (self::canUseDatabase()) {
            $merchant = Merchant::find($merchantId);
            if ($merchant) {
                return self::hydrateGatewayMerchantCredentials($merchant, $merchantId);
            }
        }

        $localMerchant = AccountService::merchantCredentialById($merchantId);
        if ($localMerchant !== null) {
            return self::hydrateGatewayMerchantCredentials(self::toObject($localMerchant), $merchantId);
        }

        return null;
    }

    protected static function hydrateGatewayMerchantCredentials(object $merchant, int $merchantId): object
    {
        if ($merchantId <= 0) {
            return $merchant;
        }

        $profile = MerchantApiService::credentialProfile($merchantId);
        foreach (['mch_key', 'rsa_public_key', 'rsa_private_key', 'api_sign_mode', 'sign_mode'] as $field) {
            $value = $profile[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $merchant->{$field} = $value;
        }

        return $merchant;
    }

    protected static function findMerchantOrder(int $merchantId, ?string $tradeNo, ?string $outTradeNo): object
    {
        if (!$tradeNo && !$outTradeNo) {
            throw new BusinessException('缂哄皯璁㈠崟鏌ヨ鏉′欢', StatusCode::BAD_REQUEST);
        }

        if (self::canUseDatabase()) {
            $query = Order::where('merchant_id', $merchantId);
            if ($tradeNo) {
                $query->where('trade_no', $tradeNo);
            } else {
                $query->where('out_trade_no', $outTradeNo);
            }

            $order = $query->find();
        } else {
            $order = LocalOrderStore::findByMerchantOrder($merchantId, $tradeNo, $outTradeNo);
        }

        if (!$order) {
            throw new BusinessException('订单不存在', StatusCode::NOT_FOUND);
        }

        return $order;
    }

    protected static function createGatewayOrder(object $merchant, array $data): object
    {
        $outTradeNo = trim((string)($data['out_trade_no'] ?? ''));
        if ($outTradeNo === '') {
            throw new BusinessException('商户订单号不能为空', StatusCode::VALIDATION_ERROR);
        }

        if (self::merchantOutTradeExists((int)$merchant->id, $outTradeNo)) {
            throw new BusinessException('商户订单号已存在', StatusCode::VALIDATION_ERROR);
        }

        $amount = number_format((float)$data['money'], 2, '.', '');
        if ((float)$amount <= 0) {
            throw new BusinessException('金额必须大于 0', StatusCode::VALIDATION_ERROR);
        }

        $prepared = self::prepareMerchantGatewayOrder($merchant, $data);
        $channel = $prepared['channel'];
        $data['legacy_channel_snapshot'] = $prepared['legacy_channel_snapshot'];
        $tradeNo = (string)$prepared['trade_no'];
        $createdAt = (string)$prepared['created_at'];
        $creationContext = self::buildOrderCreationContext($data + [
            'merchant_id' => (int)$merchant->id,
            'merchant_pid' => (string)($merchant->id ?? ''),
            'merchant_name' => (string)($merchant->name ?? ''),
            'channel_code' => (string)($channel['channel_type']->code ?? ''),
            'merchant_channel_id' => (int)($channel['merchant_channel']->id ?? 0),
            'gateway_source' => (string)($data['gateway_source'] ?? 'merchant_openapi'),
            'order_origin' => (string)($data['order_origin'] ?? 'merchant_openapi'),
            'business' => (string)($data['business'] ?? 'merchant_order'),
            'order_scene' => (string)($data['order_scene'] ?? 'merchant_order'),
        ], [
            'scene' => 'merchant_gateway',
            'source' => (string)($data['source_protocol'] ?? ''),
            'gateway_source' => (string)($data['gateway_source'] ?? 'merchant_openapi'),
            'business' => (string)($data['business'] ?? 'merchant_order'),
            'order_origin' => (string)($data['order_origin'] ?? 'merchant_openapi'),
            'order_scene' => (string)($data['order_scene'] ?? 'merchant_order'),
        ]);

        $payload = self::buildPendingOrderPayload([
            'trade_no' => $tradeNo,
            'out_trade_no' => (string)($prepared['out_trade_no'] ?? ''),
            'merchant_id' => (int)$merchant->id,
            'merchant_channel_id' => (int)$channel['merchant_channel']->id,
            'channel_code' => (string)$channel['channel_type']->code,
            'channel_category' => (int)$channel['channel_type']->category,
            'subject' => (string)($data['name'] ?? '支付订单'),
            'amount' => (string)($prepared['amount'] ?? '0.00'),
            'payable_amount' => self::resolvePayableAmountForChannelSnapshot(
                is_array($data['legacy_channel_snapshot'] ?? null) ? $data['legacy_channel_snapshot'] : [],
                (string)($prepared['amount'] ?? '0.00'),
                $tradeNo
            ),
            'status' => self::STATUS_PENDING,
            'notify_url' => (string)($data['notify_url'] ?? ''),
            'return_url' => (string)($data['return_url'] ?? ''),
            'client_ip' => (string)($data['clientip'] ?? ''),
            'param' => (string)($data['param'] ?? ''),
            'expire_time' => date('Y-m-d H:i:s', time() + self::DEFAULT_EXPIRE_SECONDS),
            'request_payload' => $creationContext['request_payload'] ?? [],
            'notify_payload' => [],
            'legacy_channel_snapshot' => is_array($data['legacy_channel_snapshot'] ?? null) ? $data['legacy_channel_snapshot'] : [],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return self::persistCreatedOrder(
            $payload,
            is_array($creationContext['event_meta'] ?? null)
                ? $creationContext['event_meta']
                : self::buildOrderCreationEventMeta(
                    is_array($payload['request_payload'] ?? null) ? $payload['request_payload'] : [],
                    [
                        'scene' => 'merchant_gateway',
                        'source' => (string)($data['source_protocol'] ?? ''),
                        'gateway_source' => (string)($data['gateway_source'] ?? 'merchant_openapi'),
                        'business' => (string)($data['business'] ?? 'merchant_order'),
                        'order_origin' => (string)($data['order_origin'] ?? 'merchant_openapi'),
                        'order_scene' => (string)($data['order_scene'] ?? 'merchant_order'),
                    ]
                )
        );
    }

    protected static function prepareMerchantGatewayOrder(object $merchant, array $data): array
    {
        $outTradeNo = trim((string)($data['out_trade_no'] ?? ''));
        if ($outTradeNo === '') {
            throw new BusinessException('merchant order number required', StatusCode::VALIDATION_ERROR);
        }

        if (self::merchantOutTradeExists((int)$merchant->id, $outTradeNo)) {
            throw new BusinessException('merchant order number already exists', StatusCode::VALIDATION_ERROR);
        }

        $amount = self::formatMoney($data['money'] ?? 0);
        if ((float)$amount <= 0) {
            throw new BusinessException('amount must be greater than 0', StatusCode::VALIDATION_ERROR);
        }

        $channel = self::resolveMerchantChannel((int)$merchant->id, (string)($data['type'] ?? ''), (float)$amount);

        return [
            'out_trade_no' => $outTradeNo,
            'amount' => $amount,
            'trade_no' => self::generateTradeNo(),
            'created_at' => date('Y-m-d H:i:s'),
            'channel' => $channel,
            'legacy_channel_snapshot' => self::legacyChannelSnapshot($channel),
        ];
    }

    protected static function resolveMerchantChannel(int $merchantId, string $requestedType, float $amount = 0.0): array
    {
        $cacheKey = self::channelCacheLookupKey($merchantId, $requestedType, $amount);
        $cached = self::cachedResolvedChannel($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $code = self::normalizeMethodCode($requestedType);
        $rotation = self::merchantRotationConfig($merchantId);
        $rawRecords = self::filterChannelRecordsByAmount($merchantId, self::activeChannelRecords($merchantId, $code, false), $amount);
        $records = self::filterRuntimeAvailableRecords($rawRecords);

        if ($records === []) {
            $runtimeMessage = self::runtimeUnavailableMessageFromRecords($rawRecords);
            if ($runtimeMessage !== '') {
                throw new BusinessException($runtimeMessage, StatusCode::BUSINESS_ERROR);
            }

            throw new BusinessException('当前商户暂无可用支付通道', StatusCode::NOT_FOUND);
        }

        $record = self::pickChannelRecord($records, $rotation, $merchantId, $code);

        if (!self::canUseDatabase()) {
            $resolved = [
                'merchant_channel' => $record['merchant_channel'],
                'channel_type' => $record['channel_type'],
            ];
            self::storeResolvedChannelCache($cacheKey, $resolved);
            return $resolved;
        }

        $merchantChannel = MerchantChannel::find($record->id);
        $channelType = ChannelType::find($record->channel_type_id);

        if (!$merchantChannel || !$channelType) {
            $fallbackRecords = self::activeChannelRecordsFromStore($merchantId, $code);
            foreach ($fallbackRecords as $fallbackRecord) {
                if (self::recordId($fallbackRecord) !== self::recordId($record)) {
                    continue;
                }

                $resolved = [
                    'merchant_channel' => $fallbackRecord['merchant_channel'],
                    'channel_type' => $fallbackRecord['channel_type'],
                ];
                self::storeResolvedChannelCache($cacheKey, $resolved);
                return $resolved;
            }
        }

        $resolved = [
            'merchant_channel' => $merchantChannel,
            'channel_type' => $channelType,
        ];
        self::storeResolvedChannelCache($cacheKey, $resolved);
        return $resolved;
    }

    protected static function activeChannelRecords(int $merchantId, string $code, bool $filterRuntime = true): array
    {
        if (self::canUseDatabase()) {
            $query = MerchantChannel::alias('mc')
                ->join('channel_types ct', 'ct.id = mc.channel_type_id')
                ->where('mc.merchant_id', $merchantId)
                ->where('mc.status', 1)
                ->where('ct.status', 1)
                ->field('mc.*,ct.code,ct.category')
                ->order('mc.id', 'asc');

            if ($code !== '') {
                $acceptedCodes = \app\service\system\PaymentMetaService::acceptedMethodCodes($code);
                if (count($acceptedCodes) === 1) {
                    $query->where('ct.code', $acceptedCodes[0]);
                } else {
                    $query->whereIn('ct.code', $acceptedCodes);
                }
            }

            $pluginStatusMap = [];
            foreach (PluginService::plugins() as $plugin) {
                $pluginCode = self::normalizePluginCode((string)($plugin['code'] ?? ''));
                if ($pluginCode === '') {
                    continue;
                }

                $pluginStatusMap[$pluginCode] = (int)($plugin['status_code'] ?? 0) === 1;
            }

            $items = [];
            foreach ($query->select() as $record) {
                $config = is_array($record->config ?? null) ? $record->config : [];
                $pluginCode = self::normalizePluginCode((string)($config['plugin_code'] ?? ''));
                if (MerchantChannelService::isSeedChannelPayload([
                    'method_code' => (string)($record->code ?? ''),
                    'plugin_code' => $pluginCode,
                    'display_value' => (string)($config['display_value'] ?? $config['payment_address'] ?? $config['address'] ?? $config['qrcode_url'] ?? ''),
                    'remark' => (string)($record->remark ?? ''),
                    'config' => $config,
                ])) {
                    continue;
                }

                if ($pluginCode !== '' && array_key_exists($pluginCode, $pluginStatusMap) && !$pluginStatusMap[$pluginCode]) {
                    continue;
                }

                $items[] = $record;
            }

            if ($items !== []) {
                return $filterRuntime ? self::filterRuntimeAvailableRecords($items) : $items;
            }
        }

        return self::activeChannelRecordsFromStore($merchantId, $code, $filterRuntime);
    }

    protected static function filterChannelRecordsByAmount(int $merchantId, array $records, float $amount): array
    {
        if ($amount <= 0 || $records === []) {
            return $records;
        }

        return array_values(array_filter(
            $records,
            static fn(mixed $record): bool => self::channelWithinBusinessLimits($merchantId, $record, $amount)
        ));
    }

    protected static function channelWithinBusinessLimits(int $merchantId, mixed $record, float $amount): bool
    {
        $channel = is_array($record) ? ($record['merchant_channel'] ?? null) : $record;
        $config = is_array($channel->config ?? null) ? $channel->config : [];
        $limits = self::channelLimitPayload($channel, $config);

        $min = (float)$limits['single_min_amount'];
        $max = (float)$limits['single_max_amount'];
        if ($min > 0 && $amount < $min) {
            return false;
        }
        if ($max > 0 && $amount > $max) {
            return false;
        }

        $dailyLimit = (float)$limits['daily_limit'];
        if ($dailyLimit > 0 && (self::channelTodayAmount($merchantId, (int)($channel->id ?? 0)) + $amount) > $dailyLimit) {
            return false;
        }

        $dailyCount = (int)$limits['daily_count_limit'];
        if ($dailyCount > 0 && self::channelTodayCount($merchantId, (int)($channel->id ?? 0)) >= $dailyCount) {
            return false;
        }

        return true;
    }

    protected static function channelLimitPayload(?object $channel, array $config): array
    {
        $dailyLimit = self::formatMoney($channel->daily_limit ?? $config['daily_limit'] ?? '0');
        return [
            'daily_limit' => $dailyLimit,
            'daily_count_limit' => max(0, (int)($config['daily_count_limit'] ?? $config['daily_count'] ?? 0)),
            'single_min_amount' => self::formatMoney($config['single_min_amount'] ?? $config['min_amount'] ?? '0'),
            'single_max_amount' => self::formatMoney($config['single_max_amount'] ?? $config['max_amount'] ?? '0'),
        ];
    }

    protected static function channelTodayAmount(int $merchantId, int $channelId): float
    {
        $target = date('Y-m-d');
        $amount = 0.0;

        foreach (self::businessOrdersForLimit($merchantId) as $order) {
            if ((int)($order->merchant_channel_id ?? 0) !== $channelId) {
                continue;
            }
            if (!str_starts_with((string)($order->created_at ?? ''), $target)) {
                continue;
            }
            if ((int)($order->status ?? self::STATUS_PENDING) === self::STATUS_FAILED) {
                continue;
            }

            $amount += (float)($order->amount ?? 0);
        }

        return $amount;
    }

    protected static function channelTodayCount(int $merchantId, int $channelId): int
    {
        $target = date('Y-m-d');
        $count = 0;

        foreach (self::businessOrdersForLimit($merchantId) as $order) {
            if ((int)($order->merchant_channel_id ?? 0) !== $channelId) {
                continue;
            }
            if (!str_starts_with((string)($order->created_at ?? ''), $target)) {
                continue;
            }
            if ((int)($order->status ?? self::STATUS_PENDING) === self::STATUS_FAILED) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    protected static function businessOrdersForLimit(int $merchantId): array
    {
        if (self::canUseDatabase()) {
            return array_values(array_filter(
                Order::where('merchant_id', $merchantId)->select()->all(),
                static fn(object $order): bool => LocalOrderStore::isBusinessOrder($order)
            ));
        }

        return LocalOrderStore::businessOrdersByMerchant($merchantId);
    }

    protected static function activeChannelRecordsFromStore(int $merchantId, string $code, bool $filterRuntime = true): array
    {
        $channelPayload = MerchantChannelService::all($merchantId);
        $items = $channelPayload['items'] ?? [];
        $plugins = $channelPayload['plugins'] ?? [];
        $runtimePluginSettings = \app\service\system\PluginRuntimeService::storedSettings();
        $matched = [];
        foreach ($items as $item) {
            if ((int)($item['status_code'] ?? 0) !== 1) {
                continue;
            }

            $pluginCode = trim((string)($item['plugin_code'] ?? ''));
            $pluginEnabled = true;
            foreach ((array)$plugins as $pluginItem) {
                if ((string)($pluginItem['code'] ?? '') === $pluginCode) {
                    $pluginEnabled = (int)($pluginItem['status_code'] ?? 0) === 1;
                    break;
                }
            }
            if (
                !$pluginEnabled
                && $pluginCode !== ''
                && is_array($runtimePluginSettings)
                && is_array($runtimePluginSettings[$pluginCode] ?? null)
            ) {
                $runtimeEnabled = $runtimePluginSettings[$pluginCode]['enabled'] ?? false;
                $pluginEnabled = self::toBool($runtimeEnabled);
            }
            if (!$pluginEnabled) {
                continue;
            }

            $normalizedCode = self::normalizeMethodCode((string)($item['method_code'] ?? $item['code'] ?? $item['channel'] ?? ''));
            if ($code !== '' && $normalizedCode !== $code) {
                continue;
            }

            $config = is_array($item['config'] ?? null)
                ? $item['config']
                : self::channelConfigByCode($normalizedCode, (string)($item['address'] ?? ''));
            $config['daily_limit'] = self::formatMoney($item['daily_limit'] ?? $config['daily_limit'] ?? '0');
            $config['daily_count_limit'] = max(0, (int)($item['daily_count_limit'] ?? $config['daily_count_limit'] ?? 0));
            $config['single_min_amount'] = self::formatMoney($item['single_min_amount'] ?? $config['single_min_amount'] ?? '0');
            $config['single_max_amount'] = self::formatMoney($item['single_max_amount'] ?? $config['single_max_amount'] ?? '0');

            $merchantChannel = self::toObject([
                'id' => (int)($item['id'] ?? 0),
                'merchant_id' => $merchantId,
                'channel_type_id' => (int)($item['id'] ?? 0),
                'config' => $config,
                'rate' => self::normalizeRateValue($item['rate'] ?? '0'),
                'daily_limit' => self::normalizeRateValue($item['daily_limit'] ?? $config['daily_limit'] ?? '0'),
                'status' => 1,
                'remark' => (string)($item['remark'] ?? ''),
            ]);

            $channelType = self::toObject([
                'id' => (int)($item['id'] ?? 0),
                'code' => $normalizedCode,
                'name' => (string)($item['channel'] ?? strtoupper($normalizedCode)),
                'category' => self::isChainCode($normalizedCode) ? 1 : 2,
                'status' => 1,
            ]);

            $matched[] = [
                'id' => $merchantChannel->id,
                'merchant_channel' => $merchantChannel,
                'channel_type' => $channelType,
            ];
        }

        return $filterRuntime ? self::filterRuntimeAvailableRecords($matched) : $matched;
    }

    protected static function localMerchantByPid(string $pid): ?object
    {
        $merchant = AccountService::merchantCredentialByPid($pid);
        return $merchant !== null ? self::toObject($merchant) : null;
    }

    protected static function checkoutUrl(string $tradeNo): string
    {
        $base = ConfigService::gatewayBaseUrl();
        return $base . '/pay/checkout/' . ltrim($tradeNo, '/');
    }

    protected static function generateTradeNo(): string
    {
        return date('YmdHis') . random_int(100000, 999999);
    }

    protected static function legacyChannelSnapshot(array $channel): array
    {
        return LegacyChannelFormatter::snapshotFromResolvedChannel($channel);
    }

    protected static function buildStoredRequestPayload(array $data): array
    {
        $payload = is_array($data['request_payload'] ?? null) ? $data['request_payload'] : [];
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];

        foreach ([
            'source_protocol' => (string)($data['source_protocol'] ?? ''),
            'method' => (string)($data['method'] ?? ''),
            'device' => (string)($data['device'] ?? ''),
            'business' => (string)($data['business'] ?? ''),
            'gateway_source' => (string)($data['gateway_source'] ?? ''),
            'order_origin' => (string)($data['order_origin'] ?? ''),
            'order_scene' => (string)($data['order_scene'] ?? ''),
            'requested_method' => (string)($data['type'] ?? $data['channel_code'] ?? $data['method_code'] ?? ''),
            'merchant_name' => (string)($data['merchant_name'] ?? ''),
            'merchant_pid' => (string)($data['merchant_pid'] ?? ''),
        ] as $key => $value) {
            if ($value !== '') {
                $meta[$key] = $value;
            }
        }

        foreach ([
            'merchant_id' => (int)($data['merchant_id'] ?? 0),
            'merchant_channel_id' => (int)($data['merchant_channel_id'] ?? 0),
            'carrier_merchant_id' => (int)($data['carrier_merchant_id'] ?? 0),
        ] as $key => $value) {
            if ($value > 0) {
                $meta[$key] = $value;
            }
        }

        if ($meta !== []) {
            $payload['_meta'] = $meta;
        }

        if (is_array($data['legacy_channel_snapshot'] ?? null)) {
            $payload['_legacy_channel'] = $data['legacy_channel_snapshot'];
        }

        return $payload;
    }

    public static function flushOrderLookupCache(string $tradeNo = ''): void
    {
        if ($tradeNo === '') {
            self::$orderLookupCache = [];
            return;
        }

        unset(self::$orderLookupCache[$tradeNo]);
    }

    public static function flushMerchantChannelCache(int $merchantId = 0): void
    {
        $records = ConfigService::get(self::CHANNEL_CACHE_KEY, []);
        if (!is_array($records) || $records === []) {
            return;
        }

        if ($merchantId <= 0) {
            ConfigService::save([self::CHANNEL_CACHE_KEY => []]);
            return;
        }

        $prefix = $merchantId . ':';
        foreach (array_keys($records) as $key) {
            if (str_starts_with((string)$key, $prefix)) {
                unset($records[$key]);
            }
        }

        ConfigService::save([self::CHANNEL_CACHE_KEY => $records]);
    }

    private static function channelCacheLookupKey(int $merchantId, string $requestedType, float $amount): string
    {
        $normalizedType = self::normalizeMethodCode($requestedType);
        return implode(':', [
            (string)$merchantId,
            $normalizedType !== '' ? $normalizedType : 'all',
            self::formatMoney($amount > 0 ? $amount : 0),
        ]);
    }

    private static function cachedResolvedChannel(string $cacheKey): ?array
    {
        $records = ConfigService::get(self::CHANNEL_CACHE_KEY, []);
        if (!is_array($records) || !is_array($records[$cacheKey] ?? null)) {
            return null;
        }

        $entry = $records[$cacheKey];
        $cachedAt = (int)($entry['cached_at'] ?? 0);
        if ($cachedAt <= 0 || (time() - $cachedAt) > self::CHANNEL_CACHE_TTL) {
            return null;
        }

        $channel = is_array($entry['merchant_channel'] ?? null) ? $entry['merchant_channel'] : [];
        $type = is_array($entry['channel_type'] ?? null) ? $entry['channel_type'] : [];
        if ($channel === [] || $type === []) {
            return null;
        }

        return [
            'merchant_channel' => self::toObject($channel),
            'channel_type' => self::toObject($type),
        ];
    }

    private static function storeResolvedChannelCache(string $cacheKey, array $resolved): void
    {
        $merchantChannel = $resolved['merchant_channel'] ?? null;
        $channelType = $resolved['channel_type'] ?? null;
        if (!is_object($merchantChannel) || !is_object($channelType)) {
            return;
        }

        $records = ConfigService::get(self::CHANNEL_CACHE_KEY, []);
        if (!is_array($records)) {
            $records = [];
        }

        $records[$cacheKey] = [
            'cached_at' => time(),
            'merchant_channel' => self::mixedToArray($merchantChannel),
            'channel_type' => self::mixedToArray($channelType),
        ];

        ConfigService::save([self::CHANNEL_CACHE_KEY => $records]);
    }

    private static function mixedToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $array = $value->toArray();
            if (is_array($array)) {
                return $array;
            }
        }

        if (is_object($value)) {
            return get_object_vars($value);
        }

        return [];
    }

    private static function cachedOrderByTradeNo(string $tradeNo): ?object
    {
        $entry = self::$orderLookupCache[$tradeNo] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $cachedAt = (int)($entry['cached_at'] ?? 0);
        if ($cachedAt <= 0 || (time() - $cachedAt) > self::ORDER_LOOKUP_CACHE_TTL) {
            unset(self::$orderLookupCache[$tradeNo]);
            return null;
        }

        $order = $entry['order'] ?? null;
        return is_object($order) ? $order : null;
    }

    private static function storeOrderLookupCache(object $order): void
    {
        $tradeNo = trim((string)($order->trade_no ?? ''));
        if ($tradeNo === '') {
            return;
        }

        if (count(self::$orderLookupCache) >= 200) {
            uasort(self::$orderLookupCache, static function (array $left, array $right): int {
                return (int)($left['cached_at'] ?? 0) <=> (int)($right['cached_at'] ?? 0);
            });

            while (count(self::$orderLookupCache) >= 200) {
                array_shift(self::$orderLookupCache);
            }
        }

        self::$orderLookupCache[$tradeNo] = [
            'order' => $order,
            'cached_at' => time(),
        ];
    }

    protected static function merchantRotationConfig(int $merchantId): array
    {
        $meta = ConfigService::get('merchant_channel_meta', []);
        $rotation = is_array($meta[$merchantId]['rotation'] ?? null) ? $meta[$merchantId]['rotation'] : [];

        return [
            'enabled' => self::toBool($rotation['enabled'] ?? false),
            'strategy' => self::normalizeRotationStrategy((string)($rotation['strategy'] ?? 'priority')),
            'fallback_channel' => self::normalizeMethodCode((string)($rotation['fallback_channel'] ?? '')),
            'pools' => self::normalizeRotationPools((array)($rotation['pools'] ?? [])),
        ];
    }

    protected static function pickChannelRecord(array $records, array $rotation, int $merchantId, string $key): mixed
    {
        $poolSelected = self::pickChannelRecordFromPools($records, $rotation, $merchantId, $key);
        if ($poolSelected !== null) {
            return $poolSelected;
        }

        if (count($records) === 1 || !self::toBool($rotation['enabled'] ?? false)) {
            return $records[0];
        }

        $strategy = strtolower(trim((string)($rotation['strategy'] ?? '')));
        if (!str_contains($strategy, 'round') && !str_contains($strategy, 'rotate')) {
            return $records[0];
        }

        $runtime = self::merchantRuntimeMap();
        $stateKey = $merchantId . ':' . ($key !== '' ? $key : 'all');
        $lastChannelId = (int)($runtime[$stateKey]['last_channel_id'] ?? 0);
        $selected = $records[0];

        foreach ($records as $index => $record) {
            $recordId = self::recordId($record);
            if ($recordId === $lastChannelId) {
                $selected = $records[($index + 1) % count($records)];
                break;
            }
        }

        $runtime[$stateKey] = [
            'last_channel_id' => self::recordId($selected),
            'last_used_at' => date('Y-m-d H:i:s'),
        ];
        ConfigService::save(['merchant_channel_runtime' => $runtime]);

        return $selected;
    }

    protected static function pickChannelRecordFromPools(array $records, array $rotation, int $merchantId, string $key): mixed
    {
        $pools = self::matchingRotationPools($records, $rotation, $key);
        if ($pools === []) {
            return null;
        }

        $selectedPool = count($pools) === 1
            ? $pools[0]
            : $pools[random_int(0, count($pools) - 1)];
        $poolRecords = self::recordsForPool($records, $selectedPool);

        if ($poolRecords === []) {
            return null;
        }

        if (count($poolRecords) === 1) {
            return $poolRecords[0];
        }

        $strategy = self::normalizeRotationStrategy((string)($selectedPool['strategy'] ?? 'sequential'));
        if ($strategy === 'weighted_random') {
            return self::pickWeightedRotationRecord($poolRecords, (array)($selectedPool['items'] ?? []));
        }

        $runtime = self::merchantRuntimeMap();
        $stateKey = $merchantId . ':pool:' . (int)($selectedPool['id'] ?? 0) . ':' . ($key !== '' ? $key : 'all');
        $lastChannelId = (int)($runtime[$stateKey]['last_channel_id'] ?? 0);
        $selected = $poolRecords[0];

        foreach ($poolRecords as $index => $record) {
            if (self::recordId($record) === $lastChannelId) {
                $selected = $poolRecords[($index + 1) % count($poolRecords)];
                break;
            }
        }

        $runtime[$stateKey] = [
            'last_channel_id' => self::recordId($selected),
            'last_used_at' => date('Y-m-d H:i:s'),
        ];
        ConfigService::save(['merchant_channel_runtime' => $runtime]);

        return $selected;
    }

    protected static function matchingRotationPools(array $records, array $rotation, string $key): array
    {
        $matched = [];
        foreach ((array)($rotation['pools'] ?? []) as $pool) {
            if (!is_array($pool) || (int)($pool['status_code'] ?? 0) !== 1) {
                continue;
            }

            $methodCode = self::normalizeMethodCode((string)($pool['method_code'] ?? ''));
            if ($key !== '' && $methodCode !== $key) {
                continue;
            }

            $poolRecords = self::recordsForPool($records, $pool);
            if ($poolRecords === []) {
                continue;
            }

            $matched[] = $pool;
        }

        return $matched;
    }

    protected static function recordsForPool(array $records, array $pool): array
    {
        $recordMap = [];
        foreach ($records as $record) {
            $recordMap[self::recordId($record)] = $record;
        }

        $selected = [];
        foreach ((array)($pool['items'] ?? []) as $item) {
            $channelId = (int)($item['channel_id'] ?? 0);
            if ($channelId <= 0 || !array_key_exists($channelId, $recordMap)) {
                continue;
            }
            $selected[] = $recordMap[$channelId];
        }

        return $selected;
    }

    protected static function pickWeightedRotationRecord(array $records, array $items): mixed
    {
        $weights = [];
        $total = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $channelId = (int)($item['channel_id'] ?? 0);
            $weight = max(1, (int)($item['weight'] ?? 50));
            $weights[$channelId] = $weight;
            $total += $weight;
        }

        if ($total <= 0) {
            return $records[0];
        }

        $cursor = random_int(1, $total);
        foreach ($records as $record) {
            $recordId = self::recordId($record);
            $cursor -= (int)($weights[$recordId] ?? 0);
            if ($cursor <= 0) {
                return $record;
            }
        }

        return $records[0];
    }

    protected static function normalizeRotationPools(array $pools): array
    {
        $normalized = [];
        foreach ($pools as $pool) {
            if (!is_array($pool)) {
                continue;
            }

            $methodCode = self::normalizeMethodCode((string)($pool['method_code'] ?? ''));
            if ($methodCode === '') {
                continue;
            }

            $normalized[] = [
                'id' => (int)($pool['id'] ?? 0),
                'method_code' => $methodCode,
                'strategy' => self::normalizeRotationStrategy((string)($pool['strategy'] ?? 'sequential')),
                'status_code' => (int)($pool['status_code'] ?? 0) === 1 ? 1 : 0,
                'items' => self::normalizeRotationPoolItems((array)($pool['items'] ?? [])),
            ];
        }

        return $normalized;
    }

    protected static function normalizeRotationPoolItems(array $items): array
    {
        $normalized = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $channelId = (int)($item['channel_id'] ?? $item['id'] ?? 0);
            if ($channelId <= 0 || isset($seen[$channelId])) {
                continue;
            }

            $seen[$channelId] = true;
            $normalized[] = [
                'channel_id' => $channelId,
                'weight' => max(1, (int)($item['weight'] ?? 50)),
            ];
        }

        return $normalized;
    }

    protected static function normalizeRotationStrategy(string $strategy): string
    {
        $normalized = strtolower(trim($strategy));

        return match ($normalized) {
            'weighted', 'weighted_random', 'weight_random', 'random' => 'weighted_random',
            'round_robin', 'round-robin', 'round', 'rotate',
            'priority', 'sequence', 'sequential', 'order' => 'sequential',
            default => 'sequential',
        };
    }

    protected static function merchantRuntimeMap(): array
    {
        $runtime = ConfigService::get('merchant_channel_runtime', []);
        return is_array($runtime) ? $runtime : [];
    }

    protected static function calculatePlatformFee(object $order): string
    {
        $rate = 0.0;

        if (self::canUseDatabase()) {
            $merchantChannel = MerchantChannel::find($order->merchant_channel_id);
            if ($merchantChannel && $merchantChannel->rate !== null) {
                $rate = (float)$merchantChannel->rate;
            } else {
                $merchant = Merchant::find($order->merchant_id);
                if ($merchant) {
                    $rate = (float)$merchant->platform_rate;
                }
            }
        } else {
            $merchant = self::resolveMerchantById((int)$order->merchant_id);
            if ($merchant) {
                $rate = (float)$merchant->platform_rate;
            }
        }

        $fee = round((float)$order->amount * ($rate / 100), 8);
        return number_format($fee, 8, '.', '');
    }

    protected static function merchantBalance(object $merchant): string
    {
        $merchantId = (int)($merchant->id ?? 0);
        if (LocalFundStore::hasBusinessFlowsForMerchant($merchantId)) {
            return LocalFundStore::balanceForMerchant($merchantId)['available'] ?? '0.00';
        }

        if (self::canUseDatabase()) {
            $balance = MerchantBalance::where('merchant_id', $merchantId)->find();
            return self::formatMoney($balance?->balance ?? '0');
        }

        return LocalFundStore::balanceForMerchant($merchantId)['available'] ?? '0.00';
    }

    protected static function countMerchantOrders(int $merchantId): int
    {
        if (self::canUseDatabase()) {
            return count(array_filter(
                Order::where('merchant_id', $merchantId)->select()->all(),
                static fn(object $order): bool => LocalOrderStore::isBusinessOrder($order)
            ));
        }

        return LocalOrderStore::countBusinessOrdersByMerchant($merchantId);
    }

    protected static function countMerchantOrdersToday(int $merchantId, string $mode): int
    {
        if (self::canUseDatabase()) {
            $orders = Order::where('merchant_id', $merchantId)->select()->all();
            $target = $mode === 'yesterday' ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
            return count(array_filter($orders, static function (object $order) use ($target): bool {
                return LocalOrderStore::isBusinessOrder($order)
                    && str_starts_with((string)($order->created_at ?? ''), $target);
            }));
        }

        return LocalOrderStore::countBusinessTodayByMerchant($merchantId, $mode);
    }

    protected static function merchantOutTradeExists(int $merchantId, string $outTradeNo): bool
    {
        if (self::canUseDatabase()) {
            return Order::where('merchant_id', $merchantId)->where('out_trade_no', $outTradeNo)->find() !== null;
        }

        return LocalOrderStore::merchantOutTradeExists($merchantId, $outTradeNo);
    }

    protected static function resolvePaymentAddress(array $channel): string
    {
        $config = is_array($channel['merchant_channel']->config ?? null) ? $channel['merchant_channel']->config : [];
        $paymentAddress = self::channelSourceValue($config);
        if ($paymentAddress === '') {
            $paymentAddress = self::checkoutUrl((string)($channel['trade_no'] ?? self::generateTradeNo()));
        }

        return $paymentAddress;
    }

    protected static function resolvePendingOrderPaymentAddress(array $data, string $tradeNo = ''): string
    {
        $paymentAddress = trim((string)($data['payment_address'] ?? ''));
        if ($paymentAddress !== '') {
            return $paymentAddress;
        }

        $legacyChannel = is_array($data['legacy_channel_snapshot'] ?? null) ? $data['legacy_channel_snapshot'] : [];
        $legacyConfig = is_array($legacyChannel['config'] ?? null) ? $legacyChannel['config'] : [];
        $paymentAddress = trim((string)($legacyConfig['payment_address'] ?? $legacyConfig['display_value'] ?? ''));
        if ($paymentAddress !== '') {
            return $paymentAddress;
        }

        $resolvedTradeNo = trim($tradeNo);
        if ($resolvedTradeNo === '') {
            $resolvedTradeNo = trim((string)($data['trade_no'] ?? ''));
        }
        if ($resolvedTradeNo === '') {
            $resolvedTradeNo = self::generateTradeNo();
        }

        return self::checkoutUrl($resolvedTradeNo);
    }

    public static function resolvePayableAmountForChannelSnapshot(array $snapshot, string $amount, string $excludeTradeNo = ''): string
    {
        $normalizedAmount = self::formatMoney($amount);
        if (!self::channelSnapshotRequiresUniquePayableAmount($snapshot)) {
            return $normalizedAmount;
        }

        $merchantChannelId = (int)($snapshot['merchant_channel_id'] ?? 0);
        if ($merchantChannelId <= 0) {
            return $normalizedAmount;
        }

        $usedAmounts = self::pendingPayableAmountsForChannel($merchantChannelId, $excludeTradeNo);
        if (!in_array($normalizedAmount, $usedAmounts, true)) {
            return $normalizedAmount;
        }

        $candidates = [];
        for ($step = 1; $step <= 10; $step++) {
            $candidate = self::formatMoney((float)$normalizedAmount + ($step / 100));
            if (!in_array($candidate, $usedAmounts, true)) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return $normalizedAmount;
        }

        shuffle($candidates);
        return (string)($candidates[0] ?? $normalizedAmount);
    }

    protected static function channelSourceValue(array $payload, int $depth = 0): string
    {
        return QrCodeService::extractSourceValue($payload);
    }

    protected static function normalizeDateTime(string $value): string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;
        return date('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());
    }

    protected static function formatMoney(mixed $amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }

    protected static function resolveGatewayPayableAmount(array $channel, string $amount): string
    {
        return self::resolvePayableAmountForChannelSnapshot(self::legacyChannelSnapshot($channel), $amount);
    }

    protected static function channelSnapshotRequiresUniquePayableAmount(array $snapshot): bool
    {
        $config = is_array($snapshot['config'] ?? null) ? $snapshot['config'] : [];
        $pluginCode = self::normalizePluginCode((string)($config['plugin_code'] ?? $snapshot['plugin_code'] ?? ''));
        if ($pluginCode === 'alipay-ck') {
            return true;
        }

        return in_array($pluginCode, ['wechat-qrcode', 'alipay-qrcode', 'qqpay-qrcode'], true);
    }

    protected static function pendingPayableAmountsForChannel(int $merchantChannelId, string $excludeTradeNo = ''): array
    {
        if ($merchantChannelId <= 0) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $values = [];

        if (self::canUseDatabase()) {
            $query = Order::where('merchant_channel_id', $merchantChannelId)
                ->where('status', self::STATUS_PENDING)
                ->where('expire_time', '>=', $now);

            if ($excludeTradeNo !== '') {
                $query->where('trade_no', '<>', $excludeTradeNo);
            }

            foreach ($query->field(['payable_amount'])->select() as $item) {
                $value = self::formatMoney($item->payable_amount ?? 0);
                if ($value !== '') {
                    $values[$value] = true;
                }
            }

            return array_keys($values);
        }

        foreach (LocalOrderStore::allOrders() as $order) {
            if ((int)($order->merchant_channel_id ?? 0) !== $merchantChannelId) {
                continue;
            }
            if ((int)($order->status ?? -1) !== self::STATUS_PENDING) {
                continue;
            }
            if ($excludeTradeNo !== '' && (string)($order->trade_no ?? '') === $excludeTradeNo) {
                continue;
            }
            if ((string)($order->expire_time ?? '') < $now) {
                continue;
            }

            $value = self::formatMoney($order->payable_amount ?? 0);
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }

    protected static function normalizeMethodCode(string $code): string
    {
        return PluginService::normalizeMethodCode($code);
    }

    protected static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    protected static function verifyV2Signature(array $payload, object $merchant, string $signature): bool
    {
        $merchantPublicKey = trim((string)($merchant->rsa_public_key ?? ''));
        return $merchantPublicKey !== ''
            && SignService::verifyRsa($payload, $merchantPublicKey, $signature);
    }

    protected static function canUseDatabase(): bool
    {
        static $usable = null;
        if ($usable !== null) {
            return $usable;
        }

        if (!database_available()) {
            $usable = false;
            return $usable;
        }

        try {
            Order::where('id', '>', 0)->limit(1)->find();
            Merchant::where('id', '>', 0)->limit(1)->find();
            $usable = true;
        } catch (Throwable) {
            $usable = false;
        }

        return $usable;
    }

    private static function recordId(mixed $record): int
    {
        if (is_array($record)) {
            return (int)($record['id'] ?? 0);
        }

        return (int)($record->id ?? 0);
    }

    private static function channelConfigByCode(string $code, string $address): array
    {
        if (self::isChainCode($code)) {
            return [
                'method_code' => $code,
                'payment_address' => $address,
                'display_value' => $address,
                'address' => $address,
                'plugin_config' => [],
            ];
        }

        return [
            'method_code' => $code,
            'payment_address' => $address,
            'display_value' => $address,
            'qrcode_url' => $address,
            'plugin_config' => [],
        ];
    }

    private static function normalizeRateValue(mixed $rate): float
    {
        return (float)str_replace('%', '', trim((string)$rate));
    }

    private static function normalizePluginCode(string $code): string
    {
        $normalized = strtolower(trim($code));
        if ($normalized === '') {
            return '';
        }

        return match ($normalized) {
            'wechat_qrcode' => 'wechat-qrcode',
            'wechat_app' => 'wechat-app',
            'alipay_qrcode' => 'alipay-qrcode',
            'alipay_app' => 'alipay-app',
            'alipay_ck' => 'alipay-ck',
            'qq_qrcode' => 'qqpay-qrcode',
            'qq_app' => 'qq-app',
            'usdt_trc20' => 'trc20',
            default => $normalized,
        };
    }

    private static function isChainCode(string $code): bool
    {
        return in_array(
            self::normalizeMethodCode($code),
            ['usdttrc20', 'erc20', 'bsc', 'usdtpolygon', 'avaxc', 'usdtaptos', 'trx'],
            true
        );
    }

    private static function filterRuntimeAvailableRecords(array $records): array
    {
        return array_values(array_filter(
            $records,
            static fn(mixed $record): bool => self::recordRuntimeAvailable($record)
        ));
    }

    private static function recordRuntimeAvailable(mixed $record): bool
    {
        $runtimeState = self::recordRuntimeState($record);
        if (!($runtimeState['runtime_requires_online'] ?? false)) {
            return true;
        }

        return (bool)($runtimeState['runtime_online'] ?? false);
    }

    private static function runtimeUnavailableMessageFromRecords(array $records): string
    {
        foreach ($records as $record) {
            $runtimeState = self::recordRuntimeState($record);
            if (($runtimeState['runtime_requires_online'] ?? false) && !($runtimeState['runtime_online'] ?? false)) {
                return MerchantChannelService::runtimeUnavailableMessageForState($runtimeState);
            }
        }

        return '';
    }

    private static function recordRuntimeState(mixed $record): array
    {
        $channel = is_array($record) ? ($record['merchant_channel'] ?? null) : $record;
        if (!is_object($channel)) {
            return MerchantChannelService::runtimeStateForPayload([]);
        }

        $config = is_array($channel->config ?? null) ? $channel->config : [];
        return MerchantChannelService::runtimeStateForPayload([
            'plugin_code' => (string)($config['plugin_code'] ?? ''),
            'plugin_kind' => (string)($config['plugin_kind'] ?? ''),
            'plugin_config' => is_array($config['plugin_config'] ?? null) ? $config['plugin_config'] : [],
            'config' => $config,
            'status_code' => (int)($channel->status ?? 1),
        ]);
    }

    private static function toObject(array $row): object
    {
        $record = new \stdClass();
        foreach ($row as $key => $value) {
            $record->$key = $value;
        }

        return $record;
    }

    public static function refundForV2Enhanced(array $payload): array
    {
        return GatewayCompatService::refundForV2($payload);
    }

    public static function refundQueryForV2(array $payload): array
    {
        return GatewayCompatService::refundQueryForV2($payload);
    }

    public static function merchantInfoForV2(array $payload): array
    {
        return GatewayCompatService::merchantInfoForV2($payload);
    }

    public static function merchantOrdersForV2(array $payload): array
    {
        return GatewayCompatService::merchantOrdersForV2($payload);
    }

    public static function transferSubmitForV2(array $payload): array
    {
        return GatewayCompatService::transferSubmitForV2($payload);
    }

    public static function transferQueryForV2(array $payload): array
    {
        return GatewayCompatService::transferQueryForV2($payload);
    }

    public static function transferBalanceForV2(array $payload): array
    {
        return GatewayCompatService::transferBalanceForV2($payload);
    }

    public static function errorResponseForV2(Throwable $exception): array
    {
        return GatewayCompatService::errorResponseForV2($exception);
    }

    private static function signV2Response(array $response): array
    {
        $response['timestamp'] = (string)($response['timestamp'] ?? time());
        $response['sign_type'] = 'RSA';
        $response['sign'] = SignService::rsaSign($response, ConfigService::platformPrivateKey());
        return $response;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $string = trim((string)$value);
        return $string === '' ? null : $string;
    }

    private static function generateRefundNo(): string
    {
        return 'RF' . date('YmdHis') . random_int(100000, 999999);
    }

    private static function generateTransferNo(): string
    {
        return 'TR' . date('YmdHis') . random_int(100000, 999999);
    }

    private static function transferCapabilityError(string $type = ''): string
    {
        $suffix = trim($type) !== '' ? '（方式：' . trim($type) . '）' : '';
        return '当前系统未声明真实代付通道能力，代付单未执行' . $suffix;
    }

    private static function transferMethodOptions(int $merchantId): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        $options = [];
        foreach (self::activeChannelRecords($merchantId, '') as $record) {
            $channel = self::legacyTransferChannelFromRecord($record, $merchantId, '');
            if ($channel === []) {
                continue;
            }

            $pluginCode = self::normalizePluginCode((string)($channel['plugin_code'] ?? ''));
            if ($pluginCode === '') {
                continue;
            }

            $capability = PluginExecutorService::capability($pluginCode);
            if (!($capability['transfer'] ?? false)) {
                continue;
            }

            $recordId = self::recordId($record);
            $channelName = trim((string)($channel['name'] ?? $channel['showname'] ?? ''));
            $methodCode = self::normalizeMethodCode((string)($channel['channel_code'] ?? $channel['type'] ?? ''));

            $declaredTransferMethods = self::pluginTransferMethods($pluginCode);
            if ($declaredTransferMethods === [] && $methodCode !== '') {
                self::mergeTransferMethodOption($options, $methodCode, $pluginCode, $channelName, $recordId);
            }

            foreach ($declaredTransferMethods as $transferMethodCode) {
                self::mergeTransferMethodOption($options, $transferMethodCode, $pluginCode, $channelName, $recordId);
            }
        }

        $items = [];
        foreach ($options as $methodCode => $item) {
            $channelNames = array_values(array_filter(array_map('strval', array_keys($item['channel_names'] ?? []))));
            $pluginCodes = array_values(array_filter(array_map('strval', array_keys($item['plugin_codes'] ?? []))));
            $items[] = [
                'value' => $methodCode,
                'code' => $methodCode,
                'label' => PaymentMetaService::friendlyMethodName($methodCode),
                'channel_count' => count($channelNames),
                'plugin_count' => count($pluginCodes),
                'channel_names' => $channelNames,
                'plugin_codes' => $pluginCodes,
                'mode' => 'plugin_transfer',
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return [(string)($left['label'] ?? ''), (string)($left['value'] ?? '')]
                <=> [(string)($right['label'] ?? ''), (string)($right['value'] ?? '')];
        });

        return $items;
    }

    private static function resolveTransferChannel(int $merchantId, string $type): ?array
    {
        $normalizedType = self::normalizeMethodCode($type);
        $seen = [];

        foreach (self::activeChannelRecords($merchantId, $normalizedType) as $record) {
            $recordId = self::recordId($record);
            if (isset($seen[$recordId])) {
                continue;
            }
            $seen[$recordId] = true;

            $channel = self::legacyTransferChannelFromRecord($record, $merchantId, $normalizedType);
            if (self::channelSupportsTransferType($channel, $normalizedType)) {
                return $channel;
            }
        }

        foreach (self::activeChannelRecords($merchantId, '') as $record) {
            $recordId = self::recordId($record);
            if (isset($seen[$recordId])) {
                continue;
            }
            $seen[$recordId] = true;

            $channel = self::legacyTransferChannelFromRecord($record, $merchantId, $normalizedType);
            if (self::channelSupportsTransferType($channel, $normalizedType)) {
                return $channel;
            }
        }

        return null;
    }

    private static function resolveSavedTransferChannel(object $transfer): ?array
    {
        $merchantId = (int)($transfer->merchant_id ?? 0);
        if ($merchantId <= 0) {
            return null;
        }

        $channelId = (int)($transfer->channel_id ?? 0);
        $pluginCode = self::normalizePluginCode((string)($transfer->channel_plugin_code ?? ''));
        foreach (self::activeChannelRecords($merchantId, '') as $record) {
            if ($channelId > 0 && self::recordId($record) !== $channelId) {
                continue;
            }

            $channel = self::legacyTransferChannelFromRecord($record, $merchantId, (string)($transfer->type ?? ''));
            if ($channel === []) {
                continue;
            }

            if ($pluginCode !== '' && self::normalizePluginCode((string)($channel['plugin_code'] ?? '')) !== $pluginCode) {
                continue;
            }

            return $channel;
        }

        return self::resolveTransferChannel($merchantId, (string)($transfer->type ?? ''));
    }

    private static function applyTransferPluginResult(object $transfer, array $result, object $merchant): object
    {
        $status = (int)($result['status'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $pluginCode = (string)($result['channel']['plugin_code'] ?? $transfer->channel_plugin_code ?? '');
        $channelOrderNo = (string)($result['channel_order_no'] ?? '');
        $channelTradeNo = (string)($result['channel_trade_no'] ?? '');

        if (!($result['ok'] ?? false) || $status === 2) {
            return LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
                'status' => $status === 2 ? 2 : 0,
                'result' => (string)($result['result'] ?? 'plugin_failed'),
                'last_error' => (string)($result['errmsg'] ?? '鏀粯鎻掍欢浠ｄ粯鎵ц澶辫触'),
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelTradeNo,
                'channel_plugin_code' => $pluginCode,
                'raw_response' => $result['raw'] ?? [],
                'rejected_at' => $status === 2 ? $now : (string)($transfer->rejected_at ?? ''),
            ]) ?? $transfer;
        }

        if ($status === 0) {
            return LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
                'status' => 0,
                'result' => 'plugin_transfer_pending',
                'last_error' => (string)($result['errmsg'] ?? '鎻掍欢浠ｄ粯澶勭悊涓紝绛夊緟鏌ヨ鎴栧紓姝ラ€氱煡'),
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelTradeNo,
                'channel_plugin_code' => $pluginCode,
                'raw_response' => $result['raw'] ?? [],
            ]) ?? $transfer;
        }

        $money = self::formatMoney($transfer->money ?? 0);
        $flow = LocalFundStore::debit(
            (int)$merchant->id,
            $money,
            '浠ｄ粯鎵ｆ',
            'transfer',
            (string)$transfer->biz_no,
            $now,
            [
                'out_biz_no' => (string)$transfer->out_biz_no,
                'type' => (string)$transfer->type,
                'account' => (string)$transfer->account,
                'name' => (string)$transfer->name,
                'plugin_code' => $pluginCode,
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelTradeNo,
            ]
        );

        return LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
            'status' => 1,
            'available_money' => (string)($flow->balance_after ?? ''),
            'channel_order_no' => $channelOrderNo,
            'channel_trade_no' => $channelTradeNo,
            'channel_plugin_code' => $pluginCode,
            'proof_no' => $channelOrderNo !== '' ? $channelOrderNo : $channelTradeNo,
            'operator' => 'plugin:' . $pluginCode,
            'result' => 'plugin_transferred',
            'last_error' => '',
            'finished_at' => $now,
            'raw_response' => $result['raw'] ?? [],
        ]) ?? $transfer;
    }

    private static function syncPendingRefundRecord(object $refund, array &$stats): void
    {
        $stats['checked']++;
        $stats['refunds']['checked']++;

        $result = self::syncPendingRefundStatusResult($refund);
        $bucket = (string)($result['bucket'] ?? 'failed');

        $stats[$bucket]++;
        $stats['refunds'][$bucket]++;
        if (($result['plugin_error'] ?? false) === true) {
            $stats['plugin_errors']++;
        }
    }

    private static function collectPendingRefundsForSync(array $refundNos, int $limit): array
    {
        $refundNos = self::normalizePayoutKeys($refundNos);
        $items = [];

        foreach (LocalTransferStore::businessRefunds(0, 0) as $refund) {
            if ((int)($refund->status ?? -1) !== 0) {
                continue;
            }
            if ((string)($refund->result ?? '') !== 'plugin_refund_pending') {
                continue;
            }
            if ($refundNos !== [] && !isset($refundNos[(string)($refund->refund_no ?? '')])) {
                continue;
            }

            $items[] = $refund;
        }

        usort($items, static fn(object $left, object $right): int => strcmp((string)($left->created_at ?? ''), (string)($right->created_at ?? '')));

        return array_slice($items, 0, $limit);
    }

    private static function syncPendingRefundStatusResult(object $refund): array
    {
        try {
            $order = self::findByTradeNoOrNull((string)($refund->trade_no ?? ''));
            if (!$order) {
                $updated = LocalTransferStore::updateRefund((string)$refund->refund_no, [
                    'last_error' => 'Original order missing for refund sync',
                ]) ?? $refund;

                return ['bucket' => 'failed', 'plugin_error' => true, 'refund' => $updated];
            }

            $merchant = self::resolveMerchantById((int)($refund->merchant_id ?? $order->merchant_id ?? 0));
            if (!$merchant) {
                $updated = LocalTransferStore::updateRefund((string)$refund->refund_no, [
                    'last_error' => 'Merchant missing for refund sync',
                ]) ?? $refund;

                return ['bucket' => 'failed', 'plugin_error' => true, 'refund' => $updated];
            }

            $result = PluginExecutorService::queryRefund($order, $refund);
            if (self::shouldManualizePayoutResult($result)) {
                $updated = self::manualizeRefundPending($refund, (string)($result['errmsg'] ?? '当前退款记录不支持自动同步，请转人工处理'), $result);
                return ['bucket' => 'manualized', 'plugin_error' => false, 'refund' => $updated];
            }

            if (!($result['ok'] ?? false)) {
                $updated = LocalTransferStore::updateRefund((string)$refund->refund_no, [
                    'status' => 0,
                    'result' => 'plugin_refund_pending',
                    'last_error' => (string)($result['errmsg'] ?? 'Plugin refund query failed'),
                    'raw_response' => $result['raw'] ?? [],
                ]) ?? $refund;

                return ['bucket' => 'deferred', 'plugin_error' => true, 'refund' => $updated];
            }

            $updated = self::applyRefundPluginResult($refund, $result, $order, $merchant);
            if ((int)($updated->status ?? 0) === 1) {
                return ['bucket' => 'completed', 'plugin_error' => false, 'refund' => $updated];
            }

            if ((int)($updated->status ?? 0) === 2) {
                return ['bucket' => 'failed', 'plugin_error' => true, 'refund' => $updated];
            }

            return ['bucket' => 'deferred', 'plugin_error' => false, 'refund' => $updated];
        } catch (Throwable $exception) {
            $updated = LocalTransferStore::updateRefund((string)($refund->refund_no ?? ''), [
                'last_error' => $exception->getMessage(),
            ]) ?? $refund;

            return ['bucket' => 'failed', 'plugin_error' => true, 'refund' => $updated];
        }
    }

    private static function syncPendingTransferRecord(object $transfer, array &$stats): void
    {
        $stats['checked']++;
        $stats['transfers']['checked']++;

        $result = self::syncPendingTransferStatusResult($transfer);
        $bucket = (string)($result['bucket'] ?? 'failed');

        $stats[$bucket]++;
        $stats['transfers'][$bucket]++;
        if (($result['plugin_error'] ?? false) === true) {
            $stats['plugin_errors']++;
        }
    }

    private static function collectPendingTransfersForSync(array $bizNos, int $limit): array
    {
        $bizNos = self::normalizePayoutKeys($bizNos);
        $items = [];

        foreach (LocalTransferStore::businessTransfers(0, 0) as $transfer) {
            if ((int)($transfer->status ?? -1) !== 0) {
                continue;
            }
            if ((string)($transfer->result ?? '') !== 'plugin_transfer_pending') {
                continue;
            }
            if ($bizNos !== [] && !isset($bizNos[(string)($transfer->biz_no ?? '')])) {
                continue;
            }

            $items[] = $transfer;
        }

        usort($items, static fn(object $left, object $right): int => strcmp((string)($left->created_at ?? ''), (string)($right->created_at ?? '')));

        return array_slice($items, 0, $limit);
    }

    private static function normalizePayoutKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            $value = trim((string)$key);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        return $normalized;
    }

    private static function syncPendingTransferStatusResult(object $transfer): array
    {
        try {
            $merchant = self::resolveMerchantById((int)($transfer->merchant_id ?? 0));
            if (!$merchant) {
                $updated = LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
                    'last_error' => 'Merchant missing for transfer sync',
                ]) ?? $transfer;

                return ['bucket' => 'failed', 'plugin_error' => true, 'transfer' => $updated];
            }

            $channel = self::resolveSavedTransferChannel($transfer);
            if ($channel === null) {
                $updated = self::manualizeTransferPending($transfer, self::transferCapabilityError((string)($transfer->type ?? '')));
                return ['bucket' => 'manualized', 'plugin_error' => false, 'transfer' => $updated];
            }

            $result = PluginExecutorService::queryTransfer($channel, $transfer);
            if (self::shouldManualizePayoutResult($result)) {
                $updated = self::manualizeTransferPending($transfer, (string)($result['errmsg'] ?? self::transferCapabilityError((string)($transfer->type ?? ''))), $result);
                return ['bucket' => 'manualized', 'plugin_error' => false, 'transfer' => $updated];
            }

            if (!($result['ok'] ?? false)) {
                $updated = LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
                    'status' => 0,
                    'result' => 'plugin_transfer_pending',
                    'last_error' => (string)($result['errmsg'] ?? 'Plugin transfer query failed'),
                    'raw_response' => $result['raw'] ?? [],
                ]) ?? $transfer;

                return ['bucket' => 'deferred', 'plugin_error' => true, 'transfer' => $updated];
            }

            $updated = self::applyTransferPluginResult($transfer, $result, $merchant);
            if (($result['ok'] ?? false) && (int)($updated->status ?? 0) === 1) {
                return ['bucket' => 'completed', 'plugin_error' => false, 'transfer' => $updated];
            }

            if ((int)($updated->status ?? 0) === 2) {
                return ['bucket' => 'failed', 'plugin_error' => true, 'transfer' => $updated];
            }

            return ['bucket' => 'deferred', 'plugin_error' => false, 'transfer' => $updated];
        } catch (Throwable $exception) {
            $updated = LocalTransferStore::updateTransfer((string)($transfer->biz_no ?? ''), [
                'last_error' => $exception->getMessage(),
            ]) ?? $transfer;

            return ['bucket' => 'failed', 'plugin_error' => true, 'transfer' => $updated];
        }
    }

    private static function applyRefundPluginResult(object $refund, array $result, object $order, object $merchant): object
    {
        $status = (int)($result['status'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $pluginCode = (string)($result['channel']['plugin_code'] ?? $refund->channel_plugin_code ?? '');
        $channelId = (int)($result['channel']['id'] ?? $refund->channel_id ?? $order->merchant_channel_id ?? 0);
        $channelOrderNo = (string)($result['channel_order_no'] ?? '');
        $channelTradeNo = (string)($result['channel_trade_no'] ?? '');
        $refundNo = (string)$refund->refund_no;

        if (!($result['ok'] ?? false)) {
            return LocalTransferStore::updateRefund($refundNo, [
                'status' => 0,
                'result' => (string)($result['result'] ?? 'plugin_refund_query_failed'),
                'last_error' => (string)($result['errmsg'] ?? 'Plugin refund query failed'),
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelTradeNo,
                'channel_plugin_code' => $pluginCode,
                'channel_id' => $channelId,
                'raw_response' => $result['raw'] ?? [],
            ]) ?? $refund;
        }

        if ($status === 0) {
            return LocalTransferStore::updateRefund($refundNo, [
                'status' => 0,
                'result' => (string)($result['result'] ?? 'plugin_refund_pending'),
                'last_error' => (string)($result['errmsg'] ?? 'Plugin refund pending'),
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelTradeNo,
                'channel_plugin_code' => $pluginCode,
                'channel_id' => $channelId,
                'raw_response' => $result['raw'] ?? [],
            ]) ?? $refund;
        }

        if ($status === 2) {
            return LocalTransferStore::updateRefund($refundNo, [
                'status' => 2,
                'result' => (string)($result['result'] ?? 'plugin_refund_failed'),
                'last_error' => (string)($result['errmsg'] ?? 'Plugin refund failed'),
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelTradeNo,
                'channel_plugin_code' => $pluginCode,
                'channel_id' => $channelId,
                'raw_response' => $result['raw'] ?? [],
            ]) ?? $refund;
        }

        $money = self::formatMoney($refund->reducemoney ?? $refund->money ?? 0);
        $flow = LocalFundStore::debit(
            (int)$merchant->id,
            $money,
            '退款扣款',
            'refund',
            $refundNo,
            $now,
            [
                'trade_no' => (string)($order->trade_no ?? $refund->trade_no ?? ''),
                'out_trade_no' => (string)($order->out_trade_no ?? $refund->out_trade_no ?? ''),
                'out_refund_no' => (string)($refund->out_refund_no ?? ''),
                'plugin_code' => $pluginCode,
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelTradeNo,
            ]
        );

        return LocalTransferStore::updateRefund($refundNo, [
            'status' => 1,
            'result' => (string)($result['result'] ?? 'plugin_refunded'),
            'last_error' => '',
            'channel_order_no' => $channelOrderNo,
            'channel_trade_no' => $channelTradeNo,
            'channel_plugin_code' => $pluginCode,
            'channel_id' => $channelId,
            'proof_no' => $channelOrderNo !== '' ? $channelOrderNo : $channelTradeNo,
            'operator' => 'plugin:' . $pluginCode,
            'finished_at' => $now,
            'raw_response' => $result['raw'] ?? [],
            'available_money' => (string)($flow->balance_after ?? ''),
        ]) ?? $refund;
    }

    private static function summarizeRefunds(array $refunds): array
    {
        $summary = [
            'total' => 0,
            'pending' => 0,
            'success' => 0,
            'failed' => 0,
            'plugin_pending' => 0,
            'manual_pending' => 0,
            'plugin_success' => 0,
            'manual_success' => 0,
            'attention_total' => 0,
            'last_pending_created_at' => '',
        ];

        $lastPending = '';
        foreach ($refunds as $refund) {
            $status = (int)($refund->status ?? 0);
            $result = trim((string)($refund->result ?? ''));
            $createdAt = (string)($refund->created_at ?? '');

            $summary['total']++;
            if ($status === 1) {
                $summary['success']++;
                if (str_starts_with($result, 'manual_')) {
                    $summary['manual_success']++;
                } else {
                    $summary['plugin_success']++;
                }
                continue;
            }

            if ($status === 2) {
                $summary['failed']++;
                $summary['attention_total']++;
                continue;
            }

            $summary['pending']++;
            $summary['attention_total']++;
            if ($result === 'manual_refund_pending') {
                $summary['manual_pending']++;
            } else {
                $summary['plugin_pending']++;
            }

            if ($createdAt !== '' && ($lastPending === '' || strcmp($createdAt, $lastPending) > 0)) {
                $lastPending = $createdAt;
            }
        }

        $summary['last_pending_created_at'] = $lastPending;
        return $summary;
    }

    private static function summarizeTransfers(array $transfers): array
    {
        $summary = [
            'total' => 0,
            'pending' => 0,
            'success' => 0,
            'failed' => 0,
            'rejected' => 0,
            'plugin_pending' => 0,
            'manual_pending' => 0,
            'plugin_success' => 0,
            'manual_success' => 0,
            'attention_total' => 0,
            'last_pending_created_at' => '',
        ];

        $lastPending = '';
        foreach ($transfers as $transfer) {
            $status = (int)($transfer->status ?? 0);
            $result = trim((string)($transfer->result ?? ''));
            $createdAt = (string)($transfer->created_at ?? '');

            $summary['total']++;
            if ($status === 1) {
                $summary['success']++;
                if (str_starts_with($result, 'manual_')) {
                    $summary['manual_success']++;
                } else {
                    $summary['plugin_success']++;
                }
                continue;
            }

            if ($status === 2) {
                if ($result === 'manual_rejected') {
                    $summary['rejected']++;
                } else {
                    $summary['failed']++;
                    $summary['attention_total']++;
                }
                continue;
            }

            $summary['pending']++;
            $summary['attention_total']++;
            if ($result === 'manual_transfer_pending') {
                $summary['manual_pending']++;
            } else {
                $summary['plugin_pending']++;
            }

            if ($createdAt !== '' && ($lastPending === '' || strcmp($createdAt, $lastPending) > 0)) {
                $lastPending = $createdAt;
            }
        }

        $summary['last_pending_created_at'] = $lastPending;
        return $summary;
    }

    private static function shouldManualizePayoutResult(array $result): bool
    {
        return in_array((string)($result['result'] ?? ''), [
            'unsupported_capability',
            'plugin_config_missing',
            'plugin_missing',
            'plugin_class_missing',
            'plugin_channel_error',
        ], true);
    }

    private static function manualizeRefundPending(object $refund, string $message, array $result = []): object
    {
        return LocalTransferStore::updateRefund((string)($refund->refund_no ?? ''), [
            'status' => 0,
            'result' => 'manual_refund_pending',
            'last_error' => trim($message) !== '' ? $message : '当前退款记录不支持自动同步，请转人工处理',
            'raw_response' => $result['raw'] ?? [],
        ]) ?? $refund;
    }

    private static function manualizeTransferPending(object $transfer, string $message, array $result = []): object
    {
        return LocalTransferStore::updateTransfer((string)($transfer->biz_no ?? ''), [
            'status' => 0,
            'result' => 'manual_transfer_pending',
            'last_error' => trim($message) !== '' ? $message : self::transferCapabilityError((string)($transfer->type ?? '')),
            'raw_response' => $result['raw'] ?? [],
        ]) ?? $transfer;
    }

    private static function appendRefundSyncAudit(string $operator, string $mode, array $response, array $context = []): void
    {
        $operator = trim($operator);
        if ($operator === '') {
            return;
        }

        $counts = self::syncAuditCounts($response);

        CompensationAuditLogService::admin([
            'operator' => $operator,
            'merchant_id' => (int)($context['merchant_id'] ?? 0),
            'action' => $mode === 'batch' ? 'batch refund status sync' : 'refund status sync',
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => array_merge($context, [
                'mode' => $mode,
                'bucket' => (string)($response['bucket'] ?? ''),
                'result' => (string)($response['result'] ?? ''),
                'message' => (string)($response['errmsg'] ?? ''),
                'counts' => $counts,
                'before' => [
                    'plugin_pending' => (int)($response['plugin_pending_before'] ?? (($response['summary_before']['plugin_pending'] ?? 0))),
                    'manual_pending' => (int)($response['manual_pending_before'] ?? (($response['summary_before']['manual_pending'] ?? 0))),
                ],
                'after' => [
                    'plugin_pending' => (int)($response['plugin_pending_after'] ?? (($response['summary_after']['plugin_pending'] ?? 0))),
                    'manual_pending' => (int)($response['manual_pending_after'] ?? (($response['summary_after']['manual_pending'] ?? 0))),
                ],
            ]),
        ]);
    }

    private static function appendTransferSyncAudit(string $operator, string $mode, array $response, array $context = []): void
    {
        $operator = trim($operator);
        if ($operator === '') {
            return;
        }

        $counts = self::syncAuditCounts($response);

        CompensationAuditLogService::admin([
            'operator' => $operator,
            'merchant_id' => (int)($context['merchant_id'] ?? 0),
            'action' => $mode === 'batch' ? 'batch transfer status sync' : 'transfer status sync',
            'created_at' => date('Y-m-d H:i:s'),
            'detail' => array_merge($context, [
                'mode' => $mode,
                'bucket' => (string)($response['bucket'] ?? ''),
                'result' => (string)($response['result'] ?? ''),
                'message' => (string)($response['errmsg'] ?? ''),
                'counts' => $counts,
                'before' => [
                    'plugin_pending' => (int)($response['plugin_pending_before'] ?? (($response['summary_before']['plugin_pending'] ?? 0))),
                    'manual_pending' => (int)($response['manual_pending_before'] ?? (($response['summary_before']['manual_pending'] ?? 0))),
                ],
                'after' => [
                    'plugin_pending' => (int)($response['plugin_pending_after'] ?? (($response['summary_after']['plugin_pending'] ?? 0))),
                    'manual_pending' => (int)($response['manual_pending_after'] ?? (($response['summary_after']['manual_pending'] ?? 0))),
                ],
            ]),
        ]);
    }

    private static function syncAuditCounts(array $response): array
    {
        $counts = [
            'checked' => (int)($response['checked'] ?? 1),
            'completed' => (int)($response['completed'] ?? 0),
            'manualized' => (int)($response['manualized'] ?? 0),
            'deferred' => (int)($response['deferred'] ?? 0),
            'failed' => (int)($response['failed'] ?? 0),
        ];

        if (!isset($response['checked'])) {
            $bucket = trim((string)($response['bucket'] ?? ''));
            if ($bucket !== '' && array_key_exists($bucket, $counts)) {
                $counts[$bucket] = 1;
            }
        }

        return $counts;
    }

    private static function singleMerchantIdFromPayoutRecords(array $items, string $field): int
    {
        $merchantIds = [];
        foreach ($items as $item) {
            $merchantId = (int)($item->{$field} ?? 0);
            if ($merchantId > 0) {
                $merchantIds[$merchantId] = true;
            }
        }

        return count($merchantIds) === 1 ? (int)array_key_first($merchantIds) : 0;
    }

    private static function legacyTransferChannelFromRecord(mixed $record, int $merchantId, string $fallbackType): array
    {
        return LegacyChannelFormatter::fromTransferRecord($record, $merchantId, $fallbackType);
    }

    private static function pluginSupportsTransferType(string $pluginCode, string $type): bool
    {
        $type = self::normalizeMethodCode($type);
        if ($type === '') {
            return false;
        }

        return in_array($type, self::pluginTransferMethods($pluginCode), true);
    }

    private static function pluginTransferMethods(string $pluginCode): array
    {
        $normalizedPluginCode = self::normalizePluginCode($pluginCode);
        if ($normalizedPluginCode === '') {
            return [];
        }

        foreach (PluginService::plugins() as $plugin) {
            if (self::normalizePluginCode((string)($plugin['code'] ?? '')) !== $normalizedPluginCode) {
                continue;
            }

            $methods = [];
            foreach ((array)($plugin['transfer_methods'] ?? []) as $method) {
                $methodCode = self::normalizeMethodCode((string)$method);
                if ($methodCode !== '') {
                    $methods[$methodCode] = $methodCode;
                }
            }

            return array_values($methods);
        }

        return [];
    }

    private static function channelSupportsTransferType(array $channel, string $type): bool
    {
        if ($channel === []) {
            return false;
        }

        $normalizedType = self::normalizeMethodCode($type);
        if ($normalizedType === '') {
            return false;
        }

        $pluginCode = self::normalizePluginCode((string)($channel['plugin_code'] ?? ''));
        if ($pluginCode === '') {
            return false;
        }

        $capability = PluginExecutorService::capability($pluginCode);
        if (!($capability['transfer'] ?? false)) {
            return false;
        }

        $declaredTransferMethods = self::pluginTransferMethods($pluginCode);
        if ($declaredTransferMethods !== []) {
            return in_array($normalizedType, $declaredTransferMethods, true);
        }

        $channelMethodCode = self::normalizeMethodCode((string)($channel['channel_code'] ?? $channel['type'] ?? ''));
        return $channelMethodCode !== '' && $channelMethodCode === $normalizedType;
    }

    private static function mergeTransferMethodOption(
        array &$options,
        string $methodCode,
        string $pluginCode,
        string $channelName,
        int $recordId
    ): void {
        $methodCode = self::normalizeMethodCode($methodCode);
        $pluginCode = self::normalizePluginCode($pluginCode);
        if ($methodCode === '' || $pluginCode === '' || $recordId <= 0) {
            return;
        }

        if (!isset($options[$methodCode])) {
            $options[$methodCode] = [
                'channel_names' => [],
                'plugin_codes' => [],
                'record_ids' => [],
            ];
        }

        $options[$methodCode]['plugin_codes'][$pluginCode] = true;
        $options[$methodCode]['record_ids'][$recordId] = true;
        if ($channelName !== '') {
            $options[$methodCode]['channel_names'][$channelName] = true;
        }
    }

    private static function findRefundRecord(int $merchantId, ?string $refundNo, ?string $outRefundNo, ?string $tradeNo): ?object
    {
        return LocalTransferStore::findRefund($merchantId, $refundNo, $outRefundNo, $tradeNo);
    }

    private static function merchantOrdersPage(int $merchantId, int $page, int $limit, ?int $statusFilter = null): array
    {
        $orders = self::canUseDatabase()
            ? Order::where('merchant_id', $merchantId)->order('id', 'desc')->select()->all()
            : LocalOrderStore::businessOrdersByMerchant($merchantId);

        $items = [];
        foreach ($orders as $order) {
            if (!LocalOrderStore::isBusinessOrder($order)) {
                continue;
            }

            if ($statusFilter !== null && (int)$order->status !== $statusFilter) {
                continue;
            }

            $items[] = [
                'trade_no' => (string)$order->trade_no,
                'out_trade_no' => (string)$order->out_trade_no,
                'api_trade_no' => (string)($order->txid ?: ''),
                'type' => (string)$order->channel_code,
                'pid' => self::merchantPidValue($merchantId),
                'status' => (int)$order->status === self::STATUS_SUCCESS ? 1 : 0,
                'addtime' => (string)$order->created_at,
                'endtime' => (string)$order->pay_time,
                'name' => (string)$order->subject,
                'money' => self::formatMoney($order->amount),
                'param' => (string)$order->param,
                'buyer' => self::buyerForOrder($order),
                'clientip' => (string)$order->client_ip,
                'bill_trade_no' => self::billTradeNoForOrder($order),
                'bill_mch_trade_no' => self::billMchTradeNoForOrder($order),
                'refundmoney' => self::refundMoneyForOrder($merchantId, (string)$order->trade_no),
            ];
        }

        $offset = max(0, ($page - 1) * $limit);
        return array_slice($items, $offset, $limit);
    }

    private static function merchantSettlePageV1(int $merchantId): array
    {
        return SettlementService::v1Settlements($merchantId);
    }

    private static function merchantOrdersPageV1(int $merchantId, int $page, int $limit): array
    {
        $items = self::merchantOrdersPage($merchantId, $page, $limit);

        return array_map(static function (array $item): array {
            return [
                'trade_no' => (string)($item['trade_no'] ?? ''),
                'out_trade_no' => (string)($item['out_trade_no'] ?? ''),
                'api_trade_no' => (string)($item['api_trade_no'] ?? ''),
                'bill_trade_no' => (string)($item['bill_trade_no'] ?? ''),
                'bill_mch_trade_no' => (string)($item['bill_mch_trade_no'] ?? ''),
                'type' => self::exportV1MethodCode((string)($item['type'] ?? '')),
                'pid' => (string)($item['pid'] ?? ''),
                'addtime' => (string)($item['addtime'] ?? ''),
                'endtime' => (string)($item['endtime'] ?? ''),
                'name' => (string)($item['name'] ?? ''),
                'money' => (string)($item['money'] ?? '0.00'),
                'param' => (string)($item['param'] ?? ''),
                'buyer' => (string)($item['buyer'] ?? ''),
                'clientip' => (string)($item['clientip'] ?? ''),
                'status' => (int)($item['status'] ?? 0),
                'refundmoney' => (string)($item['refundmoney'] ?? '0.00'),
                'trade_status' => (int)($item['status'] ?? 0) === 1 ? 'TRADE_SUCCESS' : 'WAIT_BUYER_PAY',
            ];
        }, $items);
    }

    private static function refundForV1(object $merchant, array $payload): array
    {
        $money = self::formatMoney($payload['money'] ?? '0');
        if ((float)$money <= 0) {
            throw new BusinessException('閫€娆鹃噾棰濆繀椤诲ぇ浜?0', StatusCode::VALIDATION_ERROR);
        }

        $order = self::findMerchantOrder((int)$merchant->id, $payload['trade_no'] ?? null, $payload['out_trade_no'] ?? null);
        if ((float)$money > (float)$order->amount) {
            throw new BusinessException('閫€娆鹃噾棰濅笉鑳借秴杩囧師璁㈠崟閲戦', StatusCode::VALIDATION_ERROR);
        }

        $outRefundNo = trim((string)($payload['out_refund_no'] ?? $payload['refund_no'] ?? ''));
        $existing = self::findRefundRecord(
            (int)$merchant->id,
            self::stringOrNull($payload['refund_no'] ?? null),
            self::stringOrNull($outRefundNo),
            (string)$order->trade_no
        );
        $isDuplicateOutRefundNo = $outRefundNo !== ''
            && $existing
            && strcasecmp((string)($existing->out_refund_no ?? ''), $outRefundNo) === 0
            && (int)($existing->status ?? 0) === 1;

        if (!$existing) {
            $refundNo = trim((string)($payload['refund_no'] ?? ''));
            if ($refundNo === '') {
                $refundNo = self::generateRefundNo();
            }

            $existing = LocalTransferStore::createRefund([
                'merchant_id' => (int)$merchant->id,
                'trade_no' => (string)$order->trade_no,
                'out_trade_no' => (string)$order->out_trade_no,
                'refund_no' => $refundNo,
                'out_refund_no' => $outRefundNo !== '' ? $outRefundNo : $refundNo,
                'money' => $money,
                'reducemoney' => $money,
                'status' => 1,
            ]);
        }

        return [
            'code' => 0,
            'msg' => $isDuplicateOutRefundNo
                ? '已存在相同退款单号！退款金额¥' . self::formatMoney($existing->money ?? '0')
                : '退款成功',
            'refund_no' => (string)$existing->refund_no,
            'out_refund_no' => (string)$existing->out_refund_no,
            'trade_no' => (string)$existing->trade_no,
            'out_trade_no' => (string)$existing->out_trade_no,
            'uid' => (int)($merchant->id ?? 0),
            'money' => self::formatMoney($existing->money ?? '0'),
            'reducemoney' => self::formatMoney($existing->reducemoney ?? '0'),
            'status' => (int)($existing->status ?? 0),
        ];
    }

    private static function refundQueryForV1(object $merchant, array $payload): array
    {
        $refundNo = self::stringOrNull($payload['refund_no'] ?? null);
        $outRefundNo = self::stringOrNull($payload['out_refund_no'] ?? null);
        if ($refundNo === null && $outRefundNo === null) {
            throw new BusinessException('澶栭儴閫€娆惧崟鍙?out_refund_no)涓嶈兘涓虹┖', StatusCode::BAD_REQUEST);
        }

        $refund = self::findRefundRecord((int)$merchant->id, $refundNo, $outRefundNo, null);
        if (!$refund) {
            throw new BusinessException('閫€娆捐褰曚笉瀛樺湪', StatusCode::NOT_FOUND);
        }

        return [
            'code' => 0,
            'refund_no' => (string)$refund->refund_no,
            'out_refund_no' => (string)$refund->out_refund_no,
            'trade_no' => (string)$refund->trade_no,
            'out_trade_no' => (string)$refund->out_trade_no,
            'uid' => (int)($merchant->id ?? 0),
            'money' => self::formatMoney($refund->money ?? '0'),
            'reducemoney' => self::formatMoney($refund->reducemoney ?? '0'),
            'status' => (int)($refund->status ?? 0),
            'addtime' => (string)($refund->created_at ?? ''),
            'endtime' => (string)($refund->updated_at ?? $refund->created_at ?? ''),
        ];
    }

    private static function closeForV1(object $merchant, array $payload): array
    {
        $order = self::findMerchantOrder((int)$merchant->id, $payload['trade_no'] ?? null, $payload['out_trade_no'] ?? null);

        if ((int)$order->status === self::STATUS_PENDING) {
            self::saveOrder($order, ['status' => self::STATUS_CLOSED]);
        } elseif ((int)$order->status !== self::STATUS_CLOSED) {
            return [
                'code' => -1,
                'msg' => '褰撳墠璁㈠崟鐘舵€佷笉鏀寔鍏抽棴',
            ];
        }

        return [
            'code' => 0,
            'msg' => '璁㈠崟鍏抽棴鎴愬姛',
        ];
    }

    private static function refundApiForV1(array $payload): array
    {
        $tradeNo = trim((string)($payload['trade_no'] ?? ''));
        $money = self::formatMoney($payload['money'] ?? '0');
        $signature = strtolower(trim((string)($payload['key'] ?? '')));

        if ($tradeNo === '') {
            throw new BusinessException('订单号不能为空', StatusCode::BAD_REQUEST);
        }

        $order = self::findByTradeNo($tradeNo);
        $merchant = self::resolveMerchantById((int)$order->merchant_id);
        if (!$merchant) {
            throw new BusinessException('商户不存在或已停用', StatusCode::NOT_FOUND);
        }

        $expected = strtolower(md5($tradeNo . ConfigService::internalRefundSecret() . $tradeNo));
        if ($signature === '') {
            throw new BusinessException('内部退款签名不能为空', StatusCode::UNAUTHORIZED);
        }
        if (!hash_equals($expected, $signature)) {
            throw new BusinessException('内部退款签名校验失败', StatusCode::UNAUTHORIZED);
        }

        return self::refundForV1($merchant, [
            'trade_no' => $tradeNo,
            'money' => $money !== '0.00' ? $money : self::formatMoney($order->amount),
            'refund_no' => trim((string)($payload['refund_no'] ?? '')),
            'out_refund_no' => trim((string)($payload['out_refund_no'] ?? '')),
        ]);
    }

    private static function exportV1MethodCode(string $code): string
    {
        return match (self::normalizeMethodCode($code)) {
            'wxpay' => 'wxpay',
            'alipay' => 'alipay',
            'qqpay' => 'qqpay',
            'bank' => 'bank',
            'jdpay' => 'jdpay',
            'paypal' => 'paypal',
            'douyinpay' => 'douyinpay',
            'usdttrc20' => 'usdttrc20',
            'usdtpolygon' => 'usdtpolygon',
            'usdtaptos' => 'usdtaptos',
            'erc20' => 'erc20',
            'bsc' => 'bsc',
            'trx' => 'trx',
            'avaxc' => 'avaxc',
            default => self::normalizeMethodCode($code),
        };
    }

    public static function buyerForOrder(object $order): string
    {
        $buyer = trim((string)($order->buyer ?? ''));
        if ($buyer !== '') {
            return $buyer;
        }

        $context = self::orderContext($order);
        return trim((string)($context['buyer'] ?? ''));
    }

    public static function billTradeNoForOrder(object $order): string
    {
        $billTradeNo = trim((string)($order->bill_trade_no ?? ''));
        if ($billTradeNo !== '') {
            return $billTradeNo;
        }

        $context = self::orderContext($order);
        $billTradeNo = trim((string)($context['bill_trade_no'] ?? $context['api_trade_no'] ?? ''));
        if ($billTradeNo !== '') {
            return $billTradeNo;
        }

        return trim((string)($order->txid ?? ''));
    }

    public static function billMchTradeNoForOrder(object $order): string
    {
        $billMchTradeNo = trim((string)($order->bill_mch_trade_no ?? ''));
        if ($billMchTradeNo !== '') {
            return $billMchTradeNo;
        }

        $context = self::orderContext($order);
        return trim((string)($context['bill_mch_trade_no'] ?? ''));
    }

    private static function refundMoneyForOrder(int $merchantId, string $tradeNo): string
    {
        return LocalTransferStore::sumRefundMoney($merchantId, $tradeNo);
    }

    private static function merchantPidValue(int $merchantId): string
    {
        $merchant = self::resolveMerchantById($merchantId);
        return $merchant ? (string)($merchant->id ?? $merchantId) : (string)$merchantId;
    }

    private static function repairHomepageTestOrderMerchant(object $order): object
    {
        $merchantId = (int)($order->merchant_id ?? 0);
        if ($merchantId > 0 && self::resolveMerchantById($merchantId) !== null) {
            return $order;
        }

        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        if (($meta['business'] ?? '') !== 'homepage_payment_test') {
            return $order;
        }

        $resolvedMerchantId = 0;
        foreach ([
            (int)($meta['local_merchant_id'] ?? 0),
            (int)($meta['carrier_merchant_id'] ?? 0),
            (int)($meta['merchant_id'] ?? 0),
        ] as $candidate) {
            if ($candidate > 0 && self::resolveMerchantById($candidate) !== null) {
                $resolvedMerchantId = $candidate;
                break;
            }
        }

        if ($resolvedMerchantId <= 0) {
            $resolvedMerchantId = \app\service\system\SystemBusinessPaymentService::resolveFrontendTestLocalMerchantId([
                'merchant_id' => (int)($meta['merchant_id'] ?? 0),
                'local_merchant_id' => (int)($meta['local_merchant_id'] ?? 0),
                'carrier_merchant_id' => (int)($meta['carrier_merchant_id'] ?? 0),
                'channel_code' => (string)($order->channel_code ?? $meta['requested_method'] ?? ''),
                'amount' => (float)($order->amount ?? 0),
                'request_payload' => ['_meta' => $meta],
            ]);
        }

        if ($resolvedMerchantId <= 0 || self::resolveMerchantById($resolvedMerchantId) === null) {
            return $order;
        }

        $meta['local_merchant_id'] = $resolvedMerchantId;
        $requestPayload['_meta'] = $meta;

        return self::saveOrder($order, [
            'merchant_id' => $resolvedMerchantId,
            'request_payload' => $requestPayload,
        ]);
    }

    private static function orderContext(object $order): array
    {
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];

        return array_replace($requestPayload, $notifyPayload);
    }

    private static function sumMerchantOrdersByDay(int $merchantId, string $mode): string
    {
        $target = $mode === 'yesterday' ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
        $amount = 0.0;

        if (self::canUseDatabase()) {
            foreach (Order::where('merchant_id', $merchantId)->select()->all() as $order) {
                if (!LocalOrderStore::isBusinessOrder($order)) {
                    continue;
                }

                if (str_starts_with((string)($order->created_at ?? ''), $target)) {
                    $amount += (float)($order->amount ?? 0);
                }
            }

            return self::formatMoney($amount);
        }

        foreach (LocalOrderStore::businessOrdersByMerchant($merchantId) as $order) {
            if (str_starts_with((string)$order->created_at, $target)) {
                $amount += (float)$order->amount;
            }
        }

        return self::formatMoney($amount);
    }
}
