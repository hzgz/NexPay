<?php

declare(strict_types=1);

namespace app\service\payment;

use app\service\system\ConfigService;
use app\service\system\MerchantChannelService;
use app\service\system\SettingsService;
use support\Response;
use Throwable;

class QrCodeService
{
    private const PROVIDER_CLIIM = 'cliim';
    private const PROVIDER_GOQR = 'goqr';
    private const DEFAULT_SIZE = 320;
    private const SOURCE_KEYS = [
        'payment_address',
        'display_value',
        'qrcode_url',
        'address',
        'url',
        'link',
        'appreciate_qrcode_url',
        'qrcode_image',
        'appreciate_image',
        'resolved_qrcode_content',
    ];
    private const NESTED_SOURCE_KEYS = ['plugin_config', 'config', 'channel'];

    public static function imageUrl(string $tradeNo, int $size = self::DEFAULT_SIZE): string
    {
        $size = self::normalizeSize($size);
        return '/pay/qr-image/' . rawurlencode($tradeNo) . '?size=' . $size;
    }

    public static function imageResponseByTradeNo(string $tradeNo, int $size = self::DEFAULT_SIZE): Response
    {
        $order = OrderService::findByTradeNo($tradeNo);
        return self::imageResponseForOrder($order, $size);
    }

    public static function imageDataUriForOrder(object $order, int $size = self::DEFAULT_SIZE): string
    {
        $image = self::imageBinaryForOrder($order, $size);
        if ($image['body'] === '') {
            return '';
        }

        return 'data:' . $image['mime'] . ';base64,' . base64_encode($image['body']);
    }

    public static function imageDataUriForContent(string $content, int $size = self::DEFAULT_SIZE): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        try {
            $body = self::encodeQrCode($content, null, self::normalizeSize($size));
        } catch (Throwable) {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($body);
    }

    public static function imageResponseForOrder(object $order, int $size = self::DEFAULT_SIZE): Response
    {
        $image = self::imageBinaryForOrder($order, $size);

        if ($image['body'] === '') {
            return self::svgResponse('未生成支付二维码');
        }

        return new Response(200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, max-age=300',
        ], $image['body']);

