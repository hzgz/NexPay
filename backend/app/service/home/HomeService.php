<?php

namespace app\service\home;

use app\model\Announcement;
use app\model\Merchant;
use app\model\Order;
use app\service\payment\LocalOrderStore;
use app\service\system\AnnouncementService;
use app\service\system\JsonStoreService;
use Throwable;

class HomeService
{
    public static function statistics(): array
    {
        if (database_available()) {
            try {
                $orders = Order::select()->toArray();
                $totalOrders = 0;
                $successOrders = 0;
                $totalAmount = 0.0;

                foreach ($orders as $row) {
                    if (!LocalOrderStore::isBusinessOrder($row)) {
                        continue;
                    }

                    $totalOrders++;
                    if ((int)($row['status'] ?? 0) !== 1) {
                        continue;
                    }

                    $successOrders++;
                    $totalAmount += (float)($row['amount'] ?? 0);
                }

                return [
                    'total_amount' => number_format($totalAmount, 2, '.', ''),
                    'merchant_count' => Merchant::where('status', 1)->count(),
                    'order_count' => $totalOrders,
                    'success_rate' => $totalOrders > 0 ? round($successOrders / $totalOrders * 100, 2) : 0,
                    'data_source' => 'database',
                ];
            } catch (Throwable) {
            }
        }

        return self::localStatistics();
    }

    public static function announcements(int $limit = 10): array
    {
        if (database_available()) {
            try {
                return Announcement::where('status', 1)
                    ->order('sort', 'asc')
                    ->order('id', 'desc')
                    ->limit($limit)
                    ->select()
                    ->toArray();
            } catch (Throwable) {
            }
        }

        return AnnouncementService::visible('home', $limit);
    }

    private static function localStatistics(): array
    {
        $orders = LocalOrderStore::businessOrders();
        $totalOrders = 0;
        $successOrders = 0;
        $totalAmount = 0.0;

        foreach ($orders as $order) {
            $totalOrders++;
            if ((int)($order->status ?? 0) !== 1) {
                continue;
            }

            $successOrders++;
            $totalAmount += (float)($order->amount ?? 0);
        }

        return [
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'merchant_count' => self::localActiveMerchantCount(),
            'order_count' => $totalOrders,
            'success_rate' => $totalOrders > 0 ? round($successOrders / $totalOrders * 100, 2) : 0,
            'data_source' => 'local_store',
        ];
    }

    private static function localActiveMerchantCount(): int
    {
        $users = JsonStoreService::load('merchant_auth_users', []);
        if (!is_array($users)) {
            return 0;
        }

        $count = 0;
        foreach ($users as $user) {
            if (is_array($user) && (int)($user['status'] ?? 0) === 1) {
                $count++;
            }
        }

        return $count;
    }
}
