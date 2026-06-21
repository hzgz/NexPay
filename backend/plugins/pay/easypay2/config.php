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
    'source_plugin' => 'easypay2',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '合作伙伴ID',
      'type' => 'text',
      'required' => true,
      'note' => 'reqId',
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '易生公钥',
      'type' => 'textarea',
      'required' => true,
      'note' => '不能有换行和标签',
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
      'note' => '不能有换行和标签',
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '商户编号',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'easypay2',
    'title' => '易生支付',
    'link' => 'https://www.easypay.com.cn/',
    'note' => '',
  ],
];
