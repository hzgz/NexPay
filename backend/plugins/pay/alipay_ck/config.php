<?php

return [
    'kind' => 'ck',
    'config_panel' => 'login_qrcode',
    'capabilities' => ['create', 'query', 'notify'],
    'payment_methods' => ['alipay'],
    'default_settings' => [
        'enabled' => true,
        'payment_address' => '',
        'display_value' => '',
        'qrcode_url' => '',
        'login_id' => '',
        'login_qr_content' => '',
        'login_qr_image' => '',
        'login_state' => 'idle',
        'login_state_text' => '',
        'login_state_message' => '',
        'login_checked_at' => '',
        'login_confirmed_at' => '',
        'login_cookie_base64' => '',
        'account_pid' => '',
    ],
    'settings_schema' => [],
];
