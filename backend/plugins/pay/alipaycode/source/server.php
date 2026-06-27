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
if (!$channel || $channel['plugin'] != 'alipaycode') exit('支付通道不存在');
$subChannelId = null;
if (substr($channel['apptoken'], 0, 1) == '[') {
    $channel = \app\lib\Channel::getSub($channelid);
    if (!$channel || $channel['plugin'] != 'alipaycode') exit('子通道不存在');
    $subChannelId = (int)($channel['subid'] ?? 0);
}

$alipay_config = \app\common\PaymentConfig::getAlipayConfig($channel);

$aop = new \Alipay\AlipayBillService($alipay_config);

$prefix = Config::get('database.connections.mysql.prefix', '');

while (true) {
    $now = time();
    $orderQuery = Db::name('order')
        ->field('trade_no,realmoney')
        ->where('channel', (int)$channel['id'])
        ->where('status', 0)
        ->whereTime('addtime', '>=', date('Y-m-d H:i:s', time() - 480));
    if ($subChannelId !== null && $subChannelId > 0) {
        $orderQuery->where('subchannel', $subChannelId);
    }
    $list = $orderQuery->select()->toArray();
    if (empty($list)) {
        echo '暂无未支付订单...' . PHP_EOL;
        goto WAIT;
    }
    $start_time = date('Y-m-d H:i:s', time() - 360);
    $end_time = date('Y-m-d H:i:s', time() + 60);
    try {
        $result = $aop->accountlogQuery($start_time, $end_time, 1, 2000);
    } catch (\Exception $e) {
        echo '查询账务明细失败，' . $e->getMessage() . PHP_EOL;
        goto WAIT;
    }
    if (empty($result['detail_list'])) {
        echo '共查询到0条账务明细' . PHP_EOL;
        goto WAIT;
    }
    echo '共查询到' . count($result['detail_list']) . '条账务明细' . PHP_EOL;
    foreach ($result['detail_list'] as $item) {
        if (isset($item['trans_memo']) && isset($item['trans_amount'])) {
            $trade_no = str_replace('请勿添加备注-', '', $item['trans_memo']);
            $money = $item['trans_amount'];
            $orders = array_filter($list, function ($v) use ($trade_no, $money) {
                return $v['trade_no'] == $trade_no && $v['realmoney'] == $money;
            });
            if (!empty($orders)) {
                $order = Db::name('order')->alias('A')->leftJoin('type B', 'A.type=B.id')->where('A.trade_no', $trade_no)->field('A.*,B.name typename,B.showname typeshowname')->find();
                if ($order) {
                    $order['plugin'] = $channel['plugin'];
                    $buyer = empty($order['buyer']) ? ($item['other_account'] ?? null) : null;
                    CallbackTrustService::beginTrusted([
                        'scope' => 'notify',
                        'action' => 'notify',
                        'plugin_code' => 'alipaycode',
                        'channel_id' => (int)($channel['id'] ?? 0),
                        'merchant_id' => (int)($order['uid'] ?? $order['merchant_id'] ?? 0),
                        'source' => 'plugin-query',
                        'verification' => 'bill-query-match',
                    ], static function () use ($channel, $order, $item, $buyer) {
                        (new \app\service\OrderProcessService($channel, $order))->processNotify($item['alipay_order_no'], $buyer, null, null, $item['trans_dt']);
                    });
                    echo '订单' . $trade_no . '(' . $item['trans_amount'] . '元)支付成功' . PHP_EOL;
                }
            }
        }
    }
    WAIT:
    $time = time() - $now;
    if ($time < 3) {
        sleep(3 - $time);
    }
}
echo 'stop!';
