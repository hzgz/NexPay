<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\payment\QrCodeService;

class MerchantChannelAlipayCkService
{
    private const LOGIN_PAGE_URL = 'https://auth.alipay.com/login/index.htm';
    private const LOGIN_REFERER = 'https://auth.alipay.com/login/index.htm?goto=https%3A%2F%2Fmy.alipay.com%2Fportal%2Fi.htm';
    private const STATUS_URL = 'https://securitycore.alipay.com/barcode/barcodeProcessStatus.json';
    private const LOGIN_SUBMIT_URL = 'https://authet2.alipay.com/login/index.htm';
    private const LOGIN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    private const LEGACY_FORM_UA = 'dxlTasyfLePewE7ifH7HyT5wJ5cASsGqMaYnOomEiyTBeHyI6CezVWDWcDouca6W6Y4Svep9ulZ8H0cF1X4Mgi.JZTbQL3NddYS7bCmG.cFh45fZXR3kTmjsMi3xByeTW2V5hnaat0y1OOiv8qoAfKgaUuigtJAp3UL2QgUVrpASMRKdStX0h.hzFfH26FHtMkCmnf1nRcw74yljdFFMC03XWUBNZDhPUI0aL76t.NVxaOJQngu.KFQoPrVjSWYgym6MackvvBhmL37Y0s4H.ROLsAdVrDnLoQR7y07wcwWbUSqq.6AdBebIIVg1RHjyn3K9ahqPk_HOBlXyg6_voFZWwvoFlVAZ9c_ARvTidwDCE.18sT9z2ELtWGaAVClk6HN0HXMQUwOH7Rr7sfpn3zp__eOAe75qTBmYMNXnYnChZmqWOAaVdJAcFpjoUtwtwwqcZvdvoX4_UH.06SpF.Z0i85GWt4jSkki5ijEyvav5KeLQX6Tvj7MziuxQasAOVX6CHZu62D3FhWwj1cesYq9iKyzNmhMcqc.ULS88i3oq2vZko8vOI3BFufd0GcYAMI.YS8a4IqoaE4ydLO4ALR.8WuHvpOZiilHq.hOZogZB2QoQApBuo5smKdhzGlcybdYtsoxPtD_jIRXtf7aRzIWtIlcgEHk6RyOhjsA7bSuWurcukkCAvTZtO05xq6s_lmjMUVNeyS34DpiEXKJlqmbi.amq3hj2Oph0AZtvPb8LvZJy8V.aYdcC4RH9UeAOEzzJgpeiiAAUcMRexpGs5ZDcBFdgn4MIXfq2aTIUFVHf0W3in7tGaeNmx1MUIWHHTL_3SmNYyPVaEo5qZzNLTMrc318MBiIFjcTmaub1IJw7IZefBjspVK9bzzYMwhe0ljkUqoCoshjN9rlXjKyJ.vsXLosDb7KEKmWejetEAPRlw2e49JmWJj5ohGrOGzlsTzyMUtY07nlv3gjXWkaUaE';

    public static function refreshLoginQrcode(int $merchantId, int $channelId): array
    {
        $channel = self::requireChannel($merchantId, $channelId);
        $pluginConfig = self::pluginConfig($channel);
        $login = self::requestLoginQrcode();

        $pluginConfig['login_id'] = $login['login_id'];
        $pluginConfig['login_qr_content'] = $login['qr_content'];
        $pluginConfig['login_qr_image'] = $login['qr_image'];
        $pluginConfig['login_state'] = 'pending_scan';
        $pluginConfig['login_state_text'] = '等待扫码';
        $pluginConfig['login_state_message'] = '请使用支付宝扫码，并在支付宝内确认登录。';
        $pluginConfig['login_checked_at'] = date('Y-m-d H:i:s');
        $pluginConfig['login_confirmed_at'] = '';
        $pluginConfig['login_cookie_base64'] = '';
        $pluginConfig['account_pid'] = '';

        $saved = self::savePluginConfig($merchantId, $channel, $pluginConfig);
        return self::statePayload(self::pluginConfig($saved));
    }

