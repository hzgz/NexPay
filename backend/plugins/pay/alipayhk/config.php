<?php

return [
  'kind' => 'gateway',
  'capabilities' => 
  [
    0 => 'create',
    1 => 'query',
    2 => 'notify',
    3 => 'render',
  ],
  'payment_methods' => 
  [
    0 => 'alipay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'alipayhk',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'Partner ID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => 'MD5 Key',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appswitch',
      'label' => '支付时选择钱包类型',
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
    'plugin' => 'alipayhk',
    'title' => 'AlipayHK',
    'link' => 'https://global.alipay.com/',
    'note' => '支付时选择钱包类型开启后，支付时可选择Alipay或AlipayHK，关闭则默认使用Alipay',
  ],
];
