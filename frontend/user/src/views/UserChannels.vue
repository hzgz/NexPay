<script setup lang="ts">
import { Box, Delete, EditPen, Plus, Search, Setting, SwitchButton } from '@element-plus/icons-vue'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRoute } from 'vue-router'
import {
  deleteUserChannel,
  getUserChannels,
  refreshUserAlipayCkQrcode,
  saveUserChannel,
  saveUserChannelRotation,
  saveUserPaymentSettings,
  syncUserAlipayCkStatus,
  testUserChannel,
  toggleUserChannel,
  uploadUserChannelConfigFile,
} from '../lib/api'
import {
  isSchemaFieldVisible,
  normalizeSchemaDefault,
  normalizeSchemaOptions,
} from '../lib/plugin-schema'
import templateCheckoutLivePreview from '../assets/payment-settings/template-checkout-live.png'

type ChannelMethod = {
  code: string
  name: string
  category?: string
  settlement?: string
  status_code?: number
}

type ChannelPlugin = {
  code: string
  name: string
  kind?: string
  payment_methods?: string[]
  settings_schema?: Array<Record<string, any>>
  default_settings?: Record<string, any>
  status_code?: number
}

type RotationPoolItem = {
  channel_id: number
  channel_name: string
  weight: number
}

type RotationPool = {
  id: number
  pool_name: string
  method_code: string
  method_name: string
  strategy: string
  strategy_label: string
  status_code: number
  items: RotationPoolItem[]
  channel_count: number
}

type PaymentTemplatePreset = {
  code: string
  title: string
  preview: string
}

type PaymentVariableChip = {
  label: string
  token: string
  sample: string
}

type AlipayCkPanelState = {
  status: string
  status_label: string
  status_tone: string
  message: string
  qr_image: string
  account_pid: string
  updated_at: string
}

const paymentTemplateOptions: PaymentTemplatePreset[] = [
  { code: 'nexpay-standard', title: 'NexPay 当前版', preview: templateCheckoutLivePreview },
]

const paymentVariableOptions: PaymentVariableChip[] = [
  { label: '平台订单号', token: '{{platform_order_no}}', sample: '20260620163000123456' },
  { label: '商户订单号', token: '{{merchant_order_no}}', sample: 'MERCHANT-20260620-001' },
  { label: '商品名称', token: '{{product_name}}', sample: '测试商品' },
  { label: '实付价格', token: '{{paid_amount}}', sample: '99.00' },
  { label: '订单价格', token: '{{order_amount}}', sample: '100.00' },
  { label: '收款方式', token: '{{payment_method}}', sample: '微信支付' },
]

const paymentTemplateCodeAliasMap: Record<string, string> = {
  'classic-blue': 'nexpay-standard',
  'hg-pay-1': 'nexpay-standard',
  'hg-pay-2': 'nexpay-standard',
  'modern-float': 'nexpay-standard',
  'nexpay-standard': 'nexpay-standard',
  'nexpay-center': 'nexpay-standard',
  'nexpay-dialog': 'nexpay-standard',
  'nexpay-float': 'nexpay-standard',
}

const paymentVariableTokenAliasMap: Record<string, string> = {
  '[平台订单号]': '{{platform_order_no}}',
  '[商户订单号]': '{{merchant_order_no}}',
  '[商品名称]': '{{product_name}}',
  '[实付价格]': '{{paid_amount}}',
  '[订单价格]': '{{order_amount}}',
  '[收款方式]': '{{payment_method}}',
  '{{平台订单号}}': '{{platform_order_no}}',
  '{{商户订单号}}': '{{merchant_order_no}}',
  '{{商品名称}}': '{{product_name}}',
  '{{实付价格}}': '{{paid_amount}}',
  '{{订单价格}}': '{{order_amount}}',
  '{{收款方式}}': '{{payment_method}}',
}

const paymentDefaultVoiceTemplate = '收到来自{{product_name}}的支付，金额{{paid_amount}}元，支付方式为{{payment_method}}'

const route = useRoute()
const channelDialogVisible = ref(false)
const configDialogVisible = ref(false)
const testDialogVisible = ref(false)
const rotationDialogVisible = ref(false)
const rotationChannelsDialogVisible = ref(false)
const loading = ref(false)
const channelKeyword = ref('')
const channelData = ref<Record<string, any>>({
  items: [],
  rotation: {},
  payment_settings: {},
  methods: [],
  plugins: [],
})

const channelForm = reactive<Record<string, any>>({
  id: 0,
  channel_name: '',
  method_code: '',
  plugin_code: '',
  daily_limit: '',
  daily_count_limit: '',
  single_min_amount: '',
  single_max_amount: '',
  rate: '0.85',
  display_value: '',
  remark: '',
  status_code: 1,
})

const configForm = reactive<Record<string, any>>({
  id: 0,
  channel_name: '',
  method_code: '',
  plugin_code: '',
  rate: '0.85',
  display_value: '',
  remark: '',
  status_code: 1,
  daily_limit: '',
  daily_count_limit: '',
  single_min_amount: '',
  single_max_amount: '',
  plugin_config: {},
})

const configUploadLoading = reactive<Record<string, boolean>>({})
const alipayCkLoading = reactive({
  qrcode: false,
  status: false,
})
let alipayCkPollTimer: number | null = null
let alipayCkSuccessToastShown = false
const alipayCkPanel = reactive<AlipayCkPanelState>({
  status: 'idle',
  status_label: '未获取',
  status_tone: 'muted',
  message: '点击刷新二维码，生成支付宝 CK 登录二维码。',
  qr_image: '',
  account_pid: '',
  updated_at: '',
})

const paymentForm = reactive({
  template: 'nexpay-standard',
  auto_redirect: false,
  voice_enabled: true,
  voice_content: '',
  cashier_notice: '',
})

const rotationPoolForm = reactive({
  id: 0,
  pool_name: '',
  method_code: '',
  strategy: 'sequential',
  status_code: 1,
})

const rotationChannelsForm = reactive<{
  id: number
  pool_name: string
  method_code: string
  items: RotationPoolItem[]
}>({
  id: 0,
  pool_name: '',
  method_code: '',
  items: [],
})

const rotationStrategyOptions = [
  { value: 'sequential', label: '顺序' },
  { value: 'weighted_random', label: '随机' },
]

const testForm = reactive({
  id: 0,
  title: '',
  amount: '1.00',
})

const activeSection = computed(() => {
  const section = route.meta.section
  if (section === 'rotation' || section === 'settings') return section
  return 'list'
})

const paymentVoicePreview = computed(() => {
  return renderPaymentVariablePreview(String(paymentForm.voice_content || ''))
})

const methods = computed<ChannelMethod[]>(() =>
  (channelData.value.methods || []).filter((item: ChannelMethod) => Number(item.status_code ?? 1) === 1),
)

const enabledPlugins = computed<ChannelPlugin[]>(() =>
  (channelData.value.plugins || []).filter((item: ChannelPlugin) => Number(item.status_code ?? 0) === 1),
)

const sortedChannels = computed<Record<string, any>[]>(() => {
  return [...(channelData.value.items || [])].sort((left, right) => Number(right.id || 0) - Number(left.id || 0))
})

const visibleChannels = computed<Record<string, any>[]>(() => {
  const keyword = channelKeyword.value.trim().toLowerCase()
  if (!keyword) return sortedChannels.value

  return sortedChannels.value.filter((item) => {
    const haystack = [
      item.id,
      item.channel_name,
      item.method_name,
      item.method_code,
      item.plugin_name,
      item.plugin_code,
      item.channel,
      methodName(String(item.method_code || '')),
      pluginName(String(item.plugin_code || '')),
    ]
      .map((value) => String(value || '').toLowerCase())
      .join(' ')

    return haystack.includes(keyword)
  })
})

const rotationPools = computed<RotationPool[]>(() => {
  const pools = Array.isArray(channelData.value.rotation?.pools) ? channelData.value.rotation.pools : []
  return pools.map((pool: Record<string, any>) => ({
    id: Number(pool.id || 0),
    pool_name: String(pool.pool_name || pool.name || '').trim(),
    method_code: String(pool.method_code || '').trim(),
    method_name: String(pool.method_name || '').trim(),
    strategy: String(pool.strategy || 'sequential').trim() || 'sequential',
    strategy_label: String(pool.strategy_label || '').trim(),
    status_code: Number(pool.status_code) === 1 ? 1 : 0,
    items: Array.isArray(pool.items)
      ? pool.items.map((item: Record<string, any>) => ({
          channel_id: Number(item.channel_id || 0),
          channel_name: String(item.channel_name || '').trim(),
          weight: Math.max(1, Number(item.weight || 50)),
        }))
      : [],
    channel_count: Number(pool.channel_count || (Array.isArray(pool.items) ? pool.items.length : 0)),
  }))
})

