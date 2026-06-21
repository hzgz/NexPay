<?php

return [
    'kind' => 'app',
    'capabilities' => ['create', 'query', 'notify'],
    'payment_methods' => ['wechat'],
    'default_settings' => [
        'enabled' => true,
        'mode' => 'app',
        'device' => 'mobile',
        'notify_retry' => 5,
    ],
    'settings_schema' => [
        ['key' => 'mode', 'label' => '支付模式', 'type' => 'select'],
        ['key' => 'device', 'label' => '设备场景', 'type' => 'select'],
        ['key' => 'notify_retry', 'label' => '回调重试次数', 'type' => 'number'],
    ],
];
