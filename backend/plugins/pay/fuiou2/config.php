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
    'source_plugin' => 'fuiou2',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appmchid' => '',
    'appsecret' => '',
    'appkey' => '',
    'appurl' => '',
    'entrykey' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '机构号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appmchid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appkey',
      'label' => '富友公钥',
      'type' => 'textarea',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appurl',
      'label' => '订单号前缀',
      'type' => 'text',
    ],
    5 => 
    [
      'key' => 'entrykey',
      'label' => '代理进件密钥',
      'type' => 'text',
      'note' => '不使用进件或投诉接口可不填写',
    ],
    6 => 
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
    'plugin' => 'fuiou2',
    'title' => '富友支付(合作方)',
    'link' => 'https://www.fuiou.com/',
    'note' => '',
  ],
];
