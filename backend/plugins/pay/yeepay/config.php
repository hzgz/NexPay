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
    'source_plugin' => 'yeepay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appkey' => '',
    'appsecret' => '',
    'appid' => '',
    'appmchid' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appkey',
      'label' => '应用标识',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appsecret',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appid',
      'label' => '发起方商户编号',
      'type' => 'text',
      'required' => true,
      'note' => '标准商户则填写标准商户商编；平台商入驻商户，则填写平台商商编',
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '收款商户编号',
      'type' => 'text',
      'note' => '留空则与发起方商户编号一致',
    ],
    4 => 
    [
      'key' => 'appswitch',
      'label' => '支付场景',
      'type' => 'select',
      'options' => 
      [
        0 => '线上',
        1 => '线下',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'yeepay',
    'title' => '易宝支付',
    'link' => 'https://www.yeepay.com/',
    'note' => '密钥需要选RSA格式的',
  ],
];
