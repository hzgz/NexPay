export type PageKind = 'home' | 'demo' | 'doc'

export type DemoMethod = {
  code: string
  name: string
}

export type DemoProvider = {
  value: string
  label: string
}

export type DemoConfig = {
  enabled: boolean
  title: string
  subtitle: string
  default_amount: string
  auto_complete: boolean
  methods: DemoMethod[]
  providers: DemoProvider[]
  merchant_id?: string
  merchant_name?: string
  disabled_reason?: string
}

export const pageTitles: Record<PageKind, string> = {
  home: 'NexPay 聚合支付系统',
  demo: 'NexPay 支付测试',
  doc: 'NexPay 开发文档',
}

export const heroStats = [
  { value: '99.99%', label: '支付成功率' },
  { value: '< 200ms', label: '平均响应时间' },
  { value: '80+', label: '支付方式' },
  { value: '100+', label: '覆盖国家和地区' },
]

export const paymentMethods = [
  { name: '银联支付', icon: 'payment-icons/unionpay.png' },
  { name: '支付宝', icon: 'payment-icons/alipay.png' },
  { name: '微信支付', icon: 'payment-icons/wechat.png' },
  { name: 'Visa', icon: 'payment-icons/paypal.png' },
  { name: 'Mastercard', icon: 'payment-icons/qqpay.png' },
  { name: 'Apple Pay', icon: 'payment-icons/jdpay.png' },
]

export const accessSteps = [
  {
    index: '1',
    title: '创建账户',
    description: '注册 NexPay 商户账户，完成基础资料与商户信息配置。',
  },
  {
    index: '2',
    title: '完成开发',
    description: '按照开发文档接入 API、签名、回调和通道能力。',
  },
  {
    index: '3',
    title: '上线收款',
    description: '联调完成后即可开始收款，并进入商户中心管理订单与资金。',
  },
]

export const trustBrands = ['SHEIN', 'mi', 'Trip.com', 'XPENG', 'Joyoung 九阳', '影石 Insta360']

export const docMenus = [
  '快速开始',
  '接入前准备',
  '统一下单',
  '订单查询',
  '退款接口',
  '通知回调',
  'SDK 下载',
  '更新日志',
]

export const docRequestRows = [
  ['merchant_id', 'string', '是', '商户 PID'],
  ['out_trade_no', 'string', '是', '商户订单号，需保持唯一'],
  ['amount', 'integer', '是', '订单金额，单位分'],
  ['currency', 'string', '否', '货币类型，默认 CNY'],
  ['subject', 'string', '是', '商品或订单标题'],
  ['notify_url', 'string', '是', '异步通知地址'],
  ['return_url', 'string', '否', '同步回跳地址'],
]

export const docResponseRows = [
  ['code', '200', '接口调用结果'],
  ['message', 'success', '响应消息'],
  ['payment_id', 'pay_202606200001', '平台支付单号'],
  ['payment_url', 'https://pay.example.com/pay/abc', '收银台或跳转链接'],
  ['expire_at', '1730414400', '订单过期时间戳'],
]

export const docErrorRows = [
  ['10000', '参数错误'],
  ['10001', '鉴权失败'],
  ['10003', '订单已存在'],
  ['20000', '账户余额不足'],
  ['30000', '风控已拦截'],
]

export const initialDemoConfig: DemoConfig = {
  enabled: false,
  title: 'NexPay 支付测试',
  subtitle: '在这里联调支付链路或快速发起测试订单，验证流程是否正常。',
  default_amount: '',
  auto_complete: false,
  methods: [
    { code: 'alipay', name: '支付宝' },
    { code: 'wxpay', name: '微信支付' },
    { code: 'bank', name: '银联支付' },
  ],
  providers: [
    { value: 'system', label: '系统商户' },
  ],
  merchant_id: '',
  merchant_name: '',
  disabled_reason: '',
}
