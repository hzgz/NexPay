<?php

return [
  'kind' => 'international',
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
    3 => 'paypal',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'stripe',
    'mode' => 'international',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appswitch' => '0',
    'currency_code' => 'CNY',
    'currency_rate' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'API密钥',
      'type' => 'textarea',
      'required' => true,
      'note' => 'sk_live_开头的密钥',
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => 'Webhook密钥',
      'type' => 'textarea',
      'required' => true,
      'note' => 'whsec_开头的密钥',
    ],
    2 => 
    [
      'key' => 'appswitch',
      'label' => '支付模式',
      'type' => 'select',
      'options' => 
      [
        0 => '跳转收银台',
        1 => '直接支付(仅限支付宝/微信)',
      ],
    ],
    3 => 
    [
      'key' => 'currency_code',
      'label' => '结算货币',
      'type' => 'select',
      'options' => 
      [
        0 => '人民币 (CNY)',
        1 => '港币 (HKD)',
        2 => '欧元 (EUR)',
        3 => '美元 (USD)',
        4 => '澳元 (AUD)',
        5 => '加拿大元 (CAD)',
        6 => '英镑 (GBP)',
        7 => '巴西雷亚尔 (BRL)',
        8 => '克朗 (CZK)',
        9 => '丹麦克朗(DKK)',
        10 => '匈牙利福林 (HUF)',
        11 => '印度卢比 (INR)',
        12 => '以色列新谢克尔 (ILS)',
        13 => '日元 (JPY)',
        14 => '马来西亚林吉特 (MYR)',
        15 => '墨西哥比索 (MXN)',
        16 => '新台币 (TWD)',
        17 => '新西兰元 (NZD)',
        18 => '挪威克朗 (NOK)',
        19 => '菲律宾比索 (PHP)',
        20 => '波兰兹罗提 (PLN)',
        21 => '俄罗斯卢布 (RUB)',
        22 => '新加坡元 (SGD)',
        23 => '瑞典克朗 (SEK)',
        24 => '瑞士法郎 (CHF)',
        25 => '泰铢 (THB)',
      ],
    ],
    4 => 
    [
      'key' => 'currency_rate',
      'label' => '货币汇率',
      'type' => 'text',
      'note' => '例如1元人民币兑换0.137美元(USD)，则此处填0.137',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'stripe',
    'title' => 'Stripe',
    'link' => 'https://stripe.com/',
    'note' => '需设置WebHook地址：[siteurl]pay/webhook/[channel]/ <br/>侦听的事件，直接支付用: payment_intent.succeeded，跳转收银台用：checkout.session.completed、checkout.session.async_payment_succeeded',
  ],
];
