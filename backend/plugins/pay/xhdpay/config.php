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
    'source_plugin' => 'xhdpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appmchid' => '',
    'appkey' => '',
    'complainmchid' => '',
    'appsecret' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '联合商户号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appmchid',
      'label' => '联合设备号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '交易密钥',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'complainmchid',
      'label' => '消费者投诉商户号',
      'type' => 'text',
    ],
    4 => 
    [
      'key' => 'appsecret',
      'label' => '消费者投诉签名密钥',
      'type' => 'text',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'xhdpay',
    'title' => '红小点支付',
    'link' => 'https://www.xhdpay.com/',
    'note' => '',
  ],
];
