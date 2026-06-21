<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;

/**
 * Homepage and merchant-center announcements managed by admin settings.
 */
class AnnouncementService
{
    private const STORE_KEY = 'announcements';

    public static function all(): array
    {
        return [
            'items' => JsonStoreService::load(self::STORE_KEY, []),
        ];
    }

    public static function visible(?string $target = null, int $limit = 10): array
    {
        $items = array_values(array_filter(self::all()['items'], static function (array $item) use ($target): bool {
            $itemTarget = (string)($item['target'] ?? 'both');
            $statusCode = (int)($item['status_code'] ?? 0);

            if ($statusCode !== 1) {
                return false;
            }

            if ($target === null) {
                return true;
            }

            return $itemTarget === 'both' || $itemTarget === $target;
        }));

        usort($items, static function (array $left, array $right): int {
            return [(int)$left['sort'], -(int)$left['id']] <=> [(int)$right['sort'], -(int)$right['id']];
        });

        return array_slice($items, 0, max(1, $limit));
    }

    public static function save(array $payload): array
    {
        $title = trim((string)($payload['title'] ?? ''));
        $summary = trim((string)($payload['summary'] ?? ''));
        $content = trim((string)($payload['content'] ?? $summary));
        $target = trim((string)($payload['target'] ?? 'both'));
        $sort = (int)($payload['sort'] ?? 99);

        if ($title === '' || $summary === '') {
            throw new BusinessException('公告标题和摘要不能为空', StatusCode::VALIDATION_ERROR);
        }

        if (!in_array($target, ['home', 'merchant', 'both'], true)) {
            $target = 'both';
        }

        $items = self::all()['items'];
        $id = (int)($payload['id'] ?? 0);
        $updated = false;

        foreach ($items as &$item) {
            if ((int)$item['id'] === $id && $id > 0) {
                $item['title'] = $title;
                $item['summary'] = $summary;
                $item['content'] = $content;
                $item['target'] = $target;
                $item['sort'] = $sort;
                $updated = true;
                break;
            }
        }
        unset($item);

        if (!$updated) {
            $items[] = [
                'id' => self::nextId($items),
                'title' => $title,
                'summary' => $summary,
                'content' => $content,
                'target' => $target,
                'sort' => $sort,
                'status' => '启用',
                'status_code' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }

        JsonStoreService::save(self::STORE_KEY, $items);
        return ['items' => $items];
    }

    public static function toggle(int $id, int $statusCode): array
    {
        $items = self::all()['items'];
        $found = false;

        foreach ($items as &$item) {
            if ((int)$item['id'] === $id) {
                $item['status_code'] = $statusCode === 1 ? 1 : 0;
                $item['status'] = $item['status_code'] === 1 ? '启用' : '停用';
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            throw new BusinessException('公告不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::STORE_KEY, $items);
        return ['items' => $items];
    }

    public static function delete(int $id): array
    {
        $items = self::all()['items'];
        $next = array_values(array_filter($items, static fn(array $item): bool => (int)$item['id'] !== $id));

        if (count($items) === count($next)) {
            throw new BusinessException('公告不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::STORE_KEY, $next);
        return ['items' => $next];
    }

    private static function nextId(array $items): int
    {
        $ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items);
        return ($ids ? max($ids) : 0) + 1;
    }

}
