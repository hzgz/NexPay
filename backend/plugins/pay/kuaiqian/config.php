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
    'source_plugin' => 'kuaiqian',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'platform_cert_path' => '',
    'merchant_key_path' => '',
    'appkey' => '',
    'ssl_cert_path' => '',
    'appsecret' => '',
    'merchant_id' => '',
    'terminal_id' => '',
    'appmchid' => '',
    'own_channel' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '快钱账户号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'platform_cert_path',
      'label' => '快钱证书',
      'type' => 'file',
      'required' => true,
      'note' => 'cer格式快钱证书',
      'accept' => '.cer',
    ],
    2 => 
    [
      'key' => 'merchant_key_path',
      'label' => '商户证书',
      'type' => 'file',
      'required' => true,
      'note' => 'pfx(PKCS12)格式商户证书',
      'accept' => '.pfx',
    ],
    3 => 
    [
      'key' => 'appkey',
      'label' => '商户证书密码',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'ssl_cert_path',
      'label' => 'SSL客户端证书',
      'type' => 'file',
      'required' => true,
      'note' => 'pfx格式SSL客户端证书',
      'accept' => '.pfx',
    ],
    5 => 
    [
      'key' => 'appsecret',
      'label' => 'SSL客户端证书密码',
      'type' => 'text',
      'required' => true,
    ],
    6 => 
    [
      'key' => 'merchant_id',
      'label' => '当面付-商户号',
      'type' => 'text',
      'note' => '仅当面付需要填写',
    ],
    7 => 
    [
      'key' => 'terminal_id',
      'label' => '当面付-终端号',
      'type' => 'text',
      'note' => '仅当面付需要填写',
    ],
    8 => 
    [
      'key' => 'appmchid',
      'label' => '服务商-快钱子账户号',
      'type' => 'text',
      'note' => '仅服务商需要填写',
    ],
    9 => 
    [
      'key' => 'own_channel',
      'label' => '是否自有渠道',
      'type' => 'select',
      'options' => 
      [
        0 => '否',
        1 => '是',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'kuaiqian',
    'title' => '快钱支付',
    'link' => 'https://www.99bill.com/',
    'note' => '证书类型均为RSA，需要在商户证书配置页面，添加人民币网关功能，并选中你正在使用的商户证书。',
  ],
];