    public static function queryLoginStatus(int $merchantId, int $channelId): array
    {
        $channel = self::requireChannel($merchantId, $channelId);
        $pluginConfig = self::pluginConfig($channel);
        $loginId = trim((string)($pluginConfig['login_id'] ?? ''));
        if ($loginId === '') {
            throw new BusinessException('请先刷新支付宝 CK 登录二维码', StatusCode::VALIDATION_ERROR);
        }

        $status = self::requestLoginStatus($loginId);
        $pluginConfig['login_state'] = $status['login_state'];
        $pluginConfig['login_state_text'] = $status['login_state_text'];
        $pluginConfig['login_state_message'] = $status['login_state_message'];
        $pluginConfig['login_checked_at'] = date('Y-m-d H:i:s');

        if (($status['login_state'] ?? '') === 'authenticated') {
            $pluginConfig['login_cookie_base64'] = (string)($status['login_cookie_base64'] ?? '');
            $pluginConfig['account_pid'] = (string)($status['account_pid'] ?? '');
            $pluginConfig['login_confirmed_at'] = date('Y-m-d H:i:s');
        }

        $saved = self::savePluginConfig($merchantId, $channel, $pluginConfig);
        return self::statePayload(self::pluginConfig($saved));
    }

    private static function requireChannel(int $merchantId, int $channelId): array
    {
        if ($merchantId <= 0 || $channelId <= 0) {
            throw new BusinessException('通道信息不能为空', StatusCode::VALIDATION_ERROR);
        }

        foreach ((array)(MerchantChannelService::all($merchantId)['items'] ?? []) as $item) {
            if ((int)($item['id'] ?? 0) !== $channelId) {
                continue;
            }

            if (PluginCodeService::normalize((string)($item['plugin_code'] ?? '')) !== 'alipay-ck') {
                throw new BusinessException('当前通道不是支付宝 CK 插件', StatusCode::VALIDATION_ERROR);
            }

            return $item;
        }

        throw new BusinessException('通道不存在', StatusCode::NOT_FOUND);
    }

    private static function pluginConfig(array $channel): array
    {
        return is_array($channel['plugin_config'] ?? null) ? $channel['plugin_config'] : [];
    }

    private static function requestLoginQrcode(): array
    {
        $response = self::httpRequest(self::LOGIN_PAGE_URL, 'GET', null, self::browserHeaders(self::LOGIN_REFERER));
        $body = (string)($response['body'] ?? '');
        if ($body === '') {
            throw new BusinessException('支付宝登录页请求失败，请稍后重试', StatusCode::BUSINESS_ERROR);
        }

        $securityId = self::mustMatch('/securityId:\s*"([^"]+)"/', $body, '未能解析支付宝登录二维码');
        $passwordSecurityId = self::mustMatch('/s\.sid\s*=\s*"([^"]+)"/', $body, '未能解析支付宝登录参数');
        $rdsFormToken = self::hiddenInputValue($body, 'rds_form_token');
        $alieditUid = self::hiddenInputValue($body, 'alieditUid');

        if ($rdsFormToken === '' || $alieditUid === '') {
            throw new BusinessException('支付宝登录页缺少必要参数，请稍后重试', StatusCode::BUSINESS_ERROR);
        }

        $qrContent = 'https://qr.alipay.com/_d?_b=PAI_LOGIN_DY&securityId=' . rawurlencode($securityId);
        $qrImage = QrCodeService::imageDataUriForContent($qrContent, 320);
        if ($qrImage === '') {
            throw new BusinessException('登录二维码生成失败，请稍后重试', StatusCode::BUSINESS_ERROR);
        }

        return [
            'login_id' => $securityId . 'YPay' . $passwordSecurityId . 'YPay' . $rdsFormToken . 'YPay' . $alieditUid,
            'qr_content' => $qrContent,
            'qr_image' => $qrImage,
        ];
    }

    private static function requestLoginStatus(string $loginId): array
    {
        $parts = explode('YPay', $loginId);
        if (count($parts) < 4) {
            throw new BusinessException('支付宝 CK 登录会话已失效，请刷新二维码后重试', StatusCode::BUSINESS_ERROR);
        }

        [$securityId, $passwordSecurityId, $rdsFormToken, $alieditUid] = array_slice($parts, 0, 4);
        $statusUrl = self::STATUS_URL . '?' . http_build_query([
            'securityId' => $securityId,
            '_callback' => 'light.request._callbacks.callback2',
        ]);

        $response = self::httpRequest($statusUrl, 'GET', null, self::browserHeaders(self::LOGIN_REFERER));
        $body = strtolower(trim((string)($response['body'] ?? '')));

        if ($body === '') {
            return [
                'login_state' => 'expired',
                'login_state_text' => '已失效',
                'login_state_message' => '支付宝登录状态读取失败，请刷新二维码重新登录。',
            ];
        }

        if (str_contains($body, 'waiting')) {
            return [
                'login_state' => 'pending_scan',
                'login_state_text' => '等待扫码',
                'login_state_message' => '二维码已生成，请使用支付宝扫码登录。',
            ];
        }

        if (str_contains($body, 'scanned')) {
            return [
                'login_state' => 'pending_confirm',
                'login_state_text' => '待确认',
                'login_state_message' => '已扫码，请在支付宝内确认登录。',
            ];
        }

        $loginResult = self::finalizeLogin($securityId, $passwordSecurityId, $rdsFormToken, $alieditUid);
        if (($loginResult['login_cookie_base64'] ?? '') === '') {
            return [
                'login_state' => 'expired',
                'login_state_text' => '已失效',
                'login_state_message' => '二维码已过期或登录失败，请刷新二维码重新登录。',
            ];
        }

        $pid = trim((string)($loginResult['account_pid'] ?? ''));
        return [
            'login_state' => 'authenticated',
            'login_state_text' => '已登录',
            'login_state_message' => $pid !== '' ? ('当前支付宝 PID：' . $pid) : '支付宝 CK 登录已完成。',
            'login_cookie_base64' => (string)($loginResult['login_cookie_base64'] ?? ''),
            'account_pid' => $pid,
        ];
    }