const rotationMethodOptions = computed<ChannelMethod[]>(() => {
  const usedCodes = new Set(
    sortedChannels.value
      .map((item) => normalizeMethodCode(String(item.method_code || item.code || '')))
      .filter(Boolean),
  )

  return methods.value.filter((item) => usedCodes.has(normalizeMethodCode(String(item.code || ''))))
})

const rotationAvailableChannels = computed<Record<string, any>[]>(() => {
  const methodCode = normalizeMethodCode(String(rotationChannelsForm.method_code || rotationPoolForm.method_code || ''))
  if (!methodCode) return []

  return sortedChannels.value.filter(
    (item) => normalizeMethodCode(String(item.method_code || item.code || '')) === methodCode,
  )
})

const filteredChannelPlugins = computed<ChannelPlugin[]>(() => {
  return pluginsForMethod(String(channelForm.method_code || ''), String(channelForm.plugin_code || ''))
})

const selectedConfigPlugin = computed<ChannelPlugin | null>(() => {
  const code = String(configForm.plugin_code || '')
  return pluginsForMethod(String(configForm.method_code || ''), code).find((item) => item.code === code) || null
})

const isAlipayCkConfig = computed(() => String(configForm.plugin_code || '') === 'alipay-ck')

const configSchema = computed<Record<string, any>[]>(() => {
  const rawSchema = selectedConfigPlugin.value?.settings_schema
  const rawSchemaObject = rawSchema && typeof rawSchema === 'object' ? (rawSchema as Record<string, any>) : null
  const schema = Array.isArray(rawSchema)
    ? rawSchema
    : Array.isArray(rawSchemaObject?.fields)
      ? (rawSchemaObject.fields as Record<string, any>[])
      : []

  return schema.filter((field) =>
    isSchemaFieldVisible(field, String(configForm.method_code || ''), configForm.plugin_config || {}),
  )
})

function applyChannelData(data: Record<string, any>) {
  channelData.value = data
  Object.assign(paymentForm, {
    template: 'nexpay-standard',
    auto_redirect: false,
    voice_enabled: true,
    voice_content: '',
    cashier_notice: '',
    ...(data.payment_settings || {}),
  })
  normalizePaymentFormState()
}

