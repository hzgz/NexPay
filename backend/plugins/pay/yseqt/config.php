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
    'source_plugin' => 'yseqt',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'cert_pfx' => '',
    'appkey' => '',
    'appmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '服务商商户号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'cert_pfx',
      'label' => '商户私钥证书',
      'type' => 'file',
      'required' => true,
      'note' => 'pfx格式的商户私钥证书',
      'accept' => '.pfx',
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '私钥证书密码',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '收款商户号',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'yseqt',
    'title' => '银盛e企通',
    'link' => 'https://www.ysepay.com/',
    'note' => '只能使用RSA证书！',
  ],
];
