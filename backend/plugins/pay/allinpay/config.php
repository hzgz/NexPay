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
    'source_plugin' => 'allinpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appmchid' => '',
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'orgid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appmchid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '应用ID',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '通联公钥',
      'type' => 'textarea',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appsecret',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'orgid',
      'label' => '代理商商户号',
      'type' => 'text',
      'note' => '仅代理商需要填写',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'allinpay',
    'title' => '通联支付',
    'link' => 'https://www.allinpay.com/',
    'note' => '',
  ],
];
