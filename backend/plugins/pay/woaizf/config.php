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
    'source_plugin' => 'woaizf',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appurl' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '应用AppID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '应用MD5密钥',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appurl',
      'label' => '自定义接口地址',
      'type' => 'text',
      'note' => '可不填,默认是https://payapi.52zhifu.com/',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'woaizf',
    'title' => '我爱支付',
    'link' => 'https://www.52zhifu.com/',
    'note' => '',
  ],
];
