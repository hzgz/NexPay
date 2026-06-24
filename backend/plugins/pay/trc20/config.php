<?php

return [
    'kind' => 'chain',
    'capabilities' => ['address_match', 'chain_confirm', 'notify', 'daemon'],
    'payment_methods' => ['trc20'],
    'default_settings' => [
        'enabled' => true,
        'source_plugin' => 'trc20',
        'confirmations' => 2,
        'listener' => 'manual',
        'appid' => '',
        'appkey' => '',
        'appurl' => '360',
        'botid' => '',
        'bottoken' => '',
        'xiaoshu' => '2',
    ],
    'settings_schema' => [
        ['key' => 'appid', 'label' => '收款地址', 'type' => 'text', 'required' => true, 'placeholder' => '请输入 TRC20 钱包地址', 'note' => '必须是以 T 开头的 TRC20 USDT 收款地址。'],
        ['key' => 'appkey', 'label' => 'USDT 汇率', 'type' => 'number', 'required' => true, 'placeholder' => '例如 7.20', 'note' => '按人民币填写 1 USDT 对应金额，用于换算订单应付币额。'],
        ['key' => 'appurl', 'label' => '订单有效期(秒)', 'type' => 'number', 'required' => true, 'placeholder' => '360', 'note' => '默认 360 秒，即 6 分钟有效期。'],
        ['key' => 'xiaoshu', 'label' => '金额小数位', 'type' => 'number', 'required' => true, 'placeholder' => '2', 'note' => '建议 2 到 6 位，默认 2 位。'],
        ['key' => 'botid', 'label' => 'Telegram 用户 ID', 'type' => 'text', 'placeholder' => '可选', 'note' => '选填，用于接收链上订单提醒。'],
        ['key' => 'bottoken', 'label' => 'Telegram Bot Token', 'type' => 'text', 'placeholder' => '可选', 'note' => '选填，配合 Telegram 用户 ID 使用。'],
    ],
    'source' => [
        'vendor' => 'epay_pro',
        'plugin' => 'trc20',
        'title' => 'TRC20 USDT 支付',
        'link' => '',
        'note' => '<p>监控脚本目录：<u>[basedir]plugins/payment/trc20/</u></p><p>启动命令：<u>php server.php [channel]</u></p>',
    ],
];
