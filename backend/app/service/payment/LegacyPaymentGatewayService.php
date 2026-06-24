<?php

declare(strict_types=1);

namespace app\service\payment;

use app\common\PaymentContext;
use app\model\ChannelType;
use app\model\MerchantChannel;
use app\service\system\ConfigService;
use app\service\system\MerchantChannelService;
use app\service\system\PaymentMetaService;
use app\service\system\PluginCodeService;
use plugins\payment\common\StaticPaymentPlugin;
use RuntimeException;
use support\Request;
use support\Response;

class LegacyPaymentGatewayService
{
    public static function entryUrl(string $tradeNo, string $action = 'submit', array $query = []): string
    {
        $base = rtrim(ConfigService::gatewayBaseUrl(), '/');
        $action = strtolower(trim($action));
        if ($action === '') {
            $action = 'submit';
        }

        $path = '/pay/' . rawurlencode($action) . '/' . rawurlencode($tradeNo);
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $base . $path;
    }

    public static function run(string $tradeNo, string $action, Request $request, ?string $method = null): array
    {
        $order = OrderService::findByTradeNo($tradeNo);
        $channel = self::resolveLegacyChannel($order);
        $plugin = self::instantiatePlugin($channel);
        $legacyOrder = self::legacyOrderArray($order);
        $action = strtolower(trim($action));
        if ($action === '') {
            $action = 'submit';
        }

        $context = self::buildContext($legacyOrder, $request, $method, $channel, $action);

        if (!method_exists($plugin, $action)) {
            if ($action === 'mapi' && method_exists($plugin, 'submit')) {
                return [
                    'type' => 'jump',
                    'url' => self::entryUrl($tradeNo, 'submit'),
                ];
            }

            throw new RuntimeException(self::pluginActionMissingMessage($action));
        }

        return (array)$plugin->{$action}($context);
    }


    public static function execute(string $tradeNo, string $action, Request $request, ?string $method = null): Response
    {
        try {
            $result = self::run($tradeNo, $action, $request, $method);
            if (str_contains(strtolower($action), 'notify')) {
                self::writeNotifyLog($action, 'success', $request, [
                    'trade_no' => $tradeNo,
                    'result_type' => (string)($result['type'] ?? ''),
                ]);
            }
            return self::toResponse($result, $tradeNo);
        } catch (RuntimeException $exception) {
            if (str_contains(strtolower($action), 'notify')) {
                return self::strictNotifyFailureResponse($action, $request, $exception, [
                    'trade_no' => $tradeNo,
                ]);
            }
            throw $exception;
        }
    }

    public static function executeSoft(string $tradeNo, string $action, Request $request, ?string $method = null): Response
    {
        try {
            $result = self::run($tradeNo, $action, $request, $method);
            self::writeNotifyLog($action, 'success', $request, [
                'trade_no' => $tradeNo,
                'result_type' => (string)($result['type'] ?? ''),
            ]);
            return self::toResponse($result, $tradeNo);
        } catch (RuntimeException $exception) {
            return self::strictNotifyFailureResponse($action, $request, $exception, [
                'trade_no' => $tradeNo,
            ]);
        }
    }


    public static function executeChannelSoft(string $channelId, string $action, Request $request): Response
    {
        try {
            $channel = self::resolveChannelById((int)$channelId);
            $plugin = self::instantiatePlugin($channel);
            $order = self::emptyLegacyOrder($channel);
            $action = strtolower(trim($action));
            if ($action === '') {
                throw new RuntimeException(self::pluginActionRequiredMessage());
            }

            $context = self::buildContext($order, $request, null, $channel, $action);
            if (!method_exists($plugin, $action)) {
                throw new RuntimeException(self::pluginActionMissingMessage($action));
            }

            $result = (array)$plugin->{$action}($context);
            self::writeNotifyLog($action, 'success', $request, [
                'channel_id' => (int)($channel['id'] ?? 0),
                'merchant_id' => (int)($channel['merchant_id'] ?? 0),
                'plugin_code' => (string)($channel['plugin_code'] ?? $channel['plugin'] ?? ''),
                'method_code' => (string)($channel['channel_code'] ?? $channel['type'] ?? ''),
                'result_type' => (string)($result['type'] ?? ''),
            ]);
            return self::toResponse($result, (string)($order['trade_no'] ?? ''));
        } catch (RuntimeException $exception) {
            return self::strictNotifyFailureResponse($action, $request, $exception, [
                'channel_id' => (int)$channelId,
            ]);
        }
    }


    public static function query(string $tradeNo): array
    {
        $order = OrderService::findByTradeNo($tradeNo);
        $channel = self::resolveLegacyChannel($order);
        $plugin = self::instantiatePlugin($channel);

        if (!method_exists($plugin, 'query')) {
            throw new RuntimeException(self::queryUnsupportedMessage());
        }

        return (array)$plugin->query(self::legacyOrderArray($order));
    }


    public static function legacyChannelArray(object $order): array
    {
        return self::resolveLegacyChannel($order);
    }

    public static function legacyOrderArray(object $order): array
    {
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $notifyPayload = is_array($order->notify_payload ?? null) ? $order->notify_payload : [];
        $context = array_replace($requestPayload, $notifyPayload);
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($order->channel_code ?? ''));

