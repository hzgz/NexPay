export type PageKind = 'home' | 'demo' | 'doc'

export type DemoMethod = {
  code: string
  name: string
  icon?: string
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
  min_amount?: string
  auto_complete: boolean
  methods: DemoMethod[]
  providers: DemoProvider[]
  merchant_id?: string
  merchant_name?: string
  disabled_reason?: string
}

export type DocParameterRow = {
  name: string
  type: string
  required: string
  description: string
}

export type DocResponseRow = {
  name: string
  example: string
  description: string
}

export type DocErrorRow = {
  code: string
  description: string
}

export type DocSection = {
  id: string
  menu: string
  eyebrow: string
  title: string
  summary: string
  endpoint: string
  method: string
  notes?: string[]
  requestRows?: DocParameterRow[]
  requestExample?: string
  responseRows?: DocResponseRow[]
  responseExample?: string
  errorRows?: DocErrorRow[]
}

export const pageTitles: Record<PageKind, string> = {
  home: 'NexPay 聚合支付系统',
  demo: 'NexPay 支付测试',
  doc: 'NexPay 开发文档',
}

export const paymentMethods = [
  { name: '支付宝', icon: 'payment-icons/alipay.png' },
  { name: '微信支付', icon: 'payment-icons/wechat.png' },
  { name: '银联支付', icon: 'payment-icons/unionpay.png' },
  { name: 'QQ 钱包', icon: 'payment-icons/qqpay.png' },
  { name: '京东支付', icon: 'payment-icons/jdpay.png' },
  { name: 'PayPal', icon: 'payment-icons/paypal.png' },
]

export const accessSteps = [
  {
    index: '1',
    title: '创建账户',
    description: '注册 NexPay 商户账户，完成基础资料和商户信息配置。',
  },
  {
    index: '2',
    title: '完成开发',
    description: '按照开发文档接入 API、签名、回调与支付方式编码。',
  },
  {
    index: '3',
    title: '上线收款',
    description: '联调完成后即可开始收款，并进入商户中心管理订单与资金。',
  },
]

export const trustBrands = ['SHEIN', 'mi', 'Trip.com', 'XPENG', 'Joyoung 九阳', 'Insta360']

export const docOverview = {
  title: 'NexPay 开发文档',
  subtitle:
    '当前文档对应系统实际开放的支付接口与收银台链路，包含 V1 兼容接口、V2 接口、订单查询与回调说明。',
  badges: ['真实路由', '单端口', '可直接联调'],
  quickLinks: [
    { label: 'V1 下单', target: '#v1-create' },
    { label: 'V2 下单', target: '#v2-create' },
    { label: '订单查询', target: '#v2-query' },
    { label: '支付测试', target: '/demo', external: false },
  ],
}

