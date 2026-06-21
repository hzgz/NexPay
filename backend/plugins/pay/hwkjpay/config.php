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
    'source_plugin' => 'hwkjpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'private_key_path' => '',
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
      'key' => 'private_key_path',
      'label' => '商户私钥',
      'type' => 'file',
      'required' => true,
      'note' => '上传pem格式的商户私钥文件',
      'accept' => '.pem',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'hwkjpay',
    'title' => '鸿闻Pay',
    'link' => 'http://www.hwkjpay.com/',
    'note' => '',
  ],
];
