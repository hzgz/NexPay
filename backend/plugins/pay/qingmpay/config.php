<?php

return [
  'kind' => 'gateway',
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
    2 => 'qqpay',
    3 => 'unionpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'qingmpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '应用APPID',
      'type' => 'text',
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '应用密钥',
      'type' => 'text',
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '商户号',
      'type' => 'text',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'qingmpay',
    'title' => '清麦支付',
    'link' => 'http://www.qingmpay.com/',
    'note' => '',
  ],
];
