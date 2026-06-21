<?php

return [
    'kind' => 'qrcode',
    'capabilities' => ['create', 'query', 'notify'],
    'payment_methods' => ['alipay'],
    'default_settings' => [
        'enabled' => true,
        'mode' => 'qrcode',
        'device' => 'pc',
        'notify_retry' => 5,
        'qrcode_image' => '',
        'payment_address' => '',
        'display_value' => '',
        'qrcode_url' => '',
    ],
    'settings_schema' => [
        [
            'key' => 'mode',
            'label' => '码类型',
            'type' => 'select',
            'required' => true,
            'options' => [
                ['label' => '支付宝收款码', 'value' => 'qrcode'],
            ],
        ],
        [
            'key' => 'qrcode_image',
            'label' => '支付宝收款码图片',
            'type' => 'image',
            'required' => true,
            'upload_action' => 'merchant_channel_qrcode',
            'accept' => '.jpg,.jpeg,.png,.gif,.webp,.bmp',
            'note' => '上传后将调用后台解析二维码内容，并保存为发起支付时的生码地址。',
        ],
        [
            'key' => 'payment_address',
            'label' => '解析后的二维码地址',
            'type' => 'text',
            'required' => true,
            'readonly' => true,
            'note' => '由后台解析二维码图片后自动回填。',
        ],
        [
            'key' => 'device',
            'label' => '设备场景',
            'type' => 'select',
            'options' => [
                ['label' => 'PC/网页', 'value' => 'pc'],
                ['label' => '手机/H5', 'value' => 'mobile'],
            ],
        ],
        ['key' => 'notify_retry', 'label' => '回调重试次数', 'type' => 'number'],
    ],
];
