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
    0 => 'paypal',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'paypal',
    'mode' => 'international',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appswitch' => '0',
    'currency_code' => 'USD',
    'currency_rate' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => 'ClientId',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => 'ClientSecret',
      'type' => 'text',
      'required' => true,
    ],
    2 => 
    [
      'key' => 'appsecret',
      'label' => 'WebhookId',
      'type' => 'text',
      'note' => '用于Webhook签名验证',
    ],
    3 => 
    [
      'key' => 'appswitch',
      'label' => '模式选择',
      'type' => 'select',
      'options' => 
      [
        0 => '线上模式',
        1 => '沙盒模式',
      ],
    ],
    4 => 
    [
      'key' => 'currency_code',
      'label' => '结算货币',
      'type' => 'select',
      'options' => 
      [
        0 => '美元 (USD)',
        1 => '澳元 (AUD)',
        2 => '巴西雷亚尔 (BRL)',
        3 => '加拿大元 (CAD)',
        4 => '人民币 (CNY)',
        5 => '克朗 (CZK)',
        6 => '丹麦克朗(DKK)',
        7 => '欧元 (EUR)',
        8 => '港币 (HKD)',
        9 => '匈牙利福林 (HUF)',
        10 => '印度卢比 (INR)',
        11 => '以色列新谢克尔 (ILS)',
        12 => '日元 (JPY)',
        13 => '马来西亚林吉特 (MYR)',
        14 => '墨西哥比索 (MXN)',
        15 => '新台币 (TWD)',
        16 => '新西兰元 (NZD)',
        17 => '挪威克朗 (NOK)',
        18 => '菲律宾比索 (PHP)',
        19 => '波兰兹罗提 (PLN)',
        20 => '英镑 (GBP)',
        21 => '俄罗斯卢布 (RUB)',
        22 => '新加坡元 (SGD)',
        23 => '瑞典克朗 (SEK)',
        24 => '瑞士法郎 (CHF)',
        25 => '泰铢 (THB)',
      ],
    ],
    5 => 
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
    'plugin' => 'paypal',
    'title' => 'PayPal',
    'link' => 'https://www.paypal.com/',
    'note' => '',
  ],
];
