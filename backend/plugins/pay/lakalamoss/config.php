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
    'source_plugin' => 'lakalamoss',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appsecret' => '',
    'appmchid' => '',
    'splitmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'APPID',
      'type' => 'text',
      'required' => true,
      'note' => 'reqId',
    ],
    1 => 
    [
      'key' => 'appsecret',
      'label' => '客户私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '商户ID',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'splitmchid',
      'label' => '合单支付商户ID',
      'type' => 'text',
      'note' => '不使用合单支付请留空',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'lakalamoss',
    'title' => '拉卡拉MOSS',
    'link' => 'https://moss.lakala.com/',
    'note' => '',
  ],
];