    private static function finalizeLogin(
        string $securityId,
        string $passwordSecurityId,
        string $rdsFormToken,
        string $alieditUid
    ): array {
        $payload = [
            'support' => '000001',
            'needTransfer' => '',
            'CtrlVersion' => '1,1,0,1',
            'loginScene' => 'index',
            'redirectType' => '',
            'personalLoginError' => '',
            'goto' => 'https://www.alipay.com/',
            'errorVM' => '',
            'sso_hid' => '',
            'site' => '',
            'errorGoto' => '',
            'rds_form_token' => $rdsFormToken,
            'json_tk' => '',
            'method' => 'qrCodeLogin',
            'logonId' => '',
            'superSwitch' => 'true',
            'noActiveX' => 'false',
            'passwordSecurityId' => $passwordSecurityId,
            'qrCodeSecurityId' => $securityId,
            'scid' => '',
            'password_input' => '',
            'J_aliedit_using' => 'true',
            'password' => '',
            'J_aliedit_key_hidn' => 'password',
            'J_aliedit_uid_hidn' => 'alieditUid',
            'alieditUid' => $alieditUid,
            'REMOTE_PCID_NAME' => '_seaside_gogo_pcid',
            '_seaside_gogo_pcid' => '',
            '_seaside_gogo_' => '',
            '_seaside_gogo_p' => '',
            'J_aliedit_prod_type' => '',
            'security_activeX_enabled' => 'true',
            'checkCode' => '',
            'idPrefix' => '',
            'preCheckTimes' => 5,
            'ua' => self::LEGACY_FORM_UA,
        ];

        $response = self::httpRequest(
            self::LOGIN_SUBMIT_URL,
            'POST',
            http_build_query($payload),
            array_merge(self::browserHeaders(self::LOGIN_REFERER), [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            ]),
            true
        );

        $cookies = self::collectCookies((string)($response['headers'] ?? ''));
        $sessionCookie = self::buildCookieString($cookies);
        if ($sessionCookie === '') {
            return [
                'login_cookie_base64' => '',
                'account_pid' => '',
            ];
        }

        return [
            'login_cookie_base64' => base64_encode($sessionCookie),
            'account_pid' => trim((string)($cookies['CLUB_ALIPAY_COM'] ?? '')),
        ];
    }

    private static function savePluginConfig(int $merchantId, array $channel, array $pluginConfig): array
    {
        MerchantChannelService::saveItem($merchantId, [
            'id' => (int)($channel['id'] ?? 0),
            'channel_name' => (string)($channel['channel_name'] ?? ''),
            'method_code' => (string)($channel['method_code'] ?? ''),
            'plugin_code' => (string)($channel['plugin_code'] ?? ''),
            'daily_limit' => (string)($channel['daily_limit'] ?? '0'),
            'daily_count_limit' => (int)($channel['daily_count_limit'] ?? 0),
            'single_min_amount' => (string)($channel['single_min_amount'] ?? '0'),
            'single_max_amount' => (string)($channel['single_max_amount'] ?? '0'),
            'rate' => (string)($channel['rate'] ?? '0.85'),
            'display_value' => (string)($channel['display_value'] ?? ''),
            'remark' => (string)($channel['remark'] ?? ''),
            'status_code' => (int)($channel['status_code'] ?? 1),
            'plugin_config' => $pluginConfig,
        ]);

        return self::requireChannel($merchantId, (int)($channel['id'] ?? 0));
    }

