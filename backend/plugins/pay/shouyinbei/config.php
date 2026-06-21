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
    'source_plugin' => 'shouyinbei',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appmchid' => '',
    'appurl' => '',
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
      'label' => '平台公钥',
      'type' => 'textarea',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '应用私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '机构号',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appurl',
      'label' => '分账商户号',
      'type' => 'text',
      'note' => '留空则不使用分账功能',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'shouyinbei',
    'title' => '收银呗',
    'link' => 'https://www.shouyinbei.com/',
    'note' => '',
  ],
];
