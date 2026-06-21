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
    0 => 'douyin',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'douyinpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appsecret' => '',
    'appmchid' => '',
    'apikey' => '',
    'certserial' => '',
    'merchant_key_path' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '应用ID',
      'type' => 'text',
      'required' => true,
      'note' => '抖音开放平台网站应用Client Key',
    ],
    1 => 
    [
      'key' => 'appsecret',
      'label' => '应用Secret',
      'type' => 'text',
      'note' => '对应应用的Client Secret，仅JSAPI支付需要填写',
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'apikey',
      'label' => '接口加密密钥',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'certserial',
      'label' => '商户API证书序列号',
      'type' => 'text',
      'required' => true,
    ],
    5 => 
    [
      'key' => 'merchant_key_path',
      'label' => '商户API私钥',
      'type' => 'file',
      'required' => true,
      'note' => 'pem格式商户私钥文件',
      'accept' => '.pem',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'douyinpay',
    'title' => '抖音支付',
    'link' => 'https://pay.douyinpay.com/',
    'note' => '<p>上方应用ID必须为网站应用，需要在抖音支付后台关联对应的应用才能使用。</p><p>若开启JSAPI支付，需在开放平台配置应用授权回调URL：[siteurl]user/oauth/douyin，以及JSBridge安全域名</p>',
  ],
];
