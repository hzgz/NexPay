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
    4 => 'douyin',
    5 => 'jdpay',
    6 => 'paypal',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'paypro',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appid' => '',
    'appkey' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => '网关地址',
      'type' => 'text',
      'required' => true,
      'note' => '必须以http://或https://开头',
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '商户编号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => 'MD5密钥',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'paypro',
    'title' => '超级支付',
    'link' => '',
    'note' => '',
  ],
];
