<?php

return [
    'kind' => 'qrcode',
    'capabilities' => ['create', 'query', 'notify'],
    'payment_methods' => ['qqpay'],
    'default_settings' => [
        'enabled' => true,
        'mode' => 'native',
        'device' => 'pc',
        'notify_retry' => 5,
    ],
    'settings_schema' => [
        [
            'key' => 'mode',
            'label' => '支付模式',
            'type' => 'select',
            'options' => [
                ['label' => '二维码', 'value' => 'native'],
            ],
        ],
        [
            'key' => 'device',
            'label' => '设备场景',
            'type' => 'select',
            'options' => [
                ['label' => 'PC/网页', 'value' => 'pc'],
                ['label' => '手机/H5', 'value' => 'mobile'],
            ],
        ],
        ['key' => 'notify_retry', 'label' => '回调重试次数', 'type' => 'number'],
    ],
];
