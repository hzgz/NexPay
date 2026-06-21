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
    2 => 'unionpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'haipay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'accessid' => '',
    'accesskey' => '',
    'agent_no' => '',
    'merch_no' => '',
    'pn' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'accessid',
      'label' => 'accessid',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'accesskey',
      'label' => '接入秘钥',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'agent_no',
      'label' => '服务商编号',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'merch_no',
      'label' => '商户编号',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'pn',
      'label' => '终端号',
      'type' => 'text',
      'required' => true,
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'haipay',
    'title' => '海科聚合支付',
    'link' => 'https://www.hkrt.cn/',
    'note' => '需要先加服务器IP白名单，否则无法调用支付！',
  ],
];
