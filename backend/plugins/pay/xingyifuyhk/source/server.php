<?php
if (substr(php_sapi_name(), 0, 3) != 'cli') {
    die("This Programe can only be run in CLI mode");
}
@chdir(dirname(__FILE__));

$rootPath = dirname(__DIR__, 3);
require $rootPath . '/vendor/autoload.php';
(new \think\App($rootPath))->initialize();

use think\facade\Db;
use think\facade\Config;
use app\service\payment\CallbackTrustService;

$prefix = Config::get('database.connections.mysql.prefix', '');

while (true) {
    $now = time();
    $channelIds = Db::name('channel')
        ->where('plugin', 'xingyifuyhk')
        ->where('status', 1)
        ->column('id');
    $list = $channelIds === []
        ? []
        : Db::name('order')
            ->field('trade_no,realmoney,channel')
            ->whereIn('channel', $channelIds)
            ->where('status', 0)
            ->whereTime('addtime', '>=', date('Y-m-d H:i:s', time() - 480))
            ->select()
            ->toArray();
    if (empty($list)) {
        echo '暂无未支付订单...' . PHP_EOL;
        goto WAIT;
    }
    $channelList = [];
    foreach ($list as $item) {
        $channelList[$item['channel']][] = $item;
    }
    foreach ($channelList as $channelid => $items) {
        $channel = \app\lib\Channel::get($channelid);
        if (!$channel) continue;
        $plugin = new \plugins\payment\xingyifuyhk\XingyifuyhkPlugin($channel);
        try {
            $orderList = $plugin->getOrderList();
        } catch (\Exception $ex) {
            echo '['.$channelid.']查询订单列表失败：' . $ex->getMessage() . PHP_EOL;
            continue;
        }
        if (empty($orderList)) {
            echo '['.$channelid.']共查询到0条已完成订单' . PHP_EOL;
            continue;
        }
        echo '['.$channelid.']共查询到' . count($orderList) . '条已完成订单' . PHP_EOL;
        foreach ($orderList as $item) {
            if (!isset($item['payState']) || !isset($item['renPageName']) || $item['payState'] != 1) continue;
            $trade_no = trim($item['renPageName']);
            $money = round($item['amount'] / 100, 2);
            $orders = array_filter($items, function ($v) use ($trade_no) {
                return $v['trade_no'] == $trade_no;
            });
            if (!empty($orders)) {
                $order = Db::name('order')->alias('A')->leftJoin('type B', 'A.type=B.id')->where('A.trade_no', $trade_no)->field('A.*,B.name typename,B.showname typeshowname')->find();
                if ($order) {
                    $order['plugin'] = $channel['plugin'];
                    $channel = \app\lib\Channel::get($order['channel']);
                    CallbackTrustService::beginTrusted([
                        'scope' => 'notify',
                        'action' => 'notify',
                        'plugin_code' => 'xingyifuyhk',
                        'channel_id' => (int)($channel['id'] ?? 0),
                        'merchant_id' => (int)($order['uid'] ?? $order['merchant_id'] ?? 0),
                        'source' => 'plugin-query',
                        'verification' => 'provider-order-list',
                    ], static function () use ($channel, $order, $item) {
                        (new \app\service\OrderProcessService($channel, $order))->processNotify($item['orderId'], $item['userOfficialId'] ?? null, $item['edenOrderId'] ?? null, null, $item['updateTime']);
                    });
                    echo '['.$channelid.']订单' . $trade_no . '(' . $money . '元)支付成功' . PHP_EOL;
                }
            }
        }
    }
    WAIT:
    $time = time() - $now;
    if ($time < 3) {
        sleep(3 - $time);
    } else {
        usleep(100000);
    }
}
echo 'stop!';
