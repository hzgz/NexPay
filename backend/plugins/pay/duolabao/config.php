<?php

return [
  'kind' => 'gateway',
  'capabilities' => 
  [
    0 => 'create',
    1 => 'query',
    2 => 'notify',
  ],
  'payment_methods' => 
  [
    0 => 'alipay',
    1 => 'wechat',
    2 => 'qqpay',
    3 => 'unionpay',
    4 => 'jdpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'duolabao',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'agentNum' => '',
    'customerNum' => '',
    'shopNum' => '',
    'accessKey' => '',
    'secretKey' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'agentNum',
      'label' => '代理商编号',
      'type' => 'text',
      'note' => '非代理商不需要填写',
    ],
    1 => 
    [
      'key' => 'customerNum',
      'label' => '商户编号',
      'type' => 'text',
    ],
    2 => 
    [
      'key' => 'shopNum',
      'label' => '店铺编号',
      'type' => 'text',
      'note' => '此项可留空',
    ],
    3 => 
    [
      'key' => 'accessKey',
      'label' => '公钥',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'secretKey',
      'label' => '私钥',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'duolabao',
    'title' => '哆啦宝支付',
    'link' => 'http://www.duolabao.com/',
    'note' => '',
  ],
];
