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
    3 => 'ecny',
    4 => 'douyin',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'huifu',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appurl' => '',
    'appsecret' => '',
    'appkey' => '',
    'appmchid' => '',
    'project_id' => '',
    'seq_id' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '汇付系统号',
      'type' => 'text',
      'required' => true,
      'note' => '当主体为渠道商时填写渠道商ID，主体为直连商户时填写商户ID',
    ],
    1 => 
    [
      'key' => 'appurl',
      'label' => '汇付产品号',
      'type' => 'text',
      'required' => true,
      'note' => 'product_id',
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appkey',
      'label' => '汇付公钥',
      'type' => 'textarea',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appmchid',
      'label' => '汇付子商户号',
      'type' => 'text',
      'note' => '当主体为渠道商时需要填写，主体为直连商户时不需要填写',
    ],
    5 => 
    [
      'key' => 'project_id',
      'label' => '半支付托管项目号',
      'type' => 'text',
      'note' => '仅托管H5/PC支付需要填写',
    ],
    6 => 
    [
      'key' => 'seq_id',
      'label' => '托管小程序应用ID',
      'type' => 'text',
      'note' => '仅托管小程序支付可填写，不填默认使用斗拱收银台',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'huifu',
    'title' => '汇付斗拱平台',
    'link' => 'https://paas.huifu.com/',
    'note' => '',
  ],
];
