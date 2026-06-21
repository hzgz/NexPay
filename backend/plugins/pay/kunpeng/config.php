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
    'source_plugin' => 'kunpeng',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'private_key_path' => '',
    'appmchid' => '',
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
      'note' => '上传pem格式商户私钥文件',
      'accept' => '.pem',
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '渠道子商户号',
      'type' => 'text',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'kunpeng',
    'title' => '鲲鹏支付',
    'link' => 'https://www.globebill.com/',
    'note' => '',
  ],
];
