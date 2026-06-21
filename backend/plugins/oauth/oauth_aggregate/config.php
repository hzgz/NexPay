<?php

return [
    'kind' => 'oauth',
    'capabilities' => ['qq_login', 'wechat_login', 'alipay_login'],
    'default_settings' => [
        'enabled' => false,
        'failure_fallback' => true,
        'bind_auto_create' => false,
        'callback_path' => '/user/login',
        'response_sign_required' => true,
        'qq_enabled' => false,
        'wechat_enabled' => false,
        'alipay_enabled' => false,
        'google_enabled' => false,
        'telegram_enabled' => false,
    ],
    'settings_schema' => [
        ['key' => 'enabled', 'label' => '启用聚合登录', 'type' => 'switch'],
        ['key' => 'api_url', 'label' => '聚合接口地址', 'type' => 'text'],
        ['key' => 'app_id', 'label' => '应用 ID', 'type' => 'text'],
        ['key' => 'app_key', 'label' => '应用 Key', 'type' => 'password'],
        ['key' => 'callback_url', 'label' => '回调地址', 'type' => 'text'],
        ['key' => 'response_sign_required', 'label' => '强制校验响应签名', 'type' => 'switch'],
        ['key' => 'qq_enabled', 'label' => 'QQ 登录', 'type' => 'switch'],
        ['key' => 'wechat_enabled', 'label' => '微信登录', 'type' => 'switch'],
        ['key' => 'alipay_enabled', 'label' => '支付宝登录', 'type' => 'switch'],
        ['key' => 'google_enabled', 'label' => 'Google 登录', 'type' => 'switch'],
        ['key' => 'telegram_enabled', 'label' => 'Telegram 登录', 'type' => 'switch'],
        ['key' => 'failure_fallback', 'label' => '失败降级', 'type' => 'switch'],
        ['key' => 'bind_auto_create', 'label' => '自动创建绑定', 'type' => 'switch'],
        ['key' => 'callback_path', 'label' => '回调路径', 'type' => 'text'],
    ],
];
