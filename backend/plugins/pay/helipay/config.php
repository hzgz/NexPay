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
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'helipay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'public_signkey' => '',
    'public_enckey' => '',
    'settle_signkey' => '',
    'settle_signkey2' => '',
    'accpay_signkey' => '',
    'accpay_enckey' => '',
    'appmchid' => '',
    'reportid' => '',
    'reportid2' => '',
    'reportid3' => '',
    'h5appid' => '',
    'split_pubkey' => '',
    'split_prikey' => '',
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
      'key' => 'appkey',
      'label' => '扫码产品-签名密钥',
      'type' => 'text',
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '扫码产品-加密密钥',
      'type' => 'text',
    ],
    3 => 
    [
      'key' => 'public_signkey',
      'label' => '公共产品-签名密钥',
      'type' => 'text',
    ],
    4 => 
    [
      'key' => 'public_enckey',
      'label' => '公共产品-加密密钥',
      'type' => 'text',
    ],
    5 => 
    [
      'key' => 'settle_signkey',
      'label' => '结算产品-MD5签名密钥',
      'type' => 'text',
    ],
    6 => 
    [
      'key' => 'settle_signkey2',
      'label' => '结算产品-RSA签名私钥',
      'type' => 'textarea',
    ],
    7 => 
    [
      'key' => 'accpay_signkey',
      'label' => '虚拟账户支付-签名密钥',
      'type' => 'text',
    ],
    8 => 
    [
      'key' => 'accpay_enckey',
      'label' => '虚拟账户支付-加密密钥',
      'type' => 'text',
    ],
    9 => 
    [
      'key' => 'appmchid',
      'label' => '子商户号',
      'type' => 'text',
      'note' => '留空为使用商户编号发起支付',
    ],
    10 => 
    [
      'key' => 'reportid',
      'label' => '扫码支付-报备ID',
      'type' => 'text',
      'note' => '可留空，多个报备ID可用,隔开',
    ],
    11 => 
    [
      'key' => 'reportid2',
      'label' => '公众号支付-报备ID',
      'type' => 'text',
      'note' => '可留空，多个报备ID可用,隔开',
    ],
    12 => 
    [
      'key' => 'reportid3',
      'label' => 'H5支付-报备ID',
      'type' => 'text',
      'note' => '可留空，多个报备ID可用,隔开',
    ],
    13 => 
    [
      'key' => 'h5appid',
      'label' => 'H5支付-AppId',
      'type' => 'text',
      'note' => '可留空',
    ],
    14 => 
    [
      'key' => 'split_pubkey',
      'label' => '分账产品-SM2平台公钥',
      'type' => 'textarea',
      'note' => 'hex格式',
    ],
    15 => 
    [
      'key' => 'split_prikey',
      'label' => '分账产品-SM2商户私钥',
      'type' => 'textarea',
      'note' => 'hex格式',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'helipay',
    'title' => '合利宝',
    'link' => 'http://www.helipay.com/',
    'note' => '',
  ],
];
