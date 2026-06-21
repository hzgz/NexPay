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
    'source_plugin' => 'easypay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'reqtype' => '1',
    'appid' => '',
    'appmchid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'reqtype',
      'label' => '接入模式',
      'type' => 'select',
      'options' => 
      [
        0 => '机构模式',
        1 => '商户模式',
      ],
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '机构号/商户号',
      'type' => 'text',
      'required' => true,
      'note' => 'reqId',
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '子商户号',
      'type' => 'text',
      'note' => '机构模式下填写子商户号，非机构模式请勿填写',
      'show' => 'reqtype==\'2\'',
    ],
    3 => 
    [
      'key' => 'appkey',
      'label' => '易生公钥',
      'type' => 'textarea',
      'required' => true,
      'note' => '不能有换行和标签',
    ],
    4 => 
    [
      'key' => 'appsecret',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
      'note' => '不能有换行和标签',
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
    'plugin' => 'easypay',
    'title' => '易生易企通2.0',
    'link' => 'https://www.easypay.com.cn/',
    'note' => '',
  ],
];
