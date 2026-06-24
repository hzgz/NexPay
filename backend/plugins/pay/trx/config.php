<?php

return [
    'kind' => 'chain',
    'capabilities' => ['address_match', 'chain_confirm', 'notify', 'daemon'],
    'payment_methods' => ['trx'],
    'default_settings' => [
        'enabled' => true,
        'source_plugin' => 'trx',
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
        ['key' => 'appid', 'label' => '收款地址', 'type' => 'text', 'required' => true, 'placeholder' => '请输入 TRON 钱包地址', 'note' => '必须是以 T 开头的 TRX 收款地址。'],
        ['key' => 'appkey', 'label' => 'TRX 汇率', 'type' => 'number', 'required' => true, 'placeholder' => '例如 1.10', 'note' => '按人民币填写 1 TRX 对应金额，用于换算订单应付币额。'],
        ['key' => 'xiaoshu', 'label' => '金额小数位', 'type' => 'number', 'required' => true, 'placeholder' => '2', 'note' => '建议 2 到 6 位，默认 2 位。'],
        ['key' => 'botid', 'label' => 'Telegram 用户 ID', 'type' => 'text', 'placeholder' => '可选', 'note' => '选填，用于接收链上订单提醒。'],
        ['key' => 'bottoken', 'label' => 'Telegram Bot Token', 'type' => 'text', 'placeholder' => '可选', 'note' => '选填，配合 Telegram 用户 ID 使用。'],
    ],
    'source' => [
        'vendor' => 'epay_pro',
        'plugin' => 'trx',
        'title' => 'TRX 支付',
        'link' => '',
        'note' => '<p>监控脚本目录：<u>[basedir]plugins/payment/trx/</u></p><p>启动命令：<u>php server.php [channel]</u></p>',
    ],
];