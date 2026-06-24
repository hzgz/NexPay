<?php

return [
    'kind' => 'chain',
    'capabilities' => ['address_match', 'chain_confirm', 'notify', 'daemon'],
    'payment_methods' => ['aptos'],
    'default_settings' => [
        'enabled' => true,
        'source_plugin' => 'aptos',
        'confirmations' => 2,
        'listener' => 'manual',
        'appid' => '',
        'appkey' => '',
        'appurl' => '360',
        'botid' => '',
        'bottoken' => '',
        'xiaoshu' => '4',
    ],
    'settings_schema' => [
        ['key' => 'appid', 'label' => '收款地址', 'type' => 'text', 'required' => true, 'placeholder' => '请输入 Aptos 钱包地址', 'note' => '必须是以 0x 开头的 Aptos 链地址。'],
        ['key' => 'appkey', 'label' => 'USDT 汇率', 'type' => 'number', 'required' => true, 'placeholder' => '例如 7.20', 'note' => '按人民币填写 1 USDT 对应金额，用于换算订单应付币额。'],
        ['key' => 'xiaoshu', 'label' => '金额小数位', 'type' => 'number', 'required' => true, 'placeholder' => '4', 'note' => '建议 2 到 6 位，Aptos 默认 4 位。'],
        ['key' => 'botid', 'label' => 'Telegram 用户 ID', 'type' => 'text', 'placeholder' => '可选', 'note' => '选填，用于接收链上订单提醒。'],
        ['key' => 'bottoken', 'label' => 'Telegram Bot Token', 'type' => 'text', 'placeholder' => '可选', 'note' => '选填，配合 Telegram 用户 ID 使用。'],
    ],
    'source' => [
        'vendor' => 'epay_pro',
        'plugin' => 'aptos',
        'title' => 'Aptos USDT 支付',
        'link' => '',
        'note' => '<p>监控脚本目录：<u>[basedir]plugins/payment/aptos/</u></p><p>启动命令：<u>php server.php [channel]</u></p>',
    ],
];