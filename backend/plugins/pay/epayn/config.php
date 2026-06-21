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
    4 => 'jdpay',
    5 => 'douyin',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'epayn',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appswitch' => '0',
    'merchant_id' => '',
    'channel_id' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => '接口地址',
      'type' => 'text',
      'note' => '必须以http://或https://开头，以/结尾',
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '商户ID',
      'type' => 'text',
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '平台公钥',
      'type' => 'textarea',
    ],
    3 => 
    [
      'key' => 'appsecret',
      'label' => '商户私钥',
      'type' => 'textarea',
    ],
    4 => 
    [
      'key' => 'appswitch',
      'label' => '接口类型',
      'type' => 'select',
      'options' => 
      [
        0 => '页面跳转支付',
        1 => '统一下单接口',
      ],
    ],
    5 => 
    [
      'key' => 'merchant_id',
      'label' => '自定义进件商户ID',
      'type' => 'text',
      'note' => '可留空，进件商户列表的ID',
    ],
    6 => 
    [
      'key' => 'channel_id',
      'label' => '自定义通道ID',
      'type' => 'text',
      'note' => '可留空，自定义子通道的ID',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'epayn',
    'title' => '彩虹易支付V2',
    'link' => '',
    'note' => '',
  ],
];
