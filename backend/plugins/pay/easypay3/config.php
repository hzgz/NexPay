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
    'source_plugin' => 'easypay3',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appmchid' => '',
    'termno' => '',
    'appkey' => '',
    'appsecret' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '机构号',
      'type' => 'text',
      'required' => true,
      'note' => 'orgId',
    ],
    1 => 
    [
      'key' => 'appmchid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
      'note' => 'orgMerCode',
    ],
    2 => 
    [
      'key' => 'termno',
      'label' => '设备号',
      'type' => 'text',
      'required' => true,
      'note' => 'orgTermNo',
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
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'easypay3',
    'title' => '易生易企通1.0',
    'link' => 'https://www.easypay.com.cn/',
    'note' => '',
  ],
];
