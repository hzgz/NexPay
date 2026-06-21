export type PluginSchemaField = Record<string, any>
export type PluginSchemaOption = {
  label: string
  value: string
}

function normalizeMethodCode(code: string) {
  const normalized = String(code || '').trim().toLowerCase()
  const map: Record<string, string> = {
    wxpay: 'wxpay',
    wechatpay: 'wxpay',
    wechat: 'wxpay',
    alipay: 'alipay',
    qq: 'qqpay',
    qqwallet: 'qqpay',
    qqpay: 'qqpay',
    union: 'bank',
    unionpay: 'bank',
    bank: 'bank',
    yinlian: 'bank',
    yunshanfu: 'bank',
    cloudquickpass: 'bank',
    douyin: 'douyinpay',
    douyinpay: 'douyinpay',
    jdpay: 'jdpay',
    ecny: 'bank',
    paypal: 'paypal',
    usdt: 'usdttrc20',
    'usdt-trc20': 'usdttrc20',
    usdttrc20: 'usdttrc20',
    trc20: 'usdttrc20',
    'usdt-erc20': 'erc20',
    erc20: 'erc20',
    'usdt-bsc': 'bsc',
    bep20: 'bsc',
    bsc: 'bsc',
    usdtpolygon: 'usdtpolygon',
    polygon: 'usdtpolygon',
    matic: 'usdtpolygon',
    usdtaptos: 'usdtaptos',
    aptos: 'usdtaptos',
    trx: 'trx',
    avaxc: 'avaxc',
    avalanche: 'avaxc',
  }

  return map[normalized] || normalized
}

function normalizeScalar(value: any) {
  if (typeof value === 'boolean') {
    return value ? '1' : '0'
  }
  if (value === null || value === undefined) {
    return ''
  }
  return String(value).trim()
}

function evaluateSingleRule(
  rule: string,
  methodCode: string,
  values: Record<string, any>,
  availableMethods: string[],
) {
  if (!rule) return true

  const comparison = rule.match(/^([a-zA-Z0-9_]+)\s*(==|!=)\s*['"]?(.+?)['"]?$/)
  if (comparison) {
    const [, fieldKey, operator, expected] = comparison
    const actual = normalizeScalar(values[fieldKey])
    return operator === '==' ? actual === expected : actual !== expected
  }

  const normalizedRule = normalizeMethodCode(rule)
  if (normalizedRule && normalizedRule === normalizeMethodCode(methodCode)) {
    return true
  }

  if (availableMethods.some((item) => normalizeMethodCode(item) === normalizedRule)) {
    return true
  }

  const actual = values[rule]
  if (typeof actual === 'boolean') {
    return actual
  }

  return normalizeScalar(actual) !== ''
}

export function evaluateShowRule(
  rule: any,
  methodCode: string,
  values: Record<string, any>,
  availableMethods: string[] = [],
) {
  const source = String(rule || '').trim()
  if (!source) return true

  const orParts = source.split(/\s*\|\|\s*/).filter(Boolean)
  return orParts.some((orPart) =>
    orPart
      .split(/\s*&&\s*/)
      .filter(Boolean)
      .every((andPart) => evaluateSingleRule(andPart.trim(), methodCode, values, availableMethods)),
  )
}

export function isSchemaFieldVisible(
  field: PluginSchemaField,
  methodCode: string,
  values: Record<string, any>,
  availableMethods: string[] = [],
) {
  return evaluateShowRule(field?.show, methodCode, values, availableMethods)
}

export function normalizeSchemaOptions(options: any): PluginSchemaOption[] {
  if (Array.isArray(options)) {
    return options.map((item, index) => {
      if (item && typeof item === 'object') {
        const value = item.value ?? item.key ?? item.id ?? index
        const label = item.label ?? item.name ?? item.text ?? value
        return {
          label: String(label),
          value: String(value),
        }
      }

      return {
        label: String(item),
        value: String(item),
      }
    })
  }

  if (options && typeof options === 'object') {
    return Object.entries(options).map(([value, label]) => ({
      label: String(label),
      value: String(value),
    }))
  }

  return []
}

export function normalizeSchemaDefault(
  field: PluginSchemaField,
  defaults: Record<string, any>,
  currentValue?: any,
) {
  const key = String(field?.key || '').trim()
  if (!key) return ''

  if (currentValue !== undefined) {
    return currentValue
  }

  if (Object.prototype.hasOwnProperty.call(defaults, key)) {
    return defaults[key]
  }

  if (String(field?.type || '').trim().toLowerCase() === 'select') {
    const options = normalizeSchemaOptions(field?.options)
    if (options.length) {
      return options[0].value
    }
  }

  return ''
}
