<?php

return [
    'kind' => 'utility',
    'capabilities' => ['test_create_order', 'test_mock_complete'],
    'default_settings' => [
        'enabled' => true,
        'allow_mock_complete' => true,
        'default_method' => 'alipay',
        'auto_complete_delay' => 5,
    ],
    'settings_schema' => [
        ['key' => 'allow_mock_complete', 'label' => '允许模拟支付', 'type' => 'switch'],
        ['key' => 'default_method', 'label' => '默认测试方式', 'type' => 'text'],
        ['key' => 'auto_complete_delay', 'label' => '自动完成延迟', 'type' => 'number'],
    ],
];
