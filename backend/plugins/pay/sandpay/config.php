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
    'source_plugin' => 'sandpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'merchant_private_cert' => '',
    'appkey' => '',
    'appswitch' => '0',
    'product' => 'QZF',
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
      'key' => 'merchant_private_cert',
      'label' => '商户私钥证书',
      'type' => 'file',
      'required' => true,
      'note' => 'pfx格式商户私钥证书',
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
      'key' => 'appswitch',
      'label' => '环境选择',
      'type' => 'select',
      'options' => 
      [
        0 => '生产环境',
        1 => '测试环境',
      ],
    ],
    4 => 
    [
      'key' => 'product',
      'label' => '市场产品',
      'type' => 'select',
      'options' => 
      [
        0 => '标准线上收款',
        1 => '企业杉德宝',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'sandpay',
    'title' => '杉德支付',
    'link' => 'https://www.sandpay.com.cn/',
    'note' => '',
  ],
];
