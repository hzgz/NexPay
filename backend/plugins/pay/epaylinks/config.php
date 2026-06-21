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
    'source_plugin' => 'epaylinks',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'mch_key_path' => '',
    'appmchid' => '',
    'upmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '商户编号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '证书序列号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'mch_key_path',
      'label' => '商户私钥证书',
      'type' => 'file',
      'required' => true,
      'note' => '上传key或pem格式的商户私钥',
      'accept' => '.key,.pem',
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '子商户编号',
      'type' => 'text',
      'note' => '非进件模式可留空',
    ],
    4 => 
    [
      'key' => 'upmchid',
      'label' => '上游商户号',
      'type' => 'text',
      'note' => '可留空，留空使用默认上游商户号',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'epaylinks',
    'title' => '易票联支付',
    'link' => 'https://www.epaylinks.cn/',
    'note' => '--生成私钥mch.key<br/>openssl genrsa -out mch.key 2048<br/>--生成公钥证书public-rsa.cer<br/>openssl req -new -x509 -sha256 -key mch.key -days 3650 -out public-rsa.cer',
  ],
];
