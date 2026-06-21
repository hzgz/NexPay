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
    'source_plugin' => 'shangfutong',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appmchid' => '',
    'route_no' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '应用ID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '应用MD5密钥',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '门店编号',
      'type' => 'text',
      'note' => '和策略ID二选一',
    ],
    3 => 
    [
      'key' => 'route_no',
      'label' => '策略ID',
      'type' => 'text',
      'note' => '和门店编号二选一',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'shangfutong',
    'title' => '商福通',
    'link' => 'https://pay.rscygroup.com/',
    'note' => '',
  ],
];