async function load() {
  loading.value = true
  const resp = await getUserChannels()
  if (resp.code === 0 && resp.data) {
    applyChannelData(resp.data)
  }
  loading.value = false
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

function pluginsForMethod(methodCode: string, currentCode = '') {
  const normalizedMethod = normalizeMethodCode(methodCode)
  if (!normalizedMethod) return []

  const list = enabledPlugins.value.filter((plugin) =>
    Array.isArray(plugin.payment_methods)
      && plugin.payment_methods.some((code) => normalizeMethodCode(String(code || '')) === normalizedMethod),
  )

  const current = (channelData.value.plugins || []).find((plugin: ChannelPlugin) => plugin.code === currentCode)
  if (current && !list.some((item) => item.code === current.code)) {
    return [...list, current]
  }

  return list
}

function methodName(code: string) {
  const method = methods.value.find((item) => item.code === code)
  return method?.name || code || '-'
}

function pluginName(code: string) {
  const plugin = (channelData.value.plugins || []).find((item: ChannelPlugin) => item.code === code)
  return plugin?.name || code || '-'
}

function statusClass(item: Record<string, any>) {
  return Number(item.status_code) === 1 ? 'success' : 'muted'
}

function resetAlipayCkPanel() {
  stopAlipayCkPolling()
  alipayCkSuccessToastShown = false
  Object.assign(alipayCkPanel, {
    status: 'idle',
    status_label: '未获取',
    status_tone: 'muted',
    message: '点击刷新二维码，生成支付宝 CK 登录二维码。',
    qr_image: '',
    account_pid: '',
    updated_at: '',
  })
}

function applyAlipayCkPanel(payload: Record<string, any> | null | undefined) {
  const source = payload || {}
  Object.assign(alipayCkPanel, {
    status: String(source.status || source.login_state || 'idle'),
    status_label: String(source.status_label || source.login_state_text || '未获取'),
    status_tone: String(source.status_tone || 'muted'),
    message: String(source.message || source.login_state_message || '点击刷新二维码，生成支付宝 CK 登录二维码。'),
    qr_image: String(source.qr_image || source.login_qr_image || ''),
    account_pid: String(source.account_pid || ''),
    updated_at: String(source.updated_at || source.login_checked_at || source.login_confirmed_at || ''),
  })
}

function patchCurrentChannelPluginConfig(source: Record<string, any>) {
  const channelId = Number(configForm.id || 0)
  if (!channelId) return

  const items = Array.isArray(channelData.value.items) ? channelData.value.items : []
  const index = items.findIndex((item) => Number(item?.id || 0) === channelId)
  if (index < 0) return

  const current = items[index] || {}
  const nextItems = [...items]
  nextItems[index] = {
    ...current,
    plugin_config: {
      ...(current.plugin_config || {}),
      ...(source.plugin_config || {}),
    },
  }
  channelData.value = {
    ...channelData.value,
    items: nextItems,
  }
}

function syncAlipayCkPanelFromConfig() {
  if (!isAlipayCkConfig.value) {
    resetAlipayCkPanel()
    return
  }

  applyAlipayCkPanel(configForm.plugin_config || {})
}

function mergeAlipayCkPluginConfig(source: Record<string, any>) {
  configForm.plugin_config = {
    ...(configForm.plugin_config || {}),
    ...(source.plugin_config || {}),
  }
  patchCurrentChannelPluginConfig(source)

  applyAlipayCkPanel({
    ...configForm.plugin_config,
    ...source,
  })
}

function stopAlipayCkPolling() {
  if (alipayCkPollTimer !== null) {
    window.clearTimeout(alipayCkPollTimer)
    alipayCkPollTimer = null
  }
}

function shouldPollAlipayCkStatus(status: string) {
  return ['pending_scan', 'pending_confirm'].includes(String(status || '').trim().toLowerCase())
}

function scheduleAlipayCkPolling(delay = 1600) {
  stopAlipayCkPolling()
  if (!configDialogVisible.value || !isAlipayCkConfig.value || !Number(configForm.id || 0)) {
    return
  }

  if (!shouldPollAlipayCkStatus(alipayCkPanel.status)) {
    return
  }

  alipayCkPollTimer = window.setTimeout(() => {
    alipayCkPollTimer = null
    void loadAlipayCkStatus({ silent: true })
  }, Math.max(800, delay))
}

async function loadAlipayCkQrcode() {
  if (!Number(configForm.id || 0) || !isAlipayCkConfig.value) return

  stopAlipayCkPolling()
  alipayCkSuccessToastShown = false
  alipayCkLoading.qrcode = true
  try {
    const resp = await refreshUserAlipayCkQrcode(Number(configForm.id || 0))
    if (resp.code === 0 && resp.data) {
      mergeAlipayCkPluginConfig(resp.data)
      ElMessage.success(resp.message || '登录二维码已刷新')
      scheduleAlipayCkPolling(1200)
      return
    }

    ElMessage.error(resp.message || '登录二维码刷新失败')
  } catch {
    ElMessage.error('登录二维码刷新失败')
  } finally {
    alipayCkLoading.qrcode = false
  }
}

async function loadAlipayCkStatus(options: { silent?: boolean } = {}) {
  if (!Number(configForm.id || 0) || !isAlipayCkConfig.value) return

  stopAlipayCkPolling()
  alipayCkLoading.status = true
  try {
    const resp = await syncUserAlipayCkStatus(Number(configForm.id || 0))
    if (resp.code === 0 && resp.data) {
      const previousStatus = String(alipayCkPanel.status || '').trim().toLowerCase()
      mergeAlipayCkPluginConfig(resp.data)
      const nextStatus = String((resp.data.status || resp.data.login_state || alipayCkPanel.status || '')).trim().toLowerCase()

      if (!options.silent) {
        ElMessage.success(resp.message || '登录状态已更新')
      } else if (nextStatus === 'authenticated' && previousStatus !== 'authenticated' && !alipayCkSuccessToastShown) {
        ElMessage.success('支付宝 CK 已登录成功')
        alipayCkSuccessToastShown = true
      }

      if (shouldPollAlipayCkStatus(nextStatus)) {
        scheduleAlipayCkPolling(nextStatus === 'pending_confirm' ? 1000 : 1600)
      }
      return
    }

    if (!options.silent) {
      ElMessage.error(resp.message || '登录状态更新失败')
    } else {
      scheduleAlipayCkPolling(2400)
    }
  } catch {
    if (!options.silent) {
      ElMessage.error('登录状态更新失败')
    } else {
      scheduleAlipayCkPolling(2400)
    }
  } finally {
    alipayCkLoading.status = false
  }
}

function checkAlipayCkStatus() {
  void loadAlipayCkStatus()
}

function rotationStrategyLabel(strategy: string) {
  return String(strategy || '').toLowerCase().includes('weight') ? '随机' : '顺序'
}

function createRotationPoolItem(): RotationPoolItem {
  return {
    channel_id: 0,
    channel_name: '',
    weight: 50,
  }
}

function cloneRotationPoolItems(items: RotationPoolItem[] = []) {
  return items.map((item) => ({
    channel_id: Number(item.channel_id || 0),
    channel_name: String(item.channel_name || '').trim(),
    weight: Math.max(1, Number(item.weight || 50)),
  }))
}

function cloneRotationPools() {
  return rotationPools.value.map((pool) => ({
    id: Number(pool.id || 0),
    pool_name: String(pool.pool_name || '').trim(),
    method_code: String(pool.method_code || '').trim(),
    method_name: String(pool.method_name || '').trim(),
    strategy: String(pool.strategy || 'sequential').trim() || 'sequential',
    strategy_label: String(pool.strategy_label || '').trim(),
    status_code: Number(pool.status_code) === 1 ? 1 : 0,
    items: cloneRotationPoolItems(pool.items || []),
    channel_count: Number(pool.channel_count || (pool.items || []).length),
  }))
}

function serializeRotationPools(pools: RotationPool[]) {
  return pools.map((pool) => ({
    id: Number(pool.id || 0),
    pool_name: String(pool.pool_name || '').trim(),
    method_code: String(pool.method_code || '').trim(),
    strategy: String(pool.strategy || 'sequential').trim() || 'sequential',
    status_code: Number(pool.status_code) === 1 ? 1 : 0,
    items: cloneRotationPoolItems(pool.items || []).map((item) => ({
      channel_id: Number(item.channel_id || 0),
      weight: Math.max(1, Number(item.weight || 50)),
    })),
  }))
}

function rotationPayloadForSave(pools: RotationPool[]) {
  const currentRotation = channelData.value.rotation || {}

  return {
    enabled: pools.some((pool) => Number(pool.status_code) === 1),
    strategy: String(currentRotation.strategy || 'priority'),
    fallback_channel: String(currentRotation.fallback_channel || ''),
    remark: String(currentRotation.remark || ''),
    pools: serializeRotationPools(pools),
  }
}

async function persistRotationPools(pools: RotationPool[], successMessage: string) {
  const resp = await saveUserChannelRotation(rotationPayloadForSave(pools))
  if (resp.code === 0 && resp.data) {
    applyChannelData(resp.data)
    ElMessage.success(successMessage || resp.message || '已保存')
    return true
  }

  return false
}

function resetRotationPoolForm() {
  Object.assign(rotationPoolForm, {
    id: 0,
    pool_name: '',
    method_code: '',
    strategy: 'sequential',
    status_code: 1,
  })
}

function openRotationCreate() {
  resetRotationPoolForm()
  rotationDialogVisible.value = true
}

function editRotationPool(pool: RotationPool) {
  Object.assign(rotationPoolForm, {
    id: Number(pool.id || 0),
    pool_name: String(pool.pool_name || ''),
    method_code: String(pool.method_code || ''),
    strategy: String(pool.strategy || 'sequential'),
    status_code: Number(pool.status_code) === 1 ? 1 : 0,
  })
  rotationDialogVisible.value = true
}

function openRotationChannels(pool: RotationPool) {
  rotationChannelsForm.id = Number(pool.id || 0)
  rotationChannelsForm.pool_name = String(pool.pool_name || '')
  rotationChannelsForm.method_code = String(pool.method_code || '')
  rotationChannelsForm.items = cloneRotationPoolItems(pool.items || [])
  if (!rotationChannelsForm.items.length) {
    rotationChannelsForm.items = [createRotationPoolItem()]
  }
  rotationChannelsDialogVisible.value = true
}

function addRotationChannelItem() {
  const availableCount = rotationAvailableChannels.value.length
  if (rotationChannelsForm.items.length >= availableCount && availableCount > 0) {
    ElMessage.warning('当前支付方式下没有更多可选通道。')
    return
  }

  rotationChannelsForm.items = [...rotationChannelsForm.items, createRotationPoolItem()]
}

function removeRotationChannelItem(index: number) {
  rotationChannelsForm.items = rotationChannelsForm.items.filter((_, itemIndex) => itemIndex !== index)
  if (!rotationChannelsForm.items.length) {
    rotationChannelsForm.items = [createRotationPoolItem()]
  }
}

function rotationChannelOptionsForRow(index: number) {
  const currentId = Number(rotationChannelsForm.items[index]?.channel_id || 0)
  const occupied = new Set(
    rotationChannelsForm.items
      .map((item, itemIndex) => (itemIndex === index ? 0 : Number(item.channel_id || 0)))
      .filter((id) => id > 0),
  )

  return rotationAvailableChannels.value.filter((item) => {
    const channelId = Number(item.id || 0)
    return channelId === currentId || !occupied.has(channelId)
  })
}

async function submitRotationPool() {
  const poolName = String(rotationPoolForm.pool_name || '').trim()
  const methodCode = String(rotationPoolForm.method_code || '').trim()
  if (!poolName) {
    ElMessage.warning('请输入轮询池名称。')
    return
  }
  if (!methodCode) {
    ElMessage.warning('请选择支付方式。')
    return
  }

  const pools = cloneRotationPools()
  const currentIndex = pools.findIndex((pool) => Number(pool.id) === Number(rotationPoolForm.id))
  const currentPool = currentIndex >= 0 ? pools[currentIndex] : null
  const methodChanged = currentPool && normalizeMethodCode(currentPool.method_code) !== normalizeMethodCode(methodCode)
  const nextPool: RotationPool = {
    id: Number(rotationPoolForm.id || 0),
    pool_name: poolName,
    method_code: methodCode,
    method_name: methodName(methodCode),
    strategy: String(rotationPoolForm.strategy || 'sequential'),
    strategy_label: rotationStrategyLabel(String(rotationPoolForm.strategy || 'sequential')),
    status_code: Number(rotationPoolForm.status_code) === 1 ? 1 : 0,
    items: methodChanged ? [] : cloneRotationPoolItems(currentPool?.items || []),
    channel_count: methodChanged ? 0 : Number(currentPool?.items?.length || 0),
  }

  if (currentIndex >= 0) {
    pools.splice(currentIndex, 1, nextPool)
  } else {
    pools.unshift(nextPool)
  }

  const saved = await persistRotationPools(pools, currentIndex >= 0 ? '轮询池已更新。' : '轮询池已创建。')
  if (saved) {
    rotationDialogVisible.value = false
  }
}

async function submitRotationChannels() {
  const items = cloneRotationPoolItems(rotationChannelsForm.items || []).filter((item) => Number(item.channel_id) > 0)
  const pools = cloneRotationPools()
  const currentIndex = pools.findIndex((pool) => Number(pool.id) === Number(rotationChannelsForm.id))
  if (currentIndex < 0) {
    return
  }

  const nextPool = {
    ...pools[currentIndex],
    items,
    channel_count: items.length,
  }
  pools.splice(currentIndex, 1, nextPool)

  const saved = await persistRotationPools(pools, '轮询池通道已保存。')
  if (saved) {
    rotationChannelsDialogVisible.value = false
  }
}

async function toggleRotationPool(pool: RotationPool) {
  const pools = cloneRotationPools()
  const currentIndex = pools.findIndex((item) => Number(item.id) === Number(pool.id))
  if (currentIndex < 0) {
    return
  }

  pools[currentIndex] = {
    ...pools[currentIndex],
    status_code: Number(pool.status_code) === 1 ? 0 : 1,
  }
  await persistRotationPools(pools, Number(pool.status_code) === 1 ? '轮询池已关闭。' : '轮询池已启用。')
}

async function removeRotationPool(pool: RotationPool) {
  await ElMessageBox.confirm('确认删除轮询池 ' + pool.pool_name + ' 吗？', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })

  const pools = cloneRotationPools().filter((item) => Number(item.id) !== Number(pool.id))
  await persistRotationPools(pools, '轮询池已删除。')
}

function resetChannelForm() {
  Object.assign(channelForm, {
    id: 0,
    channel_name: '',
    method_code: '',
    plugin_code: '',
    daily_limit: '',
    daily_count_limit: '',
    single_min_amount: '',
    single_max_amount: '',
    rate: '0.85',
    display_value: '',
    remark: '',
    status_code: 1,
  })
}

