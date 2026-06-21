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
    'source_plugin' => 'yinyingtong',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'merchant_private_cert' => '',
    'productkey' => '',
    'appmchid' => '',
    'trade_platform_no' => '',
    'terminal_number' => '',
    'channel_merch_no' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '应用ID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '应用KEY',
      'type' => 'text',
      'required' => true,
      'note' => '同时是私钥证书密码',
    ],
    2 => 
    [
      'key' => 'merchant_private_cert',
      'label' => '商户私钥证书(应用ID.pfx)',
      'type' => 'file',
      'note' => '进件、代付、协议快捷支付需要',
    ],
    3 => 
    [
      'key' => 'productkey',
      'label' => '产品密钥',
      'type' => 'text',
      'note' => '用于支付回调数据解密，填错将无法回调',
    ],
    4 => 
    [
      'key' => 'appmchid',
      'label' => '交易商户企业号',
      'type' => 'text',
    ],
    5 => 
    [
      'key' => 'trade_platform_no',
      'label' => '平台商企业号(参考号)',
      'type' => 'text',
      'note' => '仅分账需要填写',
    ],
    6 => 
    [
      'key' => 'terminal_number',
      'label' => '终端号',
      'type' => 'text',
      'note' => '仅快捷支付填写',
    ],
    7 => 
    [
      'key' => 'channel_merch_no',
      'label' => '渠道商户号',
      'type' => 'text',
      'note' => '可留空，多个渠道商户号可用,分隔',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'yinyingtong',
    'title' => '银盈通支付',
    'link' => 'http://www.yinyingtong.com/',
    'note' => '',
  ],
];
