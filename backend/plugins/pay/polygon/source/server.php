<?php

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

$channelId = isset($argv[1]) ? (int)$argv[1] : exit('通道ID不能为空' . PHP_EOL);
$channel = \app\lib\Channel::get($channelId);
if (!$channel || ($channel['plugin'] ?? '') !== 'polygon') {
    exit('支付通道不存在或插件不匹配' . PHP_EOL);
}

while (true) {
    $startedAt = microtime(true);
    $currentChannel = \app\lib\Channel::get($channelId);
    if (!$currentChannel || ($currentChannel['plugin'] ?? '') !== 'polygon') {
        echo '通道不存在或插件不匹配' . PHP_EOL;
        goto WAIT;
    }

    $plugin = new \plugins\payment\polygon\PolygonPlugin($currentChannel);
    $processed = 0;
    try {
        $processed = $plugin->cron($currentChannel);
    } catch (\Throwable $e) {
        echo '监控异常：' . $e->getMessage() . PHP_EOL;
    }
    echo '本轮处理：' . $processed . PHP_EOL;

    WAIT:
    $elapsed = microtime(true) - $startedAt;
    if ($elapsed < 4) {
        $remain = (int)ceil(4 - $elapsed);
        if ($remain > 0) {
            sleep($remain);
        }
    }
}
