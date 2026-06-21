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
    3 => 'douyin',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'hlpay2',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'channelcode' => '',
    'appmchid' => '',
    'appswitch' => '1',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '应用APPID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '商户私钥',
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
      'key' => 'channelcode',
      'label' => '通道编码',
      'type' => 'text',
      'note' => '可留空，留空为随机路由',
    ],
    4 => 
    [
      'key' => 'appmchid',
      'label' => '子商户编码',
      'type' => 'text',
      'note' => '仅服务商可传，普通商户请勿填写',
    ],
    5 => 
    [
      'key' => 'appswitch',
      'label' => '场景类型',
      'type' => 'select',
      'options' => 
      [
        0 => '线下',
        1 => '线上',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'hlpay2',
    'title' => '汇联支付V2',
    'link' => 'https://www.huilianlink.com/',
    'note' => '',
  ],
];
