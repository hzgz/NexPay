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
    'source_plugin' => 'xingyifuyhkpro',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appsession' => '',
    'proxy_api' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appsession',
      'label' => '登录令牌',
      'type' => 'text',
      'required' => true,
      'note' => '抓包 Header 里的 appsession',
    ],
    1 => 
    [
      'key' => 'proxy_api',
      'label' => '远程API地址',
      'type' => 'text',
      'required' => false,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'xingyifuyhkpro',
    'title' => '星驿付驿汇客Pro',
    'link' => 'https://yhk.postar.cn/',
    'note' => '<p>登录令牌获取方法：微信小程序“驿收款商户助手”，登录后抓包 header 里的 appsession。</p><p>守护进程运行目录：<u>[basedir]plugins/payment/xingyifuyhkpro/</u></p><p>启动命令（全通道）：<u>php server.php</u></p><p>启动命令（单通道）：<u>php server.php [channel]</u></p>',
  ],
];
