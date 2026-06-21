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
    'source_plugin' => 'jlpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appsecret' => '',
    'appkey' => '',
    'mch_id' => '',
    'term_no' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '应用appid',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appsecret',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
      'note' => 'SM2-Hex格式',
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '嘉联公钥',
      'type' => 'textarea',
      'required' => true,
      'note' => 'SM2-Hex格式',
    ],
    3 => 
    [
      'key' => 'mch_id',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'term_no',
      'label' => '终端号',
      'type' => 'text',
      'required' => true,
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
    'plugin' => 'jlpay',
    'title' => '嘉联支付',
    'link' => 'https://www.jlpay.com/',
    'note' => '',
  ],
];
