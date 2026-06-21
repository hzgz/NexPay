<?php

return [
    'kind' => 'notify',
    'capabilities' => ['send_mail', 'verify_code'],
    'default_settings' => [
        'enabled' => false,
        'smtp_host' => '',
        'smtp_port' => '465',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_secure' => 'ssl',
        'sender_name' => 'NexPay',
    ],
    'settings_schema' => [
        ['key' => 'smtp_host', 'label' => 'SMTP 主机', 'type' => 'text'],
        ['key' => 'smtp_port', 'label' => 'SMTP 端口', 'type' => 'number'],
        ['key' => 'smtp_user', 'label' => 'SMTP 账号', 'type' => 'text'],
        ['key' => 'smtp_pass', 'label' => 'SMTP 密码', 'type' => 'password'],
        ['key' => 'smtp_secure', 'label' => '加密方式', 'type' => 'select'],
    ],
];
