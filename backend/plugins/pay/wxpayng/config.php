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
    0 => 'wechat',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'wxpayng',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appmchid' => '',
    'appsecret' => '',
    'appkey' => '',
    'publickeyid' => '',
    'appurl' => '',
    'merchant_key_path' => '',
    'platform_pubkey_path' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '公众号或小程序APPID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appmchid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '商户APIv3密钥',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'appkey',
      'label' => '商户API证书序列号',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'publickeyid',
      'label' => '微信支付公钥ID',
      'type' => 'text',
      'note' => '平台证书模式需要留空',
    ],
    5 => 
    [
      'key' => 'appurl',
      'label' => '商户行业编码',
      'type' => 'text',
    ],
    6 => 
    [
      'key' => 'merchant_key_path',
      'label' => '商户API私钥',
      'type' => 'file',
      'required' => true,
      'note' => 'apiclient_key.pem',
      'accept' => '.pem',
    ],
    7 => 
    [
      'key' => 'platform_pubkey_path',
      'label' => '微信支付公钥',
      'type' => 'file',
      'note' => 'pub_key.pem，使用平台证书模式时留空',
      'accept' => '.pem',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'wxpayng',
    'title' => '微信支付国际版V3',
    'link' => 'https://pay.weixin.qq.com/',
    'note' => '<p>上方APPID填写公众号或小程序的皆可，需要在微信支付后台关联对应的公众号或小程序才能使用。无认证的公众号或小程序无法发起支付！</p><p><a href="https://pay.weixin.qq.com/wiki/doc/api_external/ch/terms_definition/chapter1_1_1.shtml#part-7" target="_blank">商户行业编码表</a></p>',
  ],
];
