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
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'cloudpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appmchid' => '',
    'appurl' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'isv_app_id',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '云支付公钥',
      'type' => 'textarea',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '企业私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '商户编号',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appurl',
      'label' => '门店编号',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'cloudpay',
    'title' => '支付宝云支付',
    'link' => 'https://bpaas.alipay.com/portal/industry-bpaas/ability/list',
    'note' => '需入驻行业云，并开通“云支付基础接口功能包”',
  ],
];