export const docSections: DocSection[] = [
  {
    id: 'quick-start',
    menu: '快速开始',
    eyebrow: '接入概览',
    title: '接入前先确认这 4 个地址',
    summary:
      '前台测试、支付收银台、V1 兼容接口、V2 正式接口都运行在同一套系统里，联调时优先使用本文档列出的真实路由。',
    endpoint: '首页 / 文档 / 测试页',
    method: 'GET',
    notes: [
      '首页支付测试页：`/demo`',
      '支付收银台：`/pay/checkout/{trade_no}`',
      'V1 兼容下单：`/mapi.php`',
      'V2 正式下单：`/api/pay/create`',
    ],
  },
  {
    id: 'preflight',
    menu: '接入前准备',
    eyebrow: '签名与商户',
    title: 'V1 与 V2 的签名方式不同',
    summary:
      'V1 使用商户 MD5 密钥验签，V2 使用商户 RSA 公钥验签。下单前请先在商户中心确认 PID、密钥、签名模式和支付方式编码。',
    endpoint: '签名说明',
    method: 'READ',
    notes: [
      'V1 必须提交 `pid` 与 `sign`，签名方式为 MD5。',
      'V2 必须提交 `pid`、`timestamp`、`sign_type=RSA` 与 `sign`。',
      '支付方式编码由系统支付方式配置决定，常见如 `alipay`、`wxpay`、`qqpay`、`bank`。',
      '商户订单号 `out_trade_no` 在同一商户下必须唯一。',
    ],
  },
  {
    id: 'v1-create',
    menu: 'V1 下单',
    eyebrow: '兼容接口',
    title: 'V1 兼容下单接口',
    summary:
      '用于兼容易支付旧版接入方式。成功后返回平台订单号与收银台地址，客户端可直接跳转到 `payurl`。',
    endpoint: '/mapi.php',
    method: 'POST',
    requestRows: [
      { name: 'pid', type: 'string', required: '是', description: '商户 PID' },
      { name: 'type', type: 'string', required: '是', description: '支付方式编码，例如 alipay、wxpay、qqpay、bank' },
      { name: 'out_trade_no', type: 'string', required: '是', description: '商户订单号，必须唯一' },
      { name: 'notify_url', type: 'string', required: '否', description: '异步通知地址' },
      { name: 'return_url', type: 'string', required: '否', description: '同步跳转地址' },
      { name: 'name', type: 'string', required: '否', description: '订单标题，默认“支付订单”' },
      { name: 'money', type: 'string', required: '是', description: '订单金额，单位元，必须大于 0' },
      { name: 'clientip', type: 'string', required: '否', description: '客户端 IP' },
      { name: 'param', type: 'string', required: '否', description: '附加参数，回调时原样返回' },
      { name: 'sign', type: 'string', required: '是', description: '按 V1 MD5 规则生成的签名' },
    ],
    requestExample: `{
  "pid": "1000001",
  "type": "alipay",
  "out_trade_no": "ORDER_202606230001",
  "notify_url": "https://your-domain.com/pay/notify",
  "return_url": "https://your-domain.com/pay/return",
  "name": "测试商品",
  "money": "12.50",
  "clientip": "127.0.0.1",
  "param": "demo-order",
  "sign": "md5_signature_here"
}`,
    responseRows: [
      { name: 'code', example: '1', description: '成功时固定返回 1' },
      { name: 'msg', example: '成功', description: '接口提示信息' },
      { name: 'trade_no', example: '202606230001234567', description: '平台订单号' },
      { name: 'payurl', example: '/pay/checkout/202606230001234567', description: '收银台地址或支付页地址' },
    ],
    responseExample: `{
  "code": 1,
  "msg": "成功",
  "trade_no": "202606230001234567",
  "payurl": "https://your-domain.com/pay/checkout/202606230001234567"
}`,
    errorRows: [
      { code: '401', description: 'V1 签名校验失败' },
      { code: '422', description: '商户订单号为空、重复或金额不合法' },
      { code: '1000', description: '当前支付方式没有可用通道或业务规则不允许' },
    ],
  },
  {
    id: 'v2-create',
    menu: 'V2 下单',
    eyebrow: '正式接口',
    title: 'V2 下单接口',
    summary:
      '推荐新接入优先使用 V2。成功后返回 `pay_info`，当前系统默认返回统一收银台地址，并附带平台 RSA 签名。',
    endpoint: '/api/pay/create',
    method: 'POST',
    requestRows: [
      { name: 'pid', type: 'string', required: '是', description: '商户 PID' },
      { name: 'type', type: 'string', required: '是', description: '支付方式编码，例如 alipay、wxpay、qqpay、bank' },
      { name: 'method', type: 'string', required: '否', description: '支付场景，默认 web' },
      { name: 'device', type: 'string', required: '否', description: '设备信息或终端标识' },
      { name: 'out_trade_no', type: 'string', required: '是', description: '商户订单号，必须唯一' },
      { name: 'notify_url', type: 'string', required: '否', description: '异步通知地址' },
      { name: 'return_url', type: 'string', required: '否', description: '同步跳转地址' },
      { name: 'name', type: 'string', required: '否', description: '订单标题，默认“支付订单”' },
      { name: 'money', type: 'string', required: '是', description: '订单金额，单位元，必须大于 0' },
      { name: 'clientip', type: 'string', required: '否', description: '客户端 IP' },
      { name: 'param', type: 'string', required: '否', description: '附加参数，回调时原样返回' },
      { name: 'timestamp', type: 'string', required: '是', description: '10 位秒级时间戳，默认校验 300 秒有效期' },
      { name: 'sign_type', type: 'string', required: '是', description: '固定为 RSA' },
      { name: 'sign', type: 'string', required: '是', description: '按 V2 RSA 规则生成的签名' },
    ],
    requestExample: `{
  "pid": "1000001",
  "type": "wxpay",
  "method": "web",
  "out_trade_no": "ORDER_202606230002",
  "notify_url": "https://your-domain.com/pay/notify",
  "return_url": "https://your-domain.com/pay/return",
  "name": "套餐购买",
  "money": "26.80",
  "clientip": "127.0.0.1",
  "param": "package-buy",
  "timestamp": "1751112000",
  "sign_type": "RSA",
  "sign": "rsa_signature_here"
}`,
    responseRows: [
      { name: 'code', example: '0', description: '成功时固定返回 0' },
      { name: 'msg', example: 'success', description: '接口提示信息' },
      { name: 'trade_no', example: '202606230001234568', description: '平台订单号' },
      { name: 'pay_type', example: 'jump', description: '当前默认返回 jump' },
      { name: 'pay_info', example: '/pay/checkout/202606230001234568', description: '支付跳转地址或收银台地址' },
      { name: 'timestamp', example: '1750665600', description: '平台签名时间戳' },
      { name: 'sign_type', example: 'RSA', description: '平台响应签名类型' },
      { name: 'sign', example: 'platform_rsa_signature', description: '平台响应签名' },
    ],
    responseExample: `{
  "code": 0,
  "msg": "success",
  "trade_no": "202606230001234568",
  "pay_type": "jump",
  "pay_info": "https://your-domain.com/pay/checkout/202606230001234568",
  "timestamp": "1750665600",
  "sign_type": "RSA",
  "sign": "platform_rsa_signature"
}`,
    errorRows: [
      { code: '401', description: 'V2 RSA 签名校验失败' },
      { code: '401/422', description: 'timestamp 缺失、过期或格式错误' },
      { code: '429', description: '退款、关单、代付等变更请求被判定为重复提交' },
      { code: '422', description: '商户订单号为空、重复或金额不合法' },
      { code: '1000', description: '支付方式无可用通道或商户配置不完整' },
    ],
  },
  {
    id: 'v2-query',
    menu: '订单查询',
    eyebrow: '订单状态',
    title: 'V2 订单查询接口',
    summary:
      '通过平台订单号或商户订单号查询当前订单状态，返回支付状态、金额、支付方式、买家信息等数据。',
    endpoint: '/api/pay/query',
    method: 'POST',
    requestRows: [
      { name: 'pid', type: 'string', required: '是', description: '商户 PID' },
      { name: 'trade_no', type: 'string', required: '二选一', description: '平台订单号' },
      { name: 'out_trade_no', type: 'string', required: '二选一', description: '商户订单号' },
      { name: 'timestamp', type: 'string', required: '是', description: '10 位秒级时间戳，默认校验 300 秒有效期' },
      { name: 'sign_type', type: 'string', required: '是', description: '固定为 RSA' },
      { name: 'sign', type: 'string', required: '是', description: '按 V2 RSA 规则生成的签名' },
    ],
    responseRows: [
      { name: 'code', example: '0', description: '成功时固定返回 0' },
      { name: 'status', example: '1', description: '1 为支付成功，0 为待支付' },
      { name: 'trade_status', example: 'TRADE_SUCCESS', description: '支付状态文本' },
      { name: 'trade_no', example: '202606230001234568', description: '平台订单号' },
      { name: 'out_trade_no', example: 'ORDER_202606230002', description: '商户订单号' },
      { name: 'type', example: 'wxpay', description: '支付方式编码' },
      { name: 'money', example: '26.80', description: '订单金额' },
    ],
  },
  {
    id: 'notify',
    menu: '通知回调',
    eyebrow: '异步回调',
    title: '支付成功后系统会回调 notify_url',
    summary:
      '订单支付完成后，系统会按照订单中提交的 `notify_url` 进行异步通知。若没有填写 `notify_url`，则不会触发外部业务通知。',
    endpoint: 'notify_url',
    method: 'POST',
    notes: [
      '回调前提是下单时提交了 `notify_url`。',
      '回调数据会携带订单号、金额、支付状态及签名。',
      '商户服务端收到回调后应先验签，再更新业务订单。',
      '建议你的业务回调接口成功后返回纯文本 `success`。',
    ],
  },
]

export const initialDemoConfig: DemoConfig = {
  enabled: false,
  title: 'NexPay 支付测试',
  subtitle: '在这里联调支付链路或快速发起测试订单，验证流程是否正常。',
  default_amount: '',
  min_amount: '0.10',
  auto_complete: false,
  methods: [
    { code: 'alipay', name: '支付宝' },
    { code: 'wxpay', name: '微信支付' },
    { code: 'bank', name: '银联支付' },
  ],
  providers: [{ value: 'system', label: '系统商户' }],
  merchant_id: '',
  merchant_name: '',
  disabled_reason: '',
}
