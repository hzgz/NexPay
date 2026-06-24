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
    private const CATEGORY_LABELS = [
        'avatar' => '账户头像',
        'merchant_avatar' => '账户头像',
        'channel_qrcode' => '通道二维码',
        'qrcode' => '通道二维码',
        'channel_config' => '通道配置文件',
        'config_file' => '通道配置文件',
    ];

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
        $items[] = self::normalizeItem($item);
        JsonStoreService::save(self::STORE_KEY, array_values($items));
        return end($items) ?: [];
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
        $items = array_values(array_filter(
            JsonStoreService::load(self::STORE_KEY, []),
            static fn(array $item): bool => !self::isSeedFile($item)
        ));

        return array_map(static fn(array $item): array => self::normalizeItem($item), $items);
    }

    private static function isSeedFile(array $item): bool
    {
        $fileName = (string)($item['file_name'] ?? '');
        $uploadedAt = (string)($item['uploaded_at'] ?? '');

        return in_array($fileName, ['alipay_qrcode_001.png', 'business_license.pdf', 'wechat_qrcode_store.png'], true)
            && str_starts_with($uploadedAt, '2026-06-');
    }

    private static function normalizeItem(array $item): array
    {
        $normalized = $item;
        $category = trim((string)($normalized['category'] ?? ''));
        $groupName = trim((string)($normalized['group_name'] ?? ''));
        $fileName = trim((string)($normalized['file_name'] ?? ''));
        $fileUrl = trim((string)($normalized['file_url'] ?? ''));
        $merchantId = (int)($normalized['merchant_id'] ?? 0);

        $normalized['category'] = self::categoryLabel($category, $groupName);
        $normalized['category_label'] = $normalized['category'];

        if (trim((string)($normalized['status'] ?? '')) === '') {
            $normalized['status'] = '已上传';
        }

        if (trim((string)($normalized['size'] ?? '')) === '') {
            $normalized['size'] = self::resolveSizeLabel($normalized, $fileUrl);
        }

        if (trim((string)($normalized['preview_text'] ?? '')) === '') {
            $normalized['preview_text'] = self::previewText($normalized['category']);
        }

        if (trim((string)($normalized['merchant_name'] ?? '')) === '' && $merchantId > 0) {
            $normalized['merchant_name'] = self::merchantName($merchantId);
        }

        if (trim((string)($normalized['remark'] ?? '')) === '' && $normalized['category'] === '账户头像') {
            $normalized['remark'] = '商户头像上传';
        }

        if ($fileName !== '' && trim((string)($normalized['mime_type'] ?? '')) === '') {
            $normalized['mime_type'] = self::detectMimeType($fileName, $fileUrl);
        }

        return $normalized;
    }

    private static function categoryLabel(string $category, string $groupName): string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($category)));
        if ($normalized !== '' && isset(self::CATEGORY_LABELS[$normalized])) {
            return self::CATEGORY_LABELS[$normalized];
        }

        if ($groupName !== '') {
            return $groupName;
        }

        return $category !== '' ? $category : '未分类';
    }

    private static function resolveSizeLabel(array $item, string $fileUrl): string
    {
        $bytes = (int)($item['size_bytes'] ?? 0);
        if ($bytes <= 0 && $fileUrl !== '') {
            $relativePath = ltrim(parse_url($fileUrl, PHP_URL_PATH) ?: '', '/');
            if ($relativePath !== '') {
                $absolutePath = public_path(str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
                if (is_file($absolutePath)) {
                    $bytes = (int)(@filesize($absolutePath) ?: 0);
                }
            }
        }

        return self::formatSize($bytes);
    }

    private static function previewText(string $category): string
    {
        return match ($category) {
            '账户头像' => '商户头像上传文件',
            '通道二维码' => '商户通道二维码配置上传文件',
            '通道配置文件' => '商户通道配置文件上传记录。',
            default => '查看文件详情与预览内容。',
        };
    }

    private static function merchantName(int $merchantId): string
    {
        $profile = AccountService::merchantCredentialById($merchantId);
        if (is_array($profile)) {
            $name = trim((string)($profile['merchant_name'] ?? $profile['nickname'] ?? $profile['username'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '商户' . $merchantId;
    }

    private static function detectMimeType(string $fileName, string $fileUrl): string
    {
        $target = strtolower($fileName . ' ' . $fileUrl);

        return match (true) {
            preg_match('/\.(jpg|jpeg)(\?.*)?$/', $target) === 1 => 'image/jpeg',
            preg_match('/\.(png)(\?.*)?$/', $target) === 1 => 'image/png',
            preg_match('/\.(gif)(\?.*)?$/', $target) === 1 => 'image/gif',
            preg_match('/\.(webp)(\?.*)?$/', $target) === 1 => 'image/webp',
            preg_match('/\.(bmp)(\?.*)?$/', $target) === 1 => 'image/bmp',
            preg_match('/\.(svg)(\?.*)?$/', $target) === 1 => 'image/svg+xml',
            default => '',
        };
    }

    private static function formatSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '-';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2, '.', '') . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2, '.', '') . ' KB';
        }

        return $bytes . ' B';
    }
}
