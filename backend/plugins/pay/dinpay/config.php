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
    'source_plugin' => 'dinpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appsecret' => '',
    'appkey' => '',
    'appmchid' => '',
    'reportid' => '',
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
      'key' => 'appsecret',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
      'note' => 'SM2-Hex格式',
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '平台公钥',
      'type' => 'textarea',
      'required' => true,
      'note' => 'SM2-Hex格式',
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '子商户号',
      'type' => 'text',
      'note' => '可留空',
    ],
    4 => 
    [
      'key' => 'reportid',
      'label' => '渠道商户报备ID',
      'type' => 'text',
      'note' => '可留空，多个报备ID可用,隔开',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'dinpay',
    'title' => '智付',
    'link' => 'https://www.dinpay.com/',
    'note' => '<a href="http://qqapi.cccyun.cc/dinpay.php" target="_blank" rel="noreferrer">智付SM2公私钥提取</a>',
  ],
];
