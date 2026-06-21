<?php

return [
    'kind' => 'chain',
    'capabilities' => ['address_match', 'chain_confirm', 'notify'],
    'payment_methods' => ['trc20'],
    'default_settings' => [
        'enabled' => true,
        'confirmations' => 2,
        'listener' => 'mock-listener',
        'address_strategy' => 'single',
    ],
    'settings_schema' => [
        ['key' => 'confirmations', 'label' => '确认次数', 'type' => 'number'],
        ['key' => 'listener', 'label' => '监听模式', 'type' => 'select'],
        ['key' => 'address_strategy', 'label' => '地址策略', 'type' => 'select'],
    ],
];
