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
    'source_plugin' => 'huishouqian',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'private_key_path' => '',
    'appsecret' => '',
    'cert_path' => '',
    'appswitch' => '0',
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
      'key' => 'appkey',
      'label' => '签名KEY',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'private_key_path',
      'label' => '商户私钥',
      'type' => 'file',
      'required' => true,
      'note' => '上传pfx格式的商户私钥文件',
      'accept' => '.pfx',
    ],
    3 => 
    [
      'key' => 'appsecret',
      'label' => '私钥密码',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'cert_path',
      'label' => '平台公钥证书',
      'type' => 'file',
      'required' => true,
      'note' => '上传cer格式的平台公钥证书文件',
      'accept' => '.cer',
    ],
    5 => 
    [
      'key' => 'appswitch',
      'label' => '环境选择',
      'type' => 'select',
      'options' => 
      [
        0 => '生产环境',
        1 => '测试环境',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'huishouqian',
    'title' => '慧收钱',
    'link' => 'https://www.huishouqian.com/',
    'note' => '',
  ],
];
