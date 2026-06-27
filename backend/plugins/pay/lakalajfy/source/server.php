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

$channelid = isset($argv[1]) ? intval($argv[1]) : exit('支付通道ID不能为空');
$channel = \app\lib\Channel::get($channelid);
if (!$channel || $channel['plugin'] != 'lakalajfy') exit('支付通道不存在');

$prefix = Config::get('database.connections.mysql.prefix', '');

$plugin = new \plugins\payment\lakalajfy\LakalajfyPlugin($channel);

while (true) {
    $now = time();
    // 检索5分钟内未支付订单
    $list = Db::name('order')
        ->field('trade_no,api_trade_no,channel,subchannel')
        ->where('channel', (int)$channel['id'])
        ->where('status', 0)
        ->whereTime('addtime', '>=', date('Y-m-d H:i:s', time() - 300))
        ->select()
        ->toArray();
    if (empty($list)) {
        echo '暂无未支付订单...' . PHP_EOL;
        goto WAIT;
    }
    echo '共查询到' . count($list) . '条待处理订单' . PHP_EOL;
    foreach ($list as $item) {
        $tradeNo = $item['trade_no'];
        $apiTradeNo = $item['api_trade_no'];
        if (empty($apiTradeNo)) {
            continue;
        }

        // 子通道
        if ($item['subchannel'] > 0) {
            $curChannel = \app\lib\Channel::getSub($item['subchannel']);
        } else {
            $curChannel = $channel;
        }

        $curPlugin = ($curChannel === $channel) ? $plugin : new \plugins\payment\lakalajfy\LakalajfyPlugin($curChannel);

        usleep(100000);
        try {
            $result = $curPlugin->queryOrder($apiTradeNo);
        } catch (\Throwable $e) {
            echo $tradeNo . '查单出错，' . $e->getMessage() . PHP_EOL;
            continue;
        }
        if (($result['orderStatus'] ?? 0) != 2) {
            echo $tradeNo . '订单还未支付' . PHP_EOL;
            continue;
        }

        $order = Db::name('order')->alias('A')->leftJoin('type B', 'A.type=B.id')->where('A.trade_no', $tradeNo)->field('A.*,B.name typename,B.showname typeshowname')->find();
        if (empty($order)) {
            echo '订单' . $tradeNo . '不存在或已过期' . PHP_EOL;
            continue;
        }
        $order['plugin'] = $curChannel['plugin'];
        $buyer = $result['orderTradeInfoList'][0]['userId2'] ?? null;
        $bill_trade_no = $result['orderTradeInfoList'][0]['tradeNo'] ?? null;
        $bill_mch_trade_no = $result['orderTradeInfoList'][0]['accTradeNo'] ?? null;
        CallbackTrustService::beginTrusted([
            'scope' => 'notify',
            'action' => 'notify',
            'plugin_code' => 'lakalajfy',
            'channel_id' => (int)($curChannel['id'] ?? 0),
            'merchant_id' => (int)($order['uid'] ?? $order['merchant_id'] ?? 0),
            'source' => 'plugin-query',
            'verification' => 'provider-order-query',
        ], static function () use ($curChannel, $order, $apiTradeNo, $buyer, $bill_trade_no, $bill_mch_trade_no) {
            (new \app\service\OrderProcessService($curChannel, $order))->processNotify($apiTradeNo, $buyer, $bill_trade_no, $bill_mch_trade_no);
        });
        echo '订单' . $tradeNo . '支付成功' . PHP_EOL;
    }
    WAIT:
    $time = time() - $now;
    if ($time < 4) {
        sleep(4 - $time);
    }
}
echo 'stop!';
