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
    1 => 'qqpay',
    2 => 'wechat',
    3 => 'unionpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'zyu',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appid' => '',
    'appkey' => '',
    'appmchid' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => '支付网关地址',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '商户密钥',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '通道编码',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appswitch',
      'label' => '支付跳转模式',
      'type' => 'select',
      'options' => 
      [
        0 => '直接跳转接口（默认）',
        1 => '请求接口后跳转',
        2 => '请求接口后扫码',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'zyu',
    'title' => '知宇支付',
    'link' => '',
    'note' => '',
  ],
];
