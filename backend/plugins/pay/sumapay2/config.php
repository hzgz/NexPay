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
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'sumapay2',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'biztype' => '',
    'userid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '商户编号',
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
      'key' => 'biztype',
      'label' => '业务类型代码',
      'type' => 'text',
    ],
    3 => 
    [
      'key' => 'userid',
      'label' => '子商户标识',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'sumapay2',
    'title' => '丰付企账宝',
    'link' => 'https://www.sumapay.com/',
    'note' => '',
  ],
];
