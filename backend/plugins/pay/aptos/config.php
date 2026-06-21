<?php

return [
  'kind' => 'chain',
  'capabilities' => 
  [
    0 => 'address_match',
    1 => 'chain_confirm',
    2 => 'notify',
    3 => 'daemon',
  ],
  'payment_methods' => 
  [
    0 => 'aptos',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'aptos',
    'confirmations' => 2,
    'listener' => 'manual',
    'appid' => '',
    'appkey' => '',
    'appurl' => '',
    'botid' => '',
    'bottoken' => '',
    'xiaoshu' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '收款地址',
      'type' => 'text',
      'required' => true,
      'note' => 'Aptos 钱包地址，必须以 0x 开头。',
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => 'USDT汇率(CNY)',
      'type' => 'text',
      'required' => true,
      'note' => '1 USDT 对应的人民币汇率。',
    ],
    2 => 
    [
      'key' => 'appurl',
      'label' => '支付超时时间(秒)',
      'type' => 'text',
      'required' => true,
      'note' => '建议 1200，即 20 分钟。',
    ],
    3 => 
    [
      'key' => 'botid',
      'label' => 'Telegram用户ID',
      'type' => 'text',
      'note' => '接收通知的 Telegram 用户ID。',
    ],
    4 => 
    [
      'key' => 'bottoken',
      'label' => 'Telegram Bot Token',
      'type' => 'text',
      'note' => 'BotFather 创建的 Bot Token。',
    ],
    5 => 
    [
      'key' => 'xiaoshu',
      'label' => 'USDT小数位数',
      'type' => 'text',
      'note' => '建议 4，支持范围 2-6。',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'aptos',
    'title' => 'Aptos USDT支付',
    'link' => '',
    'note' => '<p>监控脚本目录：<u>[basedir]plugins/payment/aptos/</u></p><p>启动命令：<u>php server.php [channel]</u></p>',
  ],
];
