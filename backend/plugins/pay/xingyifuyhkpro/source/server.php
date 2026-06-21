<?php

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
    die("This program can only be run in CLI mode" . PHP_EOL);
}

@chdir(__DIR__);

$rootPath = dirname(__DIR__, 3);
require $rootPath . '/vendor/autoload.php';
(new \think\App($rootPath))->initialize();

use think\facade\Config;
use think\facade\Db;
use app\service\payment\CallbackTrustService;

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
if (function_exists('ignore_user_abort')) {
    @ignore_user_abort(true);
}

$prefix = Config::get('database.connections.mysql.prefix', '');
$argChannelId = isset($argv[1]) ? (int)$argv[1] : 0;

if ($argChannelId > 0) {
    $single = \app\lib\Channel::get($argChannelId);
    if (!$single || ($single['plugin'] ?? '') !== 'xingyifuyhkpro') {
        exit('支付通道不存在或插件不匹配' . PHP_EOL);
    }
}

while (true) {
    $now = time();

    if ($argChannelId > 0) {
        $list = Db::query("SELECT trade_no,realmoney,channel FROM {$prefix}order WHERE channel='{$argChannelId}' AND status=0 AND addtime>=DATE_SUB(NOW(), INTERVAL 8 MINUTE)");
    } else {
        $list = Db::query("SELECT trade_no,realmoney,channel FROM {$prefix}order WHERE channel IN (SELECT id FROM {$prefix}channel WHERE plugin='xingyifuyhkpro' AND status=1) AND status=0 AND addtime>=DATE_SUB(NOW(), INTERVAL 8 MINUTE)");
    }

    if (empty($list)) {
        echo '暂无未支付订单...' . PHP_EOL;
        goto WAIT;
    }

    $channelList = [];
    foreach ($list as $item) {
        $channelList[$item['channel']][] = $item;
    }

    foreach ($channelList as $channelId => $items) {
        $channel = \app\lib\Channel::get((int)$channelId);
        if (!$channel || ($channel['plugin'] ?? '') !== 'xingyifuyhkpro') {
            continue;
        }

        $plugin = new \plugins\payment\xingyifuyhkpro\XingyifuyhkproPlugin($channel);

        try {
            $orderList = $plugin->getOrderList();
        } catch (\Throwable $ex) {
            echo '[' . $channelId . ']查询订单列表失败：' . $ex->getMessage() . PHP_EOL;
            continue;
        }

        if (empty($orderList)) {
            echo '[' . $channelId . ']共查询到0条已完成订单' . PHP_EOL;
            continue;
        }

        echo '[' . $channelId . ']共查询到' . count($orderList) . '条已完成订单' . PHP_EOL;

        foreach ($orderList as $item) {
            if (!isset($item['payState']) || !isset($item['renPageName']) || (int)$item['payState'] !== 1) {
                continue;
            }

            $tradeNo = trim((string)$item['renPageName']);
            $money = isset($item['amount']) ? round($item['amount'] / 100, 2) : 0;

            $orders = array_filter($items, function ($v) use ($tradeNo) {
                return (string)$v['trade_no'] === $tradeNo;
            });
            if (empty($orders)) {
                continue;
            }

            $order = Db::name('order')
                ->alias('A')
                ->leftJoin('type B', 'A.type=B.id')
                ->where('A.trade_no', $tradeNo)
                ->field('A.*,B.name typename,B.showname typeshowname')
                ->find();

            if (!$order) {
                continue;
            }

            $order['plugin'] = $channel['plugin'];
            $currentChannel = \app\lib\Channel::get((int)$order['channel']);
            if (!$currentChannel) {
                continue;
            }

            try {
                CallbackTrustService::beginTrusted([
                    'scope' => 'notify',
                    'action' => 'notify',
                    'plugin_code' => 'xingyifuyhkpro',
                    'channel_id' => (int)($currentChannel['id'] ?? 0),
                    'merchant_id' => (int)($order['uid'] ?? $order['merchant_id'] ?? 0),
                    'source' => 'plugin-query',
                    'verification' => 'provider-order-list',
                ], static function () use ($currentChannel, $order, $item) {
                    (new \app\service\OrderProcessService($currentChannel, $order))->processNotify(
                        $item['orderId'] ?? null,
                        $item['userOfficialId'] ?? null,
                        $item['edenOrderId'] ?? null,
                        null,
                        $item['updateTime'] ?? null
                    );
                });
                echo '[' . $channelId . ']订单' . $tradeNo . '(' . $money . '元)支付成功' . PHP_EOL;
            } catch (\Throwable $notifyEx) {
                echo '[' . $channelId . ']订单' . $tradeNo . '处理失败：' . $notifyEx->getMessage() . PHP_EOL;
            }
        }
    }

    WAIT:
    $elapsed = time() - $now;
    if ($elapsed < 3) {
        sleep(3 - $elapsed);
    } else {
        usleep(100000);
    }
}
