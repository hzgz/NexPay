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
    2 => 'unionpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'xxpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appmchid' => '',
    'appid' => '',
    'appkey' => '',
    'product_id' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => '接口地址',
      'type' => 'text',
      'required' => true,
      'note' => '必须以http://或https://开头，以/结尾',
    ],
    1 => 
    [
      'key' => 'appmchid',
      'label' => '商户ID',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appid',
      'label' => '应用ID',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appkey',
      'label' => '密钥',
      'type' => 'textarea',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'product_id',
      'label' => '支付产品ID',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'xxpay',
    'title' => 'XxPay Pro',
    'link' => 'https://www.xxpay.vip/',
    'note' => '',
  ],
];
