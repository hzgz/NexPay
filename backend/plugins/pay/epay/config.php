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
    'source_plugin' => 'epay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appid' => '',
    'appkey' => '',
    'appswitch' => '0',
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
      'label' => '商户密钥',
      'type' => 'text',
    ],
    3 => 
    [
      'key' => 'appswitch',
      'label' => '是否使用mapi接口',
      'type' => 'select',
      'options' => 
      [
        0 => '否',
        1 => '是',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'epay',
    'title' => '彩虹易支付',
    'link' => '',
    'note' => '',
  ],
];
