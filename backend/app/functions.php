<?php

use app\service\payment\OrderService;
use app\service\system\ConfigService;
use app\service\system\PluginCodeService;
use app\service\system\PluginRuntimeService;
use app\service\system\ProviderRuntimeService;

if (!defined('SYSTEM_ROOT')) {
    define('SYSTEM_ROOT', rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

if (!defined('PLUGIN_ROOT')) {
    define('PLUGIN_ROOT', SYSTEM_ROOT . 'plugins' . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR);
}

if (!defined('PAYPAGE_ROOT')) {
    define('PAYPAGE_ROOT', SYSTEM_ROOT . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR);
}

if (!defined('SYS_KEY')) {
    define('SYS_KEY', (string)ConfigService::internalRefundSecret());
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'plugins\\payment\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === '' || !str_contains($relative, '\\')) {
        return;
    }

    [$pluginSegment, $classSegment] = explode('\\', $relative, 2);
    $pluginDir = legacy_plugin_directory_name($pluginSegment);
    if ($pluginDir === '') {
        return;
    }

    $path = base_path()
        . DIRECTORY_SEPARATOR . 'plugins'
        . DIRECTORY_SEPARATOR . 'pay'
        . DIRECTORY_SEPARATOR . $pluginDir
        . DIRECTORY_SEPARATOR . 'source'
        . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $classSegment) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

function legacy_plugin_directory_name(string $code): string
{
    $code = trim($code);
    if ($code === '') {
        return '';
    }

    $raw = strtolower(str_replace('-', '_', $code));
    if (is_dir(base_path() . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . $raw)) {
        return $raw;
    }

    $normalized = PluginCodeService::normalize($raw);
    $candidates = array_unique(array_filter([
        $raw,
        str_replace('-', '_', $normalized),
        str_replace('_', '-', $raw),
        str_replace('_', '-', $normalized),
    ]));

    foreach ($candidates as $candidate) {
        $candidate = (string)$candidate;
        if ($candidate === '') {
            continue;
        }

        $directory = base_path()
            . DIRECTORY_SEPARATOR . 'plugins'
            . DIRECTORY_SEPARATOR . 'pay'
            . DIRECTORY_SEPARATOR . str_replace('-', '_', $candidate);

        if (is_dir($directory)) {
            return basename($directory);
        }
    }

    return $raw;
}

function config_get(string $key = '', mixed $default = null): mixed
{
    static $aliases = [
        'appname' => 'app_name',
        'sitename' => 'app_name',
        'localurl' => 'app_url',
        'appurl' => 'app_url',
        'siteurl' => 'app_url',
        'publickey' => 'platform_public_key',
        'privatekey' => 'platform_private_key',
    ];

    $normalizedKey = strtolower(trim($key));
    if ($normalizedKey === '') {
        return $default;
    }

    $mappedKey = $aliases[$normalizedKey] ?? $normalizedKey;
    $value = ConfigService::get($mappedKey, null);

    if ($value === null) {
        if (str_starts_with($normalizedKey, 'localurl_')) {
            return rtrim((string)ConfigService::gatewayBaseUrl(), '/') . '/';
        }
        return $default;
    }

    if (in_array($mappedKey, ['app_url'], true)) {
        return rtrim((string)$value, '/') . '/';
    }

    return $value;
}

function database_available(): bool
{
    static $available = null;
    static $checkedAt = 0;

    $ttl = max(1, (int)env('DB_AVAILABILITY_TTL', 30));
    if ($available !== null && (time() - $checkedAt) < $ttl) {
        return $available;
    }

    $forceLocal = strtolower(trim((string)env('DB_FORCE_LOCAL', '0')));
    if (in_array($forceLocal, ['1', 'true', 'yes', 'on'], true)) {
        $available = false;
        $checkedAt = time();
        return $available;
    }

    $host = trim((string)env('DB_HOST', '127.0.0.1'));
    $port = max(1, (int)env('DB_PORT', 3306));
    $timeout = max(0.1, (float)env('DB_AVAILABILITY_TIMEOUT', 0.2));

    $socket = @stream_socket_client(
        sprintf('tcp://%s:%d', $host, $port),
        $errno,
        $errstr,
        $timeout
    );

    if (!$socket) {
        $available = false;
        $checkedAt = time();
        return $available;
    }

    fclose($socket);

    try {
        \think\facade\Db::query('SELECT 1');
        $available = true;
    } catch (\Throwable) {
        $available = false;
    }

    $checkedAt = time();
    return $available;
}

function curl_get(string $url): string|false
{
    return get_curl($url);
}

function get_curl(
    string $url,
    mixed $post = 0,
    mixed $referer = 0,
    mixed $cookie = 0,
    mixed $header = 0,
    mixed $ua = 0,
    mixed $nobaody = 0,
    mixed $addheader = 0,
    mixed $location = 0
): string|false {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, (bool)$location);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(array_filter(array_merge([
        'Accept: */*',
        'Accept-Language: zh-CN,zh;q=0.9',
        'Connection: close',
    ], is_array($addheader) ? $addheader : []))));

    if ($post !== 0 && $post !== null && $post !== '') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    if ($referer) {
        curl_setopt($ch, CURLOPT_REFERER, (string)$referer);
    }

    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIE, (string)$cookie);
    }

    if ($header) {
        curl_setopt($ch, CURLOPT_HEADER, true);
    }

    if ($nobaody) {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

    curl_setopt(
        $ch,
        CURLOPT_USERAGENT,
        $ua ? (string)$ua : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
    );

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

function is_https(): bool
{
    $request = request();
    if ($request) {
        $scheme = strtolower((string)$request->header('x-forwarded-proto', ''));
        if ($scheme === 'https') {
            return true;
        }

        $https = strtolower((string)$request->header('https', ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }
    }

    return str_starts_with(strtolower((string)ConfigService::gatewayBaseUrl()), 'https://');
}

function real_ip(int $type = 0): string
{
    unset($type);
    $request = request();
    if (!$request) {
        return '127.0.0.1';
    }

    return (string)$request->getRealIp(false);
}

function checkwechat(): bool
{
    return str_contains(strtolower((string)request()?->header('user-agent', '')), 'micromessenger')
        && !str_contains(strtolower((string)request()?->header('user-agent', '')), 'windowswechat');
}

function checkalipay(): bool
{
    return str_contains(strtolower((string)request()?->header('user-agent', '')), 'alipayclient/');
}

function checkmobbileqq(): bool
{
    $ua = strtolower((string)request()?->header('user-agent', ''));
    return str_contains($ua, ' qq/') || str_contains($ua, 'mqqbrowser') || str_contains($ua, 'mobile qq');
}

function checkunionpay(): bool
{
    return str_contains(strtolower((string)request()?->header('user-agent', '')), 'unionpay/');
}

function checkdouyin(): bool
{
    $ua = strtolower((string)request()?->header('user-agent', ''));
    return str_contains($ua, 'aweme') || str_contains($ua, 'douyin');
}

function getDevice(): string
{
    $ua = strtolower((string)request()?->header('user-agent', ''));
    if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) {
        return 'ios';
    }
    if (str_contains($ua, 'android')) {
        return 'android';
    }
    return 'pc';
}

function getSid(): string
{
    return date('YmdHis') . random_int(100000, 999999);
}

function getCertFilePath(string $path = ''): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
        return $path;
    }

    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $candidates = [
        base_path() . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR),
        base_path() . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'pay' . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0];
}

