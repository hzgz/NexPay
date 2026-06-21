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
    'source_plugin' => 'fuiou',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appurl' => '',
    'appswitch' => '0',
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
      'label' => '订单号前缀',
      'type' => 'text',
    ],
    3 => 
    [
      'key' => 'appswitch',
      'label' => '环境选择',
      'type' => 'select',
      'options' => 
      [
        0 => '生产环境',
        1 => '测试环境',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'fuiou',
    'title' => '富友支付(前置商户)',
    'link' => 'https://www.fuiou.com/',
    'note' => '',
  ],
];