        return [
            'id' => (int)($order->id ?? 0),
            'trade_no' => (string)($order->trade_no ?? ''),
            'out_trade_no' => (string)($order->out_trade_no ?? ''),
            'name' => (string)($order->subject ?? ''),
            'subject' => (string)($order->subject ?? ''),
            'typename' => $methodCode,
            'typeshowname' => PaymentMetaService::friendlyMethodName($methodCode),
            'realmoney' => number_format((float)($order->amount ?? 0), 2, '.', ''),
            'money' => number_format((float)($order->amount ?? 0), 2, '.', ''),
            'channel' => (int)($order->merchant_channel_id ?? 0),
            'buyer' => trim((string)($context['buyer'] ?? '')),
            'api_trade_no' => trim((string)($context['api_trade_no'] ?? $order->txid ?? '')),
            'bill_trade_no' => trim((string)($context['bill_trade_no'] ?? $context['api_trade_no'] ?? $order->txid ?? '')),
            'bill_mch_trade_no' => trim((string)($context['bill_mch_trade_no'] ?? '')),
            'addtime' => (string)($order->created_at ?? ''),
            'endtime' => (string)($order->pay_time ?? ''),
            'status' => (int)($order->status ?? 0),
            'notify_url' => (string)($order->notify_url ?? ''),
            'return_url' => (string)($order->return_url ?? ''),
            'param' => (string)($order->param ?? ''),
            'clientip' => (string)($order->client_ip ?? ''),
            'payment_address' => (string)($order->payment_address ?? ''),
            'ext' => is_array($context['legacy_ext'] ?? null) ? $context['legacy_ext'] : [],
        ];
    }

    public static function resolveLegacyChannel(object $order): array
    {
        $merchantChannelId = (int)($order->merchant_channel_id ?? 0);
        $config = [];
        $rate = 0.0;
        $remark = '';
        $requestPayload = is_array($order->request_payload ?? null) ? $order->request_payload : [];
        $snapshot = is_array($requestPayload['_legacy_channel'] ?? null) ? $requestPayload['_legacy_channel'] : [];

        if ($snapshot !== []) {
            $merchantChannelId = (int)($snapshot['merchant_channel_id'] ?? $merchantChannelId);
            $config = is_array($snapshot['config'] ?? null) ? $snapshot['config'] : [];
            $rate = (float)($snapshot['rate'] ?? 0);
            $remark = (string)($snapshot['remark'] ?? '');
        }

        if ($config === [] && self::canUseDatabase()) {
            $merchantChannel = MerchantChannel::find($merchantChannelId);
            if ($merchantChannel) {
                $config = is_array($merchantChannel->config ?? null) ? $merchantChannel->config : [];
                $rate = (float)($merchantChannel->rate ?? 0);
                $remark = (string)($merchantChannel->remark ?? '');
            }
        } elseif ($config === []) {
            $payload = MerchantChannelService::all((int)$order->merchant_id);
            foreach (($payload['items'] ?? []) as $item) {
                if ((int)($item['id'] ?? 0) !== $merchantChannelId) {
                    continue;
                }

                $config = is_array($item['config'] ?? null) ? $item['config'] : [];
                $rate = (float)str_replace('%', '', (string)($item['rate'] ?? '0'));
                $remark = (string)($item['remark'] ?? '');
                break;
            }
        }

        if (($config['method_code'] ?? '') === '' && ($snapshot['channel_code'] ?? '') !== '') {
            $config['method_code'] = (string)$snapshot['channel_code'];
        }
        if (($config['payment_address'] ?? '') === '' && ($snapshot['payment_address'] ?? '') !== '') {
            $config['payment_address'] = (string)$snapshot['payment_address'];
        }
        if (($config['display_value'] ?? '') === '' && ($snapshot['payment_address'] ?? '') !== '') {
            $config['display_value'] = (string)$snapshot['payment_address'];
        }
        if (($config['plugin_code'] ?? '') === '' && ($snapshot['plugin_code'] ?? '') !== '') {
            $config['plugin_code'] = (string)$snapshot['plugin_code'];
        }
        if (($config['plugin_kind'] ?? '') === '' && ($snapshot['plugin_kind'] ?? '') !== '') {
            $config['plugin_kind'] = (string)$snapshot['plugin_kind'];
        }

        return self::legacyChannelFromConfig(
            $merchantChannelId,
            (int)($order->merchant_id ?? 0),
            PaymentMetaService::normalizeMethodCode((string)($config['method_code'] ?? $order->channel_code ?? '')),
            $config,
            $rate,
            $remark
        );
    }

    private static function resolveChannelById(int $channelId): array
    {
        if ($channelId <= 0) {
            throw new RuntimeException(self::channelIdInvalidMessage());
        }

        if (self::canUseDatabase()) {
            $merchantChannel = MerchantChannel::find($channelId);
            if ($merchantChannel) {
                $channelType = ChannelType::find((int)$merchantChannel->channel_type_id);
                return self::legacyChannelFromMerchantChannel($merchantChannel, $channelType);
            }
        }

        foreach (self::localMerchantIds() as $merchantId) {
            foreach ((MerchantChannelService::all($merchantId)['items'] ?? []) as $item) {
                if ((int)($item['id'] ?? 0) !== $channelId) {
                    continue;
                }

                return self::legacyChannelFromJsonItem($merchantId, $item);
            }
        }

        throw new RuntimeException(self::channelNotFoundMessage($channelId));
    }


    private static function legacyChannelFromMerchantChannel(object $merchantChannel, ?object $channelType = null): array
    {
        return LegacyChannelFormatter::fromMerchantChannel($merchantChannel, $channelType);
    }

    private static function legacyChannelFromJsonItem(int $merchantId, array $item): array
    {
        return LegacyChannelFormatter::fromSerializedItem($merchantId, $item);
    }

    private static function legacyChannelFromConfig(
        int $channelId,
        int $merchantId,
        string $methodCode,
        array $config,
        float $rate,
        string $remark
    ): array {
        return LegacyChannelFormatter::fromConfig($channelId, $merchantId, $methodCode, $config, $rate, $remark);
    }

    private static function instantiatePlugin(array $channel): object
    {
        $pluginDir = legacy_plugin_directory_name((string)($channel['plugin'] ?? $channel['plugin_code'] ?? ''));
        $classBase = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $pluginDir)));
        $className = 'plugins\\payment\\' . $pluginDir . '\\' . $classBase . 'Plugin';

        if (!class_exists($className)) {
            return new StaticPaymentPlugin($channel);
        }

        return new $className($channel);
    }

    private static function buildContext(
        array $order,
        Request $request,
        ?string $method = null,
        array $channel = [],
        string $action = ''
    ): PaymentContext
    {
        $device = self::resolveMobileDevice($request);

        return new PaymentContext(
            order: $order,
            ordername: (string)($order['name'] ?? ''),
            method: $method !== null && $method !== '' ? $method : (string)($order['typename'] ?? ''),
            isMobile: checkmobile(),
            mdevice: $device,
            siteurl: $request->siteUrl(),
            clientip: $request->clientIp(),
            query: is_array($request->get()) ? $request->get() : [],
            form: is_array($request->post()) ? $request->post() : [],
            runtime: [
                'action' => strtolower(trim($action)),
                'channel_id' => (int)($channel['id'] ?? 0),
                'merchant_id' => (int)($channel['merchant_id'] ?? 0),
                'plugin_code' => PluginCodeService::normalize((string)($channel['plugin_code'] ?? $channel['plugin'] ?? '')),
            ],
        );
    }

    private static function toResponse(array $result, string $tradeNo): Response
    {
        $type = strtolower(trim((string)($result['type'] ?? 'error')));
        $checkoutUrl = self::gatewayPathUrl('/pay/checkout/' . rawurlencode($tradeNo));

        return match ($type) {
            'jump', 'redirect' => redirect(self::normalizeResultUrl((string)($result['url'] ?? ''), $checkoutUrl)),
            'html' => response((string)($result['data'] ?? ''), 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'error' => self::friendlyErrorResponse($tradeNo, $result),
            'json' => response(
                json_encode($result['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int)($result['code'] ?? 200),
                ['Content-Type' => 'application/json; charset=utf-8']
            ),
            'plain', 'text' => response((string)($result['data'] ?? $result['msg'] ?? ''), (int)($result['code'] ?? 200), ['Content-Type' => 'text/plain; charset=utf-8']),
            'xml' => response((string)($result['data'] ?? ''), (int)($result['code'] ?? 200), ['Content-Type' => 'application/xml; charset=utf-8']),
            'qrcode' => self::qrcodeResponse($tradeNo, $result),
            'scheme' => redirect(self::normalizeResultUrl((string)($result['url'] ?? ''), $checkoutUrl)),
            'return' => redirect(self::normalizeResultUrl((string)($result['url'] ?? ''), $checkoutUrl)),
            'page' => self::friendlyPageResponse($tradeNo, $result),
            default => response(self::unsupportedResponseTypeMessage(), 200, ['Content-Type' => 'text/plain; charset=utf-8']),
        };
    }

    private static function friendlyErrorResponse(string $tradeNo, array $result): Response
    {
        $message = GatewayCompatService::normalizeGatewayErrorMessageSafe((string)($result['msg'] ?? $result['data'] ?? '支付请求失败'));
        $checkoutUrl = '/pay/checkout/' . rawurlencode($tradeNo);
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>NexPay 支付失败</title><style>body{margin:0;background:#f4f7fb;font-family:"Segoe UI","Microsoft YaHei",sans-serif;color:#172033}.wrap{max-width:720px;margin:56px auto;padding:0 18px}.card{background:#fff;border:1px solid #dbe7f6;border-radius:20px;padding:28px;box-shadow:0 18px 48px rgba(15,23,42,.08)}h1{margin:0 0 14px;font-size:26px}.msg{padding:16px 18px;border-radius:14px;background:#fff6f6;border:1px solid #ffd5d5;color:#b42318;line-height:1.7;word-break:break-word}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}.btn{display:inline-flex;align-items:center;justify-content:center;min-width:132px;height:42px;padding:0 18px;border-radius:12px;text-decoration:none;font-weight:600}.btn-primary{background:#1677ff;color:#fff}.btn-light{background:#eef4ff;color:#1677ff}</style></head><body><div class="wrap"><div class="card"><h1>支付请求失败</h1><div class="msg">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div><div class="actions"><a class="btn btn-primary" href="'
            . htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8')
            . '">返回收银台</a></div></div></div></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private static function friendlyPageResponse(string $tradeNo, array $result): Response
    {
        $page = strtolower(trim((string)($result['page'] ?? '')));
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        if ($page === 'return') {
            return redirect(self::normalizeResultUrl(
                (string)($data['redirect_url'] ?? $result['url'] ?? ''),
                self::gatewayPathUrl('/pay/checkout/' . rawurlencode($tradeNo))
            ));
        }

        return self::friendlyErrorResponse($tradeNo, [
            'msg' => '当前支付方式需要专用页面支持，暂未接入该展示类型：' . ($page !== '' ? $page : 'unknown'),
        ]);
    }

    private static function errorResponse(string $tradeNo, array $result): Response
    {
        $message = trim((string)($result['msg'] ?? $result['data'] ?? '支付请求失败'));
        $checkoutUrl = '/pay/checkout/' . rawurlencode($tradeNo);
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>NexPay 支付失败</title><style>body{margin:0;background:#f4f7fb;font-family:"Segoe UI","Microsoft YaHei",sans-serif;color:#172033}.wrap{max-width:720px;margin:56px auto;padding:0 18px}.card{background:#fff;border:1px solid #dbe7f6;border-radius:20px;padding:28px;box-shadow:0 18px 48px rgba(15,23,42,.08)}h1{margin:0 0 14px;font-size:26px}.msg{padding:16px 18px;border-radius:14px;background:#fff6f6;border:1px solid #ffd5d5;color:#b42318;line-height:1.7;word-break:break-word}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}.btn{display:inline-flex;align-items:center;justify-content:center;min-width:132px;height:42px;padding:0 18px;border-radius:12px;text-decoration:none;font-weight:600}.btn-primary{background:#1677ff;color:#fff}.btn-light{background:#eef4ff;color:#1677ff}</style></head><body><div class="wrap"><div class="card"><h1>支付请求失败</h1><div class="msg">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div><div class="actions"><a class="btn btn-primary" href="'
            . htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8')
            . '">返回收银台</a></div></div></div></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private static function pageResponse(string $tradeNo, array $result): Response
    {
        $page = strtolower(trim((string)($result['page'] ?? '')));
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return match ($page) {
            'return' => redirect(self::normalizeResultUrl(
                (string)($data['redirect_url'] ?? $result['url'] ?? ''),
                self::gatewayPathUrl('/pay/checkout/' . rawurlencode($tradeNo))
            )),
            default => self::errorResponse($tradeNo, [
                'msg' => '当前支付方式需要专用页面支持，暂未接入该展示类型：' . ($page !== '' ? $page : 'unknown'),
            ]),
        };
    }


    private static function qrcodeResponse(string $tradeNo, array $result): Response
    {
        $rawUrl = self::normalizeResultUrl(QrCodeService::extractGatewaySource($result));
        if ($rawUrl !== '') {
            QrCodeService::rememberOrderSource($tradeNo, $rawUrl, array_filter([
                'type' => 'qrcode',
                'page' => trim((string)($result['page'] ?? '')),
            ], static fn(mixed $value): bool => is_string($value) && $value !== ''));
        }

        $checkoutUrl = '/pay/checkout/' . rawurlencode($tradeNo);
        return redirect(self::gatewayPathUrl($checkoutUrl));
    }

    private static function normalizeResultUrl(string $url, string $fallback = ''): string
    {
        $url = trim($url);
        if ($url === '') {
            return $fallback;
        }

        if (str_starts_with($url, '//')) {
            $scheme = (string)(parse_url(ConfigService::gatewayBaseUrl(), PHP_URL_SCHEME) ?: 'http');
            return $scheme . ':' . $url;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            $parts = parse_url($url);
            $host = strtolower((string)($parts['host'] ?? ''));
            $path = (string)($parts['path'] ?? '');
            if (str_starts_with($path, '/pay/') && self::shouldRewriteGatewayHost($host)) {
                $rewritten = self::gatewayPathUrl($path);
                if (($parts['query'] ?? '') !== '') {
                    $rewritten .= '?' . $parts['query'];
                }
                if (($parts['fragment'] ?? '') !== '') {
                    $rewritten .= '#' . $parts['fragment'];
                }
                return $rewritten;
            }

            return $url;
        }

        return self::gatewayPathUrl($url);
    }

    private static function gatewayPathUrl(string $path): string
    {
        return rtrim(ConfigService::gatewayBaseUrl(), '/') . '/' . ltrim($path, '/');
    }

    private static function shouldRewriteGatewayHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $gatewayHost = strtolower((string)(parse_url(ConfigService::gatewayBaseUrl(), PHP_URL_HOST) ?: ''));
        if ($gatewayHost !== '' && $host === $gatewayHost) {
            return true;
        }

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }


    private static function emptyLegacyOrder(array $channel): array
    {
        $methodCode = PaymentMetaService::normalizeMethodCode((string)($channel['type'] ?? $channel['channel_code'] ?? ''));

        return [
            'id' => 0,
            'trade_no' => '',
            'out_trade_no' => '',
            'name' => 'Channel test order',
            'subject' => 'Channel test order',
            'typename' => $methodCode,
            'typeshowname' => PaymentMetaService::friendlyMethodName($methodCode),
            'realmoney' => '0.00',
            'money' => '0.00',
            'channel' => (int)($channel['id'] ?? 0),
            'buyer' => '',
            'api_trade_no' => '',
            'bill_trade_no' => '',
            'bill_mch_trade_no' => '',
            'addtime' => date('Y-m-d H:i:s'),
            'endtime' => '',
            'status' => 0,
            'notify_url' => '',
            'return_url' => '',
            'param' => '',
            'clientip' => '',
            'payment_address' => '',
            'ext' => [],
        ];
    }


    private static function pluginActionRequiredMessage(): string
    {
        return 'Plugin action cannot be empty';
    }

    private static function pluginActionMissingPrefix(): string
    {
        return 'Payment plugin action is not implemented:';
    }

    private static function pluginActionMissingMessage(string $action): string
    {
        return self::pluginActionMissingPrefix() . ' ' . $action;
    }

    private static function isPluginActionMissingMessage(string $message): bool
    {
        return str_starts_with(trim($message), self::pluginActionMissingPrefix());
    }

    private static function queryUnsupportedMessage(): string
    {
        return 'Current plugin does not support query';
    }

    private static function isQueryUnsupportedMessage(string $message): bool
    {
        return trim($message) === self::queryUnsupportedMessage();
    }

    private static function channelIdInvalidMessage(): string
    {
        return 'Channel id is invalid';
    }

    private static function channelNotFoundPrefix(): string
    {
        return 'Channel not found:';
    }

    private static function channelNotFoundMessage(int $channelId): string
    {
        return self::channelNotFoundPrefix() . ' ' . $channelId;
    }

    private static function unsupportedResponseTypeMessage(): string
    {
        return 'Payment plugin returned an unsupported response type';
    }

    private static function strictNotifyFailureResponse(
        string $action,
        Request $request,
        RuntimeException $exception,
        array $logExtra = []
    ): Response {
        $message = trim($exception->getMessage());

        self::writeNotifyLog($action, 'failed', $request, array_replace($logExtra, [
            'message' => $message,
            'context' => [
                'strict_notify' => true,
                'fallback_disabled' => true,
                'failure_reason' => self::strictNotifyFailureReason($message),
            ],
        ]));

        return response('fail', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private static function strictNotifyFailureReason(string $message): string
    {
        $message = trim($message);
        if ($message === self::pluginActionRequiredMessage()) {
            return 'plugin_action_required';
        }
        if (self::isPluginActionMissingMessage($message)) {
            return 'plugin_action_missing';
        }
        if (self::isQueryUnsupportedMessage($message)) {
            return 'query_unsupported';
        }

        return 'runtime_exception';
    }

    // Historical fallback implementation kept for audit/reference only.
    // Strict notify mode no longer routes runtime traffic through this path.
    private static function genericNotifyFallback(string $routeKey, string $action, Request $request): array
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['refundnotify', 'transfernotify'], true)) {
            return ['handled' => false];
        }

        $payload = self::genericNotifyPayload($request);
        if ($payload === []) {
            self::writeNotifyLog($action, 'skipped', $request, [
                'message' => 'generic notify fallback skipped: empty payload',
                'context' => ['route_key' => $routeKey],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }

        try {
            if ($action === 'refundnotify') {
                return self::genericRefundNotifyFallback($routeKey, $request, $payload);
            }

            return self::genericTransferNotifyFallback($routeKey, $request, $payload);
        } catch (\Throwable $exception) {
            self::writeNotifyLog($action, 'failed', $request, [
                'message' => $exception->getMessage(),
                'context' => ['route_key' => $routeKey, 'payload' => $payload],
            ]);
            return [
                'handled' => true,
                'response' => ['type' => 'plain', 'data' => 'fail'],
            ];
        }
    }

    private static function genericRefundNotifyFallback(string $tradeNo, Request $request, array $payload): array
    {
        $refundNo = self::firstPayloadValue($payload, ['refund_no', 'refundNo', 'refund_order_id', 'refundOrderId', 'out_refund_no', 'outRefundNo']);
        $outRefundNo = self::firstPayloadValue($payload, ['out_refund_no', 'outRefundNo', 'refund_order_id', 'refundOrderId']);
        $refund = LocalTransferStore::findRefundByAnyNo($refundNo, $outRefundNo);
        if (!$refund && trim($tradeNo) !== '') {
            $refund = LocalTransferStore::findRefundByTradeNo($tradeNo);
        }

        if (!$refund) {
            self::writeNotifyLog('refundnotify', 'skipped', $request, [
                'message' => 'generic refund notify fallback skipped: refund not found',
                'trade_no' => $tradeNo,
                'context' => ['refund_no' => $refundNo, 'out_refund_no' => $outRefundNo, 'payload' => $payload],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }

        $routeTradeNo = trim($tradeNo);
        $refundTradeNo = trim((string)($refund->trade_no ?? ''));
        if ($routeTradeNo !== '' && $refundTradeNo !== '' && $routeTradeNo !== $refundTradeNo) {
            self::writeNotifyLog('refundnotify', 'skipped', $request, [
                'message' => 'generic refund notify fallback skipped: route trade mismatch',
                'trade_no' => $routeTradeNo,
                'context' => [
                    'refund_no' => (string)$refund->refund_no,
                    'refund_trade_no' => $refundTradeNo,
                    'payload' => $payload,
                ],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }

        $order = $refundTradeNo !== '' ? self::findOrderForNotify($refundTradeNo) : null;
        $orderChannel = self::safeLegacyChannelForNotify($order);
        $orderChannelId = (int)($orderChannel['id'] ?? $order->merchant_channel_id ?? 0);
        $refundChannelId = (int)($refund->channel_id ?? 0);
        $refundPluginCode = PluginCodeService::normalize((string)($refund->channel_plugin_code ?? ''));
        $orderPluginCode = PluginCodeService::normalize((string)($orderChannel['plugin_code'] ?? $orderChannel['plugin'] ?? ''));

        if ($refundChannelId > 0 && $orderChannelId > 0 && $refundChannelId !== $orderChannelId) {
            self::writeNotifyLog('refundnotify', 'skipped', $request, [
                'message' => 'generic refund notify fallback skipped: channel mismatch',
                'trade_no' => $refundTradeNo !== '' ? $refundTradeNo : $routeTradeNo,
                'context' => [
                    'refund_no' => (string)$refund->refund_no,
                    'refund_channel_id' => $refundChannelId,
                    'order_channel_id' => $orderChannelId,
                    'payload' => $payload,
                ],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }

        if ($refundChannelId <= 0 && $refundPluginCode !== '' && $orderPluginCode !== '' && $refundPluginCode !== $orderPluginCode) {
            self::writeNotifyLog('refundnotify', 'skipped', $request, [
                'message' => 'generic refund notify fallback skipped: plugin mismatch',
                'trade_no' => $refundTradeNo !== '' ? $refundTradeNo : $routeTradeNo,
                'context' => [
                    'refund_no' => (string)$refund->refund_no,
                    'refund_plugin_code' => $refundPluginCode,
                    'order_plugin_code' => $orderPluginCode,
                    'payload' => $payload,
                ],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }

        if ((int)($refund->status ?? 0) !== 0) {
            self::writeNotifyLog('refundnotify', 'skipped', $request, [
                'message' => 'generic refund notify fallback skipped: already processed',
                'trade_no' => (string)($refund->trade_no ?? $tradeNo),
                'context' => ['refund_no' => (string)$refund->refund_no, 'current_status' => (int)$refund->status],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'success']];
        }

        $status = self::genericNotifyStatus($payload);
        if ($status === null) {
            self::writeNotifyLog('refundnotify', 'skipped', $request, [
                'message' => 'generic refund notify fallback skipped: status not recognized',
                'trade_no' => (string)($refund->trade_no ?? $tradeNo),
                'context' => ['refund_no' => (string)$refund->refund_no, 'payload' => $payload],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }
        $message = self::firstPayloadValue($payload, ['errmsg', 'err_msg', 'message', 'msg', 'reason', 'fail_reason', 'statusIllustrate']);
        $channelOrderNo = self::firstPayloadValue($payload, ['channel_order_no', 'channelOrderNo', 'trade_no', 'tradeNo', 'transaction_id', 'order_no', 'orderNo']);
        $amount = self::firstPayloadValue($payload, ['refund_fee', 'refundFee', 'refund_amount', 'refundAmount', 'amount', 'money']);
        $now = date('Y-m-d H:i:s');
        $pluginCode = $refundPluginCode !== '' ? $refundPluginCode : $orderPluginCode;
        $channelId = $refundChannelId > 0 ? $refundChannelId : $orderChannelId;

        if ($status === 0) {
            LocalTransferStore::updateRefund((string)$refund->refund_no, [
                'status' => 0,
                'result' => 'plugin_refund_pending',
                'last_error' => $message !== '' ? $message : 'Generic refund notify pending',
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelOrderNo,
                'channel_plugin_code' => $pluginCode,
                'channel_id' => $channelId,
                'raw_response' => ['source' => 'generic-refund-notify', 'payload' => $payload, 'received_at' => $now],
            ]);
            self::writeNotifyLog('refundnotify', 'pending', $request, [
                'trade_no' => (string)($refund->trade_no ?? $tradeNo),
                'message' => 'generic refund notify pending',
                'context' => ['refund_no' => (string)$refund->refund_no, 'payload' => $payload],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'success']];
        }

        if ($status === 2) {
            LocalTransferStore::updateRefund((string)$refund->refund_no, [
                'status' => 2,
                'result' => 'plugin_refund_failed',
                'last_error' => $message !== '' ? $message : 'Generic refund notify failed',
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelOrderNo,
                'channel_plugin_code' => $pluginCode,
                'channel_id' => $channelId,
                'raw_response' => ['source' => 'generic-refund-notify', 'payload' => $payload, 'received_at' => $now],
            ]);
            self::writeNotifyLog('refundnotify', 'failed', $request, [
                'trade_no' => (string)($refund->trade_no ?? $tradeNo),
                'message' => $message !== '' ? $message : 'generic refund notify failed',
                'context' => ['refund_no' => (string)$refund->refund_no, 'payload' => $payload],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'success']];
        }

        $money = number_format((float)($amount !== '' ? $amount : ($refund->reducemoney ?? $refund->money ?? 0)), 2, '.', '');
        $flow = LocalFundStore::debit(
            (int)$refund->merchant_id,
            $money,
            '闂侇偀鍋撴繛鍡欏亾婢х鈻?',
            'refund',
            (string)$refund->refund_no,
            $now,
            [
                'trade_no' => (string)$refund->trade_no,
                'out_trade_no' => (string)$refund->out_trade_no,
                'out_refund_no' => (string)$refund->out_refund_no,
                'plugin_code' => $pluginCode,
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelOrderNo,
            ]
        );

        LocalTransferStore::updateRefund((string)$refund->refund_no, [
            'status' => 1,
            'result' => 'plugin_refunded',
            'last_error' => '',
            'channel_order_no' => $channelOrderNo,
            'channel_trade_no' => $channelOrderNo,
            'channel_plugin_code' => $pluginCode,
            'channel_id' => $channelId,
            'proof_no' => $channelOrderNo,
            'operator' => 'plugin:' . $pluginCode,
            'finished_at' => $now,
            'raw_response' => ['source' => 'generic-refund-notify', 'payload' => $payload, 'balance_after' => (string)($flow->balance_after ?? ''), 'received_at' => $now],
        ]);
        self::writeNotifyLog('refundnotify', 'success', $request, [
            'trade_no' => (string)($refund->trade_no ?? $tradeNo),
            'message' => 'generic refund notify completed',
            'context' => ['refund_no' => (string)$refund->refund_no, 'amount' => $money, 'balance_after' => (string)($flow->balance_after ?? '')],
        ]);

        return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'success']];
    }

    private static function genericTransferNotifyFallback(string $channelId, Request $request, array $payload): array
    {
        $routeChannelId = (int)$channelId;
        $outBizNo = self::firstPayloadValue($payload, ['out_biz_no', 'outBizNo', 'out_trade_no', 'outTradeNo', 'mchOrderNo', 'requestId', 'biz_no', 'bizNo']);
        $bizNo = self::firstPayloadValue($payload, ['biz_no', 'bizNo', 'order_no', 'orderNo', 'trade_no', 'tradeNo']);
        $transfer = LocalTransferStore::findTransferByBizNo($bizNo, $outBizNo);
        if (!$transfer) {
            self::writeNotifyLog('transfernotify', 'skipped', $request, [
                'channel_id' => $routeChannelId,
                'message' => 'generic transfer notify fallback skipped: transfer not found',
                'context' => ['biz_no' => $bizNo, 'out_biz_no' => $outBizNo, 'payload' => $payload],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }

        if ($routeChannelId <= 0) {
            self::writeNotifyLog('transfernotify', 'skipped', $request, [
                'channel_id' => $routeChannelId,
                'message' => 'generic transfer notify fallback skipped: invalid route channel',
                'context' => ['biz_no' => (string)$transfer->biz_no, 'payload' => $payload],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }

        $transferChannelId = (int)($transfer->channel_id ?? 0);
        if ($transferChannelId > 0 && $transferChannelId !== $routeChannelId) {
            self::writeNotifyLog('transfernotify', 'skipped', $request, [
                'channel_id' => $routeChannelId,
                'message' => 'generic transfer notify fallback skipped: channel mismatch',
                'context' => [
                    'biz_no' => (string)$transfer->biz_no,
                    'transfer_channel_id' => $transferChannelId,
                    'route_channel_id' => $routeChannelId,
                    'payload' => $payload,
                ],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }

        $routeChannel = self::safeChannelByIdForNotify($routeChannelId);
        $transferPluginCode = PluginCodeService::normalize((string)($transfer->channel_plugin_code ?? ''));
        $routePluginCode = PluginCodeService::normalize((string)($routeChannel['plugin_code'] ?? $routeChannel['plugin'] ?? ''));
        if ($transferPluginCode !== '' && $routePluginCode !== '' && $transferPluginCode !== $routePluginCode) {
            self::writeNotifyLog('transfernotify', 'skipped', $request, [
                'channel_id' => $routeChannelId,
                'message' => 'generic transfer notify fallback skipped: plugin mismatch',
                'context' => [
                    'biz_no' => (string)$transfer->biz_no,
                    'transfer_plugin_code' => $transferPluginCode,
                    'route_plugin_code' => $routePluginCode,
                    'payload' => $payload,
                ],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }

        if ((int)($transfer->status ?? 0) !== 0) {
            self::writeNotifyLog('transfernotify', 'skipped', $request, [
                'channel_id' => $routeChannelId,
                'message' => 'generic transfer notify fallback skipped: already processed',
                'context' => ['biz_no' => (string)$transfer->biz_no, 'current_status' => (int)$transfer->status],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'success']];
        }

        $status = self::genericNotifyStatus($payload);
        if ($status === null) {
            self::writeNotifyLog('transfernotify', 'skipped', $request, [
                'channel_id' => $routeChannelId,
                'message' => 'generic transfer notify fallback skipped: status not recognized',
                'context' => ['biz_no' => (string)$transfer->biz_no, 'payload' => $payload],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'fail']];
        }
        $message = self::firstPayloadValue($payload, ['errmsg', 'err_msg', 'message', 'msg', 'reason', 'fail_reason', 'statusIllustrate']);
        $channelOrderNo = self::firstPayloadValue($payload, ['channel_order_no', 'channelOrderNo', 'trade_no', 'tradeNo', 'orderid', 'order_id', 'orderNo']);
        if ($channelOrderNo === '') {
            $channelOrderNo = (string)($transfer->channel_order_no ?? $transfer->biz_no);
        }
        $now = date('Y-m-d H:i:s');
        $pluginCode = $transferPluginCode !== '' ? $transferPluginCode : $routePluginCode;
        $storedChannelId = $transferChannelId > 0 ? $transferChannelId : $routeChannelId;

        if ($status === 0) {
            LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
                'status' => 0,
                'result' => 'plugin_transfer_pending',
                'last_error' => $message !== '' ? $message : 'Generic transfer notify pending',
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelOrderNo,
                'channel_plugin_code' => $pluginCode,
                'channel_id' => $storedChannelId,
                'raw_response' => ['source' => 'generic-transfer-notify', 'payload' => $payload, 'received_at' => $now],
            ]);
            self::writeNotifyLog('transfernotify', 'pending', $request, [
                'channel_id' => $routeChannelId,
                'message' => 'generic transfer notify pending',
                'context' => ['biz_no' => (string)$transfer->biz_no, 'payload' => $payload],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'success']];
        }

        if ($status === 2) {
            LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
                'status' => 2,
                'result' => 'plugin_transfer_failed',
                'last_error' => $message !== '' ? $message : 'Generic transfer notify failed',
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelOrderNo,
                'channel_plugin_code' => $pluginCode,
                'channel_id' => $storedChannelId,
                'rejected_at' => $now,
                'raw_response' => ['source' => 'generic-transfer-notify', 'payload' => $payload, 'received_at' => $now],
            ]);
            self::writeNotifyLog('transfernotify', 'failed', $request, [
                'channel_id' => $routeChannelId,
                'message' => $message !== '' ? $message : 'generic transfer notify failed',
                'context' => ['biz_no' => (string)$transfer->biz_no, 'payload' => $payload],
            ]);
            return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'success']];
        }

        $money = number_format((float)($transfer->money ?? 0), 2, '.', '');
        $flow = LocalFundStore::debit(
            (int)$transfer->merchant_id,
            $money,
            '濞寸媴绲肩划顖炲箥閿濆棭鍎?',
            'transfer',
            (string)$transfer->biz_no,
            $now,
            [
                'out_biz_no' => (string)$transfer->out_biz_no,
                'type' => (string)$transfer->type,
                'account' => (string)$transfer->account,
                'name' => (string)$transfer->name,
                'plugin_code' => $pluginCode,
                'channel_order_no' => $channelOrderNo,
                'channel_trade_no' => $channelOrderNo,
            ]
        );

        LocalTransferStore::updateTransfer((string)$transfer->biz_no, [
            'status' => 1,
            'available_money' => (string)($flow->balance_after ?? ''),
            'channel_order_no' => $channelOrderNo,
            'channel_trade_no' => $channelOrderNo,
            'channel_plugin_code' => $pluginCode,
            'channel_id' => $storedChannelId,
            'proof_no' => $channelOrderNo,
            'operator' => 'plugin:' . $pluginCode,
            'result' => 'plugin_transferred',
            'last_error' => '',
            'finished_at' => $now,
            'raw_response' => ['source' => 'generic-transfer-notify', 'payload' => $payload, 'balance_after' => (string)($flow->balance_after ?? ''), 'received_at' => $now],
        ]);
        self::writeNotifyLog('transfernotify', 'success', $request, [
            'channel_id' => $routeChannelId,
            'message' => 'generic transfer notify completed',
            'context' => ['biz_no' => (string)$transfer->biz_no, 'amount' => $money, 'balance_after' => (string)($flow->balance_after ?? '')],
        ]);

        return ['handled' => true, 'response' => ['type' => 'plain', 'data' => 'success']];
    }

    private static function findOrderForNotify(string $tradeNo): ?object
    {
        try {
            return OrderService::findByTradeNoOrNull($tradeNo);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function safeLegacyChannelForNotify(?object $order): array
    {
        if (!$order) {
            return [];
        }

        try {
            return self::resolveLegacyChannel($order);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function safeChannelByIdForNotify(int $channelId): array
    {
        try {
            return self::resolveChannelById($channelId);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function genericNotifyPayload(Request $request): array
    {
        $payload = array_replace(
            is_array($request->get()) ? $request->get() : [],
            is_array($request->post()) ? $request->post() : []
        );

        $raw = trim((string)$request->rawBody());
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = array_replace($payload, $decoded);
            } else {
                parse_str($raw, $parsed);
                if (is_array($parsed) && $parsed !== []) {
                    $payload = array_replace($payload, $parsed);
                }
            }
        }

        return self::flattenPayload($payload);
    }

    private static function flattenPayload(array $payload, string $prefix = ''): array
    {
        $flat = [];
        foreach ($payload as $key => $value) {
            $key = is_int($key) ? (string)$key : trim((string)$key);
            if ($key === '') {
                continue;
            }

            $path = $prefix !== '' ? $prefix . '.' . $key : $key;
            if (is_array($value)) {
                $flat += self::flattenPayload($value, $path);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $flat[$key] = trim((string)$value);
                $flat[$path] = trim((string)$value);
            }
        }

        return $flat;
    }

    private static function firstPayloadValue(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && trim((string)$payload[$key]) !== '') {
                return trim((string)$payload[$key]);
            }
        }

        return '';
    }

    private static function genericNotifyStatus(array $payload): ?int
    {
        $raw = self::firstPayloadValue($payload, [
            'status',
            'trade_status',
            'tradeStatus',
            'refund_status',
            'refundStatus',
            'transfer_status',
            'transferStatus',
            'state',
            'orderStatus',
            'txnStatus',
            'result',
        ]);
        $text = strtoupper(trim($raw));
        if ($text === '') {
            return null;
        }

        if (is_numeric($text) && in_array((int)$text, [0, 1, 2], true)) {
            return (int)$text;
        }

        if (in_array($text, [
            'SUCCESS',
            'SUCCEED',
            'SUCCEEDED',
            'DONE',
            'FINISHED',
            'TRADE_SUCCESS',
            'TRADE_FINISHED',
            'REFUND_SUCCESS',
            'REFUNDED',
            'ALL_REFUND',
            'TRANSFER_SUCCESS',
            'TRANSFERRED',
            'PAY_SUCCESS',
            'PAYED',
            'PAID',
            'S',
        ], true)) {
            return 1;
        }

        if (in_array($text, [
            'FAILED',
            'FAIL',
            'FAILURE',
            'TRADE_FAIL',
            'TRADE_FAILED',
            'REFUND_FAILED',
            'TRANSFER_FAILED',
            'CLOSED',
            'TRADE_CLOSED',
            'CANCELLED',
            'CANCELED',
            'F',
            'R',
        ], true)) {
            return 2;
        }

        if (in_array($text, [
            'PENDING',
            'PROCESSING',
            'ACCEPTED',
            'WAITING',
            'IN_PROCESS',
            'WAIT_BUYER_PAY',
            'REFUND_PROCESSING',
            'REFUND_PENDING',
            'TRANSFER_PROCESSING',
            'TRANSFER_PENDING',
            'P',
        ], true)) {
            return 0;
        }

        return null;
    }

    private static function isFallbackableNotifyException(string $action, string $message): bool
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['refundnotify', 'transfernotify'], true)) {
            return false;
        }

        if (
            self::isPluginActionMissingMessage($message)
            || self::isQueryUnsupportedMessage($message)
            || trim($message) === self::pluginActionRequiredMessage()
            || trim($message) === self::channelIdInvalidMessage()
            || str_starts_with(trim($message), self::channelNotFoundPrefix())
        ) {
            return true;
        }

        return false;
    }


    private static function localMerchantIds(): array
    {
        $ids = [];
        foreach (\app\service\system\JsonStoreService::load('merchant_channels', []) as $row) {
            if (is_array($row) && (int)($row['merchant_id'] ?? 0) > 0) {
                $ids[] = (int)$row['merchant_id'];
            }
        }
        foreach (\app\service\system\JsonStoreService::load('merchant_auth_users', []) as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
                $ids[] = (int)$row['id'];
            }
        }
        foreach (\app\service\system\JsonStoreService::load('merchant_accounts', []) as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
                $ids[] = (int)$row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private static function resolveMobileDevice(Request $request): string
    {
        return match (true) {
            checkwechat() => 'wechat',
            checkalipay() => 'alipay',
            checkmobbileqq() => 'qq',
            checkdouyin() => 'douyin',
            checkunionpay() => 'unionpay',
            default => checkmobile() ? 'mobile' : 'pc',
        };
    }

    private static function canUseDatabase(): bool
    {
        static $usable = null;
        if ($usable !== null) {
            return $usable;
        }

        if (!database_available()) {
            $usable = false;
            return $usable;
        }

        try {
            \app\model\Order::where('id', '>', 0)->limit(1)->find();
            $usable = true;
        } catch (\Throwable) {
            $usable = false;
        }

        return $usable;
    }

    private static function writeNotifyLog(string $action, string $status, Request $request, array $extra = []): void
    {
        try {
            PluginNotifyLogService::write(array_replace([
                'action' => strtolower(trim($action)),
                'stage' => 'legacy-gateway',
                'status' => $status,
                'request' => PluginNotifyLogService::requestSnapshot($request),
            ], $extra));
        } catch (\Throwable) {
        }
    }
}
