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
    'source_plugin' => 'chinaums',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appmchid' => '',
    'appurl' => '',
    'appsecret' => '',
    'msgsrcid' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'AppId',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => 'AppKey',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '商户号mid',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appurl',
      'label' => '终端号tid',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appsecret',
      'label' => '通讯密钥',
      'type' => 'text',
      'required' => true,
    ],
    5 => 
    [
      'key' => 'msgsrcid',
      'label' => '来源编号',
      'type' => 'text',
      'note' => '4位来源编号',
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
    'plugin' => 'chinaums',
    'title' => '银联商务',
    'link' => 'https://open.chinaums.com/',
    'note' => '',
  ],
];
