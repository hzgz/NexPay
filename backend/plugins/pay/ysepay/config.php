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
    'source_plugin' => 'ysepay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'cert_pfx' => '',
    'appkey' => '',
    'appmchid' => '',
    'appurl' => '',
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
      'note' => '不填写则和服务商商户号相同',
    ],
    4 => 
    [
      'key' => 'appurl',
      'label' => '业务代码',
      'type' => 'text',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'ysepay',
    'title' => '银盛支付',
    'link' => 'https://www.ysepay.com/',
    'note' => '只能使用RSA证书！',
  ],
];
