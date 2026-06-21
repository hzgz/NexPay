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
    'source_plugin' => 'wxpaynp',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appmchid' => '',
    'appsecret' => '',
    'appkey' => '',
    'publickeyid' => '',
    'appurl' => '',
    'appswitch' => '0',
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
      'label' => '服务商商户号',
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
      'label' => '子商户号',
      'type' => 'text',
    ],
    6 => 
    [
      'key' => 'appswitch',
      'label' => '服务商类型',
      'type' => 'select',
      'options' => 
      [
        0 => '普通服务商',
        1 => '平台收付通',
      ],
    ],
    7 => 
    [
      'key' => 'merchant_key_path',
      'label' => '商户API私钥',
      'type' => 'file',
      'required' => true,
      'note' => 'apiclient_key.pem',
      'accept' => '.pem',
    ],
    8 => 
    [
      'key' => 'platform_pubkey_path',
      'label' => '微信支付公钥',
      'type' => 'file',
      'note' => 'pub_key.pem，使用平台证书模式时留空',
      'accept' => '.pem',
    ],
    9 => 
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
    'plugin' => 'wxpaynp',
    'title' => '微信官方支付V3服务商版',
    'link' => 'https://pay.weixin.qq.com/partner/public/home',
    'note' => '<p>上方AppID填写已认证的服务号/小程序/开放平台应用皆可，需要在微信支付后台关联对应的AppID账号才能使用。</p><p>点金计划商家小票链接（用于公众号支付跳转回网站）：[siteurl]gold</p>',
  ],
];
