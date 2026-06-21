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
    1 => 'qqpay',
    2 => 'wechat',
    3 => 'unionpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'zhangyishou',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appurl' => '',
    'appmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '登录账号',
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
      'label' => '商户编号',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '通道ID',
      'type' => 'text',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'zhangyishou',
    'title' => '掌易收聚合支付',
    'link' => 'http://www.zhangyishou.com/',
    'note' => '如果微信通道有扫码和小程序2种，直接在通道ID填写2个ID，用|隔开',
  ],
];
