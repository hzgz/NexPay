<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;

class AuthPolicyService
{
    private const CAPTCHA_STORE_KEY = 'auth_captcha_runtime';
    private const CAPTCHA_TTL = 300;

    public static function merchantConfig(): array
    {
        $settings = SettingsService::all(false);
        self::syncMerchantAuthAliases($settings);
        $auth = self::authSettings($settings);
        $verify = self::verifySettings($settings);
        $merchant = self::merchantSettings($settings);

        return [
            'auth' => $auth,
            'merchant' => $merchant,
            'verify' => $verify,
            'oauth' => self::safeGroup($settings, 'oauth'),
            'sms' => self::safeGroup($settings, 'sms'),
            'mail' => self::safeGroup($settings, 'mail'),
            'payment' => [
                'system_checkout_methods' => SettingsService::frontendPaymentMethodOptions('system_checkout'),
            ],
            'captcha' => self::buildCaptchaPayload('merchant_login'),
            'geetest' => self::buildGeetestPayload('merchant_login'),
            'scenes' => [
                'merchant_login' => [
                    'captcha' => (bool)($auth['merchant_login_captcha'] ?? false),
                    'geetest' => (bool)($verify['geetest_scene_login'] ?? false),
                ],
                'merchant_register' => [
                    'captcha' => (bool)($auth['merchant_register_captcha'] ?? false),
                    'geetest' => (bool)($verify['geetest_scene_register'] ?? false),
                    'enabled' => (bool)($merchant['register_enabled'] ?? false),
                ],
                'merchant_forgot' => [
                    'captcha' => (bool)($auth['merchant_forgot_captcha'] ?? false),
                    'geetest' => (bool)($verify['geetest_scene_forgot'] ?? false),
                ],
                'admin_login' => [
                    'captcha' => (bool)($auth['admin_login_captcha'] ?? false),
                    'geetest' => (bool)($verify['geetest_scene_admin'] ?? false),
                ],
            ],
        ];
    }

    public static function adminConfig(): array
    {
        $settings = SettingsService::all(false);
        self::syncMerchantAuthAliases($settings);
        $auth = self::authSettings($settings);
        $verify = self::verifySettings($settings);

        return [
            'auth' => [
                'captcha_enabled' => (bool)($auth['captcha_enabled'] ?? false),
                'admin_login_captcha' => (bool)($auth['admin_login_captcha'] ?? false),
            ],
            'verify' => $verify,
            'captcha' => self::buildCaptchaPayload('admin_login'),
            'geetest' => self::buildGeetestPayload('admin_login'),
        ];
    }

    public static function buildCaptchaPayload(string $scene, bool $force = false): array
    {
        if (!$force && !self::isCaptchaRequired($scene)) {
            return [
                'enabled' => false,
                'required' => false,
                'scene' => $scene,
                'captcha_key' => '',
                'captcha_image' => '',
                'captcha_hint' => '',
                'expires_at' => '',
            ];
        }

        $challenge = self::issueCaptchaChallenge($scene);
        return [
            'enabled' => true,
            'required' => true,
            'forced' => $force,
            'scene' => $scene,
            'captcha_key' => $challenge['captcha_key'],
            'captcha_image' => $challenge['captcha_image'],
            'captcha_hint' => '请输入图片中的验证码',
            'expires_at' => $challenge['expires_at'],
        ];
    }

    public static function buildGeetestPayload(string $scene): array
    {
        $settings = SettingsService::all(false);
        $verify = self::verifySettings($settings);
        $enabled = self::isGeetestRequired($scene, $settings);

        return [
            'enabled' => $enabled,
            'required' => $enabled,
            'scene' => $scene,
            'captcha_id' => trim((string)($verify['captcha_id'] ?? '')),
            'captcha_key' => trim((string)($verify['captcha_key'] ?? '')),
            'failback' => (bool)($verify['failback'] ?? true),
            'verify_token_hint' => '',
            'server_validate' => $enabled,
            'scene_switches' => [
                'login' => (bool)($verify['geetest_scene_login'] ?? false),
                'register' => (bool)($verify['geetest_scene_register'] ?? false),
                'forgot' => (bool)($verify['geetest_scene_forgot'] ?? false),
                'admin' => (bool)($verify['geetest_scene_admin'] ?? false),
            ],
        ];
    }

