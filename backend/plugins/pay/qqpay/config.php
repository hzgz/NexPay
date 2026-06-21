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
    0 => 'qqpay',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'qqpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appurl' => '',
    'appmchid' => '',
    'sslcert_path' => '',
    'sslkey_path' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'QQ钱包商户号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => 'QQ钱包API密钥',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appurl',
      'label' => '操作员账号',
      'type' => 'text',
      'note' => '仅资金下发（如退款、企业付款）时需要',
    ],
    3 => 
    [
      'key' => 'appmchid',
      'label' => '操作员密码',
      'type' => 'text',
      'note' => '仅资金下发（如退款、企业付款）时需要',
    ],
    4 => 
    [
      'key' => 'sslcert_path',
      'label' => '商户证书',
      'type' => 'file',
      'note' => 'apiclient_cert.pem，仅退款、企业付款需要',
      'accept' => '.pem',
    ],
    5 => 
    [
      'key' => 'sslkey_path',
      'label' => '商户证书私钥',
      'type' => 'file',
      'note' => 'apiclient_key.pem，仅退款、企业付款需要',
      'accept' => '.pem',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'qqpay',
    'title' => 'QQ钱包官方支付',
    'link' => 'https://mp.qpay.tenpay.com/',
    'note' => '<p>如需资金下发（如退款、企业付款）功能，请上传<a href="https://mp.qpay.tenpay.com/buss/wiki/206/1213" target="_blank" rel="noreferrer">API证书</a>，并填写<a href="https://kf.qq.com/faq/170112AZ7Fzm170112VNz6zE.html" target="_blank" rel="noreferrer">操作员账号和密码</a></p>',
  ],
];
