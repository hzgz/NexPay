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
    'source_plugin' => 'lakalajfy',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appid' => '',
    'appkey' => '',
    'appmchid' => '',
    'appsecret' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => '商户ID',
      'type' => 'text',
      'required' => true,
      'note' => '解码得收款链接中PID值',
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '店铺名',
      'type' => 'text',
      'required' => true,
      'note' => '收银台店铺名',
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
      'note' => '拉卡拉商户号',
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '收款备注',
      'type' => 'text',
      'note' => '备注',
    ],
    4 => 
    [
      'key' => 'appsecret',
      'label' => '指定IP',
      'type' => 'text',
      'note' => '任意国内IP，多通道不可复用',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'lakalajfy',
    'title' => '拉卡拉缴费易',
    'link' => 'https://jfyui.lakala.com/',
    'note' => '<p>需添加守护进程，运行目录：<u>[basedir]plugins/payment/lakalajfy/</u> 启动命令：<u>php server.php [channel]</u></p>',
  ],
];