    public static function issueCaptchaChallenge(string $scene): array
    {
        $storage = self::captchaStorage();
        $key = self::randomToken(16);
        $code = self::randomDigits(4);
        $expiresAt = date('Y-m-d H:i:s', time() + self::CAPTCHA_TTL);

        $storage[$key] = [
            'scene' => $scene,
            'code' => $code,
            'expires_at' => $expiresAt,
        ];

        self::saveCaptchaStorage($storage);

        return [
            'captcha_key' => $key,
            'captcha_image' => self::renderCaptchaImage($code),
            'expires_at' => $expiresAt,
        ];
    }

    private static function renderCaptchaImage(string $code): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new BusinessException('图形验证码组件不可用', StatusCode::BUSINESS_ERROR);
        }

        $width = 132;
        $height = 44;
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 246, 249, 255);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        for ($index = 0; $index < 6; $index++) {
            imagesetthickness($image, random_int(1, 2));
            $lineColor = imagecolorallocate(
                $image,
                random_int(150, 210),
                random_int(170, 220),
                random_int(205, 240)
            );
            imageline(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $lineColor
            );
        }

        for ($index = 0; $index < 140; $index++) {
            $dotColor = imagecolorallocate(
                $image,
                random_int(110, 190),
                random_int(130, 205),
                random_int(170, 230)
            );
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $dotColor);
        }

        $font = 5;
        $chars = str_split($code);
        $charWidth = imagefontwidth($font);
        $charHeight = imagefontheight($font);
        $gap = (int)(($width - 24 - ($charWidth * count($chars))) / max(1, count($chars) - 1));

        foreach ($chars as $index => $char) {
            $textColor = imagecolorallocate(
                $image,
                random_int(20, 60),
                random_int(55, 105),
                random_int(115, 190)
            );
            $x = 12 + $index * ($charWidth + $gap) + random_int(-2, 2);
            $y = (int)(($height - $charHeight) / 2) + random_int(-4, 4);
            imagestring($image, $font, $x, $y, $char, $textColor);
        }

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode((string)$bytes);
    }

    public static function ensureMerchantLoginAllowed(array $payload): void
    {
        $captchaVerified = self::ensureCaptchaForScene('merchant_login', $payload);
        self::ensureGeetestForScene('merchant_login', $payload, null, $captchaVerified);
    }

    public static function ensureMerchantRegisterAllowed(array $payload): void
    {
        $settings = SettingsService::all(false);
        $auth = self::authSettings($settings);

        if (!(bool)($auth['register_enabled'] ?? false)) {
            throw new BusinessException('当前未开放商户注册', StatusCode::BUSINESS_ERROR);
        }

        $captchaVerified = self::ensureCaptchaForScene('merchant_register', $payload, $settings);
        self::ensureGeetestForScene('merchant_register', $payload, $settings, $captchaVerified);
    }

    public static function ensureMerchantForgotAllowed(array $payload): void
    {
        $settings = SettingsService::all(false);
        $auth = self::authSettings($settings);
        $recoverType = trim((string)($auth['recover_type'] ?? ''));

        if ($recoverType === '' || $recoverType === '关闭') {
            throw new BusinessException('当前未开启密码找回', StatusCode::BUSINESS_ERROR);
        }

        $captchaVerified = self::ensureCaptchaForScene('merchant_forgot', $payload, $settings);
        self::ensureGeetestForScene('merchant_forgot', $payload, $settings, $captchaVerified);
    }

    public static function ensureAdminLoginAllowed(array $payload): void
    {
        $captchaVerified = self::ensureCaptchaForScene('admin_login', $payload);
        self::ensureGeetestForScene('admin_login', $payload, null, $captchaVerified);
    }

    public static function ensureMerchantRealnameAllowed(int $merchantId = 0, int $userId = 0): void
    {
        $settings = SettingsService::all(false);
        $auth = self::authSettings($settings);
        $merchant = self::merchantSettings($settings);
        $required = (bool)($auth['require_realname_after_register'] ?? $merchant['require_realname'] ?? false);

        if (!$required) {
            return;
        }

        if (AccountService::merchantRealnameApproved($userId, $merchantId)) {
            return;
        }

        throw new BusinessException('请先完成实名认证后再继续操作', StatusCode::BUSINESS_ERROR);
    }

    public static function isCaptchaRequired(string $scene, ?array $settings = null): bool
    {
        $settings = $settings ?? SettingsService::all(false);
        self::syncMerchantAuthAliases($settings);
        $auth = self::authSettings($settings);
        $merchant = self::merchantSettings($settings);

        if (!(bool)($auth['captcha_enabled'] ?? false)) {
            return false;
        }

        return match ($scene) {
            'merchant_login' => (bool)($auth['merchant_login_captcha'] ?? false),
            'admin_login' => (bool)($auth['admin_login_captcha'] ?? false),
            'merchant_register' => (bool)($auth['merchant_register_captcha'] ?? false) && (bool)($merchant['register_enabled'] ?? false),
            'merchant_forgot' => (bool)($auth['merchant_forgot_captcha'] ?? false),
            default => false,
        };
    }

    public static function isGeetestRequired(string $scene, ?array $settings = null): bool
    {
        $settings = $settings ?? SettingsService::all(false);
        $verify = self::verifySettings($settings);
        $merchant = self::merchantSettings($settings);
        if (!(bool)($verify['geetest_enabled'] ?? false)) {
            return false;
        }

        return match ($scene) {
            'merchant_login' => (bool)($verify['geetest_scene_login'] ?? false),
            'merchant_register' => (bool)($verify['geetest_scene_register'] ?? false) && (bool)($merchant['register_enabled'] ?? false),
            'merchant_forgot' => (bool)($verify['geetest_scene_forgot'] ?? false),
            'admin_login' => (bool)($verify['geetest_scene_admin'] ?? false),
            default => false,
        };
    }

    private static function ensureCaptchaForScene(string $scene, array $payload, ?array $settings = null, bool $force = false): bool
    {
        if (!$force && !self::isCaptchaRequired($scene, $settings)) {
            return false;
        }

        $captchaKey = trim((string)($payload['captcha_key'] ?? ''));
        $captchaCode = trim((string)($payload['captcha_code'] ?? ''));
        if ($captchaKey === '' || $captchaCode === '') {
            throw new BusinessException('请先完成验证码校验', StatusCode::VALIDATION_ERROR);
        }

        $storage = self::captchaStorage();
        $record = is_array($storage[$captchaKey] ?? null) ? $storage[$captchaKey] : null;
        if (!$record) {
            throw new BusinessException('验证码已失效，请刷新后重试', StatusCode::VALIDATION_ERROR);
        }

        $expiresAt = strtotime((string)($record['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            unset($storage[$captchaKey]);
            self::saveCaptchaStorage($storage);
            throw new BusinessException('验证码已过期，请重新获取', StatusCode::VALIDATION_ERROR);
        }

        if (($record['scene'] ?? '') !== $scene) {
            throw new BusinessException('验证码场景不匹配，请刷新后重试', StatusCode::VALIDATION_ERROR);
        }

        if (strcasecmp((string)($record['code'] ?? ''), $captchaCode) !== 0) {
            throw new BusinessException('验证码错误', StatusCode::VALIDATION_ERROR);
        }

        unset($storage[$captchaKey]);
        self::saveCaptchaStorage($storage);
        return true;
    }

    private static function ensureGeetestForScene(string $scene, array $payload, ?array $settings = null, bool $captchaVerified = false): void
    {
        if (!self::isGeetestRequired($scene, $settings)) {
            return;
        }

        $verify = self::verifySettings($settings ?? SettingsService::all(false));
        try {
            if (ProviderRuntimeService::verifyGeetest($verify, $payload)) {
                return;
            }
        } catch (BusinessException $exception) {
            if ((bool)($verify['failback'] ?? true)) {
                if ($captchaVerified) {
                    return;
                }

                self::ensureCaptchaForScene($scene, $payload, $settings, true);
                return;
            }

            throw $exception;
        }

        throw new BusinessException('极验服务端校验未通过，请刷新后重试', StatusCode::VALIDATION_ERROR);
    }

    private static function authSettings(array $settings): array
    {
        return self::safeGroup($settings, 'auth');
    }

    private static function verifySettings(array $settings): array
    {
        return self::safeGroup($settings, 'verify');
    }

    private static function merchantSettings(array $settings): array
    {
        return self::safeGroup($settings, 'merchant');
    }

    private static function safeGroup(array $settings, string $key): array
    {
        $value = $settings[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    private static function captchaStorage(): array
    {
        $storage = JsonStoreService::load(self::CAPTCHA_STORE_KEY, []);
        $now = time();
        $filtered = [];

        foreach ($storage as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $expiresAt = strtotime((string)($item['expires_at'] ?? ''));
            if ($expiresAt === false || $expiresAt < $now) {
                continue;
            }

            $filtered[$key] = $item;
        }

        if ($filtered !== $storage) {
            self::saveCaptchaStorage($filtered);
        }

        return $filtered;
    }

    private static function saveCaptchaStorage(array $storage): void
    {
        JsonStoreService::save(self::CAPTCHA_STORE_KEY, $storage);
    }

    private static function randomDigits(int $length): string
    {
        $value = '';
        for ($index = 0; $index < $length; $index++) {
            $value .= (string)random_int(0, 9);
        }

        return $value;
    }

    private static function randomToken(int $length): string
    {
        $token = bin2hex(random_bytes((int)ceil($length / 2)));
        return substr($token, 0, $length);
    }

    private static function syncMerchantAuthAliases(array &$settings): void
    {
        $auth = self::safeGroup($settings, 'auth');
        $merchant = self::safeGroup($settings, 'merchant');

        if (!array_key_exists('register_enabled', $auth)) {
            $auth['register_enabled'] = (bool)($merchant['register_enabled'] ?? false);
        }
        if (!array_key_exists('register_auto_audit', $auth)) {
            $auth['register_auto_audit'] = (bool)($merchant['register_auto_audit'] ?? false);
        }
        if (!array_key_exists('merchant_register_fee_enabled', $auth)) {
            $auth['merchant_register_fee_enabled'] = (bool)($merchant['register_fee_enabled'] ?? false);
        }
        if (!array_key_exists('merchant_register_fee', $auth)) {
            $auth['merchant_register_fee'] = (string)($merchant['register_fee'] ?? '0.00');
        }
        if (!array_key_exists('require_realname_after_register', $auth)) {
            $auth['require_realname_after_register'] = (bool)($merchant['require_realname'] ?? false);
        }
        if (!array_key_exists('merchant_register_captcha', $auth)) {
            $auth['merchant_register_captcha'] = (bool)($auth['captcha_enabled'] ?? false);
        }
        if (!array_key_exists('merchant_forgot_captcha', $auth)) {
            $auth['merchant_forgot_captcha'] = (bool)($auth['captcha_enabled'] ?? false);
        }
        if (!array_key_exists('merchant_login_captcha', $auth)) {
            $auth['merchant_login_captcha'] = (bool)($auth['captcha_enabled'] ?? false);
        }
        if (!array_key_exists('admin_login_captcha', $auth)) {
            $auth['admin_login_captcha'] = (bool)($auth['captcha_enabled'] ?? false);
        }

        $settings['auth'] = $auth;
    }
}
