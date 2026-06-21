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
    'source_plugin' => 'baofu',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appurl' => '',
    'pfx_cert_path' => '',
    'appkey' => '',
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
      'key' => 'appurl',
      'label' => '终端号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'pfx_cert_path',
      'label' => '商户私钥证书',
      'type' => 'file',
      'required' => true,
      'note' => '.pfx格式证书',
      'accept' => '.pfx',
    ],
    3 => 
    [
      'key' => 'appkey',
      'label' => '商户私钥证书密码',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appmchid',
      'label' => '聚合交易商户号',
      'type' => 'text',
      'note' => '在微信/支付宝报备的商户号',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'baofu',
    'title' => '宝付支付',
    'link' => 'https://www.baofu.com/',
    'note' => '',
  ],
];