    private static function statePayload(array $pluginConfig): array
    {
        $status = strtolower(trim((string)($pluginConfig['login_state'] ?? '')));
        if ($status === '') {
            $status = 'idle';
        }

        $qrImage = trim((string)($pluginConfig['login_qr_image'] ?? ''));
        $qrContent = trim((string)($pluginConfig['login_qr_content'] ?? ''));
        if ($qrImage === '' && $qrContent !== '') {
            $qrImage = QrCodeService::imageDataUriForContent($qrContent, 320);
        }

        $labelMap = [
            'idle' => '未获取',
            'pending_scan' => '等待扫码',
            'pending_confirm' => '待确认',
            'authenticated' => '已登录',
            'expired' => '已失效',
            'error' => '异常',
        ];

        $toneMap = [
            'idle' => 'muted',
            'pending_scan' => 'warning',
            'pending_confirm' => 'warning',
            'authenticated' => 'success',
            'expired' => 'danger',
            'error' => 'danger',
        ];

        $statusLabel = trim((string)($pluginConfig['login_state_text'] ?? ''));
        if ($statusLabel === '') {
            $statusLabel = $labelMap[$status] ?? '未获取';
        }

        $message = trim((string)($pluginConfig['login_state_message'] ?? ''));
        if ($message === '') {
            $message = match ($status) {
                'pending_scan' => '二维码已生成，请使用支付宝扫码登录。',
                'pending_confirm' => '已扫码，请在支付宝内确认登录。',
                'authenticated' => '支付宝 CK 登录已完成。',
                'expired' => '二维码已失效，请刷新二维码重新登录。',
                'error' => '登录状态异常，请刷新二维码后重试。',
                default => '点击刷新二维码，生成支付宝 CK 登录二维码。',
            };
        }

        return [
            'status' => $status,
            'status_label' => $statusLabel,
            'status_tone' => $toneMap[$status] ?? 'muted',
            'message' => $message,
            'qr_image' => $qrImage,
            'account_pid' => trim((string)($pluginConfig['account_pid'] ?? '')),
            'updated_at' => trim((string)($pluginConfig['login_checked_at'] ?? $pluginConfig['login_confirmed_at'] ?? '')),
            'plugin_config' => $pluginConfig,
        ];
    }
    private static function browserHeaders(string $referer = ''): array
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];

        if ($referer !== '') {
            $headers[] = 'Referer: ' . $referer;
        }

        return $headers;
    }

    private static function mustMatch(string $pattern, string $content, string $message): string
    {
        if (preg_match($pattern, $content, $matches) !== 1) {
            throw new BusinessException($message, StatusCode::BUSINESS_ERROR);
        }

        $value = trim((string)($matches[1] ?? ''));
        if ($value === '') {
            throw new BusinessException($message, StatusCode::BUSINESS_ERROR);
        }

        return $value;
    }

    private static function hiddenInputValue(string $html, string $name): string
    {
        $name = preg_quote($name, '/');
        $patterns = [
            '/name="' . $name . '"[^>]*value="([^"]+)"/i',
            '/value="([^"]+)"[^>]*name="' . $name . '"/i',
            '/id="' . $name . '"[^>]*value="([^"]+)"/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                $value = trim((string)($matches[1] ?? ''));
                if ($value !== '') {
                    return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
                }
            }
        }

        return '';
    }

    private static function httpRequest(
        string $url,
        string $method = 'GET',
        ?string $payload = null,
        array $headers = [],
        bool $captureHeaders = false
    ): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_USERAGENT, self::LOGIN_UA);
        curl_setopt($ch, CURLOPT_HEADER, $captureHeaders);

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
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw)) {
            return [
                'http_code' => $httpCode,
                'headers' => '',
                'body' => '',
            ];
        }

        if (!$captureHeaders) {
            return [
                'http_code' => $httpCode,
                'headers' => '',
                'body' => $raw,
            ];
        }

        return [
            'http_code' => $httpCode,
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize),
        ];
    }

    private static function collectCookies(string $headers): array
    {
        $cookies = [];
        if (preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/mi', $headers, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $name = trim((string)($match[1] ?? ''));
                if ($name === '') {
                    continue;
                }

                $cookies[$name] = trim((string)($match[2] ?? ''));
            }
        }

        return $cookies;
    }

    private static function buildCookieString(array $cookies): string
    {
        foreach (['JSESSIONID', 'ALIPAYJSESSIONID', 'ctoken', 'CLUB_ALIPAY_COM'] as $name) {
            if (!array_key_exists($name, $cookies) || trim((string)$cookies[$name]) === '') {
                return '';
            }
        }

        $pairs = [];
        foreach ($cookies as $name => $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }
}
