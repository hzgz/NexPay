<?php

declare(strict_types=1);

namespace plugins\payment\ahrcuauto;

use app\common\BasePayment;
use app\common\PaymentContext;
use app\service\payment\CallbackTrustService;
use Exception;
use think\facade\Db;

class AhrcuautoPlugin extends BasePayment
{
    private const API_HOST = 'https://epay.ahrcu.com:1443';
    private const QRCODE_PAY_PATH = '/gateway/adapter/qrcodePay';
    private const REALTIME_PATH = '/end/data/tranFlw/queryRealMerchant';
    private const HISTORY_PATH = '/end/data/tranFlw/queryHisMerchant';
    private const AES_KEY = 'MIIBIjANBgkqhkiG';
    private const AES_IV = 'acbd1234ghijklmn';
    private const PAGE_SIZE = 100;
    private const DEFAULT_VERIFY_MINUTES = 3;
    private const DEFAULT_QRCODE_TIMEOUT = 60;
    private const REFRESH_TOKEN_PATH = '/end/merchant/auth/refreshToken';
    private const REFRESH_CHANNEL_ID = '/end/merchant';
    private const TOKEN_LOG_FILE = 'ahtoken.json';
    private const TOKEN_LOG_KEEP = 50;

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/' . $ctx->order['typename'] . '/' . $ctx->order['trade_no'] . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = trim((string)($ctx->order['typename'] ?? ''));
        if ($typename === '' || !method_exists($this, $typename)) {
            return ['type' => 'error', 'msg' => 'Unsupported pay type'];
        }
        return $this->$typename($ctx);
    }

    public function alipay(PaymentContext $ctx): array
    {
        return $this->buildPayResult($ctx, 'alipay');
    }

    public function wxpay(PaymentContext $ctx): array
    {
        return $this->buildPayResult($ctx, 'wxpay');
    }

    public function bank(PaymentContext $ctx): array
    {
        return $this->buildPayResult($ctx, 'bank');
    }

    public function cron(array $channel): int
    {
        $channelId = (int)($channel['id'] ?? 0);
        if ($channelId <= 0) return 0;

        $minutes = $this->readOrderValidMinutes($channel);
        $scanMinutes = max(5, $minutes + 2);
        $start = date('Y-m-d H:i:s', time() - $scanMinutes * 60);

        $orders = Db::name('order')
            ->where('channel', $channelId)
            ->where('status', 0)
            ->where('addtime', '>=', $start)
            ->order('addtime', 'asc')
            ->select()
            ->toArray();

        $processed = 0;
        foreach ($orders as $item) {
            $tradeNo = trim((string)($item['trade_no'] ?? ''));
            if ($tradeNo === '') continue;

            $fullOrder = $this->getOrder($tradeNo);
            if (!$fullOrder) continue;

            $result = $this->verifyOrder($fullOrder, $channel, trim((string)($item['api_trade_no'] ?? '')));
            if (!$result['paid']) {
                if (!empty($result['error'])) {
                    echo $tradeNo . ' 查单异常：' . $result['error'] . PHP_EOL;
                }
                continue;
            }

            try {
                CallbackTrustService::beginTrusted([
                    'scope' => 'notify',
                    'action' => 'notify',
                    'plugin_code' => 'ahrcuauto',
                    'channel_id' => $channelId,
                    'merchant_id' => (int)($fullOrder['uid'] ?? $fullOrder['merchant_id'] ?? 0),
                    'source' => 'plugin-query',
                    'verification' => 'provider-order-query',
                ], function () use ($channel, $fullOrder, $result) {
                    (new \app\service\OrderProcessService($channel, $fullOrder))->processNotify(
                        $result['api_trade_no'],
                        $result['buyer'] ?: null,
                        $result['bill_trade_no'] ?: null,
                        $result['bill_mch_trade_no'] ?: null,
                        $result['end_time'] ?: null
                    );
                });
                $processed++;
                echo $tradeNo . ' 支付成功' . PHP_EOL;
            } catch (\Throwable $e) {
                echo $tradeNo . ' 回调处理失败：' . $e->getMessage() . PHP_EOL;
            }
        }

        return $processed;
    }

    private function buildPayResult(PaymentContext $ctx, string $scene): array
    {
        try {
            $payUrl = $this->createQrcode($ctx);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '安徽农金下码失败：' . $e->getMessage()];
        }

        $isImage = stripos($payUrl, 'data:image/') === 0;
        $expire = strtotime((string)($ctx->order['addtime'] ?? '')) + $this->readOrderValidMinutes($this->channel) * 60;

        if ($scene === 'alipay') {
            if ($ctx->mdevice === 'alipay' && !$isImage) {
                return ['type' => 'jump', 'url' => $payUrl];
            }
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $payUrl, 'expire' => $expire];
        }

        if ($scene === 'wxpay') {
            if ($ctx->mdevice === 'wechat' && !$isImage) {
                return ['type' => 'jump', 'url' => $payUrl];
            }
            if ($ctx->isMobile) {
                return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'url' => $payUrl, 'expire' => $expire];
            }
            return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'url' => $payUrl, 'expire' => $expire];
        }

        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'url' => $payUrl, 'expire' => $expire];
    }

    private function createQrcode(PaymentContext $ctx): string
    {
        $amount = $this->roundAmount((float)($ctx->order['realmoney'] ?? 0));
        if ($amount <= 0) $amount = $this->roundAmount((float)($ctx->order['money'] ?? 0));
        if ($amount <= 0) throw new Exception('订单金额无效');

        $token = $this->getValidTokenFromChannel($this->channel);

        $tradeNo = (string)($ctx->order['trade_no'] ?? '');
        $state = self::lockPayData($tradeNo, function () use ($ctx, $amount, $token) {
            $payload = $this->buildQrcodePayload($ctx->order, $amount);
            $ret = $this->ahrcuRequestWithAutoToken($this->channel, $token, self::QRCODE_PAY_PATH, $payload);
            $transInfo = $this->parseQrcodeResponse($ret);

            $payUrl = trim((string)$this->pickFirst($transInfo, ['qrcodeUrl', 'qrCodeUrl', 'payUrl', 'codeUrl', 'code_url']));
            if ($payUrl === '') throw new Exception('安徽农金未返回二维码链接');

            return [
                'pay_url' => $this->normalizePayUrl($payUrl),
                'api_trade_no' => trim((string)$this->pickFirst($transInfo, ['outTradeNo', 'outTradeId', 'merchantOrderNo'])),
            ];
        });

        if (!is_array($state) || empty($state['pay_url'])) {
            throw new Exception('二维码生成失败');
        }

        $payUrl = $this->normalizePayUrl((string)$state['pay_url']);
        if ($payUrl === '') throw new Exception('二维码链接为空');

        $updateData = ['payurl' => substr($payUrl, 0, 500)];
        $apiTradeNo = trim((string)($state['api_trade_no'] ?? ''));
        if ($apiTradeNo !== '') $updateData['api_trade_no'] = $apiTradeNo;
        Db::name('order')->where('trade_no', $tradeNo)->update($updateData);

        return $payUrl;
    }

    private function buildQrcodePayload(array $order, float $amountYuan): array
    {
        $mchntCd = trim((string)($this->channel['mchid'] ?? ''));
        $shopId = trim((string)($this->channel['shop_id'] ?? ''));
        $staffId = trim((string)($this->channel['staff_id'] ?? ''));
        if ($staffId === '') $staffId = trim((string)($this->channel['device_id'] ?? ''));

        if ($mchntCd === '') throw new Exception('mchid不能为空');
        if ($shopId === '') throw new Exception('shop_id不能为空');
        if ($staffId === '') throw new Exception('staff_id不能为空');

        $timeout = $this->readPositiveInt($this->channel['qrcode_timeout'] ?? null, self::DEFAULT_QRCODE_TIMEOUT, 30, 300);
        $amountFen = (string)max(1, (int)round($amountYuan * 100));
        $aesPin = $this->buildAesPin($mchntCd, $shopId, $staffId);

        $remark = trim((string)($order['name'] ?? ''));
        if ($remark === '') $remark = trim((string)($order['sitename'] ?? ''));
        if ($remark !== '') {
            $remark = function_exists('mb_substr') ? mb_substr($remark, 0, 80, 'UTF-8') : substr($remark, 0, 80);
        }

        return [
            'sysId' => '002',
            'data' => [
                'version' => '1.0.0',
                'apiFlag' => '8',
                'transType' => '1102',
                'mchntCd' => $mchntCd,
                'staffId' => $staffId,
                'shopId' => $shopId,
                'transAmount' => $amountFen,
                'timeOut' => $timeout,
                'mchntRemark' => $remark,
                'traceNo' => '1',
                'aesPin' => $aesPin,
            ],
        ];
    }

    private function parseQrcodeResponse(array $ret): array
    {
        $respCode = trim((string)($ret['respCode'] ?? ''));
        if ($respCode !== '' && $respCode !== '00') {
            $respMsg = trim((string)($ret['respMsg'] ?? ''));
            if ($respMsg === '') $respMsg = '下码失败，响应码：' . $respCode;
            throw new Exception($respMsg);
        }

        $transInfo = $ret['transInfo'] ?? ($ret['data']['transInfo'] ?? ($ret['data'] ?? $ret));
        if (!is_array($transInfo)) throw new Exception('安徽农金下码响应异常');
        return $transInfo;
    }

    private function verifyOrder(array $order, array $channel, string $apiTradeNo): array
    {
        try {
            if ((int)($order['status'] ?? 0) > 0) {
                return [
                    'paid' => true,
                    'api_trade_no' => (string)($order['api_trade_no'] ?? ''),
                    'bill_trade_no' => (string)($order['bill_trade_no'] ?? ''),
                    'bill_mch_trade_no' => (string)($order['bill_mch_trade_no'] ?? ''),
                    'buyer' => (string)($order['buyer'] ?? ''),
                    'end_time' => null,
                ];
            }

            $expectedAmount = $this->roundAmount((float)($order['realmoney'] ?? 0));
            if ($expectedAmount <= 0) $expectedAmount = $this->roundAmount((float)($order['money'] ?? 0));

            $orderTs = $this->parseTime((string)($order['addtime'] ?? ''));
            if ($orderTs <= 0) return ['paid' => false, 'error' => '订单时间无效'];

            $validEndTs = $orderTs + $this->readOrderValidMinutes($channel) * 60;
            $rows = $this->queryReconciliationRows($channel, $orderTs, min($validEndTs, time() + 30));
            if (empty($rows)) return ['paid' => false];

            $knownTradeNos = $this->collectKnownTradeNos($order, $apiTradeNo);
            $matched = $this->pickMatchedRowByKnownTrade($rows, $knownTradeNos);
            if (!$matched) $matched = $this->pickMatchedRow($order, $rows, $expectedAmount, $orderTs, $validEndTs);
            if (!$matched) return ['paid' => false];

            $notifyApiTradeNo = trim((string)$this->pickFirst($matched, ['order_sn', 'orderSn', 'trade_no', 'tradeNo', 'channel_order_sn', 'channelOrderSn']));
            if ($notifyApiTradeNo === '') $notifyApiTradeNo = trim($apiTradeNo);
            if ($notifyApiTradeNo === '') return ['paid' => false, 'error' => '查单失败：缺少交易号'];

            $billTradeNo = trim((string)$this->pickFirst($matched, ['channel_order_sn', 'channelOrderSn', 'ins_order_sn', 'insOrderSn']));
            $billMchTradeNo = trim((string)$this->pickFirst($matched, ['merchant_order_sn', 'merchantOrderSn', 'out_order_no', 'outOrderNo']));
            $buyer = trim((string)$this->pickFirst($matched, ['user_id', 'userId', 'buyer_id', 'buyerId', 'open_id', 'openid']));

            if ($this->isTradeAlreadyUsed((string)$order['trade_no'], $notifyApiTradeNo, $billTradeNo, $billMchTradeNo)) {
                return ['paid' => false];
            }

            $updateData = [];
            if ($notifyApiTradeNo !== '' && $notifyApiTradeNo !== trim((string)($order['api_trade_no'] ?? ''))) $updateData['api_trade_no'] = $notifyApiTradeNo;
            if ($billTradeNo !== '' && $billTradeNo !== trim((string)($order['bill_trade_no'] ?? ''))) $updateData['bill_trade_no'] = $billTradeNo;
            if ($billMchTradeNo !== '' && $billMchTradeNo !== trim((string)($order['bill_mch_trade_no'] ?? ''))) $updateData['bill_mch_trade_no'] = $billMchTradeNo;
            if (!empty($updateData)) Db::name('order')->where('trade_no', (string)$order['trade_no'])->update($updateData);

            $endTime = $this->formatDateTime($this->parseTime((string)$this->pickFirst($matched, ['pay_time', 'payTime', 'finish_time', 'finishTime', 'trade_time', 'tradeTime'])));
            return [
                'paid' => true,
                'api_trade_no' => $notifyApiTradeNo,
                'bill_trade_no' => $billTradeNo,
                'bill_mch_trade_no' => $billMchTradeNo,
                'buyer' => $buyer,
                'end_time' => $endTime,
            ];
        } catch (\Throwable $e) {
            return ['paid' => false, 'error' => $this->safeError($e->getMessage())];
        }
    }

    private function queryReconciliationRows(array $channel, int $startTs, int $endTs): array
    {
        $token = $this->getValidTokenFromChannel($channel);

        if ($startTs <= 0 || $endTs <= 0) return [];
        if ($startTs > $endTs) [$startTs, $endTs] = [$endTs, $startTs];
        $payload = $this->buildQueryPayload($channel, $startTs, $endTs);

        $realtimeError = '';
        try {
            $realtimeRows = $this->extractRows($this->ahrcuRequestWithAutoToken($channel, $token, self::REALTIME_PATH, $payload));
            if (!empty($realtimeRows)) return $this->uniqueRowsByTrade($this->normalizeRows($realtimeRows, $channel));
        } catch (\Throwable $e) {
            $realtimeError = $e->getMessage();
        }

        $historyError = '';
        try {
            $historyRows = $this->extractRows($this->ahrcuRequestWithAutoToken($channel, $token, self::HISTORY_PATH, $payload));
            if (empty($historyRows)) return [];
            return $this->uniqueRowsByTrade($this->normalizeRows($historyRows, $channel));
        } catch (\Throwable $e) {
            $historyError = $e->getMessage();
        }

        if ($realtimeError !== '' && $historyError !== '') {
            throw new Exception('Anhui reconciliation failed: realtime=' . $this->safeError($realtimeError) . '; history=' . $this->safeError($historyError));
        }
        if ($historyError !== '') throw new Exception($historyError);
        if ($realtimeError !== '') throw new Exception($realtimeError);
        return [];
    }

    private function buildQueryPayload(array $channel, int $startTs, int $endTs): array
    {
        $startDatetime = date('Y-m-d H:i:s', $startTs);
        $endDatetime = date('Y-m-d H:i:s', $endTs);
        return [
            'pageNo' => 1,
            'pageSize' => self::PAGE_SIZE,
            'params' => [
                'transTypeId' => '',
                'transStatus' => '',
                'transWay' => '',
                'outTradeId' => '',
                'transAmountStart' => '',
                'transAmountEnd' => '',
                'transAmount' => '',
                'startDatetime' => $startDatetime,
                'endDatetime' => $endDatetime,
                'shopId' => trim((string)($channel['shop_id'] ?? '')),
                'staffId' => trim((string)($channel['staff_id'] ?? '')),
                'deviceId' => trim((string)($channel['device_id'] ?? '')),
                'channelType' => '',
                'launchChannelType' => '',
                'transDateTime' => [$startDatetime, $endDatetime],
            ],
        ];
    }

    private function ahrcuRequest(string $token, string $path, array $payload, int $attempt = 1): array
    {
        $url = rtrim(self::API_HOST, '/') . $path;
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) throw new Exception('请求参数编码失败');

        $ch = curl_init($url);
        if (!$ch) throw new Exception('初始化请求失败');

        curl_setopt($ch, CURLOPT_TIMEOUT, $attempt > 1 ? 14 : 12);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $attempt > 1 ? 7 : 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json;charset=UTF-8',
            'token: ' . $token,
            'Origin: https://epay.ahrcu.com:1443',
            'Referer: https://epay.ahrcu.com:1443/merchant/',
            'Accept: application/json, text/plain, */*',
            'Connection: keep-alive',
        ]);
        if (defined('CURLOPT_NOSIGNAL')) curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_1_1')) curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            if ($attempt < 2 && in_array($errno, [6, 7, 28, 52, 56], true)) {
                usleep(200000);
                return $this->ahrcuRequest($token, $path, $payload, $attempt + 1);
            }
            throw new Exception('请求安徽农金接口失败：' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) throw new Exception('安徽农金接口HTTP状态异常：' . $httpCode);

        $ret = json_decode((string)$body, true);
        return is_array($ret) ? $ret : [];
    }



    private function getValidTokenFromChannel(array &$channel): string
    {
        $token = trim((string)($channel['token'] ?? ''));
        if ($token === '') {
            throw new Exception('token is empty');
        }
        return $token;
    }


    private function ahrcuRequestWithAutoToken(array &$channel, string $token, string $path, array $payload): array
    {
        $ret = $this->ahrcuRequest($token, $path, $payload);
        if (!$this->isTokenExpiredResponse($ret)) {
            return $ret;
        }

        $channelId = (int)($channel['id'] ?? 0);
        $this->appendTokenLog($channelId, 'token_expired_detected', 'token expired, try refresh', 'warning', [
            'path' => $path,
        ]);
        try {
            $newToken = $this->refreshToken($channel, $token);
        } catch (\Throwable $e) {
            $this->appendTokenLog($channelId, 'token_refresh_exception', $this->safeError($e->getMessage()), 'error', [
                'path' => $path,
            ]);
            throw $e;
        }

        $ret = $this->ahrcuRequest($newToken, $path, $payload);
        if ($this->isTokenExpiredResponse($ret)) {
            $this->appendTokenLog($channelId, 'token_still_expired_after_refresh', 'token still invalid after refresh', 'error', [
                'path' => $path,
            ]);
            throw new Exception('token expired and auto refresh failed, please update token');
        }

        $this->appendTokenLog($channelId, 'request_retry_success', 'request succeeded after refresh', 'info', [
            'path' => $path,
        ]);
        return $ret;
    }


    private function refreshToken(array &$channel, string $token): string
    {
        $channelId = (int)($channel['id'] ?? 0);
        $this->appendTokenLog($channelId, 'token_refresh_start', 'start refresh token', 'info', [
            'channel_id' => $channelId,
        ]);

        $payload = [
            'token' => $token,
            'channelId' => self::REFRESH_CHANNEL_ID,
        ];

        $ret = $this->ahrcuRequest($token, self::REFRESH_TOKEN_PATH, $payload);
        if (!is_array($ret)) {
            $this->appendTokenLog($channelId, 'token_refresh_invalid_response', 'refreshToken invalid response', 'error');
            throw new Exception('refreshToken invalid response');
        }

        $newToken = '';
        $candidates = [
            $ret['data'] ?? null,
            $ret['token'] ?? null,
            $ret['authorization'] ?? null,
            $ret['authToken'] ?? null,
            $ret,
        ];
        foreach ($candidates as $cand) {
            if (is_string($cand) && trim($cand) !== '') {
                $newToken = trim($cand);
                break;
            }
            if (is_array($cand)) {
                foreach (['token', 'authorization', 'authToken', 'accessToken', 'access_token'] as $k) {
                    if (!empty($cand[$k]) && is_string($cand[$k])) {
                        $newToken = trim((string)$cand[$k]);
                        break 2;
                    }
                }
            }
        }

        if ($newToken === '' || strlen($newToken) < 16) {
            $respCode = trim((string)($ret['respCode'] ?? ''));
            $respMsg = trim((string)($ret['respMsg'] ?? ''));
            if ($respMsg === '') $respMsg = trim((string)($ret['message'] ?? ''));
            $extra = $respCode !== '' ? (' respCode=' . $respCode) : '';
            $this->appendTokenLog($channelId, 'token_refresh_empty_token', 'refreshToken did not return valid token', 'error', [
                'respCode' => $respCode,
                'respMsg' => $respMsg,
            ]);
            throw new Exception('refreshToken did not return token' . $extra . ($respMsg !== '' ? (': ' . $respMsg) : ''));
        }

        if ($newToken !== $token) {
            $channel['token'] = $newToken;
            $this->channel['token'] = $newToken;
            $this->saveChannelToken((int)($channel['id'] ?? 0), $newToken);
            $this->appendTokenLog($channelId, 'token_refresh_success', 'token refreshed and saved', 'info', [
                'changed' => true,
            ], $newToken);
        } else {
            $this->appendTokenLog($channelId, 'token_refresh_success_same_token', 'refresh succeeded but token unchanged', 'info', [
                'changed' => false,
            ], $newToken);
        }

        return $newToken;
    }

    private function saveChannelToken(int $channelId, string $token): void
    {
        if ($channelId <= 0 || $token === '') return;

        $configRaw = Db::name('channel')->where('id', $channelId)->value('config');
        if (!is_string($configRaw) || $configRaw === '') return;

        $config = json_decode($configRaw, true);
        if (!is_array($config)) return;

        $config['token'] = $token;
        $newConfigRaw = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($newConfigRaw === false || $newConfigRaw === $configRaw) return;

        Db::name('channel')->where('id', $channelId)->update(['config' => $newConfigRaw]);
        $this->appendTokenLog($channelId, 'channel_config_token_saved', 'channel config token updated', 'info', [], $token);
    }

    private function getTokenLogFilePath(): string
    {
        return rtrim($this->payRoot, '/\\') . DIRECTORY_SEPARATOR . self::TOKEN_LOG_FILE;
    }

    private function appendTokenLog(
        int $channelId,
        string $event,
        string $message,
        string $level = 'info',
        array $extra = [],
        ?string $token = null
    ): void {
        try {
            $path = $this->getTokenLogFilePath();
            $payload = $this->readTokenLogPayload($path);

            if ($channelId > 0) {
                $payload['channel_id'] = $channelId;
            }

            $entry = [
                'time' => date('Y-m-d H:i:s'),
                'level' => $level,
                'event' => $event,
                'message' => $message,
            ];
            if (!empty($extra)) {
                $entry['extra'] = $extra;
            }

            if (!isset($payload['logs']) || !is_array($payload['logs'])) {
                $payload['logs'] = [];
            }
            $payload['logs'][] = $entry;
            if (count($payload['logs']) > self::TOKEN_LOG_KEEP) {
                $payload['logs'] = array_slice($payload['logs'], -self::TOKEN_LOG_KEEP);
            }

            $tokenText = trim((string)$token);
            if ($tokenText !== '') {
                $payload['token'] = $tokenText;
                $payload['token_masked'] = $this->maskToken($tokenText);
                $payload['updated_at'] = date('Y-m-d H:i:s');
            }

            $this->writeTokenLogPayload($path, $payload);
        } catch (\Throwable $e) {
            // ignore token log write errors to avoid affecting payment flow
        }
    }

    private function readTokenLogPayload(string $path): array
    {
        $default = ['logs' => []];
        if (!is_file($path)) {
            return $default;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $default;
        }

        if (!isset($payload['logs']) || !is_array($payload['logs'])) {
            $payload['logs'] = [];
        }
        return $payload;
    }

    private function writeTokenLogPayload(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }

        @file_put_contents($path, $json, LOCK_EX);
    }

    private function maskToken(string $token): string
    {
        $token = trim($token);
        $len = strlen($token);
        if ($len <= 8) {
            return $token;
        }
        return substr($token, 0, 4) . str_repeat('*', $len - 8) . substr($token, -4);
    }


    private function isTokenExpiredResponse(array $ret): bool
    {
        $respCode = strtolower(trim((string)($ret['respCode'] ?? '')));
        $respMsg = strtolower(trim((string)($ret['respMsg'] ?? '')));
        $message = strtolower(trim((string)($ret['message'] ?? '')));

        $codeHints = ['401', '403', 'token_expired', 'token_invalid', 'invalid_token', 'no_login'];
        foreach ($codeHints as $hint) {
            if ($respCode === $hint || strpos($respCode, $hint) !== false) {
                return true;
            }
        }

        $text = $respMsg . ' ' . $message;
        $textHints = ['token', 'expired', 'invalid', 'auth', 'login'];

        $hasTokenWord = (strpos($text, 'token') !== false) || (strpos($text, 'auth') !== false) || (strpos($text, 'login') !== false);
        if ($hasTokenWord) {
            foreach ($textHints as $hint) {
                if (strpos($text, $hint) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractRows(array $ret): array
    {
        if (trim((string)($ret['respCode'] ?? '')) !== '00') return [];
        $rows = $ret['data']['rows'] ?? null;
        return is_array($rows) ? $rows : [];
    }

    private function normalizeRows(array $rows, array $channel): array
    {
        $merchantId = trim((string)($channel['mchid'] ?? ''));
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if ($merchantId !== '' && trim((string)($row['mchntCd'] ?? '')) !== $merchantId) continue;

            $status = trim((string)($row['transStatus'] ?? ''));
            if (!in_array($status, ['02', '03'], true)) continue;

            $tradeTime = trim((string)($row['transDateTime'] ?? ''));
            if ($tradeTime === '') continue;

            $amount = $this->normalizeAmountFromFen($row['actualAmount'] ?? ($row['transAmount'] ?? null));
            if ($amount <= 0) continue;

            $tradeId = trim((string)($row['tradeId'] ?? ''));
            $outTradeId = trim((string)($row['outTradeId'] ?? ''));
            $channelType = trim((string)($row['channelType'] ?? ''));

            $normalized[] = [
                'order_sn' => $tradeId !== '' ? $tradeId : $outTradeId,
                'trade_no' => $tradeId,
                'channel_order_sn' => $tradeId,
                'ins_order_sn' => $tradeId,
                'merchant_order_sn' => $outTradeId,
                'out_order_no' => $outTradeId,
                'pay_type' => $this->mapChannelTypeToPayType($channelType),
                'pay_type_name' => $channelType,
                'total_amount' => sprintf('%.2f', $amount),
                'amount' => sprintf('%.2f', $amount),
                'pay_time' => $tradeTime,
                'trade_time' => $tradeTime,
                'order_status' => $status,
                'trade_status' => $status,
            ];
        }

        return $normalized;
    }

    private function uniqueRowsByTrade(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = trim((string)$this->pickFirst($row, ['trade_no', 'channel_order_sn', 'ins_order_sn', 'order_sn', 'merchant_order_sn']));
            if ($key === '') $key = md5(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            if (!isset($items[$key])) {
                $items[$key] = $row;
                continue;
            }
            $oldTs = $this->parseTime((string)$this->pickFirst($items[$key], ['pay_time', 'trade_time', 'finish_time']));
            $newTs = $this->parseTime((string)$this->pickFirst($row, ['pay_time', 'trade_time', 'finish_time']));
            if ($newTs >= $oldTs) $items[$key] = array_merge($items[$key], $row);
        }
        return array_values($items);
    }

    private function collectKnownTradeNos(array $order, string $apiTradeNo = ''): array
    {
        $items = [];
        foreach ([(string)($order['api_trade_no'] ?? ''), (string)($order['bill_trade_no'] ?? ''), (string)($order['bill_mch_trade_no'] ?? ''), $apiTradeNo] as $value) {
            $value = trim($value);
            if ($value === '') continue;
            $items[$value] = $value;
        }
        return array_values($items);
    }

    private function pickMatchedRowByKnownTrade(array $rows, array $knownTradeNos): ?array
    {
        if (empty($knownTradeNos)) return null;
        $index = [];
        foreach ($knownTradeNos as $tradeNo) {
            $tradeNo = trim((string)$tradeNo);
            if ($tradeNo !== '') $index[$tradeNo] = true;
        }
        if (empty($index)) return null;

        $best = null;
        $bestTs = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !$this->isPaidRecord($row)) continue;
            $keys = [
                trim((string)$this->pickFirst($row, ['merchant_order_sn', 'merchantOrderSn', 'out_order_no', 'outOrderNo'])),
                trim((string)$this->pickFirst($row, ['order_sn', 'orderSn', 'trade_no', 'tradeNo'])),
                trim((string)$this->pickFirst($row, ['channel_order_sn', 'channelOrderSn', 'ins_order_sn', 'insOrderSn'])),
            ];
            $matched = false;
            foreach ($keys as $key) {
                if ($key !== '' && isset($index[$key])) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) continue;

            $ts = $this->parseTime((string)$this->pickFirst($row, ['pay_time', 'payTime', 'finish_time', 'finishTime', 'trade_time', 'tradeTime']));
            if ($ts >= $bestTs) {
                $bestTs = $ts;
                $best = $row;
            }
        }
        return $best;
    }

    private function pickMatchedRow(array $order, array $rows, float $expectedAmount, int $orderTs, int $validEndTs): ?array
    {
        $expectedType = trim((string)($order['typename'] ?? ''));
        $expectedAmount = $this->roundAmount($expectedAmount);
        $best = null;
        $bestDelta = PHP_INT_MAX;

        foreach ($rows as $row) {
            if (!is_array($row) || !$this->isPaidRecord($row)) continue;
            $payType = $this->detectPayType($row);
            if ($expectedType !== '' && $payType !== '' && $payType !== $expectedType) continue;

            $rowAmount = $this->parseAmount((string)$this->pickFirst($row, ['total_fee', 'totalFee', 'total_amount', 'totalAmount', 'amount', 'money', 'trade_amount']));
            if (abs($rowAmount - $expectedAmount) >= 0.01) continue;

            $tradeTs = $this->parseTime((string)$this->pickFirst($row, ['pay_time', 'payTime', 'finish_time', 'finishTime', 'trade_time', 'tradeTime', 'update_time']));
            if ($tradeTs <= 0 || $orderTs <= 0) continue;

            $delta = $tradeTs - $orderTs;
            if ($delta <= 0) continue;
            if ($validEndTs > 0 && $tradeTs > $validEndTs) continue;

            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = $row;
            }
        }
        return $best;
    }

    private function isPaidRecord(array $row): bool
    {
        $status = trim((string)$this->pickFirst($row, ['pay_status', 'payStatus', 'order_status', 'orderStatus', 'status', 'trade_status', 'tradeStatus']));
        if ($status !== '') {
            $upper = strtoupper($status);
            if (in_array($upper, ['1', '2', '02', '03', '200', '2000', 'SUCCESS', 'PAID', 'PAY_SUCCESS', 'TRADE_SUCCESS', 'FINISH', 'COMPLETED'], true)) return true;
            if (strpos($upper, 'SUCCESS') !== false || strpos($upper, 'PAID') !== false) return true;
            if (strpos($status, '成功') !== false || strpos($status, '已支付') !== false) return true;
            if (strpos($upper, 'FAIL') !== false || strpos($upper, 'CLOSE') !== false || strpos($upper, 'CANCEL') !== false) return false;
        }
        return $this->parseTime((string)$this->pickFirst($row, ['pay_time', 'payTime', 'finish_time', 'finishTime'])) > 0;
    }

    private function detectPayType(array $row): string
    {
        $rawType = trim((string)$this->pickFirst($row, ['pay_type', 'payType']));
        if ($rawType !== '') {
            if (in_array($rawType, ['1', '01'], true)) return 'wxpay';
            if (in_array($rawType, ['2', '02'], true)) return 'alipay';
            if (in_array($rawType, ['5', '05'], true)) return 'bank';
        }
        $rawText = strtoupper(trim((string)$this->pickFirst($row, ['pay_type_name', 'payTypeName', 'pay_channel', 'payChannel', 'channel_name', 'channelName'])));
        if ($rawText === '') $rawText = strtoupper($rawType);
        if ($rawText === '') return '';
        if (strpos($rawText, 'WECHAT') !== false || strpos($rawText, 'WX') !== false || strpos($rawText, '微信') !== false) return 'wxpay';
        if (strpos($rawText, 'ALIPAY') !== false || strpos($rawText, '支付宝') !== false || strpos($rawText, 'ZFB') !== false) return 'alipay';
        if (strpos($rawText, 'BANK') !== false || strpos($rawText, 'UNION') !== false || strpos($rawText, '云闪付') !== false || strpos($rawText, '银联') !== false) return 'bank';
        return '';
    }

    private function isTradeAlreadyUsed(string $tradeNo, string $apiTradeNo, string $billTradeNo, string $billMchTradeNo): bool
    {
        $query = Db::name('order')->where('trade_no', '<>', $tradeNo)->where('status', '>', 0);
        $query->where(function ($q) use ($apiTradeNo, $billTradeNo, $billMchTradeNo) {
            if ($apiTradeNo !== '') $q->whereOr('api_trade_no', $apiTradeNo);
            if ($billTradeNo !== '') $q->whereOr('bill_trade_no', $billTradeNo);
            if ($billMchTradeNo !== '') $q->whereOr('bill_mch_trade_no', $billMchTradeNo);
        });
        return !empty($query->value('trade_no'));
    }

    private function buildAesPin(string $mchntCd, string $shopId, string $staffId): string
    {
        if (!function_exists('openssl_encrypt')) throw new Exception('openssl扩展不可用');
        $plainText = trim($mchntCd) . ',' . trim($shopId) . ',' . trim($staffId) . ',8';
        $cipherRaw = openssl_encrypt($plainText, 'AES-128-CBC', self::AES_KEY, OPENSSL_RAW_DATA, self::AES_IV);
        if ($cipherRaw === false) throw new Exception('生成aesPin失败');
        return base64_encode($cipherRaw);
    }

    private function normalizePayUrl(string $payUrl): string
    {
        $payUrl = trim($payUrl);
        if ($payUrl === '' || stripos($payUrl, 'data:image/') === 0) return $payUrl;
        if (preg_match('#^https?://#i', $payUrl)) return $payUrl;
        if (strpos($payUrl, '//') === 0) return 'https:' . $payUrl;
        if ($payUrl[0] === '/') return rtrim(self::API_HOST, '/') . $payUrl;
        if (stripos($payUrl, 'merchant/') === 0 || stripos($payUrl, 'end/') === 0) return rtrim(self::API_HOST, '/') . '/' . ltrim($payUrl, '/');
        return $payUrl;
    }

    private function mapChannelTypeToPayType(string $channelType): string
    {
        if ($channelType === '1') return '1';
        if ($channelType === '2') return '2';
        if (in_array($channelType, ['3', '4', '5'], true)) return '5';
        return '';
    }

    private function normalizeAmountFromFen($value): float
    {
        if ($value === null || $value === '') return 0.00;
        if (!is_numeric((string)$value)) return $this->parseAmount((string)$value);
        return $this->roundAmount(((float)$value) / 100);
    }

    private function pickFirst(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') return $row[$key];
        }
        return '';
    }

    private function parseAmount(string $value): float
    {
        $value = trim($value);
        if ($value === '') return 0.00;
        $value = str_replace(',', '', $value);
        $value = preg_replace('/[^\d\.\-]/', '', $value);
        if ($value === '' || $value === '-' || $value === '.') return 0.00;
        return $this->roundAmount((float)$value);
    }

    private function parseTime(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        if (is_numeric($value)) {
            if (strlen($value) >= 13) return (int)substr($value, 0, 10);
            return (int)$value;
        }
        if (preg_match('/^\d{14}$/', $value)) {
            $dt = \DateTime::createFromFormat('YmdHis', $value);
            if ($dt) return $dt->getTimestamp();
        }
        $ts = strtotime($value);
        return $ts !== false ? (int)$ts : 0;
    }

    private function formatDateTime(int $ts): ?string
    {
        if ($ts <= 0) return null;
        return date('Y-m-d H:i:s', $ts);
    }

    private function roundAmount(float $value): float
    {
        return round($value, 2);
    }

    private function readPositiveInt($value, int $default, int $min, int $max): int
    {
        $num = (int)$value;
        if ($num <= 0) $num = $default;
        if ($num < $min) $num = $min;
        if ($num > $max) $num = $max;
        return $num;
    }

    private function readOrderValidMinutes(array $channel): int
    {
        return $this->readPositiveInt($channel['order_valid_minutes'] ?? null, self::DEFAULT_VERIFY_MINUTES, 1, 180);
    }

    private function safeError(string $msg): string
    {
        $msg = trim($msg);
        if ($msg === '') return '未知异常';
        $msg = preg_replace('/\s+/', ' ', $msg);
        return substr((string)$msg, 0, 180);
    }
}

