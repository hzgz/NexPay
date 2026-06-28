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
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'leshuapro',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
    'appkey' => '',
    'appsecret' => '',
    'appid' => '',
    'appmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => '乐刷商户号',
      'type' => 'text',
      'required' => true,
      'note' => 'merchant_id（乐刷分配）',
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '交易签名Key',
      'type' => 'text',
      'required' => true,
      'note' => '用于交易请求与交易应答验签',
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '推送验签Key',
      'type' => 'text',
      'required' => true,
      'note' => '用于支付通知回调验签',
    ],
    3 => 
    [
      'key' => 'appid',
      'label' => 'JSAPI AppID',
      'type' => 'text',
      'note' => '微信/支付宝JSAPI场景建议填写',
    ],
    4 => 
    [
      'key' => 'appmchid',
      'label' => '订单有效期(秒)',
      'type' => 'text',
      'note' => '默认360秒',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'leshuapro',
    'title' => '乐刷PRO',
    'link' => '',
    'note' => '<p>通道接口类型建议选择2；微信/支付宝内走JSAPI，非微信/非支付宝环境自动跳收银台。</p>',
  ],
];
