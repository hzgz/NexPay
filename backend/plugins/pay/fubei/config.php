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
    'source_plugin' => 'fubei',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appmchid' => '',
    'mchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '开放平台ID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '接口密钥',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '门店ID',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'mchid',
      'label' => '商户ID',
      'type' => 'text',
      'note' => '服务商模式时必填',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'fubei',
    'title' => '付呗聚合支付',
    'link' => 'https://www.51fubei.com/',
    'note' => '如果是微信支付，需要<a href="./payapi/pluginPage?channel=[channel]&func=wxconfig" target="_blank">配置绑定AppId和支付目录</a>',
  ],
];
