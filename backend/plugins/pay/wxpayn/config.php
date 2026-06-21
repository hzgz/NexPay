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
    1 => 'qqpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'wxpayn',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appmchid' => '',
    'appsecret' => '',
    'appkey' => '',
    'publickeyid' => '',
    'merchant_key_path' => '',
    'platform_pubkey_path' => '',
    'apiv2key' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '服务号/小程序/开放平台AppID',
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
      'key' => 'merchant_key_path',
      'label' => '商户API私钥',
      'type' => 'file',
      'required' => true,
      'note' => 'apiclient_key.pem',
      'accept' => '.pem',
    ],
    6 => 
    [
      'key' => 'platform_pubkey_path',
      'label' => '微信支付公钥',
      'type' => 'file',
      'note' => 'pub_key.pem，使用平台证书模式时留空',
      'accept' => '.pem',
    ],
    7 => 
    [
      'key' => 'apiv2key',
      'label' => '商户APIv2密钥',
      'type' => 'text',
      'note' => '非必填，仅付款码支付需要填写',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'wxpayn',
    'title' => '微信官方支付V3',
    'link' => 'https://pay.weixin.qq.com/',
    'note' => '<p>上方AppID填写已认证的服务号/小程序/开放平台应用皆可，需要在微信支付后台关联对应的AppID账号才能使用。</p>',
  ],
];
