<?php

return [
  'kind' => 'qrcode',
  'capabilities' => 
  [
    0 => 'create',
    1 => 'query',
    2 => 'notify',
    3 => 'daemon',
    4 => 'render',
  ],
  'payment_methods' => 
  [
    0 => 'alipay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'alipaycode',
    'mode' => 'qrcode',
    'notify_retry' => 5,
    'sign_type' => '0',
    'appid' => '',
    'appsecret' => '',
    'appkey' => '',
    'app_cert_path' => '',
    'alipay_cert_path' => '',
    'root_cert_path' => '',
    'appmchid' => '',
    'apptoken' => '',
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
      'label' => '支付宝UID',
      'type' => 'text',
      'note' => '2088开头的16位纯数字',
    ],
    8 => 
    [
      'key' => 'apptoken',
      'label' => '商户授权token',
      'type' => 'text',
      'note' => '只有第三方应用需要填写，非第三方应用必须留空',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'alipaycode',
    'title' => '支付宝免签约码支付',
    'link' => 'https://open.alipay.com/',
    'note' => '<p>可不签约支付产品，支付宝开放平台应用需要已上线，不能开启余额宝自动转入。如果是第三方应用类型，还需要填写商户授权token。</p><p>需添加守护进程，运行目录：<u>[basedir]plugins/payment/alipaycode/</u> 启动命令：<u>php server.php [channel]</u></p>',
  ],
];
