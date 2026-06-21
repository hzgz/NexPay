<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;

class MerchantGroupService
{
    private const STORE_KEY = 'merchant_groups';

    public static function all(): array
    {
        return [
            'items' => JsonStoreService::load(self::STORE_KEY, self::defaults()),
        ];
    }

    public static function save(array $payload): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        $code = trim((string)($payload['code'] ?? ''));

        if ($name === '' || $code === '') {
            throw new BusinessException('用户组名称和标识不能为空', StatusCode::VALIDATION_ERROR);
        }

        $items = self::all()['items'];
        $id = (int)($payload['id'] ?? 0);
        $updated = false;

        foreach ($items as &$item) {
            if ((int)$item['id'] === $id && $id > 0) {
                $item['name'] = $name;
                $item['code'] = $code;
                $item['rate_discount'] = trim((string)($payload['rate_discount'] ?? $item['rate_discount']));
                $item['daily_limit'] = trim((string)($payload['daily_limit'] ?? $item['daily_limit']));
                $item['status'] = trim((string)($payload['status'] ?? $item['status']));
                $updated = true;
                break;
            }
        }
        unset($item);

        if (!$updated) {
            $items[] = [
                'id' => self::nextId($items),
                'name' => $name,
                'code' => $code,
                'rate_discount' => trim((string)($payload['rate_discount'] ?? '默认费率')),
                'daily_limit' => trim((string)($payload['daily_limit'] ?? '100000')),
                'status' => trim((string)($payload['status'] ?? '启用')),
            ];
        }

        JsonStoreService::save(self::STORE_KEY, $items);
        return ['items' => $items];
    }

    public static function delete(int $id): array
    {
        $items = self::all()['items'];
        $next = array_values(array_filter($items, static fn(array $item): bool => (int)$item['id'] !== $id));

        if (count($next) === count($items)) {
            throw new BusinessException('用户组不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::STORE_KEY, $next);
        return ['items' => $next];
    }

    private static function nextId(array $items): int
    {
        $ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function defaults(): array
    {
        return [
            ['id' => 1, 'name' => '基础组', 'code' => 'basic', 'rate_discount' => '默认费率', 'daily_limit' => '100000', 'status' => '启用'],
            ['id' => 2, 'name' => '专业组', 'code' => 'pro', 'rate_discount' => '0.78%', 'daily_limit' => '500000', 'status' => '启用'],
            ['id' => 3, 'name' => '风控观察组', 'code' => 'risk-watch', 'rate_discount' => '单独审核', 'daily_limit' => '50000', 'status' => '停用'],
        ];
    }
}
