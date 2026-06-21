<?php

return [
  'kind' => 'gateway',
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
    'source_plugin' => 'cpcn',
    'mode' => 'gateway',
    'notify_retry' => 5,
    'appid' => '',
    'appurl' => '',
    'appmchid' => '',
    'appswitch' => '0',
  ],
  'settings_schema' => 
  [
    0 => 
    [
      'key' => 'appid',
      'label' => '机构编号',
      'type' => 'text',
      'required' => true,
    ],
    1 => 
    [
      'key' => 'appurl',
      'label' => '中转API接口地址',
      'type' => 'text',
      'required' => true,
      'note' => '必须以http://或https://开头，以/结尾',
    ],
    2 => 
    [
      'key' => 'appmchid',
      'label' => '渠道子商户号',
      'type' => 'text',
      'note' => '可留空，多个商户号用,分隔，最多10个',
    ],
    3 => 
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
    'plugin' => 'cpcn',
    'title' => '中金支付',
    'link' => 'https://www.cpcn.com.cn/',
    'note' => '需自行搭建中金支付（国密证书）中转API接口站点，<a href="http://file.cccyun.cc/resource/%E4%B8%AD%E9%87%91%E6%94%AF%E4%BB%98%E4%B8%AD%E8%BD%ACAPI.zip" target="_blank" rel="noreferrer">下载源码</a>',
  ],
];