function fillChannelForm(item: Record<string, any>) {
  Object.assign(channelForm, {
    id: item.id,
    channel_name: item.channel_name || '',
    method_code: item.method_code || '',
    plugin_code: item.plugin_code || '',
    daily_limit: item.daily_limit || '',
    daily_count_limit: item.daily_count_limit || '',
    single_min_amount: item.single_min_amount || '',
    single_max_amount: item.single_max_amount || '',
    rate: String(item.rate || '0.85').replace('%', ''),
    display_value: item.display_value || '',
    remark: item.remark || '',
    status_code: Number(item.status_code) === 1 ? 1 : 0,
  })
}

function openCreate() {
  resetChannelForm()
  channelDialogVisible.value = true
}

function editChannel(item: Record<string, any>) {
  fillChannelForm(item)
  channelDialogVisible.value = true
}

async function openConfig(item: Record<string, any>) {
  Object.assign(configForm, {
    id: item.id,
    channel_name: item.channel_name || item.method_name || '',
    method_code: item.method_code || '',
    plugin_code: item.plugin_code || '',
    rate: String(item.rate || '0.85').replace('%', ''),
    display_value: item.display_value || '',
    remark: item.remark || '',
    status_code: Number(item.status_code) === 1 ? 1 : 0,
    daily_limit: item.daily_limit || '',
    daily_count_limit: item.daily_count_limit || '',
    single_min_amount: item.single_min_amount || '',
    single_max_amount: item.single_max_amount || '',
    plugin_config: { ...(item.plugin_config || {}) },
  })
  ensureConfigDefaults(selectedConfigPlugin.value, true)
  syncAlipayCkPanelFromConfig()
  configDialogVisible.value = true

  if (!isAlipayCkConfig.value) {
    return
  }

  if (String(configForm.plugin_config?.login_id || '').trim()) {
    await loadAlipayCkStatus()
    return
  }

  await loadAlipayCkQrcode()
}

function openTestDialog(item: Record<string, any>) {
  Object.assign(testForm, {
    id: item.id,
    title: `${item.channel_name || item.method_name || item.channel} 测试订单`,
    amount: '1.00',
  })
  testDialogVisible.value = true
}

function ensureConfigDefaults(plugin: ChannelPlugin | null, preserveExisting = true) {
  const nextConfig: Record<string, any> = preserveExisting
    ? { ...(configForm.plugin_config || {}) }
    : {}

  const defaults = plugin?.default_settings || {}
  const schema = Array.isArray(plugin?.settings_schema) ? plugin?.settings_schema || [] : []

  for (const field of schema) {
    const key = String(field.key || '')
    if (!key) continue
    if (!Object.prototype.hasOwnProperty.call(nextConfig, key)) {
      nextConfig[key] = normalizeSchemaDefault(field, defaults, nextConfig[key])
    }
  }

  configForm.plugin_config = nextConfig
}

watch(
  () => channelForm.method_code,
  (next, prev) => {
    if (!next) {
      channelForm.plugin_code = ''
      return
    }

    if (prev && next !== prev) {
      channelForm.plugin_code = ''
    }
  },
)

watch(
  () => selectedConfigPlugin.value?.code || '',
  () => {
    ensureConfigDefaults(selectedConfigPlugin.value, true)
    syncAlipayCkPanelFromConfig()
  },
)

watch(
  () => configSchema.value.map((field) => String(field.key || '')).join('|'),
  () => ensureConfigDefaults(selectedConfigPlugin.value, true),
)

watch(
  () => configDialogVisible.value,
  (visible) => {
    if (!visible) {
      resetAlipayCkPanel()
      return
    }

    if (isAlipayCkConfig.value) {
      scheduleAlipayCkPolling(800)
    }
  },
)

watch(
  () => alipayCkPanel.status,
  (status) => {
    if (!configDialogVisible.value || !isAlipayCkConfig.value) {
      stopAlipayCkPolling()
      return
    }

    if (shouldPollAlipayCkStatus(String(status || ''))) {
      scheduleAlipayCkPolling(status === 'pending_confirm' ? 1000 : 1600)
      return
    }

    stopAlipayCkPolling()
  },
)

onBeforeUnmount(() => {
  stopAlipayCkPolling()
})

function syncDisplayValueFromConfig() {
  const config = configForm.plugin_config || {}
  for (const key of ['payment_address', 'display_value', 'qrcode_url', 'address', 'url', 'link']) {
    const value = String(config[key] ?? '').trim()
    if (value) {
      configForm.display_value = value
      return
    }
  }
}

function baseChannelPayload(source: Record<string, any>) {
  return {
    id: Number(source.id || 0),
    channel_name: source.channel_name,
    method_code: source.method_code,
    plugin_code: source.plugin_code,
    daily_limit: source.daily_limit,
    daily_count_limit: source.daily_count_limit,
    single_min_amount: source.single_min_amount,
    single_max_amount: source.single_max_amount,
    rate: source.rate || '0.85',
    display_value: source.display_value || '',
    remark: source.remark || '',
    status_code: Number(source.status_code) === 1 ? 1 : 0,
  }
}

function selectPaymentTemplate(code: string) {
  paymentForm.template = normalizePaymentTemplate(code)
}

function appendPaymentVariable(field: 'voice_content' | 'cashier_notice', token: string) {
  const current = String(paymentForm[field] || '')
  paymentForm[field] = current ? `${current}${token}` : token
}

function normalizePaymentTemplate(code: string) {
  const normalized = String(code || '').trim()
  if (!normalized) {
    return 'nexpay-standard'
  }

  return paymentTemplateCodeAliasMap[normalized] || 'nexpay-standard'
}

function normalizePaymentVariableText(content: string) {
  return Object.entries(paymentVariableTokenAliasMap).reduce(
    (current, [legacyToken, systemToken]) => current.split(legacyToken).join(systemToken),
    String(content || ''),
  )
}

function normalizePaymentFormState() {
  paymentForm.template = normalizePaymentTemplate(String(paymentForm.template || ''))
  paymentForm.voice_content = normalizePaymentVariableText(String(paymentForm.voice_content || ''))
  paymentForm.cashier_notice = normalizePaymentVariableText(String(paymentForm.cashier_notice || ''))
}

function renderPaymentVariablePreview(content: string) {
  const normalized = normalizePaymentVariableText(String(content || '')).trim() || paymentDefaultVoiceTemplate
  return paymentVariableOptions.reduce(
    (current, item) => current.split(item.token).join(item.sample),
    normalized,
  )
}

async function submitChannel() {
  const resp = await saveUserChannel(baseChannelPayload(channelForm))
  if (resp.code === 0) {
    ElMessage.success(channelForm.id ? '通道已更新' : '通道添加成功')
    channelDialogVisible.value = false
    await load()
  }
}

async function submitConfig() {
  syncDisplayValueFromConfig()
  const resp = await saveUserChannel({
    ...baseChannelPayload(configForm),
    plugin_config: configForm.plugin_config,
    validate_plugin_config: true,
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '插件配置已保存')
    configDialogVisible.value = false
    await load()
  }
}

async function submitPaymentSettings() {
  normalizePaymentFormState()
  const resp = await saveUserPaymentSettings({
    ...paymentForm,
    template: normalizePaymentTemplate(String(paymentForm.template || '')),
    voice_content: normalizePaymentVariableText(String(paymentForm.voice_content || '')),
    cashier_notice: normalizePaymentVariableText(String(paymentForm.cashier_notice || '')),
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '已保存')
    await load()
  }
}

async function submitTest() {
  const paymentWindow = window.open('about:blank', '_blank')
  if (paymentWindow) {
    paymentWindow.document.open()
    paymentWindow.document.write('<title>通道测试支付</title><p style="font-family:Microsoft YaHei,Segoe UI,sans-serif;padding:24px;color:#1f2c45;">测试订单创建中，正在跳转支付页面...</p>')
    paymentWindow.document.close()
  }

  const resp = await testUserChannel({
    id: testForm.id,
    amount: testForm.amount,
    subject: testForm.title,
  })

  if (resp.code === 0 && resp.data?.pay_url) {
    testDialogVisible.value = false
    if (paymentWindow && !paymentWindow.closed) {
      paymentWindow.location.replace(String(resp.data.pay_url))
      paymentWindow.focus?.()
      return
    }

    ElMessage.warning('浏览器拦截了新窗口，请允许弹窗后重试')
    return
  }

  if (paymentWindow && !paymentWindow.closed) {
    paymentWindow.close()
  }

  ElMessage.error(resp.message || '测试订单创建失败')
}

