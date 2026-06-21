<?php

return [
    'kind' => 'verify',
    'capabilities' => ['login_verify', 'register_verify', 'forgot_verify', 'admin_verify'],
    'default_settings' => [
        'enabled' => false,
        'captcha_id' => '',
        'captcha_key' => '',
        'failback' => true,
    ],
    'settings_schema' => [
        ['key' => 'captcha_id', 'label' => '极验 ID', 'type' => 'text'],
        ['key' => 'captcha_key', 'label' => '极验 Key', 'type' => 'password'],
        ['key' => 'failback', 'label' => '失败降级', 'type' => 'switch'],
    ],
];
