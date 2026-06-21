<?php

return [
  'kind' => 'qrcode',
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
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'vmq',
    'mode' => 'qrcode',
    'notify_retry' => 5,
    'appurl' => '',
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
      'key' => 'appid',
      'label' => '商户ID',
      'type' => 'text',
      'required' => true,
      'note' => '如果不需要商户ID，随便填写即可',
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '通讯密钥',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'vmq',
    'title' => 'V免签',
    'link' => 'https://github.com/szvone/vmqphp',
    'note' => '',
  ],
];
