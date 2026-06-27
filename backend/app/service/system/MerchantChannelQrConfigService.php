<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\QrCodeService;
use Webman\Http\UploadFile;

class MerchantChannelQrConfigService
{
    private const IMAGE_STORE_DIR = 'upload/channel-qrcode';
    private const FILE_STORE_DIR = 'upload/channel-config';
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    private const DEFAULT_FILE_EXTENSIONS = ['crt', 'cer', 'pem', 'key', 'pfx', 'p12', 'txt'];
    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
    ];
    private const MAX_IMAGE_SIZE = 8 * 1024 * 1024;
    private const MAX_FILE_SIZE = 12 * 1024 * 1024;

    public static function upload(
        int $merchantId,
        array $payload,
        ?UploadFile $file
    ): array {
        $channelId = (int)($payload['id'] ?? 0);
        if ($merchantId <= 0) {
            throw new BusinessException('商户信息不存在', StatusCode::VALIDATION_ERROR);
        }

        if (!$file || !$file->isValid()) {
            throw new BusinessException('请先选择二维码图片', StatusCode::VALIDATION_ERROR);
        }

        $pluginCode = PluginCodeService::normalize((string)($payload['plugin_code'] ?? ''));
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($payload['method_code'] ?? ''));
        $fieldKey = trim((string)($payload['field_key'] ?? ''));
        $existingChannel = $channelId > 0 ? MerchantChannelService::findItem($merchantId, $channelId) : null;
        $existingPluginConfig = is_array($existingChannel['plugin_config'] ?? null) ? $existingChannel['plugin_config'] : [];
        $pluginConfig = array_replace(
            $existingPluginConfig,
            self::normalizePluginConfig($payload['plugin_config'] ?? [])
        );

        if ($pluginCode === '' || $methodCode === '' || $fieldKey === '') {
            throw new BusinessException('缺少插件、支付方式或配置字段信息', StatusCode::VALIDATION_ERROR);
        }

        $schemaField = self::schemaField($pluginCode, $fieldKey);
        self::assertSupportedField($schemaField);
        self::assertFile($file, $schemaField);

        $fieldType = self::fieldType($schemaField);
        $saved = self::storeFile($merchantId, $pluginCode, $fieldKey, $file, $fieldType);
        $fileUrl = self::publicFileUrl($saved['relative_path']);

        $pluginConfig[$fieldKey] = $fileUrl;

        $result = [
            'field_key' => $fieldKey,
            'file_url' => $fileUrl,
            'resolved_content' => '',
            'resolved_source' => '',
            'store_mode' => $fieldType,
            'plugin_config' => $pluginConfig,
            'file' => self::fileRow($merchantId, $pluginCode, $fieldKey, $saved, $fieldType),
        ];

        $configPanel = trim((string)((PluginRuntimeService::discoverMap()[$pluginCode]['config_panel'] ?? 'generic')));

        if ($configPanel === 'qrcode_upload' && $fieldKey === 'qrcode_image') {
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
            if ($pluginCode === 'wechat-qrcode') {
                $pluginConfig['appreciate_image'] = '';
                $pluginConfig['appreciate_qrcode_url'] = '';
                $result['plugin_config'] = $pluginConfig;
            }
        } elseif (($pluginCode === 'wechat-qrcode' || $configPanel === 'fixed_image') && $fieldKey === 'appreciate_image') {
            $pluginConfig['appreciate_image'] = $fileUrl;
            $pluginConfig['appreciate_qrcode_url'] = $fileUrl;
            $pluginConfig['payment_address'] = '';
            $pluginConfig['display_value'] = '';
            $pluginConfig['qrcode_url'] = '';
            $pluginConfig['qrcode_image'] = '';
            $pluginConfig['resolved_qrcode_content'] = '';
            $pluginConfig['resolved_qrcode_source'] = 'image';

            $result['resolved_content'] = $fileUrl;
            $result['resolved_source'] = 'image';
            $result['plugin_config'] = $pluginConfig;
        }

        FileService::appendItem($result['file']);

        if ($channelId > 0 && is_array($existingChannel)) {
            $savedChannels = MerchantChannelService::saveItem($merchantId, [
                'id' => $channelId,
                'plugin_config' => $pluginConfig,
            ]);

            foreach (($savedChannels['items'] ?? []) as $item) {
                if (!is_array($item) || (int)($item['id'] ?? 0) !== $channelId) {
                    continue;
                }

                $result['plugin_config'] = is_array($item['plugin_config'] ?? null)
                    ? $item['plugin_config']
                    : $pluginConfig;
                break;
            }
        }

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

    private static function schemaField(string $pluginCode, string $fieldKey): array
    {
        $definitions = PluginRuntimeService::discoverMap();
        $schema = is_array($definitions[$pluginCode]['settings_schema'] ?? null)
            ? $definitions[$pluginCode]['settings_schema']
            : [];

        foreach ($schema as $field) {
            if (!is_array($field)) {
                continue;
            }

            if (trim((string)($field['key'] ?? '')) !== $fieldKey) {
                continue;
            }

            return $field;
        }

        throw new BusinessException('当前插件字段不存在或未声明上传配置', StatusCode::VALIDATION_ERROR);
    }

    private static function assertSupportedField(array $field): void
    {
        $type = self::fieldType($field);
        if (!in_array($type, ['image', 'file'], true)) {
            throw new BusinessException('当前插件字段不支持上传文件', StatusCode::VALIDATION_ERROR);
        }
    }

    private static function assertFile(UploadFile $file, array $field): void
    {
        $type = self::fieldType($field);
        $extension = strtolower($file->getUploadExtension());
        $allowedExtensions = self::allowedExtensions($field);
        if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
            $message = $type === 'image'
                ? '仅支持 ' . implode('、', $allowedExtensions) . ' 图片'
                : '仅支持 ' . implode('、', $allowedExtensions) . ' 文件';
            throw new BusinessException($message, StatusCode::VALIDATION_ERROR);
        }

        $mimeType = strtolower((string)$file->getUploadMimeType());
        if ($type === 'image' && $mimeType !== '' && !in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            throw new BusinessException('上传文件类型不正确', StatusCode::VALIDATION_ERROR);
        }

        $size = (int)($file->getSize() ?: 0);
        $maxSize = $type === 'image' ? self::MAX_IMAGE_SIZE : self::MAX_FILE_SIZE;
        if ($size <= 0 || $size > $maxSize) {
            throw new BusinessException(
                $type === 'image' ? '图片大小不能超过 8MB' : '文件大小不能超过 12MB',
                StatusCode::VALIDATION_ERROR
            );
        }
    }

    private static function storeFile(
        int $merchantId,
        string $pluginCode,
        string $fieldKey,
        UploadFile $file,
        string $fieldType
    ): array
    {
        $extension = strtolower($file->getUploadExtension()) ?: 'png';
        $datePath = date('Ymd');
        $basename = $merchantId
            . '-' . str_replace(['-', '_'], '', $pluginCode)
            . '-' . $fieldKey
            . '-' . date('His')
            . '-' . substr(bin2hex(random_bytes(6)), 0, 12)
            . '.' . $extension;

        $relativeDir = ($fieldType === 'image' ? self::IMAGE_STORE_DIR : self::FILE_STORE_DIR) . '/' . $datePath;
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

    private static function fileRow(
        int $merchantId,
        string $pluginCode,
        string $fieldKey,
        array $saved,
        string $fieldType
    ): array
    {
        $category = self::isQrField($pluginCode, $fieldKey) ? '通道二维码' : '通道配置文件';

        return [
            'id' => FileService::nextId(),
            'merchant_id' => $merchantId,
            'merchant_name' => self::merchantName($merchantId),
            'file_name' => (string)$saved['file_name'],
            'category' => $category,
            'size' => self::formatSize((int)$saved['size_bytes']),
            'status' => '已上传',
            'uploaded_at' => date('Y-m-d H:i:s'),
            'remark' => $pluginCode . ' / ' . $fieldKey,
            'preview_text' => $category === '通道二维码' ? '商户通道二维码配置上传文件' : '商户通道配置文件上传记录。',
            'file_url' => self::publicFileUrl((string)$saved['relative_path']),
            'mime_type' => (string)($saved['mime_type'] ?? ''),
            'file_type' => $fieldType,
        ];
    }

    private static function fieldType(array $field): string
    {
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        return $type !== '' ? $type : 'text';
    }

    /**
     * @return array<int, string>
     */
    private static function allowedExtensions(array $field): array
    {
        $type = self::fieldType($field);
        $accept = trim((string)($field['accept'] ?? ''));
        if ($accept !== '') {
            $extensions = [];
            foreach (preg_split('/\s*,\s*/', $accept, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
                $item = strtolower(trim($item));
                if ($item === '') {
                    continue;
                }

                if (str_starts_with($item, '.')) {
                    $item = substr($item, 1);
                } elseif (str_contains($item, '/')) {
                    $parts = explode('/', $item, 2);
                    $item = trim((string)($parts[1] ?? ''));
                }

                if ($item !== '') {
                    $extensions[] = $item;
                }
            }

            if ($extensions !== []) {
                return array_values(array_unique($extensions));
            }
        }

        return $type === 'image' ? self::IMAGE_EXTENSIONS : self::DEFAULT_FILE_EXTENSIONS;
    }

    private static function isQrField(string $pluginCode, string $fieldKey): bool
    {
        $definition = PluginRuntimeService::discoverMap()[$pluginCode] ?? [];
        $configPanel = trim((string)($definition['config_panel'] ?? 'generic'));

        if ($fieldKey === 'qrcode_image' && in_array($configPanel, ['qrcode_upload', 'login_qrcode'], true)) {
            return true;
        }

        if ($fieldKey === 'appreciate_image' && ($pluginCode === 'wechat-qrcode' || $configPanel === 'fixed_image')) {
            return true;
        }

        return false;
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
