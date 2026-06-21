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
    0 => 'jdpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'jdpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'private_key_path' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '商户号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '商户DES密钥',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'private_key_path',
      'label' => '商户RSA私钥',
      'type' => 'file',
      'required' => true,
      'note' => 'seller_rsa_private_key.pem',
      'accept' => '.pem',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'jdpay',
    'title' => '京东支付',
    'link' => 'https://www.jdpay.com/',
    'note' => '',
  ],
];
