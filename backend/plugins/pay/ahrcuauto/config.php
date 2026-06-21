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
    'source_plugin' => 'ahrcuauto',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'token' => '',
    'mchid' => '',
    'shop_id' => '',
    'staff_id' => '',
    'device_id' => '',
    'order_valid_minutes' => '3',
    'qrcode_timeout' => '60',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'token',
      'label' => '安徽农金Token',
      'type' => 'textarea',
      'required' => true,
      'note' => '安徽农金商户后台登录后的 token；插件会自动续期并回写',
    ],
    1 => 
    [
      'key' => 'mchid',
      'label' => '安徽农金商户号',
      'type' => 'text',
      'required' => true,
      'note' => '用于动态下码与补单校验',
    ],
    2 => 
    [
      'key' => 'shop_id',
      'label' => '门店ID',
      'type' => 'text',
      'required' => true,
      'note' => '动态下码必填',
    ],
    3 => 
    [
      'key' => 'staff_id',
      'label' => '收银ID',
      'type' => 'text',
      'required' => true,
      'note' => '动态下码必填，来自安徽农金商户后台登录账号',
    ],
    4 => 
    [
      'key' => 'device_id',
      'label' => '设备ID',
      'type' => 'text',
      'note' => '选填，补单时可提高查单精度',
    ],
    5 => 
    [
      'key' => 'order_valid_minutes',
      'label' => '订单有效期(分钟)',
      'type' => 'text',
      'note' => '补单窗口，默认3分钟',
    ],
    6 => 
    [
      'key' => 'qrcode_timeout',
      'label' => '二维码有效秒数',
      'type' => 'text',
      'note' => '动态下码有效期，默认60秒',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'ahrcuauto',
    'title' => '安徽农金免输入金额',
    'link' => 'https://epay.ahrcu.com:1443/',
    'note' => '<p>仅支持免输入金额版（动态金额码）。</p><p>守护进程运行目录：<u>[basedir]plugins/payment/ahrcuauto/</u></p><p>启动命令：<u>php server.php [channel]</u></p>',
  ],
];
