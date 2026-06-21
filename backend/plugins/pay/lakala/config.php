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
    'source_plugin' => 'lakala',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'mch_cert_path' => '',
    'mch_key_path' => '',
    'appmchid' => '',
    'appkey' => '',
    'appselect' => '0',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'APPID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'mch_cert_path',
      'label' => '商户证书',
      'type' => 'file',
      'required' => true,
      'note' => '上传商户证书文件api_cert.cer',
      'accept' => '.cer',
    ],
    2 => 
    [
      'key' => 'mch_key_path',
      'label' => '商户私钥',
      'type' => 'file',
      'required' => true,
      'note' => '上传商户私钥文件api_private_key.pem',
      'accept' => '.pem,.key',
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    4 => 
    [
      'key' => 'appkey',
      'label' => '终端号',
      'type' => 'text',
      'required' => true,
    ],
    5 => 
    [
      'key' => 'appselect',
      'label' => '接口类型',
      'type' => 'select',
      'options' => 
      [
        0 => '聚合扫码',
        1 => '聚合收银台',
      ],
    ],
    6 => 
    [
      'key' => 'appswitch',
      'label' => '环境选择',
      'type' => 'select',
      'options' => 
      [
        0 => '生产环境',
        1 => '测试环境',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'lakala',
    'title' => '拉卡拉',
    'link' => 'https://www.lakala.com/',
    'note' => '',
  ],
];
