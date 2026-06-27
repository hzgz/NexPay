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

$selectedChannelIds = [];
if (isset($argv[1])) {
    $offset = intval($argv[1]);
    $selectedChannelIds = array_map('intval', Db::name('channel')
        ->where('plugin', 'suixinglife')
        ->where('status', 1)
        ->order('id', 'asc')
        ->limit($offset, 1)
        ->column('id'));
} else {
    $selectedChannelIds = array_map('intval', Db::name('channel')
        ->where('plugin', 'suixinglife')
        ->where('status', 1)
        ->column('id'));
}

$tranDetailCache = [];
$tranDetailCacheTtl = 600;

while (true) {
    $now = time();
    foreach ($tranDetailCache as $key => $cache) {
        if ($cache['expire'] <= $now) {
            unset($tranDetailCache[$key]);
        }
    }

    $list = $selectedChannelIds === []
        ? []
        : Db::name('order')
            ->field('trade_no,realmoney,channel')
            ->whereIn('channel', $selectedChannelIds)
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
        $plugin = new \plugins\payment\suixinglife\SuixinglifePlugin($channel);
        
        $orderMap = [];
        foreach ($items as $pendingOrder) {
            $orderMap[$pendingOrder['trade_no']] = $pendingOrder;
        }

        try {
            $orderList = $plugin->queryTranList();
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
            if (empty($item['uuid']) || !isset($item['tranStsDesc']) || $item['tranStsDesc'] != '成功') continue;

            $detail = null;
            $detailKey = $item['uuid'];
            if (isset($tranDetailCache[$detailKey])) {
                $detail = $tranDetailCache[$detailKey]['detail'];
            } else {
                try {
                    $detail = $plugin->queryTranDetail($item['uuid'], $item['payImag'], $item['creDt']);
                    $tranDetailCache[$detailKey] = [
                        'detail' => $detail,
                        'expire' => $now + $tranDetailCacheTtl,
                    ];
                } catch (\Exception $ex) {
                    echo '['.$channelid.']查询订单详情失败：' . $ex->getMessage() . PHP_EOL;
                    continue;
                }
            }
            if (!$detail) continue;
            $trade_no = $detail['tranExtend'];
            if (empty($trade_no)) continue;
            if (!isset($orderMap[$trade_no])) continue; 

            $money = $item['amt'] ?? 0;
            $order = Db::name('order')->alias('A')->leftJoin('type B', 'A.type=B.id')->where('A.trade_no', $trade_no)->field('A.*,B.name typename,B.showname typeshowname')->find();
            if ($order) {
                $order['plugin'] = $channel['plugin'];
                $bill_trade_no = $detail['transactionId'] ?? null;
                $bill_mch_trade_no = $detail['refundNo'] ?? null;
                $end_time = $detail['finshTime'] ?? null;
                $buyer = $detail['consumerId'] ?? null;
                CallbackTrustService::beginTrusted([
                    'scope' => 'notify',
                    'action' => 'notify',
                    'plugin_code' => 'suixinglife',
                    'channel_id' => (int)($channel['id'] ?? 0),
                    'merchant_id' => (int)($order['uid'] ?? $order['merchant_id'] ?? 0),
                    'source' => 'plugin-query',
                    'verification' => 'provider-order-detail',
                ], static function () use ($channel, $order, $item, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time) {
                    (new \app\service\OrderProcessService($channel, $order))->processNotify($item['uuid'], $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time);
                });
                echo '['.$channelid.']订单' . $trade_no . '(' . $money . '元)支付成功' . PHP_EOL;
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
