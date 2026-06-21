<?php

return [
    'kind' => 'verify',
    'capabilities' => ['login_verify', 'register_verify', 'forgot_verify'],
    'default_settings' => [
        'enabled' => false,
        'scene_login' => true,
        'scene_register' => true,
        'scene_forgot' => true,
    ],
    'settings_schema' => [
        ['key' => 'scene_login', 'label' => '登录场景', 'type' => 'switch'],
        ['key' => 'scene_register', 'label' => '注册场景', 'type' => 'switch'],
        ['key' => 'scene_forgot', 'label' => '找回场景', 'type' => 'switch'],
    ],
];
