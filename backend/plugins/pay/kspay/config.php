<?php

return [
  'kind' => 'gateway',
  'capabilities' => 
  [
    0 => 'create',
    1 => 'query',
    2 => 'notify',
    3 => 'daemon',
  ],
  'payment_methods' => 
  [
    0 => 'alipay',
    1 => 'wechat',
  ],
  'default_settings' => 
  [
    'enabled' => true,
    'source_plugin' => 'kspay',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appurl' => '',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appurl',
      'label' => '快手ID',
      'type' => 'text',
      'required' => true,
      'note' => '快手商户ID或收款账号标识',
    ],
  ],
  'source' => 
  [
    'vendor' => 'epay_pro',
    'plugin' => 'kspay',
    'title' => '快手支付',
    'link' => '',
    'note' => '<p>监控脚本目录：<u>[basedir]plugins/payment/kspay/</u></p><p>启动命令：<u>php server.php [channel]</u></p><p>注意：支付金额建议为 0.1 的倍数。</p>',
  ],
];
