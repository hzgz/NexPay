<?php

return [
    'kind' => 'notify',
    'capabilities' => ['send_sms', 'verify_code'],
    'default_settings' => [
        'enabled' => false,
        'sign_name' => 'NexPay',
        'template_code' => '',
        'access_key_id' => '',
        'access_key_secret' => '',
    ],
    'settings_schema' => [
        ['key' => 'sign_name', 'label' => '短信签名', 'type' => 'text'],
        ['key' => 'template_code', 'label' => '模板编码', 'type' => 'text'],
        ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'type' => 'text'],
        ['key' => 'access_key_secret', 'label' => 'AccessKey Secret', 'type' => 'password'],
    ],
];
