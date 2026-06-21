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
    'source_plugin' => 'jindd',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'mch_id' => '',
    'sub_mchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'ISV机构号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => 'ISV私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '平台公钥',
      'type' => 'textarea',
      'required' => true,
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
      'key' => 'sub_mchid',
      'label' => '子商户号',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'jindd',
    'title' => '金多多支付',
    'link' => 'https://www.jindd.com.cn/',
    'note' => '',
  ],
];