async function changeStatus(item: Record<string, any>) {
  const nextStatus = Number(item.status_code) === 1 ? 0 : 1
  const resp = await toggleUserChannel(item.id, nextStatus)
  if (resp.code === 0) {
    ElMessage.success(nextStatus === 1 ? '通道已启用' : '通道已关闭')
    await load()
  }
}

async function removeChannel(item: Record<string, any>) {
  await ElMessageBox.confirm('确认删除通道 ' + (item.channel_name || item.method_name || item.channel) + ' 吗？', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })

  const resp = await deleteUserChannel(item.id)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '已删除')
    await load()
  }
}

function fieldType(field: Record<string, any>) {
  const type = String(field.type || 'text').toLowerCase()
  if (['textarea', 'number', 'password', 'select', 'image', 'file', 'radio', 'checkbox', 'html'].includes(type)) {
    return type
  }
  return 'text'
}

function fieldReadonly(field: Record<string, any>) {
  return Boolean(field?.readonly)
}

function uploadFieldButtonText(field: Record<string, any>) {
  const type = fieldType(field)
  if (type === 'image') return '上传图片'
  if (type === 'file') return '上传文件'
  return '上传'
}

function fieldAccept(field: Record<string, any>) {
  return String(field.accept || '.jpg,.jpeg,.png,.gif,.webp,.bmp')
}

function uploadHint(field: Record<string, any>) {
  return String(field.note || '')
}

function uploadPreviewValue(fieldKey: string) {
  return String(configForm.plugin_config?.[fieldKey] ?? '')
}

async function handleConfigFileChange(field: Record<string, any>, event: Event) {
  const input = event.target as HTMLInputElement | null
  const file = input?.files?.[0]
  if (!file) return

  const fieldKey = String(field.key || '').trim()
  if (!fieldKey) return

  configUploadLoading[fieldKey] = true

  try {
    const resp = await uploadUserChannelConfigFile({
      id: Number(configForm.id || 0),
      method_code: String(configForm.method_code || ''),
      plugin_code: String(configForm.plugin_code || ''),
      field_key: fieldKey,
      plugin_config: { ...(configForm.plugin_config || {}) },
      file,
    })

    if (resp.code === 0 && resp.data) {
      configForm.plugin_config = {
        ...(configForm.plugin_config || {}),
        ...(resp.data.plugin_config || {}),
      }
      syncDisplayValueFromConfig()
      ElMessage.success(resp.message || '上传成功')
    } else {
      ElMessage.error(resp.message || '上传失败')
    }
  } catch {
    ElMessage.error('上传失败')
  } finally {
    configUploadLoading[fieldKey] = false
    if (input) {
      input.value = ''
    }
  }
}

function normalizeOptions(options: any): Array<{ label: string; value: string }> {
  return normalizeSchemaOptions(options)
}

onMounted(load)
</script>

