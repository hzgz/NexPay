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
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'alipayrp',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'sign_type' => '1',
    'appid' => '',
    'appsecret' => '',
    'app_cert_path' => '',
    'alipay_cert_path' => '',
    'root_cert_path' => '',
    'appmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'sign_type',
      'label' => '接口加签方式',
      'type' => 'text',
      'required' => true,
      'options' => 
      [
        0 => 'RSA2证书',
      ],
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '应用APPID',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => '应用私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    3 => 
    [
      'key' => 'app_cert_path',
      'label' => '应用公钥证书',
      'type' => 'file',
      'required' => true,
      'note' => 'appCertPublicKey开头的crt格式证书',
      'accept' => '.crt',
    ],
    4 => 
    [
      'key' => 'alipay_cert_path',
      'label' => '支付宝公钥证书',
      'type' => 'file',
      'required' => true,
      'note' => 'alipayCertPublicKey开头的crt格式证书',
      'accept' => '.crt',
    ],
    5 => 
    [
      'key' => 'root_cert_path',
      'label' => '支付宝根证书',
      'type' => 'file',
      'required' => true,
      'note' => 'alipayRootCert开头的crt格式证书',
      'accept' => '.crt',
    ],
    6 => 
    [
      'key' => 'appmchid',
      'label' => '收款方支付宝UID',
      'type' => 'text',
      'note' => '留空则使用商户绑定的支付宝UID',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'alipayrp',
    'title' => '支付宝现金红包',
    'link' => 'https://b.alipay.com/signing/productSetV2.htm',
    'note' => '<p>需要签约支付宝现金红包才能使用！</p><p>订阅"资金单据状态变更通知"，应用网关地址：[siteurl]pay/notify/[channel]/</p>',
  ],
];
