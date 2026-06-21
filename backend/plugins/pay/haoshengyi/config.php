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
    'source_plugin' => 'haoshengyi',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appmchid' => '',
    'appkey' => '',
    'appsecret' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '代理商编号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appmchid',
      'label' => '商户编码',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '验签公钥',
      'type' => 'textarea',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appsecret',
      'label' => '验签私钥',
      'type' => 'textarea',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'haoshengyi',
    'title' => '好生意',
    'link' => 'https://www.haoshengyi365.com/',
    'note' => '',
  ],
];
