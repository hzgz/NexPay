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
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'wxpaysl',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appmchid' => '',
    'appkey' => '',
    'appurl' => '',
    'sslcert_path' => '',
    'sslkey_path' => '',
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
      'key' => 'appkey',
      'label' => '商户API密钥',
      'type' => 'text',
      'required' => true,
      'note' => 'APIv2密钥',
    ],
    3 => 
    [
      'key' => 'appurl',
      'label' => '子商户号',
      'type' => 'text',
    ],
    4 => 
    [
      'key' => 'sslcert_path',
      'label' => '商户证书',
      'type' => 'file',
      'note' => 'apiclient_cert.pem，仅退款需要',
      'accept' => '.pem',
    ],
    5 => 
    [
      'key' => 'sslkey_path',
      'label' => '商户证书私钥',
      'type' => 'file',
      'note' => 'apiclient_key.pem，仅退款需要',
      'accept' => '.pem',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'wxpaysl',
    'title' => '微信官方支付服务商版',
    'link' => 'https://pay.weixin.qq.com/partner/public/home',
    'note' => '<p>上方AppID填写已认证的服务号/小程序/开放平台应用皆可，需要在微信支付后台关联对应的AppID账号才能使用。</p><p>如需资金下发（如退款）功能，请上传<a href="https://pay.weixin.qq.com/wiki/doc/api/tools/mch_pay.php?chapter=4_3" target="_blank" rel="noreferrer">API证书</a>。</p><p>点金计划商家小票链接（用于公众号支付跳转回网站）：[siteurl]gold</p>',
  ],
];
