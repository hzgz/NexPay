<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\QrCodeService;
use Webman\Http\UploadFile;

class MerchantChannelQrConfigService
{
    private const STORE_DIR = 'upload/channel-qrcode';
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
    ];
    private const MAX_FILE_SIZE = 8 * 1024 * 1024;

    public static function upload(
        int $merchantId,
        array $payload,
        ?UploadFile $file
    ): array {
        if ($merchantId <= 0) {
            throw new BusinessException('商户信息不存在', StatusCode::VALIDATION_ERROR);
        }

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请先选择二维码图片', StatusCode::VALIDATION_ERROR);
        }

        $pluginCode = PluginCodeService::normalize((string)($payload['plugin_code'] ?? ''));
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($payload['method_code'] ?? ''));
        $fieldKey = trim((string)($payload['field_key'] ?? ''));
        $pluginConfig = self::normalizePluginConfig($payload['plugin_config'] ?? []);

        if ($pluginCode === '' || $methodCode === '' || $fieldKey === '') {
            throw new BusinessException('缺少插件、支付方式或配置字段信息', StatusCode::VALIDATION_ERROR);
        }

        self::assertSupportedField($pluginCode, $fieldKey);
        self::assertFile($file);

        $saved = self::storeFile($merchantId, $pluginCode, $fieldKey, $file);
        $fileUrl = self::publicFileUrl($saved['relative_path']);

        $pluginConfig[$fieldKey] = $fileUrl;

        $result = [
            'field_key' => $fieldKey,
            'file_url' => $fileUrl,
            'resolved_content' => '',
            'resolved_source' => '',
            'store_mode' => 'image',
            'plugin_config' => $pluginConfig,
            'file' => self::fileRow($merchantId, $pluginCode, $fieldKey, $saved),
        ];

        if ($pluginCode === 'alipay-qrcode' && $fieldKey === 'qrcode_image') {
            $resolved = self::decodeImage($saved['absolute_path'], $fileUrl);
            $pluginConfig['qrcode_image'] = $fileUrl;
            $pluginConfig['payment_address'] = $resolved;
            $pluginConfig['display_value'] = $resolved;
            $pluginConfig['qrcode_url'] = $resolved;
            $pluginConfig['resolved_qrcode_content'] = $resolved;
            $pluginConfig['resolved_qrcode_source'] = 'decode';

            $result['resolved_content'] = $resolved;
            $result['resolved_source'] = 'decode';
            $result['store_mode'] = 'decoded_link';
            $result['plugin_config'] = $pluginConfig;
        } elseif ($pluginCode === 'wechat-qrcode' && $fieldKey === 'qrcode_image') {
            $resolved = self::decodeImage($saved['absolute_path'], $fileUrl);
            $pluginConfig['qrcode_image'] = $fileUrl;
            $pluginConfig['payment_address'] = $resolved;
            $pluginConfig['display_value'] = $resolved;
            $pluginConfig['qrcode_url'] = $resolved;
            $pluginConfig['resolved_qrcode_content'] = $resolved;
            $pluginConfig['resolved_qrcode_source'] = 'decode';

            $result['resolved_content'] = $resolved;
            $result['resolved_source'] = 'decode';
            $result['store_mode'] = 'decoded_link';
            $result['plugin_config'] = $pluginConfig;
        } elseif ($pluginCode === 'wechat-qrcode' && $fieldKey === 'appreciate_image') {
            $pluginConfig['appreciate_image'] = $fileUrl;
            $pluginConfig['appreciate_qrcode_url'] = $fileUrl;
            $pluginConfig['resolved_qrcode_source'] = 'image';

            $result['resolved_content'] = $fileUrl;
            $result['resolved_source'] = 'image';
            $result['plugin_config'] = $pluginConfig;
        } elseif ($pluginCode === 'alipay-ck' && $fieldKey === 'qrcode_image') {
            $resolved = self::decodeImage($saved['absolute_path'], $fileUrl);
            $pluginConfig['qrcode_image'] = $fileUrl;
            $pluginConfig['payment_address'] = $resolved;
            $pluginConfig['display_value'] = $resolved;
            $pluginConfig['qrcode_url'] = $resolved;
            $pluginConfig['resolved_qrcode_content'] = $resolved;
            $pluginConfig['resolved_qrcode_source'] = 'decode';

            $result['resolved_content'] = $resolved;
            $result['resolved_source'] = 'decode';
            $result['store_mode'] = 'decoded_link';
            $result['plugin_config'] = $pluginConfig;
        }

        FileService::appendItem($result['file']);

        return $result;
    }

    private static function normalizePluginConfig(mixed $payload): array
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($payload) ? $payload : [];
    }

    private static function assertSupportedField(string $pluginCode, string $fieldKey): void
    {
        $allowed = match ($pluginCode) {
            'alipay-qrcode' => ['qrcode_image'],
            'wechat-qrcode' => ['qrcode_image', 'appreciate_image'],
            'alipay-ck' => ['qrcode_image'],
            default => [],
        };

        if (!in_array($fieldKey, $allowed, true)) {
            throw new BusinessException('当前插件字段不支持上传图片', StatusCode::VALIDATION_ERROR);
        }
    }

    private static function assertFile(UploadFile $file): void
    {
        $extension = strtolower($file->getUploadExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new BusinessException('仅支持 jpg、jpeg、png、gif、webp、bmp 图片', StatusCode::VALIDATION_ERROR);
        }

        $mimeType = strtolower((string)$file->getUploadMimeType());
        if ($mimeType !== '' && !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new BusinessException('上传文件类型不正确', StatusCode::VALIDATION_ERROR);
        }

        $size = (int)($file->getSize() ?: 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new BusinessException('图片大小不能超过 8MB', StatusCode::VALIDATION_ERROR);
        }
    }

    private static function storeFile(int $merchantId, string $pluginCode, string $fieldKey, UploadFile $file): array
    {
        $extension = strtolower($file->getUploadExtension()) ?: 'png';
        $datePath = date('Ymd');
        $basename = $merchantId
            . '-' . str_replace(['-', '_'], '', $pluginCode)
            . '-' . $fieldKey
            . '-' . date('His')
            . '-' . substr(bin2hex(random_bytes(6)), 0, 12)
            . '.' . $extension;

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

    private static function decodeImage(string $absolutePath, string $fallbackUrl): string
    {
        if (!is_file($absolutePath)) {
            throw new BusinessException('二维码图片保存失败', StatusCode::BUSINESS_ERROR);
        }

        $bytes = (string)file_get_contents($absolutePath);
        if ($bytes === '') {
            throw new BusinessException('二维码图片读取失败', StatusCode::BUSINESS_ERROR);
        }

        try {
            $decoded = self::invokeQrDecode($bytes);
        } catch (\Throwable $exception) {
            throw new BusinessException('二维码解析失败：' . $exception->getMessage(), StatusCode::BUSINESS_ERROR);
        }

        $decoded = trim($decoded);
        if ($decoded === '') {
            throw new BusinessException('二维码解析失败，请更换更清晰的图片', StatusCode::BUSINESS_ERROR);
        }

        return $decoded ?: $fallbackUrl;
    }

    private static function invokeQrDecode(string $bytes): string
    {
        $ref = new \ReflectionClass(QrCodeService::class);
        $method = $ref->getMethod('decodeQrCodeBinary');
        $method->setAccessible(true);
        return (string)$method->invoke(null, $bytes, null);
    }

    private static function fileRow(int $merchantId, string $pluginCode, string $fieldKey, array $saved): array
    {
        return [
            'id' => FileService::nextId(),
            'merchant_id' => $merchantId,
            'merchant_name' => self::merchantName($merchantId),
            'file_name' => (string)$saved['file_name'],
            'category' => '通道二维码',
            'size' => self::formatSize((int)$saved['size_bytes']),
            'status' => '已上传',
            'uploaded_at' => date('Y-m-d H:i:s'),
            'remark' => $pluginCode . ' / ' . $fieldKey,
            'preview_text' => '商户通道二维码配置上传文件',
            'file_url' => self::publicFileUrl((string)$saved['relative_path']),
            'mime_type' => (string)($saved['mime_type'] ?? ''),
        ];
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

    private static function formatSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2, '.', '') . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2, '.', '') . ' KB';
        }

        return $bytes . ' B';
    }
}
