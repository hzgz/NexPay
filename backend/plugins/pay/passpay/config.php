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
    2 => 'qqpay',
    3 => 'unionpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'passpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => 'API接口地址',
      'type' => 'text',
      'required' => true,
      'note' => '以http://或https://开头，以/结尾',
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '商户编号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appsecret',
      'label' => '平台公钥',
      'type' => 'textarea',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appmchid',
      'label' => '通道ID',
      'type' => 'text',
      'note' => '不填写将进行子商户号轮训',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'passpay',
    'title' => '精秀支付',
    'link' => 'https://www.jxpays.com/',
    'note' => '',
  ],
];
