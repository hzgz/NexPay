<?php

return [
  'kind' => 'gateway',
  'capabilities' => 
  [
    0 => 'create',
    1 => 'query',
    2 => 'notify',
    3 => 'daemon',
    4 => 'render',
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
    'source_plugin' => 'xingyifuyhk',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appsession' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appsession',
      'label' => '登录令牌',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'xingyifuyhk',
    'title' => '星驿付驿汇客',
    'link' => 'https://yhk.postar.cn/',
    'note' => '<p>登录令牌获取方法：微信小程序“驿收款商户助手”，登录后，抓包header里面appsession的值。</p><p>需添加守护进程，运行目录：<u>[basedir]plugins/payment/xingyifuyhk/</u> 启动命令：<u>php server.php</u></p>',
  ],
];
