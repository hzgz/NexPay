<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use Webman\Http\UploadFile;

class AdminAvatarUploadService
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

    public static function upload(int $adminId, ?UploadFile $file): array
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员信息不存在', StatusCode::VALIDATION_ERROR);
        }

        $basic = SettingsService::all(false)['basic'] ?? [];
        if (empty($basic['allow_avatar_upload'])) {
            throw new BusinessException('当前系统未开启头像上传', StatusCode::VALIDATION_ERROR);
        }

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请先选择头像图片', StatusCode::VALIDATION_ERROR);
        }

        self::assertFile($file);
        $saved = self::storeFile($adminId, $file);
        $fileUrl = self::publicFileUrl($saved['relative_path']);
        $fileRow = self::fileRow($adminId, $saved, $fileUrl);
        FileService::appendItem($fileRow);
        $user = AccountService::saveAdminProfile($adminId, [
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
        $extension = strtolower((string) $file->getUploadExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new BusinessException('仅支持 jpg、jpeg、png、gif、webp、bmp 图片', StatusCode::VALIDATION_ERROR);
        }

        $mimeType = strtolower((string) $file->getUploadMimeType());
        if ($mimeType !== '' && !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new BusinessException('上传文件类型不正确', StatusCode::VALIDATION_ERROR);
        }

        $size = (int) ($file->getSize() ?: 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new BusinessException('头像图片大小不能超过 4MB', StatusCode::VALIDATION_ERROR);
        }
    }

    private static function storeFile(int $adminId, UploadFile $file): array
    {
        $extension = strtolower((string) $file->getUploadExtension()) ?: 'png';
        $datePath = date('Ymd');
        $basename = 'admin-avatar-'
            . $adminId
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
            'size_bytes' => (int) (@filesize($absolutePath) ?: 0),
            'mime_type' => strtolower((string) $file->getUploadMimeType()),
        ];
    }

    private static function publicFileUrl(string $relativePath): string
    {
        return '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    }

    private static function fileRow(int $adminId, array $saved, string $fileUrl): array
    {
        $profile = AccountService::adminProfile($adminId);
        $displayName = trim((string) ($profile['nickname'] ?? $profile['username'] ?? ''));

        return [
            'id' => FileService::nextId(),
            'merchant_id' => 0,
            'admin_id' => $adminId,
            'category' => 'avatar',
            'group_name' => '账户头像',
            'file_name' => (string) $saved['file_name'],
            'file_url' => $fileUrl,
            'mime_type' => (string) $saved['mime_type'],
            'size_bytes' => (int) $saved['size_bytes'],
            'uploaded_at' => date('Y-m-d H:i:s'),
            'remark' => $displayName !== '' ? $displayName . ' 头像上传' : '管理员头像上传',
        ];
    }
}
