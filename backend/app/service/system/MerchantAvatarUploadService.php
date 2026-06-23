<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use Webman\Http\UploadFile;

class MerchantAvatarUploadService
{
    private const STORE_DIR = 'upload/avatar';
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
    ];
    private const MAX_FILE_SIZE = 4 * 1024 * 1024;

    public static function upload(int $merchantId, ?UploadFile $file): array
    {
        if ($merchantId <= 0) {
            throw new BusinessException('商户信息不存在', StatusCode::VALIDATION_ERROR);
        }

        $basic = SettingsService::all(false)['basic'] ?? [];
        if (empty($basic['allow_avatar_upload'])) {
            throw new BusinessException('当前系统未开启头像上传', StatusCode::VALIDATION_ERROR);
        }

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请先选择头像图片', StatusCode::VALIDATION_ERROR);
        }

        self::assertFile($file);
        $credential = AccountService::merchantCredentialById($merchantId);
        if (!is_array($credential)) {
            throw new BusinessException('商户信息不存在', StatusCode::NOT_FOUND);
        }
        $saved = self::storeFile($merchantId, $file);
        $fileUrl = self::publicFileUrl($saved['relative_path']);
        $fileRow = self::fileRow($merchantId, $saved, $fileUrl);
        FileService::appendItem($fileRow);
        $user = AccountService::saveMerchantProfile((int)($credential['id'] ?? $merchantId), [
            'avatar' => $fileUrl,
        ]);

        return [
            'avatar' => $fileUrl,
            'file_url' => $fileUrl,
            'file' => $fileRow,
            'user' => $user,
        ];
    }

    private static function assertFile(UploadFile $file): void
    {
        $extension = strtolower((string)$file->getUploadExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new BusinessException('仅支持 jpg、jpeg、png、gif、webp、bmp 图片', StatusCode::VALIDATION_ERROR);
        }

        $mimeType = strtolower((string)$file->getUploadMimeType());
        if ($mimeType !== '' && !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new BusinessException('上传文件类型不正确', StatusCode::VALIDATION_ERROR);
        }

        $size = (int)($file->getSize() ?: 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new BusinessException('头像图片大小不能超过 4MB', StatusCode::VALIDATION_ERROR);
        }
    }

    private static function storeFile(int $merchantId, UploadFile $file): array
    {
        $extension = strtolower((string)$file->getUploadExtension()) ?: 'png';
        $datePath = date('Ymd');
        $basename = 'merchant-avatar-'
            . $merchantId
            . '-'
            . date('His')
            . '-'
            . substr(bin2hex(random_bytes(6)), 0, 12)
            . '.'
            . $extension;

        $relativeDir = self::STORE_DIR . '/' . $datePath;
        $relativePath = $relativeDir . '/' . $basename;
        $absolutePath = public_path($relativePath);

        $file->move($absolutePath);

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'file_name' => $basename,
            'size_bytes' => (int)(@filesize($absolutePath) ?: 0),
            'mime_type' => strtolower((string)$file->getUploadMimeType()),
        ];
    }

    private static function publicFileUrl(string $relativePath): string
    {
        return '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    }

    private static function fileRow(int $merchantId, array $saved, string $fileUrl): array
    {
        $profile = AccountService::merchantCredentialById($merchantId);
        $merchantName = '';
        if (is_array($profile)) {
            $merchantName = trim((string)($profile['merchant_name'] ?? $profile['nickname'] ?? $profile['username'] ?? ''));
        }

        return [
            'id' => FileService::nextId(),
            'merchant_id' => $merchantId,
            'category' => 'avatar',
            'group_name' => '账户头像',
            'file_name' => (string)$saved['file_name'],
            'file_url' => $fileUrl,
            'mime_type' => (string)$saved['mime_type'],
            'size_bytes' => (int)$saved['size_bytes'],
            'uploaded_at' => date('Y-m-d H:i:s'),
            'remark' => $merchantName !== '' ? $merchantName . ' 头像上传' : '商户头像上传',
        ];
    }
}
