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
    2 => 'qqpay',
    3 => 'unionpay',
    4 => 'jdpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'swiftpass',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'sign_type' => '0',
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'md5_key' => '',
    'appurl' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'sign_type',
      'label' => '签名方式',
      'type' => 'select',
      'required' => true,
      'options' => 
      [
        0 => 'RSA签名',
        1 => 'MD5签名',
      ],
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '平台RSA公钥',
      'type' => 'textarea',
      'required' => true,
      'show' => 'sign_type==\'0\'',
    ],
    3 => 
    [
      'key' => 'appsecret',
      'label' => '商户RSA私钥',
      'type' => 'textarea',
      'required' => true,
      'show' => 'sign_type==\'0\'',
    ],
    4 => 
    [
      'key' => 'md5_key',
      'label' => '商户MD5密钥',
      'type' => 'text',
      'required' => true,
      'show' => 'sign_type==\'1\'',
    ],
    5 => 
    [
      'key' => 'appurl',
      'label' => '自定义网关URL',
      'type' => 'text',
      'note' => '可不填，默认是https://pay.swiftpass.cn/pay/gateway',
    ],
    6 => 
    [
      'key' => 'appswitch',
      'label' => '微信是否支持H5',
      'type' => 'select',
      'show' => 'wxpay',
      'options' => 
      [
        0 => '否',
        1 => '是',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'swiftpass',
    'title' => '威富通',
    'link' => 'https://www.swiftpass.cn/',
    'note' => '',
  ],
];