<template>
  <section class="page-stack">
    <section v-if="activeSection === 'settings'" class="payment-settings-page">
      <header class="payment-settings-page__header">
        <h1 class="payment-settings-page__title">支付设置</h1>
      </header>

      <article class="payment-settings-panel">
        <header class="payment-settings-panel__head">
          <h2 class="payment-settings-panel__title">支付模板</h2>
        </header>
        <div class="payment-settings-panel__body payment-settings-panel__body--templates">
          <div class="payment-template-grid">
            <button
              v-for="item in paymentTemplateOptions"
              :key="item.code"
              class="payment-template-card"
              :class="{ 'payment-template-card--selected': paymentForm.template === item.code }"
              type="button"
              :aria-pressed="paymentForm.template === item.code"
              @click="selectPaymentTemplate(item.code)"
            >
              <span class="payment-template-card__preview">
                <img :src="item.preview" :alt="item.title" />
              </span>
              <span class="payment-template-card__meta">
                <span class="payment-template-card__name">{{ item.title }}</span>
                <span v-if="paymentForm.template === item.code" class="payment-template-card__badge">已选择</span>
              </span>
            </button>
          </div>
        </div>
      </article>

      <article class="payment-settings-panel">
        <header class="payment-settings-panel__head">
          <h2 class="payment-settings-panel__title">收款语音设置</h2>
        </header>
        <div class="payment-settings-panel__body">
          <label class="payment-setting-field">
            <span class="payment-setting-field__label">收款语音</span>
            <span class="payment-switch-row">
              <span class="payment-switch-copy" :class="{ 'payment-switch-copy--active': !paymentForm.voice_enabled }">关闭</span>
              <el-switch
                v-model="paymentForm.voice_enabled"
                class="payment-switch"
                :active-value="true"
                :inactive-value="false"
              />
              <span class="payment-switch-copy" :class="{ 'payment-switch-copy--active': paymentForm.voice_enabled }">开启</span>
            </span>
          </label>

          <label class="payment-setting-field">
            <span class="payment-setting-field__label">语音提示内容</span>
            <input
              v-model="paymentForm.voice_content"
              type="text"
              placeholder="请输入语音模板，支持 {{product_name}} 等系统变量"
            />
          </label>

          <div class="payment-variable-block">
            <span class="payment-variable-block__label">系统变量：</span>
            <div class="payment-variable-block__chips">
              <button
                v-for="item in paymentVariableOptions"
                :key="`voice-${item.label}`"
                class="payment-variable-chip"
                type="button"
                @click="appendPaymentVariable('voice_content', item.token)"
              >
                {{ item.label }}
              </button>
            </div>
          </div>

          <div class="payment-example-box">
            示例: "{{ paymentVoicePreview }}"
          </div>
        </div>
      </article>

      <article class="payment-settings-panel">
        <header class="payment-settings-panel__head">
          <h2 class="payment-settings-panel__title">收银提醒设置</h2>
        </header>
        <div class="payment-settings-panel__body">
          <label class="payment-setting-field">
            <span class="payment-setting-field__label">自定义收银提醒内容</span>
            <textarea
              v-model="paymentForm.cashier_notice"
              rows="6"
              placeholder="可输入基础 HTML 标签，并插入 {{platform_order_no}} 等系统变量"
            />
          </label>

          <div class="payment-variable-block">
            <span class="payment-variable-block__label">系统变量：</span>
            <div class="payment-variable-block__chips">
              <button
                v-for="item in paymentVariableOptions"
                :key="`notice-${item.label}`"
                class="payment-variable-chip"
                type="button"
                @click="appendPaymentVariable('cashier_notice', item.token)"
              >
                {{ item.label }}
              </button>
            </div>
          </div>

          <p class="payment-helper-copy">此内容将显示在收银台页面，支持基础 HTML 标签与系统变量</p>
        </div>
      </article>

      <div class="payment-settings-page__actions">
        <button class="primary-btn" type="button" @click="submitPaymentSettings">保存支付设置</button>
      </div>
    </section>

    <article v-else class="metric-card settings-panel settings-workspace" :class="{ 'settings-workspace--rotation': activeSection === 'rotation' }">
      <div v-if="activeSection === 'list'" class="channel-toolbar">
        <label class="channel-search">
          <el-icon class="channel-search__icon"><Search /></el-icon>
          <input v-model="channelKeyword" type="text" placeholder="搜索通道名称 / 支付方式 / 插件 / 通道 ID" />
        </label>
        <button class="primary-btn" type="button" @click="openCreate">新增通道</button>
      </div>

      <div v-else-if="false" class="channel-toolbar channel-toolbar--actions">
        <button class="primary-btn" type="button" @click="submitPaymentSettings">保存设置</button>
      </div>

      <div v-if="activeSection === 'list'" class="table-wrap settings-workspace__body">
        <div class="table-head channel-grid">
          <span>序号</span>
          <span>通道名称</span>
          <span>支付方式</span>
          <span>支付插件</span>
          <span>单日限额</span>
          <span>单日限笔</span>
          <span>单笔最小</span>
          <span>单笔最大</span>
          <span>状态</span>
          <span>操作</span>
        </div>
        <div v-for="(item, index) in visibleChannels" :key="item.id" class="table-row channel-grid">
          <strong>{{ index + 1 }}</strong>
          <div class="stack-cell">
            <strong>{{ item.channel_name || item.method_name || item.channel }}</strong>
            <small>ID {{ item.id }}</small>
          </div>
          <span>{{ item.method_name || methodName(item.method_code) }}</span>
          <span>{{ item.plugin_name || pluginName(item.plugin_code) }}</span>
          <span>{{ item.daily_limit || '0.00' }}</span>
          <span>{{ item.daily_count_limit || 0 }}</span>
          <span>{{ item.single_min_amount || '0.00' }}</span>
          <span>{{ item.single_max_amount || '0.00' }}</span>
          <span>
            <span class="status-chip" :class="statusClass(item)">{{ Number(item.status_code) === 1 ? '启用' : '关闭' }}</span>
          </span>
          <div class="inline-actions">
            <button class="link-action" type="button" @click="openTestDialog(item)">测试</button>
            <button class="link-action" type="button" @click="openConfig(item)">配置</button>
            <button class="link-action" type="button" @click="editChannel(item)">编辑</button>
            <button class="link-action" type="button" @click="changeStatus(item)">
              {{ Number(item.status_code) === 1 ? '关闭' : '启用' }}
            </button>
            <button class="link-action danger-text" type="button" @click="removeChannel(item)">删除</button>
          </div>
        </div>
        <p v-if="!loading && !visibleChannels.length && sortedChannels.length" class="empty-note">未找到匹配的通道。</p>
        <p v-if="!loading && !sortedChannels.length" class="empty-note">暂无已配置通道。</p>
      </div>

      <div v-else-if="activeSection === 'rotation'" class="rotation-stack settings-workspace__body">
        <section class="rotation-panel">
          <header class="rotation-panel__head">
            <h3 class="rotation-panel__title">轮询池管理</h3>
          </header>

          <div class="rotation-panel__body">
            <div class="rotation-tip">
              <span class="rotation-tip__label">温馨提示</span>
              <ul class="rotation-tip__list">
                <li>轮询池用于管理支付通道的轮询策略。</li>
                <li>如果同一种支付方式有多个可用的轮询组，系统将随机选择其中一个进行使用。</li>
              </ul>
            </div>

            <div class="rotation-panel__actions">
              <button class="primary-btn" type="button" @click="openRotationCreate">
                <el-icon><Plus /></el-icon>
                <span>添加轮询池</span>
              </button>
            </div>
          </div>
        </section>

        <div v-if="rotationPools.length" class="table-wrap rotation-table">
          <div class="table-head rotation-grid">
            <span>轮询池名称</span>
            <span>支付方式</span>
            <span>轮询方式</span>
            <span>已配通道</span>
            <span>状态</span>
            <span>操作</span>
          </div>
          <div v-for="pool in rotationPools" :key="pool.id" class="table-row rotation-grid">
            <div class="stack-cell">
              <strong>{{ pool.pool_name }}</strong>
              <small>ID {{ pool.id }}</small>
            </div>
            <span>{{ pool.method_name || methodName(pool.method_code) }}</span>
            <span>{{ pool.strategy_label || rotationStrategyLabel(pool.strategy) }}</span>
            <span>{{ pool.channel_count || pool.items.length }} 个通道</span>
            <span>
              <span class="status-chip" :class="Number(pool.status_code) === 1 ? 'success' : 'muted'">
                {{ Number(pool.status_code) === 1 ? '启用' : '关闭' }}
              </span>
            </span>
            <div class="rotation-actions">
              <button class="rotation-link" type="button" @click="openRotationChannels(pool)">
                <el-icon><Setting /></el-icon>
                <span>通道管理</span>
              </button>
              <button class="rotation-link rotation-link--edit" type="button" @click="editRotationPool(pool)">
                <el-icon><EditPen /></el-icon>
                <span>编辑</span>
              </button>
              <button class="rotation-link rotation-link--danger" type="button" @click="removeRotationPool(pool)">
                <el-icon><Delete /></el-icon>
                <span>删除</span>
              </button>
              <button class="rotation-link" type="button" @click="toggleRotationPool(pool)">
                <el-icon><SwitchButton /></el-icon>
                <span>{{ Number(pool.status_code) === 1 ? '关闭' : '开启' }}</span>
              </button>
            </div>
          </div>
        </div>

        <div v-else class="rotation-empty">
          <el-icon class="rotation-empty__icon"><Box /></el-icon>
          <strong class="rotation-empty__title">暂无轮询池数据</strong>
          <button class="primary-btn" type="button" @click="openRotationCreate">
            <el-icon><Plus /></el-icon>
            <span>创建第一个轮询池</span>
          </button>
        </div>
      </div>

      <div v-else class="settings-block settings-workspace__body">
        <div class="settings-block-head">
          <h3 class="settings-block-title">支付设置</h3>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">支付页风格</span>
            <select v-model="paymentForm.template">
              <option value="nexpay-standard">NexPay 标准版</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">自动跳转</span>
            <select v-model="paymentForm.auto_redirect">
              <option :value="true">启用</option>
              <option :value="false">停用</option>
            </select>
          </label>
        </div>
      </div>
    </article>

    <el-dialog v-model="channelDialogVisible" :title="channelForm.id ? '编辑通道' : '新增通道'" width="760px">
      <div class="dialog-form">
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">通道名称</span>
            <input v-model="channelForm.channel_name" type="text" />
          </label>
          <label class="field">
            <span class="field-label">支付方式</span>
            <select v-model="channelForm.method_code">
              <option value="">请选择支付方式</option>
              <option v-for="method in methods" :key="method.code" :value="method.code">{{ method.name }}</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">支付插件</span>
            <select v-model="channelForm.plugin_code" :disabled="!channelForm.method_code">
              <option value="">{{ channelForm.method_code ? '请选择支付插件' : '请先选择支付方式' }}</option>
              <option v-for="plugin in filteredChannelPlugins" :key="plugin.code" :value="plugin.code">{{ plugin.name }}</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">单日限额</span>
            <input v-model="channelForm.daily_limit" type="number" min="0" step="0.01" />
          </label>
          <label class="field">
            <span class="field-label">单日限笔</span>
            <input v-model="channelForm.daily_count_limit" type="number" min="0" step="1" />
          </label>
          <label class="field">
            <span class="field-label">单笔最小</span>
            <input v-model="channelForm.single_min_amount" type="number" min="0" step="0.01" />
          </label>
          <label class="field">
            <span class="field-label">单笔最大</span>
            <input v-model="channelForm.single_max_amount" type="number" min="0" step="0.01" />
          </label>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="channelDialogVisible = false">取消</button>
        <button class="primary-btn" type="button" @click="submitChannel">提交</button>
      </template>
    </el-dialog>

    <el-dialog v-model="configDialogVisible" :title="`${configForm.channel_name || '通道'}配置`" width="760px">
      <div class="dialog-form channel-dialog">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">{{ pluginName(configForm.plugin_code) }}</h3>
          </div>

          <div v-if="isAlipayCkConfig" class="alipay-ck-panel">
            <div class="alipay-ck-panel__head">
              <div class="alipay-ck-panel__summary">
                <div class="alipay-ck-panel__title-row">
                  <span class="alipay-ck-panel__title">支付宝 CK 登录</span>
                  <span class="status-chip" :class="alipayCkPanel.status_tone">{{ alipayCkPanel.status_label }}</span>
                </div>
                <p class="alipay-ck-panel__message">{{ alipayCkPanel.message }}</p>
              </div>
              <div class="alipay-ck-panel__actions">
                <button class="ghost-btn" type="button" :disabled="alipayCkLoading.qrcode" @click="loadAlipayCkQrcode">
                  {{ alipayCkLoading.qrcode ? '刷新中...' : '刷新二维码' }}
                </button>
                <button class="ghost-btn" type="button" :disabled="alipayCkLoading.status" @click="checkAlipayCkStatus">
                  {{ alipayCkLoading.status ? '检查中...' : '检查状态' }}
                </button>
              </div>
            </div>

            <div class="alipay-ck-panel__body">
              <div class="alipay-ck-panel__meta">
                <div class="alipay-ck-panel__item">
                  <span class="alipay-ck-panel__label">通道名称</span>
                  <strong class="alipay-ck-panel__value">{{ configForm.channel_name || '-' }}</strong>
                </div>
                <div class="alipay-ck-panel__item">
                  <span class="alipay-ck-panel__label">当前状态</span>
                  <strong class="alipay-ck-panel__value">{{ alipayCkPanel.status_label || '未获取' }}</strong>
                </div>
                <div class="alipay-ck-panel__item">
                  <span class="alipay-ck-panel__label">支付宝 PID</span>
                  <strong class="alipay-ck-panel__value">{{ alipayCkPanel.account_pid || '登录成功后自动识别' }}</strong>
                </div>
                <div class="alipay-ck-panel__item">
                  <span class="alipay-ck-panel__label">最近更新</span>
                  <strong class="alipay-ck-panel__value">{{ alipayCkPanel.updated_at || '暂未更新' }}</strong>
                </div>
              </div>

              <div class="alipay-ck-panel__qrcode">
                <img v-if="alipayCkPanel.qr_image" :src="alipayCkPanel.qr_image" alt="支付宝 CK 登录二维码" />
                <div v-else class="alipay-ck-panel__placeholder">
                  点击“刷新二维码”生成支付宝 CK 登录二维码
                </div>
              </div>
            </div>
          </div>

          <div v-else-if="configSchema.length" class="field-grid compact">
            <label
              v-for="field in configSchema"
              :key="field.key"
              class="field"
              :class="{ 'field-span-2': ['textarea', 'html'].includes(fieldType(field)) }"
            >
              <span class="field-label">{{ field.label || field.key }}</span>

              <textarea
                v-if="fieldType(field) === 'textarea'"
                v-model="configForm.plugin_config[field.key]"
                rows="4"
                :readonly="fieldReadonly(field)"
              />

              <input
                v-else-if="fieldType(field) === 'number'"
                v-model="configForm.plugin_config[field.key]"
                type="number"
                :readonly="fieldReadonly(field)"
              />

              <input
                v-else-if="fieldType(field) === 'password'"
                v-model="configForm.plugin_config[field.key]"
                type="password"
                :readonly="fieldReadonly(field)"
              />

              <select
                v-else-if="fieldType(field) === 'select' || fieldType(field) === 'radio'"
                v-model="configForm.plugin_config[field.key]"
              >
                <option
                  v-for="option in normalizeOptions(field.options)"
                  :key="`${field.key}-${option.value}`"
                  :value="option.value"
                >
                  {{ option.label }}
                </option>
              </select>

              <select
                v-else-if="fieldType(field) === 'checkbox'"
                v-model="configForm.plugin_config[field.key]"
                :disabled="fieldReadonly(field)"
              >
                <option value="0">否</option>
                <option value="1">是</option>
              </select>

              <div v-else-if="fieldType(field) === 'image' || fieldType(field) === 'file'" class="upload-field">
                <div class="upload-field__row">
                  <input :value="uploadPreviewValue(field.key)" type="text" readonly />
                  <label class="ghost-btn upload-field__button">
                    <span>{{ configUploadLoading[field.key] ? '上传中...' : uploadFieldButtonText(field) }}</span>
                    <input
                      class="upload-field__input"
                      type="file"
                      :accept="fieldAccept(field)"
                      :disabled="Boolean(configUploadLoading[field.key])"
                      @change="handleConfigFileChange(field, $event)"
                    />
                  </label>
                </div>
                <small v-if="uploadHint(field)" class="upload-field__hint">{{ uploadHint(field) }}</small>
              </div>

              <div v-else-if="fieldType(field) === 'html'" class="field-html" v-html="String(field.note || field.label || '')" />

              <input v-else v-model="configForm.plugin_config[field.key]" type="text" :readonly="fieldReadonly(field)" />
            </label>
          </div>

          <div v-else class="info-box">
            当前插件没有额外配置项。
          </div>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="configDialogVisible = false">
          {{ isAlipayCkConfig ? '关闭' : '取消' }}
        </button>
        <button v-if="!isAlipayCkConfig" class="primary-btn" type="button" @click="submitConfig">保存配置</button>
      </template>
    </el-dialog>

    <el-dialog v-model="testDialogVisible" title="通道测试" width="480px">
      <div class="dialog-form">
        <label class="field">
          <span class="field-label">订单标题</span>
          <input v-model="testForm.title" type="text" />
        </label>
        <label class="field">
          <span class="field-label">测试金额</span>
          <input v-model="testForm.amount" type="number" min="0.01" step="0.01" />
        </label>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="testDialogVisible = false">取消</button>
        <button class="primary-btn" type="button" @click="submitTest">立即测试</button>
      </template>
    </el-dialog>

    <el-dialog v-model="rotationDialogVisible" :title="rotationPoolForm.id ? '编辑轮询池' : '新增轮询池'" width="560px">
      <div class="rotation-modal">
        <label class="rotation-modal__field">
          <span class="rotation-modal__label">
            <span class="rotation-modal__required">*</span>
            <span>轮询池名称</span>
          </span>
          <input v-model="rotationPoolForm.pool_name" type="text" placeholder="请输入名称" />
        </label>

        <label class="rotation-modal__field">
          <span class="rotation-modal__label">
            <span class="rotation-modal__required">*</span>
            <span>支付方式</span>
          </span>
          <select v-model="rotationPoolForm.method_code">
            <option value="">请选择</option>
            <option v-for="method in rotationMethodOptions" :key="method.code" :value="method.code">{{ method.name }}</option>
          </select>
        </label>

        <label class="rotation-modal__field">
          <span class="rotation-modal__label">
            <span class="rotation-modal__required">*</span>
            <span>轮询方式</span>
          </span>
          <select v-model="rotationPoolForm.strategy">
            <option v-for="option in rotationStrategyOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>

        <label class="rotation-modal__field">
          <span class="rotation-modal__label">
            <span class="rotation-modal__required">*</span>
            <span>状态</span>
          </span>
          <el-switch v-model="rotationPoolForm.status_code" :active-value="1" :inactive-value="0" />
        </label>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="rotationDialogVisible = false">取消</button>
        <button class="primary-btn" type="button" @click="submitRotationPool">保存</button>
      </template>
    </el-dialog>

    <el-dialog v-model="rotationChannelsDialogVisible" title="通道管理" width="620px">
      <div class="rotation-manage">
        <div class="rotation-manage__header">
          <span>选择通道</span>
          <span>权重</span>
          <span>操作</span>
        </div>

        <div v-for="(item, index) in rotationChannelsForm.items" :key="`${rotationChannelsForm.id}-${index}`" class="rotation-manage__row">
          <select v-model="item.channel_id">
            <option value="0">请选择</option>
            <option
              v-for="channel in rotationChannelOptionsForRow(index)"
              :key="channel.id"
              :value="Number(channel.id)"
            >
              {{ channel.channel_name || channel.method_name || channel.channel }}
            </option>
          </select>

          <input v-model="item.weight" type="number" min="1" step="1" />

          <button class="ghost-btn rotation-manage__delete" type="button" @click="removeRotationChannelItem(index)">删除</button>
        </div>

        <div class="rotation-manage__actions">
          <button class="ghost-btn" type="button" @click="addRotationChannelItem">添加</button>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="rotationChannelsDialogVisible = false">取消</button>
        <button class="primary-btn" type="button" @click="submitRotationChannels">保存</button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.payment-settings-page {
  display: grid;
  gap: 14px;
}

