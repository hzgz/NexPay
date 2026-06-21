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
    'source_plugin' => 'yizhifubj',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'private_key_path' => '',
    'appkey' => '',
    'appmchid' => '',
    'partnerid' => '',
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
      'key' => 'private_key_path',
      'label' => '私钥证书',
      'type' => 'file',
      'required' => true,
      'note' => 'pfx格式的私钥证书文件',
      'accept' => '.pfx',
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '私钥证书密码',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '报备序列号',
      'type' => 'text',
      'note' => '可留空',
    ],
    4 => 
    [
      'key' => 'partnerid',
      'label' => '服务端ID',
      'type' => 'text',
      'note' => '可留空',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'yizhifubj',
    'title' => '首信易支付',
    'link' => 'http://www.payeasenet.com/',
    'note' => '',
  ],
];