        try {
            $content = self::resolveOrderPayload($order, $source);
            if ($content !== '') {
                $image = self::encodeQrCode($content, null, $size);
                return new Response(200, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'private, max-age=300',
                ], $image);
            }
        } catch (Throwable) {
        }

        if (self::isImageValue($source)) {
            $image = self::fetchImageBinary($source);
            if ($image['body'] !== '') {
                return new Response(200, [
                    'Content-Type' => $image['mime'],
                    'Cache-Control' => 'private, max-age=300',
                ], $image['body']);
            }
        }

        return self::svgResponse('二维码生成失败');
    }

    public static function displayValueForOrder(object $order): string
    {
        $source = self::resolveOrderSourceValue($order);
        if ($source === '') {
            return '';
        }

        if (!self::isImageValue($source)) {
            return $source;
        }

        try {
            $content = self::resolveOrderPayload($order, $source);
            return $content !== '' ? $content : $source;
        } catch (Throwable) {
            return $source;
        }
    }

    public static function hasDisplayableQr(object $order): bool
    {
        return self::resolveOrderSourceValue($order) !== '';
    }

    public static function rememberOrderSource(string $tradeNo, string $source, array $meta = []): void
    {
        $source = trim($source);
        if ($tradeNo === '' || $source === '') {
            return;
        }

        $order = OrderService::findByTradeNoOrNull($tradeNo);
        if (!$order) {
            return;
        }

        $changes = [];
        if ((string)($order->payment_address ?? '') !== $source) {
            $changes['payment_address'] = $source;
        }

        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $runtime = is_array($notifyPayload['qr_runtime'] ?? null) ? $notifyPayload['qr_runtime'] : [];
        $currentSource = trim((string)($runtime['source'] ?? ''));
        $currentMeta = is_array($runtime['meta'] ?? null) ? $runtime['meta'] : [];

        if ($currentSource !== $source || $currentMeta !== $meta) {
            $runtime = [
                'source' => $source,
                'meta' => $meta,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $notifyPayload['qr_runtime'] = $runtime;
            $changes['notify_payload'] = $notifyPayload;
        }

        if ($changes !== []) {
            OrderService::saveOrder($order, $changes);
        }
    }

    public static function resolveOrderSourceValue(object $order): string
    {
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $legacySnapshot = is_array($requestPayload['_legacy_channel'] ?? null) ? $requestPayload['_legacy_channel'] : [];
        $legacyConfig = is_array($legacySnapshot['config'] ?? null) ? $legacySnapshot['config'] : [];
        $legacyExt = $notifyPayload['legacy_ext'] ?? null;
        $runtime = is_array($notifyPayload['qr_runtime'] ?? null) ? $notifyPayload['qr_runtime'] : [];

        $candidates = [
            $runtime['source'] ?? '',
            $runtime['resolved_content'] ?? '',
            $order->payment_address ?? '',
            self::sourceFromLegacyExt($legacyExt),
            self::sourceFromPayload($legacyConfig),
            self::sourceFromPayload($legacySnapshot),
            self::sourceFromPayload($notifyPayload),
            self::sourceFromMerchantChannel($order),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value === '' || self::isInternalGatewayReference($value)) {
                continue;
            }

            return $value;
        }

        return '';
    }

    private static function resolveOrderPayload(object $order, string $source): string
    {
        if (!self::isImageValue($source)) {
            self::cacheResolvedPayload($order, $source, $source);
            return $source;
        }

        $cached = self::cachedResolvedPayload($order, $source);
        if ($cached !== '') {
            return $cached;
        }

        $content = self::decodeQrCodeImage($source);
        if ($content !== '') {
            self::cacheResolvedPayload($order, $source, $content);
        }

        return $content;
    }

    private static function imageBinaryForOrder(object $order, int $size = self::DEFAULT_SIZE): array
    {
        $size = self::normalizeSize($size);
        $source = self::resolveOrderSourceValue($order);

        if ($source === '') {
            return ['mime' => 'image/png', 'body' => ''];
        }

        try {
            $content = self::resolveOrderPayload($order, $source);
            if ($content !== '') {
                return [
                    'mime' => 'image/png',
                    'body' => self::encodeQrCode($content, null, $size),
                ];
            }
        } catch (Throwable) {
        }

        if (self::isImageValue($source)) {
            return self::fetchImageBinary($source);
        }

        return ['mime' => 'image/png', 'body' => ''];
    }

    private static function cacheResolvedPayload(object $order, string $source, string $content): void
    {
        $content = trim($content);
        if ($content === '') {
            return;
        }

        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $runtime = is_array($notifyPayload['qr_runtime'] ?? null) ? $notifyPayload['qr_runtime'] : [];
        $currentSource = trim((string)($runtime['source'] ?? ''));
        $currentContent = trim((string)($runtime['resolved_content'] ?? ''));

        if ($currentSource === $source && $currentContent === $content) {
            return;
        }

        $runtime['source'] = $source;
        $runtime['resolved_content'] = $content;
        $runtime['resolved_at'] = date('Y-m-d H:i:s');
        $notifyPayload['qr_runtime'] = $runtime;
        OrderService::saveOrder($order, ['notify_payload' => $notifyPayload]);
        $order->notify_payload = $notifyPayload;
    }

    private static function cachedResolvedPayload(object $order, string $source): string
    {
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $runtime = is_array($notifyPayload['qr_runtime'] ?? null) ? $notifyPayload['qr_runtime'] : [];
        if (trim((string)($runtime['source'] ?? '')) !== $source) {
            return '';
        }

        return trim((string)($runtime['resolved_content'] ?? ''));
    }

    private static function sourceFromLegacyExt(mixed $legacyExt): string
    {
        if (!is_array($legacyExt)) {
            return '';
        }

        $direct = self::sourceFromPayload($legacyExt);
        if ($direct !== '') {
            return $direct;
        }

        if (isset($legacyExt[1]) && is_string($legacyExt[1])) {
            return trim($legacyExt[1]);
        }

        return '';
    }

    private static function sourceFromPayload(mixed $payload, int $depth = 0): string
    {
        if (!is_array($payload) || $depth > 2) {
            return '';
        }

        $direct = self::firstValue($payload, self::SOURCE_KEYS);
        if ($direct !== '') {
            return $direct;
        }

        foreach (self::NESTED_SOURCE_KEYS as $key) {
            $nested = $payload[$key] ?? null;
            if (!is_array($nested)) {
                continue;
            }

            $value = self::sourceFromPayload($nested, $depth + 1);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function sourceFromMerchantChannel(object $order): string
    {
        $merchantId = (int)($order->merchant_id ?? 0);
        $merchantChannelId = (int)($order->merchant_channel_id ?? 0);
        if ($merchantId <= 0 || $merchantChannelId <= 0) {
            return '';
        }

        try {
            $payload = MerchantChannelService::all($merchantId);
        } catch (Throwable) {
            return '';
        }

        foreach ((array)($payload['items'] ?? []) as $item) {
            if ((int)($item['id'] ?? 0) !== $merchantChannelId) {
                continue;
            }

            return self::sourceFromPayload($item);
        }

        return '';
    }

    private static function firstValue(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function encodeQrCode(string $content, ?string $provider = null, int $size = self::DEFAULT_SIZE): string
    {
        $provider = self::normalizeProvider($provider ?: self::encodeProvider());
        $size = self::normalizeSize($size);

        if ($provider === self::PROVIDER_GOQR) {
            $url = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
                'size' => $size . 'x' . $size,
                'data' => $content,
            ]);
        } else {
            $url = 'https://api.2dcode.biz/v1/create-qr-code?' . http_build_query([
                'data' => $content,
                'size' => $size,
            ]);
        }

        $response = self::httpRequest($url);
        if ($response['http_code'] >= 400 || $response['body'] === '') {
            throw new \RuntimeException('二维码生成失败');
        }

        return $response['body'];
    }

    private static function decodeQrCodeImage(string $source): string
    {
        $provider = self::decodeProvider();
        $image = self::fetchImageBinary($source);
        if ($image['body'] === '') {
            return '';
        }

        $decoded = self::decodeQrCodeBinary($image['body'], $provider);
        return trim($decoded);
    }

    private static function decodeQrCodeBinary(string $bytes, ?string $provider = null): string
    {
        $provider = self::normalizeProvider($provider ?: self::decodeProvider());
        $tmp = tempnam(sys_get_temp_dir(), 'qr_');
        if ($tmp === false) {
            throw new \RuntimeException('无法创建临时文件');
        }

        $pngPath = $tmp . '.png';
        @unlink($tmp);
        file_put_contents($pngPath, $bytes);

        try {
            $url = $provider === self::PROVIDER_GOQR
                ? 'https://api.qrserver.com/v1/read-qr-code/'
                : 'https://api.2dcode.biz/v1/read-qr-code';

            $response = self::httpRequest($url, 'POST', [
                'file' => new \CURLFile($pngPath, 'image/png', 'qrcode.png'),
            ]);

            if ($response['http_code'] >= 400 || $response['body'] === '') {
                throw new \RuntimeException('二维码解码失败');
            }

            $json = json_decode($response['body'], true);
            if ($provider === self::PROVIDER_GOQR) {
                if (!is_array($json) || !isset($json[0]['symbol'][0])) {
                    return '';
                }

                return trim((string)($json[0]['symbol'][0]['data'] ?? ''));
            }

            if (!is_array($json) || (int)($json['code'] ?? -1) !== 0) {
                return '';
            }

            return trim((string)($json['data']['contents'][0] ?? ''));
        } finally {
            @unlink($pngPath);
        }
    }

    private static function fetchImageBinary(string $source): array
    {
        if (str_starts_with(strtolower($source), 'data:image/')) {
            return self::dataUriBinary($source);
        }

        $localPath = self::resolveLocalImagePath($source);
        if ($localPath !== '') {
            $body = (string)@file_get_contents($localPath);
            if ($body !== '') {
                return [
                    'mime' => self::normalizeMimeType('', $body),
                    'body' => $body,
                ];
            }
        }

        $requestUrl = trim($source);
        if ($requestUrl !== '' && !self::isHttpUrl($requestUrl)) {
            $requestUrl = rtrim(ConfigService::gatewayBaseUrl(), '/') . '/' . ltrim($requestUrl, '/');
        }

        $response = self::httpRequest($requestUrl);
        if ($response['body'] === '') {
            return ['mime' => 'image/png', 'body' => ''];
        }

        return [
            'mime' => self::normalizeMimeType((string)$response['content_type'], $response['body']),
            'body' => $response['body'],
        ];
    }

    private static function resolveLocalImagePath(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        if (!self::isHttpUrl($source) && is_file($source)) {
            return $source;
        }

        $parts = parse_url($source);
        if ($parts === false) {
            return '';
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $baseHost = strtolower((string)parse_url((string)ConfigService::gatewayBaseUrl(), PHP_URL_HOST));
        if ($host !== '' && $baseHost !== '' && $host !== $baseHost) {
            return '';
        }

        $path = trim((string)($parts['path'] ?? ''));
        if ($path === '' && $host === '' && !self::isHttpUrl($source)) {
            $path = $source;
        }
        if ($path === '') {
            return '';
        }

        $absolutePath = public_path(ltrim(str_replace('\\', '/', $path), '/'));
        return is_file($absolutePath) ? $absolutePath : '';
    }

    private static function isHttpUrl(string $value): bool
    {
        return (bool)preg_match('/^https?:\/\//i', trim($value));
    }

    private static function dataUriBinary(string $value): array
    {
        if (!preg_match('/^data:(image\/[a-z0-9.+-]+);base64,(.+)$/i', trim($value), $matches)) {
            return ['mime' => 'image/png', 'body' => ''];
        }

        $bytes = base64_decode($matches[2], true);
        if ($bytes === false) {
            return ['mime' => 'image/png', 'body' => ''];
        }

        return [
            'mime' => strtolower($matches[1]),
            'body' => $bytes,
        ];
    }

    private static function httpRequest(
        string $url,
        string $method = 'GET',
        array|string|null $payload = null,
        array $headers = []
    ): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_USERAGENT, 'NexPay/1.0');

        $method = strtoupper(trim($method));
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
        }

        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            return [
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'body' => '',
                'error' => $error,
            ];
        }

        return [
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'body' => substr($raw, $headerSize),
            'error' => $error,
        ];
    }

    private static function normalizeProvider(string $value): string
    {
        return strtolower(trim($value)) === self::PROVIDER_GOQR ? self::PROVIDER_GOQR : self::PROVIDER_CLIIM;
    }

    private static function encodeProvider(): string
    {
        $settings = SettingsService::all(false);
        return self::normalizeProvider((string)($settings['api']['encode_provider'] ?? ''));
    }

    private static function decodeProvider(): string
    {
        $settings = SettingsService::all(false);
        return self::normalizeProvider((string)($settings['api']['decode_provider'] ?? ''));
    }

    private static function isImageValue(string $value): bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, 'data:image/')) {
            return true;
        }

        return (bool)preg_match('/\.(png|jpg|jpeg|gif|webp|svg)(\?.*)?$/', $value);
    }

    private static function isInternalGatewayReference(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, '/pay/')) {
            return true;
        }

        $baseHost = strtolower((string)parse_url((string)ConfigService::gatewayBaseUrl(), PHP_URL_HOST));
        $host = strtolower((string)parse_url($value, PHP_URL_HOST));
        $path = strtolower((string)parse_url($value, PHP_URL_PATH));

        return $host !== '' && $baseHost !== '' && $host === $baseHost && str_starts_with($path, '/pay/');
    }

    private static function normalizeSize(int $size): int
    {
        return max(120, min(1024, $size));
    }

    private static function normalizeMimeType(string $contentType, string $body): string
    {
        $contentType = strtolower(trim($contentType));
        if (str_starts_with($contentType, 'image/')) {
            return explode(';', $contentType)[0];
        }

        $signature = bin2hex(substr($body, 0, 12));
        if (str_starts_with($signature, '89504e47')) {
            return 'image/png';
        }
        if (str_starts_with($signature, 'ffd8ff')) {
            return 'image/jpeg';
        }
        if (str_starts_with($signature, '47494638')) {
            return 'image/gif';
        }
        if (str_starts_with($signature, '52494646') && str_contains($body, 'WEBP')) {
            return 'image/webp';
        }

        return 'image/png';
    }

    private static function svgResponse(string $message): Response
    {
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="360" height="360" viewBox="0 0 360 360">
  <rect width="360" height="360" rx="24" fill="#f4f8ff"/>
  <rect x="20" y="20" width="320" height="320" rx="20" fill="#ffffff" stroke="#dbe7f6"/>
  <text x="180" y="172" text-anchor="middle" fill="#1677ff" font-size="18" font-family="Segoe UI,Microsoft YaHei,sans-serif">NexPay</text>
  <text x="180" y="202" text-anchor="middle" fill="#607089" font-size="14" font-family="Segoe UI,Microsoft YaHei,sans-serif">{$safe}</text>
</svg>
SVG;

        return new Response(200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-store',
        ], $svg);
    }
}
