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
    'source_plugin' => 'llianpay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appmchid' => '',
    'payee_uid' => '',
    'chnlmchid' => '',
    'busi_type' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '商户编号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => '商户私钥',
      'type' => 'textarea',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '二级商户号',
      'type' => 'text',
      'note' => '仅平台商模式需要填写',
    ],
    3 => 
    [
      'key' => 'payee_uid',
      'label' => '收款方用户ID',
      'type' => 'text',
      'note' => '留空为商户自身',
    ],
    4 => 
    [
      'key' => 'chnlmchid',
      'label' => '渠道子商户号',
      'type' => 'text',
      'note' => '可留空，支付宝/微信子商户号，多个用,分隔',
    ],
    5 => 
    [
      'key' => 'busi_type',
      'label' => '业务属性',
      'type' => 'select',
      'options' => 
      [
        0 => '虚拟商品购买',
        1 => '实物商品租购',
        2 => '其他商家消费',
        3 => '商业众筹',
        4 => '信贷偿还',
        5 => '支付账户充值',
      ],
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'llianpay',
    'title' => '连连支付-聚合支付',
    'link' => 'https://open.lianlianpay.com/',
    'note' => '',
  ],
];
