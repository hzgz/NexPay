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
    'source_plugin' => 'heepay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appmchid' => '',
    'bank_id' => '',
    'appkey' => '',
    'appsecret' => '',
    'transfer_key' => '',
    'transfer_des_key' => '',
    'mch_private_key' => '',
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
      'key' => 'appmchid',
      'label' => '二级商户号',
      'type' => 'text',
      'note' => '可留空，集团模式传参',
    ],
    2 => 
    [
      'key' => 'bank_id',
      'label' => '上游商户BankId',
      'type' => 'text',
      'note' => '可留空，若需指定上游商户号可填写，多个用,隔开',
    ],
    3 => 
    [
      'key' => 'appkey',
      'label' => '支付密钥',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appsecret',
      'label' => '退款密钥',
      'type' => 'text',
      'required' => true,
    ],
    5 => 
    [
      'key' => 'transfer_key',
      'label' => '付款密钥',
      'type' => 'text',
      'note' => '不需要付款功能的可留空',
    ],
    6 => 
    [
      'key' => 'transfer_des_key',
      'label' => '付款3DES加密密钥',
      'type' => 'text',
      'note' => '不需要付款功能的可留空',
    ],
    7 => 
    [
      'key' => 'mch_private_key',
      'label' => '常规业务RSA私钥',
      'type' => 'textarea',
      'note' => '不需要投诉查询功能的可留空',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'heepay',
    'title' => '汇付宝',
    'link' => 'https://www.heepay.com/',
    'note' => '',
  ],
];
