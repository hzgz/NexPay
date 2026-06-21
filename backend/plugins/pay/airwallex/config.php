<?php

return [
  'kind' => 'international',
  'capabilities' => 
  [
    0 => 'create',
    1 => 'query',
    2 => 'notify',
  ],
  'payment_methods' => 
  [
    0 => 'alipay',
    1 => 'wechat',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'airwallex',
    'mode' => 'international',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appurl' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'Client ID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => 'API 密钥',
      'type' => 'textarea',
      'required' => true,
      'note' => 'Admin key',
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => 'Webhook Secret',
      'type' => 'textarea',
      'required' => true,
      'note' => '用于验证Webhook回调签名',
    ],
    3 => 
    [
      'key' => 'appurl',
      'label' => '环境',
      'type' => 'select',
      'required' => true,
      'options' => 
      [
        0 => '正式环境',
        1 => '沙箱环境',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'airwallex',
    'title' => 'Airwallex',
    'link' => 'https://www.airwallex.com/',
    'note' => '<p>Webhook配置方法：登录<a href="https://www.airwallex.com/app/" target="_blank" rel="noreferrer">Airwallex控制台</a> → Settings → Developer → Webhooks → New webhook</p><p>Notification URL填写：<b>[siteurl]pay/webhook/[channel]/</b></p><p>侦听事件选择：<b>payment_intent.succeeded</b></p><p>创建后将Webhook的Secret Key填写到上方「Webhook Secret」中</p>',
  ],
];