.payment-settings-page__header {
  display: none;
}

.payment-settings-page__title {
  margin: 0;
  color: #1f2d3d;
  font-size: 20px;
  font-weight: 600;
}

.payment-settings-page__actions {
  display: flex;
  justify-content: flex-end;
  padding: 0;
}

.payment-settings-panel {
  overflow: hidden;
  border: 1px solid rgba(219, 227, 238, 0.95);
  border-radius: 10px;
  background: #fff;
  box-shadow: none;
}

.payment-settings-panel__head {
  padding: 14px 16px 10px;
  border-bottom: 1px solid var(--brand-border);
  background: #fff;
}

.payment-settings-panel__title {
  margin: 0;
  color: #1f2d3d;
  font-size: 16px;
  font-weight: 500;
}

.payment-settings-panel__body {
  display: grid;
  gap: 18px;
  padding: 16px;
  background: #fff;
}

.payment-settings-panel__body--templates {
  gap: 0;
}

.payment-template-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 360px));
  justify-content: flex-start;
  gap: 14px;
}

.payment-template-card {
  display: grid;
  gap: 0;
  border: 1px solid #e1e9f5;
  border-radius: 10px;
  padding: 0;
  background: #fff;
  overflow: hidden;
  text-align: left;
  box-shadow: none;
}

.payment-template-card--selected {
  border-color: #4290ff;
  box-shadow: 0 0 0 1px rgba(66, 144, 255, 0.2);
}

.payment-template-card__preview {
  display: block;
  aspect-ratio: 1.56;
  background: #fff;
  overflow: hidden;
}

.payment-template-card__preview img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center top;
}

.payment-template-card__meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 10px 12px 12px;
  background: #fff;
}

.payment-template-card__name {
  color: #1f2d3d;
  font-size: 14px;
  font-weight: 500;
}

.payment-template-card__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 28px;
  padding: 0 12px;
  border-radius: 8px;
  background: #66c534;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
}

.payment-setting-field {
  display: grid;
  gap: 10px;
}

.payment-setting-field__label,
.payment-variable-block__label {
  color: #1f2d3d;
  font-size: 14px;
  font-weight: 500;
}

.payment-switch-row {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  width: fit-content;
}

.payment-switch-copy {
  color: #1f2d3d;
  font-size: 14px;
}

.payment-switch-copy--active {
  color: #1677ff;
}

.payment-switch-row :deep(.payment-switch .el-switch__core) {
  border-color: #d8e0ea;
  background: #d8e0ea;
}

