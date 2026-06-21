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
    4 => 'jdpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'unionpay',
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
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '商户密钥',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appurl',
      'label' => '自定义网关URL',
      'type' => 'text',
      'note' => '可不填,默认是https://qra.95516.com/pay/gateway',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'unionpay',
    'title' => '银联前置',
    'link' => 'https://cn.unionpay.com/',
    'note' => '',
  ],
];
