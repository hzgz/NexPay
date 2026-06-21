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
    'source_plugin' => 'huolian',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appmchid' => '',
    'appurl' => '',
    'appsecret' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '对接商授权编号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '对接商MD5加密盐',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '商户ID',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appurl',
      'label' => '收银员手机号',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appsecret',
      'label' => '退款密码（管理密码）',
      'type' => 'text',
      'note' => '如不需要退款功能可留空',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'huolian',
    'title' => '火脸支付',
    'link' => 'https://www.lianok.com/',
    'note' => '',
  ],
];
