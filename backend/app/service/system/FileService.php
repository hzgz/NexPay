<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;

/**
 * Lightweight file metadata management for admin and merchant pages.
 */
class FileService
{
    private const STORE_KEY = 'files';

    public static function adminList(): array
    {
        return ['items' => self::items()];
    }

    public static function merchantList(int $merchantId): array
    {
        return [
            'items' => array_values(array_filter(
                self::items(),
                static fn(array $item): bool => (int)($item['merchant_id'] ?? 0) === $merchantId
            )),
        ];
    }

    public static function deleteForAdmin(int $id): array
    {
        return ['items' => self::deleteById($id)];
    }

    public static function deleteForMerchant(int $merchantId, int $id): array
    {
        $target = null;
        foreach (self::items() as $item) {
            if ((int)$item['id'] === $id) {
                $target = $item;
                break;
            }
        }

        if ($target === null || (int)$target['merchant_id'] !== $merchantId) {
            throw new BusinessException('文件不存在或无权操作', StatusCode::NOT_FOUND);
        }

        return ['items' => self::deleteById($id)];
    }

    public static function appendItem(array $item): array
    {
        $items = self::items();
        $items[] = $item;
        JsonStoreService::save(self::STORE_KEY, array_values($items));
        return $item;
    }

    public static function nextId(): int
    {
        $max = 0;
        foreach (self::items() as $item) {
            $max = max($max, (int)($item['id'] ?? 0));
        }

        return $max + 1;
    }

    private static function deleteById(int $id): array
    {
        $items = self::items();
        $next = array_values(array_filter($items, static fn(array $item): bool => (int)$item['id'] !== $id));

        if (count($next) === count($items)) {
            throw new BusinessException('文件不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::STORE_KEY, $next);
        return $next;
    }

    private static function items(): array
    {
        return array_values(array_filter(
            JsonStoreService::load(self::STORE_KEY, []),
            static fn(array $item): bool => !self::isSeedFile($item)
        ));
    }

    private static function isSeedFile(array $item): bool
    {
        $fileName = (string)($item['file_name'] ?? '');
        $uploadedAt = (string)($item['uploaded_at'] ?? '');

        return in_array($fileName, ['alipay_qrcode_001.png', 'business_license.pdf', 'wechat_qrcode_store.png'], true)
            && str_starts_with($uploadedAt, '2026-06-');
    }
}
