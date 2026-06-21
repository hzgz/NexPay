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
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'mhxxkj',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appmchid' => '',
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appmchid',
      'label' => '商户编号',
      'type' => 'text',
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '商户标识',
      'type' => 'text',
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '公钥',
      'type' => 'textarea',
    ],
    3 => 
    [
      'key' => 'appsecret',
      'label' => '私钥',
      'type' => 'textarea',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'mhxxkj',
    'title' => '米花科技',
    'link' => 'http://www.mhxxkj.cn/',
    'note' => '',
  ],
];
