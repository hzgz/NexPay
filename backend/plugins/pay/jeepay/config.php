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
    'source_plugin' => 'jeepay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appmchid' => '',
    'appid' => '',
    'appkey' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => '接口地址',
      'type' => 'text',
      'required' => true,
      'note' => '必须以http://或https://开头，以/结尾',
    ],
    1 => 
    [
      'key' => 'appmchid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appid',
      'label' => '应用AppId',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appkey',
      'label' => '私钥AppSecret',
      'type' => 'textarea',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'jeepay',
    'title' => 'Jeepay计全支付',
    'link' => 'https://www.jeequan.com/',
    'note' => '',
  ],
];