function checkBlockUser(mixed $userId = null, mixed $tradeNo = null): ?array
{
    unset($userId, $tradeNo);
    return null;
}

function wechat_oauth(mixed ...$args): string
{
    $openid = legacy_oauth_pick_identity($args, [
        'sub_openid',
        'sub_open_id',
        'openid',
        'open_id',
        'wx_openid',
        'wechat_openid',
    ]);

    if ($openid !== '') {
        return $openid;
    }

    return legacy_oauth_fail(
        'wechat_oauth',
        '微信 JSAPI 支付缺少真实 openid/sub_openid，不能使用模拟 openid 下单',
        $args
    );
}

function wechat_applet_oauth(mixed ...$args): string
{
    $openid = legacy_oauth_pick_identity($args, [
        'sub_openid',
        'sub_open_id',
        'openid',
        'open_id',
        'wx_openid',
        'wechat_openid',
    ]);

    if ($openid !== '') {
        return $openid;
    }

    return legacy_oauth_fail(
        'wechat_applet_oauth',
        '微信小程序支付缺少真实 openid/sub_openid，不能使用模拟 openid 下单',
        $args
    );
}

function alipay_oauth(mixed ...$args): array
{
    $identity = legacy_alipay_identity($args);
    if ($identity[1] !== '') {
        return $identity;
    }

    return legacy_oauth_fail(
        'alipay_oauth',
        '支付宝 JSAPI 支付缺少真实 buyer_id/buyer_open_id，不能使用模拟用户 ID 下单',
        $args
    );
}

function alipay_mini_oauth(mixed ...$args): array
{
    $appId = legacy_oauth_pick_identity($args, ['app_id', 'appid', 'op_app_id']);
    if ($appId === '') {
        return legacy_oauth_fail(
            'alipay_mini_oauth',
            '支付宝小程序支付缺少真实 app_id/appid 配置',
            $args
        );
    }

    $identity = legacy_alipay_identity($args);
    if ($identity[1] === '') {
        return legacy_oauth_fail(
            'alipay_mini_oauth',
            '支付宝小程序支付缺少真实 buyer_id/buyer_open_id，不能使用模拟用户 ID 下单',
            $args
        );
    }

    return [$appId, $identity[0], $identity[1]];
}

