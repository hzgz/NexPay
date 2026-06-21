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
    0 => 'wechat',
    1 => 'alipay',
    2 => 'unionpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'xsy',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appmchid' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '机构代码',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '平台公钥',
      'type' => 'textarea',
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
      'key' => 'appmchid',
      'label' => '商户编号',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
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
    'plugin' => 'xsy',
    'title' => '新生易',
    'link' => 'https://www.hnapay.com/',
    'note' => '',
  ],
];
