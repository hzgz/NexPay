<?php

namespace app\service\system;

use app\model\Merchant;
use app\model\MerchantBalance;
use app\model\Order;
use app\service\payment\CallbackService;
use app\service\payment\LocalFundStore;
use app\service\payment\LocalOrderStore;
use app\service\payment\OrderService;
use Throwable;

class DashboardDataService
{
    public static function adminOverview(): array
    {
        $orders = self::orders();
        $merchants = self::merchants();
        $successfulOrders = array_values(array_filter($orders, static fn(array $row): bool => (int)($row['status'] ?? 0) === 1));
        $callbackSummary = CallbackService::summary();
        $payoutSummary = OrderService::payoutSummary();
        $riskAlerts = (int)($callbackSummary['attention_total'] ?? 0) + (int)($payoutSummary['attention_total'] ?? 0);

        return [
            'data_source' => self::dataSource(),
            'cards' => [
                'total_amount' => self::formatMoney(self::sumAmount($successfulOrders)),
                'merchant_count' => count(array_filter($merchants, static fn(array $row): bool => self::merchantStatusCode($row) === 1)),
                'order_count' => count($orders),
                'recharge_amount' => self::rechargeAmount(),
                'pending_orders' => count(array_filter($orders, static fn(array $row): bool => (int)($row['status'] ?? 0) === 0)),
            ],
            'todos' => [
                'pending_merchants' => count(array_filter($merchants, static fn(array $row): bool => self::merchantStatusCode($row) === 0)),
                'pending_orders' => count(array_filter($orders, static fn(array $row): bool => (int)($row['status'] ?? 0) === 0)),
                'pending_tickets' => self::pendingTicketCount(),
                'risk_alerts' => $riskAlerts,
                'pending_refunds' => (int)($payoutSummary['refunds']['pending'] ?? 0),
                'pending_transfers' => (int)($payoutSummary['transfers']['pending'] ?? 0),
                'callback_due' => (int)($callbackSummary['pending_due'] ?? 0),
                'callback_exhausted' => (int)($callbackSummary['retry_exhausted'] ?? 0),
            ],
            'trend' => self::trend($successfulOrders),
            'latest_orders' => self::latestOrders($orders, 8),
            'callback_summary' => $callbackSummary,
            'payout_summary' => $payoutSummary,
        ];
    }

    public static function merchantOverview(int $merchantId): array
    {
        $orders = array_values(array_filter(
            self::orders(),
            static fn(array $row): bool => (int)($row['merchant_id'] ?? 0) === $merchantId
        ));
        $successfulOrders = array_values(array_filter($orders, static fn(array $row): bool => (int)($row['status'] ?? 0) === 1));
        $today = date('Y-m-d');
        $todayOrders = array_values(array_filter(
            $orders,
            static fn(array $row): bool => str_starts_with((string)($row['created_at'] ?? ''), $today)
        ));
        $todaySuccessfulOrders = array_values(array_filter(
            $successfulOrders,
            static fn(array $row): bool => self::orderTrendDate($row) === $today
        ));
        $callbackSummary = CallbackService::summary($merchantId);
        $payoutSummary = OrderService::payoutSummary($merchantId);

        return [
            'data_source' => self::dataSource(),
            'cards' => [
                'balance' => self::merchantBalance($merchantId),
                'today_amount' => self::formatMoney(self::sumAmount($todaySuccessfulOrders)),
                'today_orders' => count($todayOrders),
                'pending_orders' => count(array_filter($orders, static fn(array $row): bool => (int)($row['status'] ?? 0) === 0)),
            ],
            'todos' => [
                'pending_orders' => count(array_filter($orders, static fn(array $row): bool => (int)($row['status'] ?? 0) === 0)),
                'pending_refunds' => (int)($payoutSummary['refunds']['pending'] ?? 0),
                'pending_transfers' => (int)($payoutSummary['transfers']['pending'] ?? 0),
                'manual_refunds' => (int)($payoutSummary['refunds']['manual_pending'] ?? 0),
                'manual_transfers' => (int)($payoutSummary['transfers']['manual_pending'] ?? 0),
                'callback_due' => (int)($callbackSummary['pending_due'] ?? 0),
                'callback_exhausted' => (int)($callbackSummary['retry_exhausted'] ?? 0),
            ],
            'trend' => self::trend($successfulOrders),
            'latest_orders' => self::latestOrders($orders, 5),
            'callback_summary' => $callbackSummary,
            'payout_summary' => $payoutSummary,
            'announcements' => self::storedMerchantAnnouncements(),
        ];
    }

