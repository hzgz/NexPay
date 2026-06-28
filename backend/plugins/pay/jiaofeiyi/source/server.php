<?php

use think\facade\Db;
use app\service\payment\CallbackTrustService;

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
    die("This program can only be run in CLI mode" . PHP_EOL);
}

@chdir(dirname(__FILE__));

$rootPath = dirname(__DIR__, 3);
require $rootPath . '/vendor/autoload.php';
(new \think\App($rootPath))->initialize();

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
if (function_exists('ignore_user_abort')) {
    @ignore_user_abort(true);
}

$arg = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($arg === '') {
    exit('Channel ID cannot be empty, or use all' . PHP_EOL);
}

$watchAll = strtolower($arg) === 'all';
$singleChannel = null;

if (!$watchAll) {
    $channelId = (int)$arg;
    if ($channelId <= 0) {
        exit('Invalid channel ID' . PHP_EOL);
    }

    $singleChannel = \app\lib\Channel::get($channelId);
    if (!$singleChannel || ($singleChannel['plugin'] ?? '') !== 'jiaofeiyi') {
        exit('Payment channel does not exist or plugin mismatch' . PHP_EOL);
    }
}

$loadChannels = static function () use ($watchAll, $singleChannel): array {
    if (!$watchAll) {
        return [$singleChannel];
    }

    $channelIds = Db::name('channel')->where('plugin', 'jiaofeiyi')->column('id');
    $channels = [];
    foreach ($channelIds as $id) {
        $channel = \app\lib\Channel::get((int)$id);
        if ($channel && ($channel['plugin'] ?? '') === 'jiaofeiyi') {
            $channels[] = $channel;
        }
    }

    return $channels;
};

while (true) {
    $startedAt = microtime(true);
    $sinceTime = date('Y-m-d H:i:s', time() - 360);

    $channels = $loadChannels();
    if (empty($channels)) {
        echo 'No available jiaofeiyi channels found' . PHP_EOL;
        goto WAIT;
    }

    $hasOrders = false;

    foreach ($channels as $channel) {
        $channelId = (int)($channel['id'] ?? 0);
        if ($channelId <= 0) {
            continue;
        }

        $plugin = new \plugins\payment\jiaofeiyi\JiaofeiyiPlugin($channel);

        $orders = Db::name('order')
            ->where('channel', $channelId)
            ->where('status', 0)
            ->where('addtime', '>=', $sinceTime)
            ->field('trade_no,api_trade_no,subchannel,ext,payurl')
            ->order('addtime', 'asc')
            ->select()
            ->toArray();

        if (empty($orders)) {
            continue;
        }

        $hasOrders = true;
        echo '[channel ' . $channelId . '] found ' . count($orders) . ' pending orders' . PHP_EOL;

        foreach ($orders as $item) {
            $tradeNo = (string)($item['trade_no'] ?? '');
            if ($tradeNo === '') {
                continue;
            }

            $subchannel = (int)($item['subchannel'] ?? 0);
            if ($subchannel > 0) {
                $curChannel = \app\lib\Channel::getSub($subchannel);
                if (!$curChannel || ($curChannel['plugin'] ?? '') !== 'jiaofeiyi') {
                    echo $tradeNo . ' failed to load subchannel config' . PHP_EOL;
                    continue;
                }
                $curPlugin = new \plugins\payment\jiaofeiyi\JiaofeiyiPlugin($curChannel);
            } else {
                $curChannel = $channel;
                $curPlugin = $plugin;
            }

            $queryTargets = [];
            if (method_exists($curPlugin, 'buildQueryTargets')) {
                $queryTargets = $curPlugin->buildQueryTargets($item);
            }

            $apiTradeNo = trim((string)($item['api_trade_no'] ?? ''));
            if ($apiTradeNo !== '' && !in_array($apiTradeNo, $queryTargets, true)) {
                array_unshift($queryTargets, $apiTradeNo);
            }

            $queryTargets = array_values(array_unique(array_filter($queryTargets, static function ($value) {
                return trim((string)$value) !== '';
            })));

            if (empty($queryTargets)) {
                echo $tradeNo . ' no query target available' . PHP_EOL;
                continue;
            }

            $matched = false;

            foreach ($queryTargets as $target) {
                usleep(100000);

                try {
                    $result = $curPlugin->queryOrder((string)$target);
                } catch (\Throwable $e) {
                    echo $tradeNo . ' query failed: ' . $e->getMessage() . PHP_EOL;
                    continue;
                }

                $status = $result['orderStatus'] ?? null;
                if (!$curPlugin->isPaidStatus($status)) {
                    echo $tradeNo . ' not paid yet' . PHP_EOL;
                    continue;
                }

                $order = Db::name('order')
                    ->alias('A')
                    ->leftJoin('type B', 'A.type=B.id')
                    ->where('A.trade_no', $tradeNo)
                    ->field('A.*,B.name typename,B.showname typeshowname')
                    ->find();

                if (empty($order)) {
                    echo 'order ' . $tradeNo . ' does not exist or has expired' . PHP_EOL;
                    $matched = true;
                    break;
                }

                $notifyApiTradeNo = trim((string)($order['api_trade_no'] ?? ''));
                if ($notifyApiTradeNo === '') {
                    $notifyApiTradeNo = trim((string)($result['payOrderNo'] ?? ''));
                }
                if ($notifyApiTradeNo === '') {
                    $notifyApiTradeNo = trim((string)($result['channelTradeNo'] ?? ''));
                }
                if ($notifyApiTradeNo === '') {
                    $notifyApiTradeNo = trim((string)($result['sysTradeNo'] ?? ''));
                }
                if ($notifyApiTradeNo === '') {
                    $notifyApiTradeNo = trim((string)$target);
                }

                $buyer = $result['buyer'] ?? null;
                $billTradeNo = $result['billTradeNo'] ?? null;
                $billMchTradeNo = $result['billMchTradeNo'] ?? ($result['payOrderNo'] ?? ($result['channelTradeNo'] ?? null));

                try {
                    CallbackTrustService::beginTrusted([
                        'scope' => 'notify',
                        'action' => 'notify',
                        'plugin_code' => 'jiaofeiyi',
                        'channel_id' => (int)($curChannel['id'] ?? 0),
                        'merchant_id' => (int)($order['uid'] ?? $order['merchant_id'] ?? 0),
                        'source' => 'plugin-query',
                        'verification' => 'provider-order-query',
                    ], static function () use ($curChannel, $order, $notifyApiTradeNo, $buyer, $billTradeNo, $billMchTradeNo) {
                        (new \app\service\OrderProcessService($curChannel, $order))
                            ->processNotify($notifyApiTradeNo, $buyer, $billTradeNo, $billMchTradeNo);
                    });
                    echo 'order ' . $tradeNo . ' paid successfully' . PHP_EOL;
                } catch (\Throwable $e) {
                    echo 'order ' . $tradeNo . ' notify failed: ' . $e->getMessage() . PHP_EOL;
                }

                $matched = true;
                break;
            }

            if (!$matched) {
                echo $tradeNo . ' no confirmed paid result found' . PHP_EOL;
            }
        }
    }

    if (!$hasOrders) {
        echo 'No pending orders' . PHP_EOL;
    }

    WAIT:
    $elapsed = microtime(true) - $startedAt;
    if ($elapsed < 4) {
        $remain = (int)ceil(4 - $elapsed);
        if ($remain > 0) {
            sleep($remain);
        }
    }
}