function qqpay_oauth(mixed ...$args): string
{
    $openid = legacy_oauth_pick_identity($args, [
        'openid',
        'open_id',
        'qq_openid',
        'sub_openid',
        'sub_open_id',
    ]);

    if ($openid !== '') {
        return $openid;
    }

    return legacy_oauth_fail(
        'qqpay_oauth',
        'QQ 钱包支付缺少真实 openid，不能使用模拟 openid 下单',
        $args
    );
}

function legacy_alipay_identity(array $args): array
{
    $openid = legacy_oauth_pick_identity($args, [
        'buyer_open_id',
        'op_buyer_open_id',
        'open_id',
        'openid',
        'sub_openid',
        'sub_open_id',
    ]);
    if ($openid !== '') {
        return ['openid', $openid];
    }

    $userId = legacy_oauth_pick_identity($args, [
        'buyer_id',
        'buyer_user_id',
        'payer_user_id',
        'user_id',
        'userid',
        'alipay_user_id',
    ]);

    return [$userId !== '' ? 'userid' : '', $userId];
}

function legacy_oauth_pick_identity(array $args, array $keys): string
{
    foreach (legacy_oauth_request_sources() as $source) {
        $value = legacy_oauth_pick_from_array($source, $keys);
        if ($value !== '') {
            return $value;
        }
    }

    foreach ($args as $arg) {
        if (!is_array($arg)) {
            continue;
        }

        $value = legacy_oauth_pick_from_array($arg, $keys);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function legacy_oauth_request_sources(): array
{
    try {
        $request = request();
    } catch (\Throwable) {
        return [];
    }

    if (!$request) {
        return [];
    }

    $sources = [];
    foreach (['get', 'post'] as $method) {
        try {
            $value = method_exists($request, $method) ? $request->{$method}() : [];
        } catch (\Throwable) {
            $value = [];
        }

        if (is_array($value)) {
            $sources[] = $value;
        }
    }

    return $sources;
}

function legacy_oauth_pick_from_array(array $source, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }

        $value = $source[$key];
        if (is_scalar($value)) {
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
    }

    foreach ($source as $value) {
        if (is_array($value)) {
            $nested = legacy_oauth_pick_from_array($value, $keys);
            if ($nested !== '') {
                return $nested;
            }
        }
    }

    return '';
}

function legacy_oauth_fail(string $scene, string $message, array $args): never
{
    try {
        ProviderRuntimeService::recordEvent([
            'type' => 'payment_oauth',
            'scene' => $scene,
            'provider_code' => 'legacy_plugin_helper',
            'target' => legacy_oauth_log_target($args),
            'status' => 'failed',
            'message' => $message,
            'operator' => 'system',
            'ip' => real_ip(),
        ]);
    } catch (\Throwable) {
    }

    throw new \RuntimeException($message);
}

function legacy_oauth_log_target(array $args): string
{
    foreach ($args as $arg) {
        if (is_scalar($arg)) {
            $value = trim((string)$arg);
            if ($value !== '') {
                return substr($value, 0, 4) . '***' . substr($value, -4);
            }
        }

        if (is_array($arg)) {
            $value = legacy_oauth_pick_from_array($arg, ['trade_no', 'out_trade_no']);
            if ($value !== '') {
                return substr($value, 0, 4) . '***' . substr($value, -4);
            }
        }
    }

    return '';
}

function wxminipay_jump_scheme(mixed ...$args): string
{
    $tradeNo = legacy_trade_no_from_args($args);
    return rtrim((string)ConfigService::gatewayBaseUrl(), '/') . '/pay/checkout/' . $tradeNo;
}

function wxminipay_jump_path(mixed ...$args): string
{
    $tradeNo = legacy_trade_no_from_args($args);
    return '/pay/checkout/' . $tradeNo;
}

function legacy_trade_no_from_args(array $args): string
{
    foreach ($args as $arg) {
        if (is_array($arg) && !empty($arg['trade_no'])) {
            return (string)$arg['trade_no'];
        }
        if (is_object($arg) && !empty($arg->trade_no)) {
            return (string)$arg->trade_no;
        }
        if (is_string($arg) && preg_match('/^\d{20,}$/', $arg) === 1) {
            return $arg;
        }
    }

    return getSid();
}

function getBankCardInfo(string $cardno): array
{
    $cardno = trim($cardno);
    if ($cardno === '') {
        throw new RuntimeException('银行卡号不能为空');
    }

    return [
        'card_no' => $cardno,
        'bank_code' => 'UNIONPAY',
        'bank_name' => '银联',
        'card_type' => 'DC',
    ];
}

function checkmobile(): bool
{
    return getDevice() !== 'pc';
}
