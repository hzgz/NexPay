<?php

return [
  'kind' => 'qrcode',
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
    'source_plugin' => 'hnapay',
    'mode' => 'qrcode',
    'notify_retry' => 5,
    'appswitch' => '0',
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appmchid' => '',
    'mch_key_path' => '',
    'pay_key_path' => '',
    'wxmchid' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appswitch',
      'label' => '接口类型',
      'type' => 'select',
      'options' => 
      [
        0 => '公众号/生活号支付',
        1 => '支付宝H5/快捷支付',
        2 => '扫码支付',
      ],
    ],
    1 => 
    [
      'key' => 'appid',
      'label' => '商户ID',
      'type' => 'text',
      'required' => true,
      'note' => '新生用户ID',
    ],
    2 => 
    [
      'key' => 'appkey',
      'label' => '新收款密钥-新生公钥',
      'type' => 'textarea',
      'note' => '不能带有标签和换行，文件名类似HnapayExpPublicKey.pem',
    ],
    3 => 
    [
      'key' => 'appsecret',
      'label' => '新收款密钥-商户私钥',
      'type' => 'textarea',
      'note' => '不能带有标签和换行，文件名类似PrivateKey_10.pem',
    ],
    4 => 
    [
      'key' => 'appmchid',
      'label' => '报备编号',
      'type' => 'text',
      'note' => '仅支付宝&微信需要填写',
    ],
    5 => 
    [
      'key' => 'mch_key_path',
      'label' => '收款密钥-商户私钥',
      'type' => 'file',
      'note' => '仅扫码支付需要上传，需要转换为pem格式',
      'accept' => '.key,.pem',
    ],
    6 => 
    [
      'key' => 'pay_key_path',
      'label' => '付款密钥-商户私钥',
      'type' => 'file',
      'note' => '仅付款功能需要上传，需要转换为pem格式',
      'accept' => '.key,.pem',
    ],
    7 => 
    [
      'key' => 'wxmchid',
      'label' => '微信服务商商户号',
      'type' => 'text',
      'note' => '用于获取微信支付投诉',
      'show' => 'wxpay',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'hnapay',
    'title' => '新生支付',
    'link' => 'https://www.hnapay.com/',
    'note' => '需要使用RSA格式密钥！公众号/生活号支付、支付宝H5、退款使用<b>新收款密钥</b>，扫码支付使用<b>收款密钥</b>。收款密钥和付款密钥需要用KeyStore Explorer将jks格式转换成pem格式',
  ],
];
