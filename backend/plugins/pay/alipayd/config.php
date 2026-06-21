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
    'source_plugin' => 'alipayd',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'sign_type' => '0',
    'appid' => '',
    'appsecret' => '',
    'appkey' => '',
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
      'type' => 'select',
      'required' => true,
      'options' => 
      [
        0 => 'RSA2密钥',
        1 => 'RSA2证书',
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
      'key' => 'appkey',
      'label' => '支付宝公钥',
      'type' => 'textarea',
      'required' => true,
      'note' => '填错也可以支付成功但会无法回调',
      'show' => 'sign_type==\'0\'',
    ],
    4 => 
    [
      'key' => 'app_cert_path',
      'label' => '应用公钥证书',
      'type' => 'file',
      'required' => true,
      'note' => 'appCertPublicKey开头的crt格式证书',
      'show' => 'sign_type==\'1\'',
      'accept' => '.crt',
    ],
    5 => 
    [
      'key' => 'alipay_cert_path',
      'label' => '支付宝公钥证书',
      'type' => 'file',
      'required' => true,
      'note' => 'alipayCertPublicKey开头的crt格式证书',
      'show' => 'sign_type==\'1\'',
      'accept' => '.crt',
    ],
    6 => 
    [
      'key' => 'root_cert_path',
      'label' => '支付宝根证书',
      'type' => 'file',
      'required' => true,
      'note' => 'alipayRootCert开头的crt格式证书',
      'show' => 'sign_type==\'1\'',
      'accept' => '.crt',
    ],
    7 => 
    [
      'key' => 'appmchid',
      'label' => '子商户SMID',
      'type' => 'text',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'alipayd',
    'title' => '支付宝官方支付直付通版',
    'link' => 'https://b.alipay.com/signing/productSetV2.htm',
    'note' => '<p>需要先申请互联网平台直付通才能使用！</p>',
  ],
];