    private static function orders(): array
    {
        $orders = [];
        $seenTradeNos = [];

        if (self::canUseDatabase()) {
            try {
                foreach (Order::order('id', 'desc')->limit(5000)->select()->toArray() as $row) {
                    if (!LocalOrderStore::isBusinessOrder($row)) {
                        continue;
                    }

                    $normalized = self::normalizeOrderRow($row);
                    $orders[] = $normalized;
                    $tradeNo = (string)($normalized['trade_no'] ?? '');
                    if ($tradeNo !== '') {
                        $seenTradeNos[$tradeNo] = true;
                    }
                }
            } catch (Throwable) {
            }
        }

        foreach (LocalOrderStore::businessOrders() as $order) {
            $normalized = self::normalizeOrderRow(self::row($order));
            $tradeNo = (string)($normalized['trade_no'] ?? '');
            if ($tradeNo !== '' && isset($seenTradeNos[$tradeNo])) {
                continue;
            }

            $orders[] = $normalized;
            if ($tradeNo !== '') {
                $seenTradeNos[$tradeNo] = true;
            }
        }

        usort($orders, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return $orders;
    }

    private static function merchants(): array
    {
        return array_map(static function (array $row): array {
            $row['status'] = self::merchantStatusCode($row);
            return $row;
        }, ResourceDataService::adminMerchants()['items'] ?? []);
    }

    private static function merchantBalance(int $merchantId): string
    {
        if (LocalFundStore::hasBusinessFlowsForMerchant($merchantId)) {
            return LocalFundStore::balanceForMerchant($merchantId)['available'] ?? '0.00';
        }

        if (self::canUseDatabase()) {
            try {
                $balance = MerchantBalance::where('merchant_id', $merchantId)->find();
                return self::formatMoney($balance?->balance ?? 0);
            } catch (Throwable) {
            }
        }

        return LocalFundStore::balanceForMerchant($merchantId)['available'] ?? '0.00';
    }

    private static function rechargeAmount(): string
    {
        return LocalFundStore::sumRecharge();
    }

    private static function trend(array $orders): array
    {
        $days = [];
        for ($offset = 6; $offset >= 0; $offset--) {
            $date = date('Y-m-d', strtotime('-' . $offset . ' day'));
            $days[$date] = [
                'date' => $date,
                'amount' => '0.00',
                'orders' => 0,
            ];
        }

        foreach ($orders as $order) {
            $date = self::orderTrendDate($order);
            if (!isset($days[$date])) {
                continue;
            }

            $days[$date]['orders']++;
            $days[$date]['amount'] = self::formatMoney((float)$days[$date]['amount'] + (float)($order['amount'] ?? 0));
        }

        return array_values($days);
    }

    private static function latestOrders(array $orders, int $limit): array
    {
        usort($orders, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return array_map(
            static fn(array $row): array => ResourceDataService::dashboardLatestOrderRow($row),
            array_slice($orders, 0, max(1, $limit))
        );
    }

    private static function storedMerchantAnnouncements(): array
    {
        $items = JsonStoreService::load('announcements', []);
        $visible = array_values(array_filter($items, static function (array $item): bool {
            $target = (string)($item['target'] ?? 'both');
            return (int)($item['status_code'] ?? 0) === 1 && ($target === 'both' || $target === 'merchant');
        }));

        usort($visible, static function (array $left, array $right): int {
            return [(int)($left['sort'] ?? 99), -(int)($left['id'] ?? 0)] <=> [(int)($right['sort'] ?? 99), -(int)($right['id'] ?? 0)];
        });

        return array_slice($visible, 0, 6);
    }

    private static function pendingTicketCount(): int
    {
        $tickets = TicketService::adminData()['items'] ?? [];

        return count(array_filter($tickets, static function (array $ticket): bool {
            $status = trim((string)($ticket['status'] ?? ''));
            return $status === '待处理' || $status === '处理中';
        }));
    }

    private static function normalizeOrderRow(array $row): array
    {
        return [
            'trade_no' => (string)($row['trade_no'] ?? ''),
            'out_trade_no' => (string)($row['out_trade_no'] ?? ''),
            'merchant_id' => (int)($row['merchant_id'] ?? 0),
            'merchant_name' => (string)($row['merchant_name'] ?? ''),
            'channel_code' => (string)($row['channel_code'] ?? ''),
            'channel_name' => (string)($row['channel_name'] ?? ''),
            'method_name' => (string)($row['method_name'] ?? ''),
            'subject' => (string)($row['subject'] ?? ''),
            'amount' => self::formatMoney($row['amount'] ?? $row['realmoney'] ?? 0),
            'status' => (int)($row['status'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? $row['addtime'] ?? ''),
            'pay_time' => (string)($row['pay_time'] ?? $row['endtime'] ?? ''),
            'request_payload' => self::normalizeRequestPayload($row['request_payload'] ?? []),
        ];
    }

    private static function normalizeRequestPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_object($payload) && method_exists($payload, 'toArray')) {
            $array = $payload->toArray();
            return is_array($array) ? $array : [];
        }

        if (is_object($payload)) {
            $decoded = json_decode((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private static function sumAmount(array $orders): float
    {
        $amount = 0.0;
        foreach ($orders as $order) {
            $amount += (float)($order['amount'] ?? 0);
        }

        return $amount;
    }

    private static function formatMoney(mixed $amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }

    private static function orderTrendDate(array $order): string
    {
        $status = (int)($order['status'] ?? 0);
        $payTime = trim((string)($order['pay_time'] ?? ''));
        if ($status === 1 && $payTime !== '') {
            return substr($payTime, 0, 10);
        }

        return substr((string)($order['created_at'] ?? ''), 0, 10);
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

    private static function dataSource(): string
    {
        return self::canUseDatabase() ? 'database+local_store' : 'local_store';
    }

    private static function merchantStatusCode(array $row): int
    {
        if (array_key_exists('status_code', $row)) {
            return (int)$row['status_code'];
        }

        return (int)($row['status'] ?? 0);
    }

    private static function canUseDatabase(): bool
    {
        static $usable = null;
        if ($usable !== null) {
            return $usable;
        }

        if (!\database_available()) {
            $usable = false;
            return $usable;
        }

        try {
            Order::where('id', '>', 0)->limit(1)->find();
            $usable = true;
        } catch (Throwable) {
            $usable = false;
        }

        return $usable;
    }
}
