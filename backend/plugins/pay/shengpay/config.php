<?php

return [
  'kind' => 'gateway',
  'capabilities' => 
  [
    0 => 'create',
    1 => 'query',
    2 => 'notify',
    3 => 'render',
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
    'source_plugin' => 'shengpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appswitch' => '0',
    'appmchid' => '',
    'aeskey' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '商户号',
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
      'key' => 'appswitch',
      'label' => '收单接口类型',
      'type' => 'select',
      'options' => 
      [
        0 => '线上',
        1 => '线下',
      ],
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '子商户号',
      'type' => 'text',
      'note' => '非代理商户可留空',
    ],
    4 => 
    [
      'key' => 'aeskey',
      'label' => 'AES加密密钥',
      'type' => 'text',
      'note' => '用于投诉事件回调解密',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'shengpay',
    'title' => '盛付通',
    'link' => 'https://www.shengpay.com/',
    'note' => '如果是微信支付，需要<a href="./payapi/pluginPage?channel=[channel]&func=wxconfig" target="_blank">配置绑定AppId和支付目录</a>',
  ],
];
