<?php

return [
    'kind' => 'chain',
    'capabilities' => ['address_match', 'chain_confirm', 'notify'],
    'payment_methods' => ['trc20'],
    'default_settings' => [
        'enabled' => true,
        'address' => '',
        'appurl' => 360,
        'xiaoshu' => 2,
        'confirmations' => 2,
        'listener' => 'mock-listener',
        'address_strategy' => 'single',
    ],
    'settings_schema' => [
        [
            'key' => 'address',
            'label' => '收款地址',
            'type' => 'text',
            'required' => true,
            'placeholder' => '请输入 TRC20 收款地址',
            'note' => '发起支付时将使用这里的 TRC20 地址生成链上收银台二维码。',
        ],
        [
            'key' => 'appurl',
            'label' => '订单有效期（秒）',
            'type' => 'number',
            'required' => true,
            'placeholder' => '360',
            'note' => '默认 360 秒，即 6 分钟有效期。',
        ],
        [
            'key' => 'xiaoshu',
            'label' => '金额小数位',
            'type' => 'number',
            'required' => true,
            'placeholder' => '2',
            'note' => '建议 2 到 6 位，用于链上金额差异化。',
        ],
        [
            'key' => 'confirmations',
            'label' => '确认次数',
            'type' => 'number',
            'required' => true,
            'placeholder' => '2',
        ],
        [
            'key' => 'listener',
            'label' => '监听模式',
            'type' => 'select',
            'required' => true,
            'options' => [
                ['label' => '模拟监听', 'value' => 'mock-listener'],
            ],
        ],
        [
            'key' => 'address_strategy',
            'label' => '地址策略',
            'type' => 'select',
            'required' => true,
            'options' => [
                ['label' => '单地址收款', 'value' => 'single'],
            ],
        ],
    ],
];
