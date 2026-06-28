<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\LocalFundStore;
use app\service\payment\OrderService;
use Throwable;
use think\facade\Db;

class PackageService
{
    private const STORE_KEY = 'packages';
    private const PURCHASE_STORE_KEY = 'merchant_packages';
    private const BUSINESS_PACKAGE_PURCHASE = 'merchant_package_purchase';

    public static function all(): array
    {
        if (database_available()) {
            try {
                $rows = Db::table('packages')
                    ->order('id', 'desc')
                    ->get()
                    ->toArray();

                if ($rows !== []) {
                    return [
                        'items' => array_map(
                            static fn($item): array => self::shapePackage((array)$item),
                            $rows
                        ),
                    ];
                }
            } catch (Throwable) {
            }
        }

        $stored = self::loadStoredPackages();

        return [
            'items' => array_map(static fn(array $item): array => self::shapePackage($item), $stored),
        ];
    }

    public static function activeBusinessPackages(): array
    {
        return array_values(array_filter(
            self::all()['items'],
            static fn(array $item): bool => (int)($item['status_code'] ?? 0) === 1 && self::isBusinessPackage($item)
        ));
    }

    public static function save(array $payload): array
    {
        $name = self::normalizeText((string)($payload['name'] ?? ''));
        $price = trim((string)($payload['price'] ?? ''));
        $durationDays = (int)($payload['duration_days'] ?? 0);
        $statusCode = (int)($payload['status_code'] ?? 1) === 1 ? 1 : 0;
        $benefits = self::normalizeBenefits($payload['benefits'] ?? []);

        if ($name === '' || $price === '' || $durationDays <= 0) {
            throw new BusinessException('请完整填写套餐信息', StatusCode::VALIDATION_ERROR);
        }

        if (!is_numeric($price) || (float)$price < 0) {
            throw new BusinessException('套餐价格格式不正确', StatusCode::VALIDATION_ERROR);
        }

        if ($benefits === []) {
            throw new BusinessException('请至少填写一项套餐权益', StatusCode::VALIDATION_ERROR);
        }

        if (database_available()) {
            try {
                Db::table('packages')->insert([
                    'name' => $name,
                    'price' => number_format((float)$price, 2, '.', ''),
                    'duration_days' => $durationDays,
                    'benefits' => json_encode($benefits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => $statusCode,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                return self::all();
            } catch (Throwable) {
            }
        }

        $items = self::loadStoredPackages();
        $items[] = [
            'id' => self::nextId($items),
            'name' => $name,
            'price' => number_format((float)$price, 2, '.', ''),
            'duration_days' => $durationDays,
            'benefits' => $benefits,
            'status' => $statusCode === 1 ? '上架中' : '已下架',
            'status_code' => $statusCode,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        JsonStoreService::save(self::STORE_KEY, $items);

        return self::all();
    }

    public static function merchantPackages(int $merchantId): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $items = [];
        $nonBusinessPackageIds = self::nonBusinessPackageIds();

        foreach (JsonStoreService::load(self::PURCHASE_STORE_KEY, []) as $item) {
            if (!is_array($item) || (int)($item['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }

            $packageId = (int)($item['package_id'] ?? 0);
            if (in_array($packageId, $nonBusinessPackageIds, true) || !self::isBusinessPackage($item)) {
                continue;
            }

            $endTime = (string)($item['end_time'] ?? '');
            $status = $endTime !== '' && $endTime < $now
                ? '已过期'
                : (trim((string)($item['status'] ?? '')) !== '' ? (string)$item['status'] : '生效中');

            $items[] = [
                'id' => (int)($item['id'] ?? 0),
                'package_id' => $packageId,
                'name' => self::normalizeText((string)($item['name'] ?? '')),
                'price' => number_format((float)($item['price'] ?? 0), 2, '.', ''),
                'status' => $status,
                'start_time' => (string)($item['start_time'] ?? ''),
                'end_time' => $endTime,
                'created_at' => (string)($item['created_at'] ?? ''),
                'trade_no' => (string)($item['trade_no'] ?? ''),
                'order_no' => (string)($item['order_no'] ?? ''),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });

        return $items;
    }

    public static function buy(int $merchantId, array $payload): array
    {
        if ($merchantId <= 0) {
            throw new BusinessException('商户身份无效', StatusCode::UNAUTHORIZED);
        }

        $packageId = (int)($payload['package_id'] ?? $payload['id'] ?? 0);
        $package = self::findActivePackage($packageId);
        if ($package === null) {
            throw new BusinessException('套餐不存在或未上架', StatusCode::NOT_FOUND);
        }

        $price = number_format((float)($package['price'] ?? 0), 2, '.', '');
        OrderService::assertPositiveOrderAmount($price, '套餐金额');
        $subject = OrderService::normalizeGatewayOrderSubject(
            '套餐购买 - ' . self::normalizeText((string)($package['name'] ?? '')),
            '套餐购买 - 商户套餐'
        );
        $outTradeNo = OrderService::normalizeGatewayOutTradeNo(
            self::generatePurchaseOutTradeNo($merchantId, $packageId)
        );

        if ((float)$price <= 0) {
            $grant = self::grantPackage($merchantId, $package, null, date('Y-m-d H:i:s'));

            return [
                'payment_required' => false,
                'message' => '套餐已生效',
                'record' => $grant,
                'my_packages' => self::merchantPackages($merchantId),
            ];
        }

        $balance = LocalFundStore::balanceForMerchant($merchantId);
        if ((float)($balance['available'] ?? '0.00') >= (float)$price) {
            $order = self::createBalancePurchaseOrder(
                $merchantId,
                $packageId,
                $package,
                $payload,
                $price,
                $subject,
                $outTradeNo
            );
            $completed = OrderService::completeOrder($order, [
                'source' => 'package-balance-pay',
                'paid_at' => date('Y-m-d H:i:s'),
                'txid' => (string)$order->trade_no,
                'buyer' => 'merchant-balance',
            ]);
            $tradeNo = trim((string)($completed->trade_no ?? $order->trade_no ?? ''));
            $flow = $tradeNo !== ''
                ? LocalFundStore::findFlowByReference($merchantId, 'package_purchase', $tradeNo)
                : null;
            $grant = self::merchantPackageByTradeNo($tradeNo);
            if ($grant === null) {
                throw new BusinessException('套餐购买记录未落地，请刷新后重试', StatusCode::BUSINESS_ERROR);
            }

            return [
                'payment_required' => false,
                'balance_paid' => true,
                'order_no' => (string)($grant['order_no'] ?? $tradeNo),
                'trade_no' => (string)($grant['trade_no'] ?? $tradeNo),
                'amount' => $price,
                'balance_after' => (string)($flow->balance_after ?? (LocalFundStore::balanceForMerchant($merchantId)['available'] ?? '')),
                'record' => $grant,
                'my_packages' => self::merchantPackages($merchantId),
            ];
        }

        $methodCode = SettingsService::resolveEnabledPaymentMethodCode(
            'system_checkout',
            (string)($payload['method_code'] ?? $payload['channel_code'] ?? $payload['type'] ?? '')
        );
        if ($methodCode === '') {
            throw new BusinessException('请选择后台已启用的支付方式', StatusCode::VALIDATION_ERROR);
        }

        $order = SystemBusinessPaymentService::createBusinessOrder(
            'system_checkout',
            $merchantId,
            self::BUSINESS_PACKAGE_PURCHASE,
            [
                'merchant_id' => $merchantId,
                'out_trade_no' => $outTradeNo !== '' ? $outTradeNo : SystemBusinessPaymentService::fallbackBusinessOutTradeNo('PKG', $merchantId, [$packageId]),
                'channel_code' => $methodCode,
                'channel_category' => 2,
                'force_configured_gateway' => true,
                'subject' => $subject,
                'amount' => $price,
                'notify_url' => '',
                'return_url' => '/user/funds/packages',
                'client_ip' => (string)($payload['client_ip'] ?? ''),
                'param' => 'merchant-package-purchase',
                'request_payload' => [
                    '_meta' => [
                        'business' => self::BUSINESS_PACKAGE_PURCHASE,
                        'merchant_id' => $merchantId,
                        'requested_method' => $methodCode,
                        'package_id' => $packageId,
                        'package_name' => self::normalizeText((string)($package['name'] ?? '')),
                        'duration_days' => (int)($package['duration_days'] ?? 0),
                        'benefits' => array_values((array)($package['benefits'] ?? [])),
                    ],
                ],
            ]
        );

        return [
            'payment_required' => true,
            'order_no' => (string)$order->trade_no,
            'trade_no' => (string)$order->trade_no,
            'amount' => $price,
            'pay_url' => SystemBusinessPaymentService::submitUrl((string)$order->trade_no),
            'submit_url' => SystemBusinessPaymentService::submitUrl((string)$order->trade_no),
            'checkout_url' => SystemBusinessPaymentService::checkoutUrl((string)$order->trade_no),
            'expire_time' => (string)$order->expire_time,
            'my_packages' => self::merchantPackages($merchantId),
        ];
    }

    public static function completePurchasePayment(object $order): void
    {
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
        if (($meta['business'] ?? '') !== self::BUSINESS_PACKAGE_PURCHASE) {
            return;
        }

        $tradeNo = trim((string)($order->trade_no ?? ''));
        if ($tradeNo === '' || self::merchantPackageByTradeNo($tradeNo) !== null) {
            return;
        }

        $merchantId = (int)($meta['merchant_id'] ?? $order->merchant_id ?? 0);
        $packageId = (int)($meta['package_id'] ?? 0);
        $package = self::findActivePackage($packageId);

        if ($package === null) {
            $package = [
                'id' => $packageId,
                'name' => self::normalizeText((string)($meta['package_name'] ?? '商户套餐')),
                'price' => number_format((float)($order->amount ?? 0), 2, '.', ''),
                'duration_days' => (int)($meta['duration_days'] ?? 30),
                'benefits' => array_values(array_filter(array_map('strval', (array)($meta['benefits'] ?? [])))),
            ];
        }

        self::grantPackage($merchantId, $package, $tradeNo, (string)($order->pay_time ?? date('Y-m-d H:i:s')));
    }

    public static function isBusinessPackage(mixed $package): bool
    {
        $row = self::rowFromMixed($package);
        $text = strtolower((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        foreach ([
            'test package',
            'package verification',
            'verify-openapi',
            'mock_fallback',
            '测试套餐',
            '测试购买',
            '闭环验证',
        ] as $marker) {
            $marker = strtolower($marker);
            if ($marker !== '' && str_contains($text, $marker)) {
                return false;
            }
        }

        return true;
    }

    private static function createBalancePurchaseOrder(
        int $merchantId,
        int $packageId,
        array $package,
        array $payload,
        string $price,
        string $subject,
        string $outTradeNo
    ): object {
        $requestPayload = [
            '_meta' => [
                'business' => self::BUSINESS_PACKAGE_PURCHASE,
                'merchant_id' => $merchantId,
                'requested_method' => 'balance',
                'package_id' => $packageId,
                'package_name' => self::normalizeText((string)($package['name'] ?? '')),
                'duration_days' => (int)($package['duration_days'] ?? 0),
                'benefits' => array_values((array)($package['benefits'] ?? [])),
                'method_name' => '余额支付',
                'order_amount' => $price,
                'gross_amount' => $price,
                'balance_paid' => true,
                'source_protocol' => self::BUSINESS_PACKAGE_PURCHASE,
                'order_origin' => 'system_business',
                'order_scene' => self::BUSINESS_PACKAGE_PURCHASE,
            ],
        ];

        return OrderService::persistCreatedOrder(
            OrderService::buildPendingOrderPayload([
                'trade_no' => OrderService::nextTradeNo(),
                'out_trade_no' => $outTradeNo !== '' ? $outTradeNo : SystemBusinessPaymentService::fallbackBusinessOutTradeNo('PKGB', $merchantId, [$packageId]),
                'merchant_id' => $merchantId,
                'merchant_channel_id' => 0,
                'channel_code' => 'balance',
                'channel_category' => 2,
                'subject' => $subject,
                'amount' => $price,
                'payable_amount' => $price,
                'status' => OrderService::STATUS_PENDING,
                'notify_url' => '',
                'return_url' => '/user/funds/packages',
                'client_ip' => (string)($payload['client_ip'] ?? ''),
                'param' => 'merchant-package-purchase-balance',
                'expire_time' => date('Y-m-d H:i:s', time() + OrderService::DEFAULT_EXPIRE_SECONDS),
                'request_payload' => $requestPayload,
                'notify_payload' => [],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]),
            OrderService::buildOrderCreationEventMeta($requestPayload, [
                'scene' => 'system_business',
                'business' => 'system_business',
                'order_origin' => 'system_business',
                'order_scene' => 'system_business',
            ])
        );
    }

    private static function grantPackage(int $merchantId, array $package, ?string $tradeNo, string $startTime): array
    {
        if ($merchantId <= 0) {
            throw new BusinessException('商户身份无效', StatusCode::UNAUTHORIZED);
        }

        if ($tradeNo !== null && trim($tradeNo) !== '') {
            $existing = self::merchantPackageByTradeNo($tradeNo);
            if ($existing !== null) {
                return $existing;
            }
        }

        $items = JsonStoreService::load(self::PURCHASE_STORE_KEY, []);
        $timestamp = strtotime($startTime);
        $normalizedStartTime = $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');
        $durationDays = max(1, (int)($package['duration_days'] ?? 0));
        $endTime = date('Y-m-d H:i:s', strtotime($normalizedStartTime . ' +' . $durationDays . ' days'));

        $record = [
            'id' => self::nextId($items),
            'merchant_id' => $merchantId,
            'package_id' => (int)($package['id'] ?? 0),
            'trade_no' => trim((string)($tradeNo ?? '')),
            'order_no' => trim((string)($tradeNo ?? '')) !== '' ? trim((string)$tradeNo) : self::generatePackageRecordNo(),
            'name' => self::normalizeText((string)($package['name'] ?? '')),
            'price' => number_format((float)($package['price'] ?? 0), 2, '.', ''),
            'status' => '生效中',
            'start_time' => $normalizedStartTime,
            'end_time' => $endTime,
            'benefits' => array_values((array)($package['benefits'] ?? [])),
            'created_at' => $normalizedStartTime,
        ];

        $items[] = $record;
        JsonStoreService::save(self::PURCHASE_STORE_KEY, $items);

        return $record;
    }

    private static function findActivePackage(int $packageId): ?array
    {
        foreach (self::activeBusinessPackages() as $item) {
            if ((int)($item['id'] ?? 0) === $packageId && (int)($item['status_code'] ?? 0) === 1) {
                return $item;
            }
        }

        return null;
    }

    private static function merchantPackageByTradeNo(string $tradeNo): ?array
    {
        $tradeNo = trim($tradeNo);
        if ($tradeNo === '') {
            return null;
        }

        foreach (JsonStoreService::load(self::PURCHASE_STORE_KEY, []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (trim((string)($item['trade_no'] ?? '')) === $tradeNo) {
                return $item;
            }
        }

        return null;
    }

    private static function generatePurchaseOutTradeNo(int $merchantId, int $packageId): string
    {
        return 'PKG-' . date('YmdHis') . '-' . $merchantId . '-' . $packageId . '-' . random_int(100000, 999999);
    }

    private static function generatePackageRecordNo(): string
    {
        return 'PKGREC-' . date('YmdHis') . '-' . random_int(100000, 999999);
    }

    private static function normalizeBenefits(mixed $benefits): array
    {
        if (is_array($benefits)) {
            return array_values(array_filter(
                array_map(static fn($item) => self::normalizeText((string)$item), $benefits),
                static fn(string $item): bool => $item !== ''
            ));
        }

        if (is_string($benefits)) {
            $decoded = json_decode($benefits, true);
            if (is_array($decoded)) {
                return array_values(array_filter(
                    array_map(static fn($item) => self::normalizeText((string)$item), $decoded),
                    static fn(string $item): bool => $item !== ''
                ));
            }
        }

        return array_values(array_filter(
            array_map(
                static fn($item) => self::normalizeText((string)$item),
                preg_split('/[\r\n,]+/', (string)$benefits, -1, PREG_SPLIT_NO_EMPTY) ?: []
            ),
            static fn(string $item): bool => $item !== ''
        ));
    }

    private static function normalizeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $repaired = EncodingRepairService::repair($value);
        return is_string($repaired) ? trim($repaired) : $value;
    }

    private static function nextId(array $items): int
    {
        $ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function loadStoredPackages(): array
    {
        $items = JsonStoreService::load(self::STORE_KEY, []);
        $nextId = self::nextId($items);
        $changed = false;

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                unset($items[$index]);
                $changed = true;
                continue;
            }

            if ((int)($item['id'] ?? 0) <= 0) {
                $item['id'] = $nextId++;
                $changed = true;
            }

            if (!array_key_exists('status_code', $item)) {
                $status = trim((string)($item['status'] ?? '上架中'));
                $item['status_code'] = $status === '已下架' ? 0 : 1;
                $changed = true;
            }

            $items[$index] = self::shapePackage($item);
        }

        $items = array_values($items);
        if ($changed) {
            JsonStoreService::save(self::STORE_KEY, $items);
        }

        return $items;
    }

    private static function shapePackage(array $item): array
    {
        $statusCode = (int)($item['status_code'] ?? 0) === 1 ? 1 : 0;

        return [
            'id' => (int)($item['id'] ?? 0),
            'name' => self::normalizeText((string)($item['name'] ?? '')),
            'price' => number_format((float)($item['price'] ?? 0), 2, '.', ''),
            'duration_days' => (int)($item['duration_days'] ?? 0),
            'benefits' => self::normalizeBenefits($item['benefits'] ?? []),
            'status' => $statusCode === 1 ? '上架中' : '已下架',
            'status_code' => $statusCode,
            'created_at' => (string)($item['created_at'] ?? ''),
        ];
    }

    private static function nonBusinessPackageIds(): array
    {
        $ids = [];

        foreach (self::all()['items'] as $package) {
            if (!self::isBusinessPackage($package)) {
                $ids[] = (int)($package['id'] ?? 0);
            }
        }

        return array_values(array_filter(array_unique($ids), static fn(int $id): bool => $id > 0));
    }

    private static function rowFromMixed(mixed $record): array
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
