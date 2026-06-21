<?php

return [
  'kind' => 'gateway',
  'capabilities' => 
  [
    0 => 'create',
    1 => 'query',
    2 => 'notify',
    3 => 'daemon',
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
    'source_plugin' => 'suixinglife',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'username' => '',
    'password' => '',
    'mno' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'username',
      'label' => '登录账号',
      'type' => 'text',
      'required' => true,
      'note' => '商编/手机号/门店编号',
    ],
    1 => 
    [
      'key' => 'password',
      'label' => '登录密码',
      'type' => 'text',
      'required' => true,
      'note' => '登录密码',
    ],
    2 => 
    [
      'key' => 'mno',
      'label' => '商户编号',
      'type' => 'text',
      'note' => '多商户账号时必填，单商户可留空',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'suixinglife',
    'title' => '随行付会生活',
    'link' => 'https://www.suixingpay.com/',
    'note' => '<p>需添加守护进程，运行目录：<u>[basedir]plugins/payment/suixinglife/</u> 启动命令：<u>php server.php</p>',
  ],
];
