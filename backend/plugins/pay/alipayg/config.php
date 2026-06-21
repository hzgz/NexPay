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
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'alipayg',
    'mode' => 'international',
    'notify_retry' => 5,
    'appid' => '',
    'appkey' => '',
    'appsecret' => '',
    'appswitch' => '0',
    'currency_code' => 'CNY',
    'currency_rate' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '应用Client ID',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appkey',
      'label' => 'Antom公钥',
      'type' => 'textarea',
      'required' => true,
      'note' => '填错也可以支付成功但会无法回调',
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
      'key' => 'appswitch',
      'label' => '选择网关地址',
      'type' => 'select',
      'options' => 
      [
        0 => '亚洲（https://open-sea-global.alipay.com）',
        1 => '北美（https://open-na-global.alipay.com）',
        2 => '欧洲（https://open-de-global.alipay.com）',
      ],
    ],
    4 => 
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
    'plugin' => 'alipayg',
    'title' => '支付宝国际版',
    'link' => 'https://www.antom.com/',
    'note' => '<p>默认使用Antom在线支付的收银台支付</p>',
  ],
];
