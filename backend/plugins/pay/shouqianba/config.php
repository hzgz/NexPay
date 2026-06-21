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
    'source_plugin' => 'shouqianba',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '终端号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '终端密钥',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'shouqianba',
    'title' => '收钱吧支付',
    'link' => 'https://www.shouqianba.com/',
    'note' => '需要先<a href="./payapi/pluginPage?channel=[channel]&func=active" target="_blank">激活收钱吧终端</a>',
  ],
];