.payment-switch-row :deep(.payment-switch.is-checked .el-switch__core) {
  border-color: #24cb63;
  background: #24cb63;
}

.payment-variable-block {
  display: grid;
  gap: 10px;
}

.payment-variable-block__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 10px;
}

.payment-variable-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 30px;
  padding: 0 14px;
  border: 1px solid #d8e0ea;
  border-radius: 8px;
  background: #fff;
  color: #42556f;
  font-size: 13px;
  font-weight: 500;
}

.payment-example-box {
  padding: 12px 14px;
  border-radius: 8px;
  background: #f7f9fc;
  color: #4d617d;
  font-size: 13px;
  line-height: 1.7;
}

.payment-helper-copy {
  margin: 0;
  color: #7a879c;
  font-size: 13px;
  line-height: 1.7;
}

.payment-settings-panel textarea {
  min-height: 112px;
  resize: vertical;
}

.channel-grid {
  display: grid;
  grid-template-columns: 0.45fr 1.1fr 0.82fr 1fr 0.72fr 0.68fr 0.72fr 0.72fr 0.76fr 1.54fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.upload-field {
  display: grid;
  gap: 8px;
}

.upload-field__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
  align-items: center;
}

.upload-field__button {
  position: relative;
  overflow: hidden;
  cursor: pointer;
  white-space: nowrap;
}

.upload-field__input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}

.upload-field__hint {
  color: #7a879c;
  font-size: 12px;
  line-height: 1.6;
}

.stack-cell {
  display: grid;
  gap: 4px;
}

.stack-cell small {
  color: #607089;
}

.channel-dialog {
  gap: 18px;
}

.alipay-ck-panel {
  display: grid;
  gap: 16px;
  margin-bottom: 16px;
  padding: 14px 16px;
  border: 1px solid #dbe6f5;
  border-radius: 9px;
  background: #f8fbff;
}

.alipay-ck-panel__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.alipay-ck-panel__summary {
  display: grid;
  gap: 8px;
  min-width: 0;
}

.alipay-ck-panel__title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.alipay-ck-panel__title {
  color: #355276;
  font-size: 14px;
  font-weight: 600;
}

.alipay-ck-panel__message {
  margin: 0;
  color: #51647f;
  font-size: 13px;
  line-height: 1.7;
}

.alipay-ck-panel__actions {
  display: inline-flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
}

.alipay-ck-panel__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 236px;
  gap: 16px;
  align-items: start;
}

.alipay-ck-panel__meta {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 16px;
}

.alipay-ck-panel__item {
  display: grid;
  gap: 4px;
  min-width: 0;
}

.alipay-ck-panel__label {
  color: #607089;
  font-size: 12px;
}

.alipay-ck-panel__value {
  color: #1f2d3d;
  font-size: 13px;
  font-weight: 500;
  line-height: 1.5;
  word-break: break-all;
}

.alipay-ck-panel__qrcode {
  display: grid;
  justify-items: center;
  gap: 10px;
  padding: 14px;
  border: 1px dashed #c8d8ef;
  border-radius: 8px;
  background: #fff;
}

.alipay-ck-panel__qrcode img {
  display: block;
  width: 100%;
  max-width: 208px;
  height: auto;
  border-radius: 6px;
}

.alipay-ck-panel__placeholder {
  display: grid;
  place-items: center;
  width: 100%;
  min-height: 208px;
  padding: 16px;
  color: #607089;
  font-size: 13px;
  line-height: 1.7;
  text-align: center;
}

.channel-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--brand-border);
  background: #fff;
}

.channel-toolbar--actions {
  justify-content: flex-end;
}

.channel-search {
  position: relative;
  width: min(380px, 100%);
}

.channel-search input {
  padding-left: 40px;
  background: #fff;
}

.channel-search__icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: #8ca1bd;
  font-size: 15px;
}

.rotation-stack {
  display: grid;
  gap: 0;
  background: #fff;
}

.rotation-panel {
  display: grid;
  background: transparent;
}

.rotation-panel__head {
  padding: 16px 18px 12px;
  border-bottom: 1px solid var(--brand-border);
  background: #fff;
}

.rotation-panel__title {
  margin: 0;
  color: #2f3f57;
  font-size: 16px;
  font-weight: 600;
}

.rotation-panel__body {
  display: grid;
  gap: 16px;
  padding: 16px 18px 18px;
  background: #fff;
}

.rotation-tip {
  display: grid;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 8px;
  background: #f7f9fc;
  color: #355276;
}

.rotation-tip__label {
  color: #7a8799;
  font-size: 13px;
  font-weight: 500;
}

.rotation-tip__list {
  display: grid;
  gap: 10px;
  margin: 0;
  padding-left: 20px;
  line-height: 1.65;
}

.rotation-panel__actions {
  display: flex;
  justify-content: flex-start;
}

.rotation-panel__actions .primary-btn,
.rotation-empty .primary-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.rotation-grid {
  display: grid;
  grid-template-columns: 1.15fr 0.86fr 0.78fr 0.8fr 0.68fr 1.7fr;
  gap: 16px;
  align-items: center;
  min-width: 0;
}

.rotation-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 20px;
  font-size: 14px;
}

.rotation-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border: 0;
  padding: 0;
  background: transparent;
  color: #2f6cff;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
}

.rotation-link :deep(svg) {
  width: 14px;
  height: 14px;
}

.rotation-link--edit {
  color: #19a35b;
}

.rotation-link--danger {
  color: #ff5a4f;
}

.rotation-empty {
  display: grid;
  justify-items: center;
  gap: 18px;
  padding: 56px 16px 60px;
  border-top: 1px solid var(--brand-border);
  background: #f8fafc;
  color: #6e7f96;
}

.rotation-empty__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 72px;
  height: 72px;
  color: #98a2b3;
  font-size: 72px;
  line-height: 1;
}

.rotation-empty__title {
  font-size: 16px;
  font-weight: 500;
  color: #5b6d86;
}

.rotation-modal {
  display: grid;
  gap: 24px;
  padding: 10px 2px 0;
}

.rotation-modal__field {
  display: grid;
  gap: 10px;
}

.rotation-modal__label {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #51647f;
  font-size: 14px;
}

.rotation-modal__required {
  color: #ff5d5d;
  font-size: 15px;
  line-height: 1;
}

.rotation-modal :deep(.el-switch.is-checked .el-switch__core) {
  background: #22c55e;
  border-color: #22c55e;
}

.rotation-manage {
  display: grid;
  gap: 18px;
  padding: 10px 2px 0;
}

.rotation-manage__header,
.rotation-manage__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 0.92fr 96px;
  gap: 12px;
  align-items: center;
}

.rotation-manage__header {
  color: #596b82;
  font-size: 14px;
}

.rotation-manage__actions {
  display: flex;
  justify-content: flex-start;
  padding-top: 4px;
}

.rotation-manage__delete {
  min-width: 0;
  padding: 0 18px;
}

.field-html {
  min-height: 44px;
  padding: 12px 14px;
  border: 1px solid #d8e4f5;
  border-radius: 8px;
  background: #f7fbff;
  color: #41536d;
  line-height: 1.7;
}

.danger-text {
  color: var(--brand-danger);
}

@media (max-width: 1200px) {
  .payment-template-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .channel-grid {
    grid-template-columns: 1fr;
    min-width: 0;
  }

  .rotation-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .payment-settings-page {
    gap: 18px;
  }

  .payment-settings-panel__head,
  .payment-settings-panel__body {
    padding: 16px;
  }

  .payment-template-grid {
    grid-template-columns: 1fr;
    gap: 16px;
  }

  .payment-variable-block__chips {
    gap: 10px 12px;
  }

  .payment-variable-chip {
    width: 100%;
    justify-content: center;
  }

  .payment-settings-page__actions {
    padding-inline: 0;
  }

  .channel-toolbar {
    flex-direction: column;
    align-items: stretch;
    padding: 16px;
  }

  .channel-search {
    width: 100%;
  }

  .rotation-panel__body {
    padding: 18px 16px;
  }

  .alipay-ck-panel {
    padding: 14px;
  }

  .alipay-ck-panel__head {
    align-items: flex-start;
    flex-direction: column;
  }

  .alipay-ck-panel__actions {
    width: 100%;
    justify-content: flex-start;
  }

  .alipay-ck-panel__body,
  .alipay-ck-panel__meta {
    grid-template-columns: 1fr;
  }

  .rotation-panel__head {
    padding: 16px;
  }

  .rotation-empty {
    padding: 56px 16px 60px;
  }

  .rotation-manage__header,
  .rotation-manage__row {
    grid-template-columns: 1fr;
  }

  .rotation-actions {
    gap: 12px;
  }
}
</style>

