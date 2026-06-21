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
    'source_plugin' => 'umfpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'platform_public_cert' => '',
    'merchant_private_cert' => '',
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
      'key' => 'appkey',
      'label' => '商户密钥',
      'type' => 'text',
      'required' => true,
      'note' => '此项随便填写',
    ],
    2 => 
    [
      'key' => 'platform_public_cert',
      'label' => '平台公钥',
      'type' => 'file',
      'required' => true,
      'note' => 'pem格式平台公钥文件',
      'accept' => '.pem',
    ],
    3 => 
    [
      'key' => 'merchant_private_cert',
      'label' => '商户私钥',
      'type' => 'file',
      'required' => true,
      'note' => 'pem格式商户私钥文件',
      'accept' => '.pem',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'umfpay',
    'title' => '联动优势',
    'link' => 'https://www.umfintech.com/',
    'note' => '',
  ],
];
