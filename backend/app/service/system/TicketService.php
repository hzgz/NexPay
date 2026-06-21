<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;

/**
 * Shared ticket storage for admin and merchant workflows.
 */
class TicketService
{
    private const CATEGORY_STORE_KEY = 'ticket_categories';
    private const TICKET_STORE_KEY = 'tickets';

    public static function adminData(): array
    {
        return [
            'categories' => self::categories(),
            'items' => self::tickets(),
        ];
    }

    public static function merchantData(int $merchantId): array
    {
        return [
            'categories' => self::categories(),
            'items' => array_values(array_filter(
                self::tickets(),
                static fn(array $item): bool => (int)($item['merchant_id'] ?? 0) === $merchantId
            )),
        ];
    }

    public static function saveCategory(array $payload): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new BusinessException('工单分类名称不能为空', StatusCode::VALIDATION_ERROR);
        }

        $items = self::categories();
        $id = (int)($payload['id'] ?? 0);
        $updated = false;

        foreach ($items as &$item) {
            if ((int)$item['id'] === $id && $id > 0) {
                $item['name'] = $name;
                $item['status'] = trim((string)($payload['status'] ?? $item['status']));
                $item['description'] = trim((string)($payload['description'] ?? $item['description']));
                $updated = true;
                break;
            }
        }
        unset($item);

        if (!$updated) {
            $items[] = [
                'id' => self::nextId($items),
                'name' => $name,
                'status' => trim((string)($payload['status'] ?? '启用')),
                'description' => trim((string)($payload['description'] ?? '')),
            ];
        }

        JsonStoreService::save(self::CATEGORY_STORE_KEY, $items);
        return ['items' => $items];
    }

    public static function deleteCategory(int $id): array
    {
        $items = self::categories();
        $next = array_values(array_filter($items, static fn(array $item): bool => (int)$item['id'] !== $id));

        if (count($next) === count($items)) {
            throw new BusinessException('工单分类不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::CATEGORY_STORE_KEY, $next);
        return ['items' => $next];
    }

    public static function createTicket(int $merchantId, string $merchantName, array $payload): array
    {
        $title = trim((string)($payload['title'] ?? ''));
        $content = trim((string)($payload['content'] ?? ''));

        if ($title === '' || $content === '') {
            throw new BusinessException('工单标题和内容不能为空', StatusCode::VALIDATION_ERROR);
        }

        $categoryId = (int)($payload['category_id'] ?? 0);
        $categoryName = '未分类';
        foreach (self::categories() as $category) {
            if ((int)$category['id'] === $categoryId) {
                $categoryName = (string)$category['name'];
                break;
            }
        }

        $items = self::tickets();
        $items[] = [
            'id' => self::nextId($items),
            'ticket_no' => 'TK' . date('YmdHis'),
            'merchant_id' => $merchantId,
            'merchant_name' => $merchantName,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'title' => $title,
            'content' => $content,
            'priority' => trim((string)($payload['priority'] ?? '普通')),
            'status' => '待处理',
            'reply' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        JsonStoreService::save(self::TICKET_STORE_KEY, $items);
        return self::merchantData($merchantId);
    }

    public static function updateTicket(array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        $items = self::tickets();
        $found = false;

        foreach ($items as &$item) {
            if ((int)$item['id'] === $id) {
                if (isset($payload['status'])) {
                    $item['status'] = trim((string)$payload['status']);
                }
                if (isset($payload['priority'])) {
                    $item['priority'] = trim((string)$payload['priority']);
                }
                if (isset($payload['reply'])) {
                    $item['reply'] = trim((string)$payload['reply']);
                }
                $item['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            throw new BusinessException('工单不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::TICKET_STORE_KEY, $items);
        return ['items' => $items];
    }

    public static function createAdminTicket(array $payload): array
    {
        $merchantId = (int)($payload['merchant_id'] ?? 0);
        $merchantName = trim((string)($payload['merchant_name'] ?? ''));
        $title = trim((string)($payload['title'] ?? ''));
        $content = trim((string)($payload['content'] ?? ''));
        $priority = trim((string)($payload['priority'] ?? '普通'));
        $categoryId = (int)($payload['category_id'] ?? 0);

        if ($merchantId <= 0 || $merchantName === '' || $title === '' || $content === '') {
            throw new BusinessException('请完整填写工单信息', StatusCode::VALIDATION_ERROR);
        }

        $categoryName = '未分类';
        foreach (self::categories() as $category) {
            if ((int)($category['id'] ?? 0) === $categoryId) {
                $categoryName = (string)($category['name'] ?? '未分类');
                break;
            }
        }

        $items = self::tickets();
        $items[] = [
            'id' => self::nextId($items),
            'ticket_no' => 'TK' . date('YmdHis') . random_int(10, 99),
            'merchant_id' => $merchantId,
            'merchant_name' => $merchantName,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'title' => $title,
            'content' => $content,
            'priority' => $priority,
            'status' => '待处理',
            'reply' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        JsonStoreService::save(self::TICKET_STORE_KEY, $items);
        return self::adminData();
    }

    private static function categories(): array
    {
        return JsonStoreService::load(self::CATEGORY_STORE_KEY, self::defaultCategories());
    }

    private static function tickets(): array
    {
        return array_values(array_filter(
            JsonStoreService::load(self::TICKET_STORE_KEY, []),
            static fn(array $item): bool => !self::isSeedTicket($item)
        ));
    }

    private static function nextId(array $items): int
    {
        $ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function defaultCategories(): array
    {
        return [
            ['id' => 1, 'name' => '通道异常', 'status' => '启用', 'description' => '支付回调、到账异常、通道不可用'],
            ['id' => 2, 'name' => '套餐与计费', 'status' => '启用', 'description' => '套餐购买、续费、费率问题'],
            ['id' => 3, 'name' => '资料审核', 'status' => '启用', 'description' => '实名认证、资质审核、账号资料'],
        ];
    }

    private static function isSeedTicket(array $item): bool
    {
        $ticketNo = (string)($item['ticket_no'] ?? '');
        $title = (string)($item['title'] ?? '');

        return in_array($ticketNo, ['TK20260611001', 'TK20260609004', 'TK20260611002'], true)
            && in_array($title, ['TRC20 到账回调延迟', '申请上调单日限额', '企业资质补件'], true);
    }
}
