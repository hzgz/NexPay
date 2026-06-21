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
    'source_plugin' => 'jiaofeiyi',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appkey' => '',
    'wxpay_type' => '',
    'appid' => '',
    'appmchid' => '',
    'appsecret' => '',
    'remote_api' => '',
    'proxy_api' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => '商户ID',
      'type' => 'text',
      'required' => true,
      'note' => '收款PID值，必填',
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
      'note' => '拉卡拉商户号，必填',
    ],
    2 => 
    [
      'key' => 'wxpay_type',
      'label' => '微信支付模式',
      'type' => 'select',
      'note' => '微信支付展示方式，默认 1',
      'options' => 
      [
        0 => 'H5支付',
        1 => '二维码支付',
        2 => '二维码链接',
      ],
    ],
    3 => 
    [
      'key' => 'appid',
      'label' => '店铺名',
      'type' => 'text',
      'note' => '收银台店铺名，选填',
    ],
    4 => 
    [
      'key' => 'appmchid',
      'label' => '收款备注',
      'type' => 'text',
      'note' => '备注，选填',
    ],
    5 => 
    [
      'key' => 'appsecret',
      'label' => '指定IP',
      'type' => 'text',
      'note' => '固定国内出口IP（仅本机模式生效）',
    ],
    6 => 
    [
      'key' => 'remote_api',
      'label' => '远程API',
      'type' => 'text',
      'note' => '填写完整远程API地址',
    ],
    7 => 
    [
      'key' => 'proxy_api',
      'label' => '代理IP API',
      'type' => 'text',
      'note' => '填写代理IP提取接口',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'jiaofeiyi',
    'title' => '拉卡拉缴费易PRO',
    'link' => 'https://www.973700.xyz/',
    'note' => '<p>需要添加监控进程：</p><p>运行目录：<u>[basedir]plugins/payment/jiaofeiyi/</u></p><p>启动命令：<u>php server.php [channel]</u></p><p>全插件通道：<u>php server.php all</u></p>',
  ],
];
