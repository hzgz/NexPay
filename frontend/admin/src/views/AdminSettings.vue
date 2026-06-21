<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRoute } from 'vue-router'
import {
  clearAdminCache,
  deleteAdminAnnouncement,
  getAdminSettings,
  runAdminCleanup,
  saveAdminAnnouncement,
  saveAdminSettings,
  testAdminProvider,
  toggleAdminAnnouncement,
} from '../lib/api'

type ProviderItem = {
  code: string
  name: string
  group?: string
  kind?: string
  description?: string
  settings_schema?: Array<Record<string, any>>
  default_settings?: Record<string, any>
}

type ProviderBucket = 'mail' | 'sms' | 'oauth' | 'captcha' | 'geetest' | 'realname'

type ProviderField = {
  key: string
  label: string
  type: string
  options: any[]
  note?: string
  value?: any
}

type CleanupQuickAction = {
  key: string
  label: string
  copy: string
  action: string
  bundle?: string
  default_days?: number
}

type CleanupStoreItem = {
  store: string
  label: string
  copy: string
  default_days?: number
}

type CleanupWorkspace = {
  quick_actions: CleanupQuickAction[]
  stores: CleanupStoreItem[]
}

type QrProviderOption = {
  value: string
  label: string
  copy: string
}

type PaymentProviderOption = {
  value: string
  label: string
}

type PaymentMethodFormItem = {
  key: string
  enabled: boolean
  builtin: boolean
  code: string
  name: string
  icon: string
}

const route = useRoute()
const loading = ref(true)
const announcementDialog = ref(false)
const providerTestDialog = ref(false)
const providerTestLoading = ref(false)

const cleanupWorkspace = reactive<CleanupWorkspace>({
  quick_actions: [],
  stores: [],
})

const cleanupDays = reactive<Record<string, number>>({})
const cleanupPending = reactive<Record<string, boolean>>({})

const form = reactive<Record<string, any>>({
  basic: {},
  payment: {},
  merchant: {},
  oauth: {},
  verify: {},
  mail: {},
  telegram: {},
  sms: {},
  realname: {},
  upload: {},
  api: {},
  auth: {},
  announcements: [],
  provider_options: {},
})

const announcementForm = reactive({
  id: 0,
  title: '',
  summary: '',
  content: '',
  target: 'both',
  sort: 99,
})

const providerTestForm = reactive({
  type: 'mail',
  target: '',
})

const activeSection = computed(() => String(route.meta.section || 'basic'))
const providerOptions = computed<Record<string, { items: ProviderItem[]; selected?: ProviderItem | null; selected_code?: string }>>(
  () => form.provider_options || {}
)

const providerBindingMap: Record<ProviderBucket, { group: string; key: string }> = {
  mail: { group: 'mail', key: 'provider_code' },
  sms: { group: 'sms', key: 'provider_code' },
  oauth: { group: 'oauth', key: 'provider_code' },
  captcha: { group: 'auth', key: 'captcha_provider_code' },
  geetest: { group: 'verify', key: 'provider_code' },
  realname: { group: 'realname', key: 'provider' },
}

const hiddenProviderFieldKeys: Record<ProviderBucket, string[]> = {
  mail: [],
  sms: [],
  oauth: [],
  captcha: ['scene_login', 'scene_register', 'scene_forgot', 'scene_admin'],
  geetest: [],
  realname: [],
}

const authBooleanKeys = [
  'register_enabled',
  'captcha_enabled',
  'behavior_verify',
  'merchant_register_fee_enabled',
  'register_auto_audit',
  'require_realname_after_register',
  'merchant_login_captcha',
  'merchant_register_captcha',
  'merchant_forgot_captcha',
  'admin_login_captcha',
]

const verifyBooleanKeys = [
  'geetest_enabled',
  'geetest_scene_login',
  'geetest_scene_register',
  'geetest_scene_forgot',
  'geetest_scene_admin',
  'failback',
]

const realnameBooleanKeys = [
  'enabled',
  'auto_audit',
  'require_before_api',
]

const captchaSceneFields = [
  { key: 'merchant_login_captcha', label: '商户登录验证码' },
  { key: 'merchant_register_captcha', label: '商户注册验证码' },
  { key: 'merchant_forgot_captcha', label: '商户找回密码验证码' },
  { key: 'admin_login_captcha', label: '管理员登录验证码' },
]

const geetestSceneFields = [
  { key: 'geetest_scene_login', label: '商户登录极验' },
  { key: 'geetest_scene_register', label: '商户注册极验' },
  { key: 'geetest_scene_forgot', label: '商户找回密码极验' },
  { key: 'geetest_scene_admin', label: '管理员登录极验' },
]

const switchOptions = [
  { label: '开启', value: 'true' },
  { label: '关闭', value: 'false' },
]

const cleanupQuickActionMeta: Record<string, { label: string; copy: string }> = {
  runtime: {
    label: '系统运行缓存',
    copy: '清理 runtime/cache 和 runtime/views 下的运行缓存。',
  },
  assets: {
    label: '旧前端静态资源',
    copy: '清理后台与商户前端未被当前入口页引用的历史构建文件。',
  },
  trade_records: {
    label: '30 天前交易记录',
    copy: '订单、回调、退款、提现、代付和资金明细。',
  },
  runtime_logs: {
    label: '30 天前运行日志',
    copy: '商户操作、插件通知、服务商测试、实名审核和任务运行日志。',
  },
}

const cleanupStoreMeta: Record<string, { label: string; copy: string }> = {
  orders_local: { label: '订单记录', copy: '清理超过保留天数的订单主记录。' },
  callback_queue_local: { label: '回调记录', copy: '清理历史回调队列与回调结果记录。' },
  refunds_local: { label: '退款记录', copy: '清理超过保留天数的退款记录。' },
  settlements_local: { label: '提现记录', copy: '清理历史提现申请与审核记录。' },
  transfers_local: { label: '代付记录', copy: '清理超过保留天数的代付记录。' },
  fund_flows_local: { label: '资金明细', copy: '清理历史余额变动与流水明细。' },
  merchant_operation_logs: { label: '商户操作日志', copy: '清理后台处理商户业务时留下的操作日志。' },
  admin_operation_logs: { label: '管理员操作日志', copy: '清理后台人工补偿、同步和运营处理留下的管理员操作日志。' },
  plugin_notify_logs: { label: '插件通知日志', copy: '清理插件回调、通知和网关联调日志。' },
  provider_test_logs: { label: '服务商测试日志', copy: '清理邮件、短信等服务商测试发送日志。' },
  realname_audit_logs: { label: '实名审核日志', copy: '清理实名认证审核过程中的历史记录。' },
  tickets: { label: '工单记录', copy: '清理历史工单内容与处理记录。' },
  task_runs: { label: '任务执行日志', copy: '清理计划任务执行结果与运行日志。' },
}

const qrProviderOptions: QrProviderOption[] = [
  {
    value: 'cliim',
    label: '草料二维码（国内）',
    copy: '用于国内二维码生成与解码。',
  },
  {
    value: 'goqr',
    label: '国际二维码接口',
    copy: '用于国际二维码生成与解码。',
  },
]

const paymentProviderOptions: PaymentProviderOption[] = [
  { value: 'system', label: '系统商户' },
]

const paymentModeOptions: PaymentProviderOption[] = [
  { value: 'v1', label: 'V1' },
  { value: 'v2', label: 'V2' },
]

const paymentV1AppswitchOptions: PaymentProviderOption[] = [
  { value: '0', label: '否' },
  { value: '1', label: '是' },
]

const paymentV2AppswitchOptions: PaymentProviderOption[] = [
  { value: '0', label: '页面跳转支付' },
  { value: '1', label: '统一下单接口' },
]

const paymentMethodPresetOptions = [
  { value: 'alipay', label: '支付宝', icon: 'payment-icons/alipay.png', code: 'alipay' },
  { value: 'wxpay', label: '微信支付', icon: 'payment-icons/wechat.png', code: 'wxpay' },
  { value: 'qqpay', label: 'QQ钱包', icon: 'payment-icons/qqpay.png', code: 'qqpay' },
]

function normalizeQrProvider(value: unknown): string {
  const normalized = String(value || '').trim().toLowerCase()

  if (normalized === 'goqr') {
    return 'goqr'
  }

  return 'cliim'
}

function qrProviderCopy(value: unknown): string {
  return qrProviderOptions.find((item) => item.value === normalizeQrProvider(value))?.copy || ''
}

function normalizePaymentProvider(value: unknown): string {
  return String(value || '').trim().toLowerCase() === 'epay' ? 'epay' : 'system'
}

function normalizePaymentMode(value: unknown): string {
  return String(value || '').trim().toLowerCase() === 'v1' ? 'v1' : 'v2'
}

function normalizePaymentAppswitch(value: unknown, mode: unknown): string {
  const normalized = String(value ?? '').trim()
  if (normalized === '0' || normalized === '1') {
    return normalized
  }

  return normalizePaymentMode(mode) === 'v2' ? '1' : '0'
}

function buildPaymentGatewayForm(source: Record<string, any> | undefined, extra: Record<string, any> = {}) {
  const mode = normalizePaymentMode(source?.mode)

  return {
    provider: normalizePaymentProvider(source?.provider),
    mode,
    appswitch: normalizePaymentAppswitch(source?.appswitch, mode),
    payment_url: String(source?.payment_url ?? ''),
    merchant_id: String(source?.merchant_id ?? ''),
    merchant_md5: String(source?.merchant_md5 ?? ''),
    platform_public_key: String(source?.platform_public_key ?? ''),
    merchant_private_key: String(source?.merchant_private_key ?? ''),
    methods: buildPaymentMethodFormList(source?.methods),
    ...extra,
  }
}

function buildPaymentMethodFormList(source: any): PaymentMethodFormItem[] {
  const items = Array.isArray(source) ? source : []
  const normalized: PaymentMethodFormItem[] = items
    .map((item: Record<string, any>, index: number) => {
      const preset = paymentMethodPresetOptions.find((presetItem) => presetItem.value === String(item?.key || '').trim() || presetItem.code === String(item?.code || '').trim())
      const key = String(item?.key || preset?.value || `custom_${index + 1}`).trim()
      const code = String(item?.code || preset?.code || '').trim()
      if (!key && !code) {
        return null
      }

      return {
        key: key || `custom_${index + 1}`,
        enabled: Boolean(item?.enabled ?? true),
        builtin: Boolean(item?.builtin ?? Boolean(preset)),
        code,
        name: String(item?.name ?? preset?.label ?? ''),
        icon: String(item?.icon ?? preset?.icon ?? ''),
      }
    })
    .filter((item): item is PaymentMethodFormItem => Boolean(item))

  for (const preset of paymentMethodPresetOptions) {
    if (normalized.some((item) => item.key === preset.value || item.code === preset.code)) {
      continue
    }

    normalized.push({
      key: preset.value,
      enabled: true,
      builtin: true,
      code: preset.code,
      name: preset.label,
      icon: preset.icon,
    })
  }

  return normalized
}

function createCustomPaymentMethod(): PaymentMethodFormItem {
  return {
    key: `custom_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`,
    enabled: true,
    builtin: false,
    code: '',
    name: '',
    icon: '',
  }
}

function addPaymentMethod(target: PaymentMethodFormItem[]) {
  target.push(createCustomPaymentMethod())
}

function removePaymentMethod(target: PaymentMethodFormItem[], key: string) {
  const index = target.findIndex((item) => item.key === key)
  if (index >= 0) {
    target.splice(index, 1)
  }
}

function paymentMethodRows(source: Record<string, any> | undefined): PaymentMethodFormItem[] {
  return Array.isArray(source?.methods) ? source.methods : []
}

function paymentUsesV2(source: Record<string, any> | undefined): boolean {
  return normalizePaymentMode(source?.mode) === 'v2'
}

function paymentUsesV1(source: Record<string, any> | undefined): boolean {
  return normalizePaymentMode(source?.mode) === 'v1'
}

function syncSectionForm(data: Record<string, any>) {
  for (const key of Object.keys(form)) {
    if (key === 'provider_options') {
      form[key] = data.provider_options || {}
      continue
    }

    const nextValue = Object.prototype.hasOwnProperty.call(data, key)
      ? data[key]
      : (key === 'announcements' ? [] : {})

    if (Array.isArray(nextValue)) {
      form[key] = nextValue
      continue
    }

    if (nextValue && typeof nextValue === 'object') {
      form[key] = { ...nextValue }
      continue
    }

    form[key] = nextValue
  }

  form.api = {
    ...(form.api || {}),
    encode_provider: normalizeQrProvider(form.api?.encode_provider),
    decode_provider: normalizeQrProvider(form.api?.decode_provider),
    notify_retry: String(form.api?.notify_retry ?? '5'),
  }

  form.payment = {
    ...(form.payment || {}),
    system_checkout: buildPaymentGatewayForm(form.payment?.system_checkout),
    frontend_test: buildPaymentGatewayForm(form.payment?.frontend_test, {
      enabled: Boolean(form.payment?.frontend_test?.enabled),
      amount: String(form.payment?.frontend_test?.amount ?? ''),
      auto_complete: Boolean(form.payment?.frontend_test?.auto_complete),
    }),
  }
}

function syncCleanupWorkspace(payload: Record<string, any> | undefined) {
  const nextQuickActions = Array.isArray(payload?.quick_actions) ? payload!.quick_actions : []
  const nextStores = Array.isArray(payload?.stores) ? payload!.stores : []

  cleanupWorkspace.quick_actions.splice(0, cleanupWorkspace.quick_actions.length, ...nextQuickActions)
  cleanupWorkspace.stores.splice(0, cleanupWorkspace.stores.length, ...nextStores)

  for (const key of Object.keys(cleanupDays)) {
    delete cleanupDays[key]
  }

  for (const item of nextStores) {
    cleanupDays[item.store] = Math.max(1, Number(item.default_days || 30))
  }
}

function providerList(bucket: string): ProviderItem[] {
  return providerOptions.value[bucket]?.items || []
}

function providerBinding(bucket: ProviderBucket) {
  return providerBindingMap[bucket]
}

function providerCode(bucket: ProviderBucket): string {
  const binding = providerBinding(bucket)
  const localCode = String(form[binding.group]?.[binding.key] || '').trim()
  if (localCode) return localCode
  return String(providerOptions.value[bucket]?.selected_code || providerOptions.value[bucket]?.selected?.code || '').trim()
}

function selectedProvider(bucket: ProviderBucket): ProviderItem | null {
  const code = providerCode(bucket)
  const item = providerList(bucket).find((provider) => provider.code === code)
  return item || providerOptions.value[bucket]?.selected || null
}

function providerFieldValue(group: string, key: string) {
  return form[group]?.[key]
}

async function load() {
  loading.value = true

  try {
    const resp = await getAdminSettings()
    if (resp.code === 0 && resp.data) {
      syncSectionForm(resp.data)
      syncCleanupWorkspace(resp.data.cleanup_workspace)
      return
    }

    ElMessage.error(resp.message || '加载后台设置失败')
  } finally {
    loading.value = false
  }
}

function normalizeBooleanGroup(source: Record<string, any>, keys: string[]) {
  const next = { ...(source || {}) }
  for (const key of keys) {
    next[key] = Boolean(source?.[key])
  }
  return next
}

async function saveSection(section: string) {
  const payload = section === 'verify'
    ? { verify: normalizeBooleanGroup(form.verify, verifyBooleanKeys) }
    : section === 'auth'
      ? { auth: normalizeBooleanGroup(form.auth, authBooleanKeys) }
      : section === 'realname'
        ? { realname: normalizeBooleanGroup(form.realname, realnameBooleanKeys) }
        : section === 'payment'
          ? {
            payment: {
              ...(form.payment || {}),
              system_checkout: buildPaymentGatewayForm(form.payment?.system_checkout),
              frontend_test: buildPaymentGatewayForm(form.payment?.frontend_test, {
                enabled: Boolean(form.payment?.frontend_test?.enabled),
                amount: String(form.payment?.frontend_test?.amount ?? ''),
                auto_complete: Boolean(form.payment?.frontend_test?.auto_complete),
              }),
            },
          }
        : { [section]: form[section] }

  const resp = await saveAdminSettings(payload)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '已保存')
    await load()
    return
  }

  ElMessage.error(resp.message || '保存失败')
}

function openAnnouncement(item?: Record<string, any>) {
  Object.assign(announcementForm, {
    id: item?.id || 0,
    title: item?.title || '',
    summary: item?.summary || '',
    content: item?.content || '',
    target: item?.target || 'both',
    sort: item?.sort || 99,
  })
  announcementDialog.value = true
}

async function submitAnnouncement() {
  const resp = await saveAdminAnnouncement(announcementForm)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '已保存')
    announcementDialog.value = false
    await load()
    return
  }

  ElMessage.error(resp.message || '保存失败')
}

async function toggleAnnouncement(item: Record<string, any>) {
  const resp = await toggleAdminAnnouncement(item.id, Number(item.status_code) === 1 ? 0 : 1)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '已更新')
    await load()
    return
  }

  ElMessage.error(resp.message || '更新失败')
}

async function removeAnnouncement(item: Record<string, any>) {
  try {
    await ElMessageBox.confirm(
      `确认删除公告“${item.title}”吗？`,
      '删除公告',
      {
        confirmButtonText: '删除',
        cancelButtonText: '取消',
        type: 'warning',
      }
    )
  } catch {
    return
  }

  const resp = await deleteAdminAnnouncement(item.id)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '已删除')
    await load()
    return
  }

  ElMessage.error(resp.message || '删除失败')
}

function openProviderTest(type: 'mail' | 'sms') {
  providerTestForm.type = type
  providerTestForm.target = ''
  providerTestDialog.value = true
}

async function submitProviderTest() {
  providerTestLoading.value = true

  try {
    const resp = await testAdminProvider({
      type: providerTestForm.type,
      target: providerTestForm.target,
    })

    if (resp.code === 0) {
      ElMessage.success(resp.data?.message || resp.message || '测试消息已发送')
      providerTestDialog.value = false
      return
    }

    ElMessage.error(resp.message || '服务商测试失败')
  } finally {
    providerTestLoading.value = false
  }
}

function updateProvider(group: string, key: string, value: any) {
  form[group] = { ...(form[group] || {}), [key]: value }
}

function changeProvider(bucket: ProviderBucket, value: string) {
  const binding = providerBinding(bucket)
  updateProvider(binding.group, binding.key, value)
}

function setProviderField(bucket: ProviderBucket, fieldKey: string, value: any) {
  const binding = providerBinding(bucket)
  updateProvider(binding.group, fieldKey, value)
}

function setProviderFieldValue(bucket: ProviderBucket, field: ProviderField, value: string) {
  setProviderField(bucket, field.key, field.type === 'switch' ? value === 'true' : value)
}

function providerDescription(bucket: ProviderBucket): string {
  return selectedProvider(bucket)?.description || '暂无服务商说明'
}

const providerFieldLabelMap: Record<string, string> = {
  smtp_host: 'SMTP 主机',
  smtp_port: 'SMTP 端口',
  smtp_user: 'SMTP 账号',
  smtp_pass: 'SMTP 密码',
  smtp_secure: '加密方式',
  sender_name: '发件人名称',
  sign_name: '短信签名',
  template_code: '模板编码',
  access_key_id: '访问密钥 ID',
  access_key_secret: '访问密钥 Secret',
  scene_login: '登录场景',
  scene_register: '注册场景',
  scene_forgot: '找回场景',
  captcha_id: '极验 ID',
  captcha_key: '极验 Key',
  failback: '失败降级',
  api_url: '接口地址',
  app_id: '应用 ID',
  app_key: '应用 Key',
  app_secret: '应用密钥',
}

const providerFieldOptionMap: Record<string, string> = {
  ssl: 'SSL',
  tls: 'TLS',
  true: '开启',
  false: '关闭',
}

function providerFieldLabel(field: ProviderField): string {
  return providerFieldLabelMap[field.key] || field.label
}

function announcementTargetText(value: unknown): string {
  const raw = String(value || '').trim().toLowerCase()
  if (raw === 'both') return '首页和商户中心'
  if (raw === 'home') return '首页'
  if (raw === 'merchant') return '商户中心'
  return String(value || '-')
}

function providerFields(bucket: ProviderBucket): ProviderField[] {
  const provider = selectedProvider(bucket)
  const binding = providerBinding(bucket)
  const hiddenKeys = hiddenProviderFieldKeys[bucket]
  const schema = Array.isArray(provider?.settings_schema) ? provider.settings_schema : []

  return schema
    .map((field): ProviderField => ({
      key: String(field.key || '').trim(),
      label: String(field.label || field.key || '').trim(),
      type: String(field.type || 'text').trim(),
      options: Array.isArray(field.options) ? field.options : [],
      note: field.note ? String(field.note) : '',
      value: providerFieldValue(binding.group, String(field.key || '').trim()),
    }))
    .filter((field) => field.key !== '' && !hiddenKeys.includes(field.key))
}

function fieldOptions(field: ProviderField): Array<{ label: string; value: string }> {
  if (field.type === 'switch') {
    return switchOptions
  }

  return (field.options || [])
    .map((item) => {
      if (item && typeof item === 'object') {
        const value = String((item as any).value ?? (item as any).key ?? (item as any).id ?? '')
        const label = String((item as any).label ?? (item as any).name ?? (item as any).text ?? value)
        return { label: providerFieldOptionMap[label] || providerFieldOptionMap[value] || label, value }
      }

      const value = String(item)
      return { label: providerFieldOptionMap[value] || value, value }
    })
    .filter((item) => item.value !== '')
}

function fieldControl(field: ProviderField): 'input' | 'textarea' | 'select' | 'switch' {
  if (field.type === 'switch') return 'switch'
  if (field.type === 'textarea' || field.type === 'html') return 'textarea'
  if (field.type === 'select' || field.type === 'radio' || field.type === 'checkbox') return 'select'
  return 'input'
}

function fieldInputType(field: ProviderField): string {
  if (field.type === 'password' || field.type === 'number') return field.type
  return 'text'
}

function fieldModelValue(field: ProviderField): string {
  if (field.type === 'switch') {
    return String(Boolean(field.value))
  }

  return String(field.value ?? '')
}

function cleanupActionKey(item: CleanupQuickAction): string {
  if (item.action === 'bundle' && item.bundle) {
    return `bundle:${item.bundle}`
  }

  return `action:${item.key || item.action}`
}

function cleanupQuickActionTitle(item: CleanupQuickAction): string {
  return cleanupQuickActionMeta[item.key]?.label || item.label || item.key
}

function cleanupQuickActionCopy(item: CleanupQuickAction): string {
  return cleanupQuickActionMeta[item.key]?.copy || item.copy || ''
}

function cleanupStoreTitle(item: CleanupStoreItem): string {
  return cleanupStoreMeta[item.store]?.label || item.label || item.store
}

function cleanupStoreCopy(item: CleanupStoreItem): string {
  return cleanupStoreMeta[item.store]?.copy || item.copy || ''
}

function cleanupRetainDays(value: unknown, fallback = 30): number {
  const days = Math.max(1, Number(value || 0))
  return Number.isFinite(days) ? days : fallback
}

function formatCleanupMessage(data: Record<string, any> | undefined, label: string): string {
  const removed = Number(data?.removed_count ?? 0)
  const remaining = data?.remaining_count

  if (typeof remaining === 'number') {
    return `${label}已完成，共清理 ${removed} 项，剩余 ${remaining} 项。`
  }

  return `${label}已完成，共清理 ${removed} 项。`
}

async function runQuickCleanup(item: CleanupQuickAction) {
  const key = cleanupActionKey(item)
  cleanupPending[key] = true

  try {
    const resp = item.action === 'runtime'
      ? await clearAdminCache()
      : await runAdminCleanup({
          action: item.action,
          bundle: item.bundle,
          days: cleanupRetainDays(item.default_days),
        })

    if (resp.code === 0) {
      ElMessage.success(formatCleanupMessage(resp.data, cleanupQuickActionTitle(item)))
      await load()
      return
    }

    ElMessage.error(resp.message || '清理失败')
  } finally {
    cleanupPending[key] = false
  }
}

async function runStoreCleanup(item: CleanupStoreItem) {
  const key = `store:${item.store}`
  cleanupPending[key] = true

  try {
    const resp = await runAdminCleanup({
      action: 'store',
      store: item.store,
      days: cleanupRetainDays(cleanupDays[item.store], cleanupRetainDays(item.default_days)),
    })

    if (resp.code === 0) {
      ElMessage.success(formatCleanupMessage(resp.data, cleanupStoreTitle(item)))
      await load()
      return
    }

    ElMessage.error(resp.message || '清理失败')
  } finally {
    cleanupPending[key] = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page-stack admin-settings-page">
    <article class="metric-card settings-panel settings-workspace">
      <div v-if="activeSection === 'basic'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split settings-block-head--payment">
          <div>
            <h3 class="settings-block-title">基本信息</h3>
            <p class="settings-block-copy">在这里维护站点名称、访问地址、Logo、备案号和商户起始编号。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('basic')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field"><span class="field-label">站点名称</span><input v-model="form.basic.site_name" type="text" /></label>
          <label class="field"><span class="field-label">站点标题</span><input v-model="form.basic.site_title" type="text" /></label>
          <label class="field field-span-2"><span class="field-label">站点副标题</span><input v-model="form.basic.site_subtitle" type="text" /></label>
          <label class="field"><span class="field-label">站点地址</span><input v-model="form.basic.site_url" type="text" /></label>
          <label class="field"><span class="field-label">网关基础地址</span><input v-model="form.basic.gateway_base_url" type="text" /></label>
          <label class="field"><span class="field-label">首页 Logo 地址</span><input v-model="form.basic.site_logo_home" type="text" /></label>
          <label class="field"><span class="field-label">全局 Logo 地址</span><input v-model="form.basic.site_logo_global" type="text" /></label>
          <label class="field"><span class="field-label">ICP备案号</span><input v-model="form.basic.icp_no" type="text" /></label>
          <label class="field"><span class="field-label">商户 ID 起始值</span><input v-model="form.basic.member_start_id" type="text" /></label>
        </div>
      </div>

      <div v-else-if="activeSection === 'announcements'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">公告管理</h3>
            <p class="settings-block-copy">首页和商户中心公告统一在这里发布、排序和启停。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" @click="openAnnouncement()">新增公告</button>
          </div>
        </div>
        <div class="table-wrap">
          <div class="table-head announcement-grid">
            <span>标题</span>
            <span>投放位置</span>
            <span>排序</span>
            <span>状态</span>
            <span>创建时间</span>
            <span>操作</span>
          </div>
          <div v-for="item in form.announcements || []" :key="item.id" class="table-row announcement-grid">
            <div>
              <strong>{{ item.title }}</strong>
              <div class="minor-copy">{{ item.summary }}</div>
            </div>
            <span>{{ announcementTargetText(item.target) }}</span>
            <span>{{ item.sort }}</span>
            <span>{{ item.status }}</span>
            <span>{{ item.created_at }}</span>
            <div class="inline-actions">
              <button class="link-action" @click="openAnnouncement(item)">编辑</button>
              <button class="link-action" @click="toggleAnnouncement(item)">{{ Number(item.status_code) === 1 ? '关闭' : '开启' }}</button>
              <button class="link-action" @click="removeAnnouncement(item)">删除</button>
            </div>
          </div>
        </div>
      </div>

      <div v-else-if="activeSection === 'payment'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">支付配置</h3>
            <p class="settings-block-copy">系统业务支付和首页测试支付分开配置，保存后首页测试会直接按这里的接口参数执行。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('payment')">保存当前设置</button>
          </div>
        </div>
        <div class="payment-config-stack">
          <section class="payment-config-group">
            <div class="payment-config-head">
              <h4>系统业务支付配置</h4>
              <p>上面的配置用于商户付费注册、套餐购买、余额充值等业务场景。</p>
            </div>
            <div class="field-grid compact">
              <label class="field">
                <span class="field-label">接口选择</span>
                <select v-model="form.payment.system_checkout.provider">
                  <option v-for="item in paymentProviderOptions" :key="`system-provider-${item.value}`" :value="item.value">{{ item.label }}</option>
                </select>
              </label>
              <label class="field">
                <span class="field-label">接口模式</span>
                <select v-model="form.payment.system_checkout.mode">
                  <option v-for="item in paymentModeOptions" :key="`system-mode-${item.value}`" :value="item.value">{{ item.label }}</option>
                </select>
              </label>
              <label v-if="paymentUsesV1(form.payment.system_checkout)" class="field">
                <span class="field-label">是否使用mapi接口</span>
                <select v-model="form.payment.system_checkout.appswitch">
                  <option v-for="item in paymentV1AppswitchOptions" :key="`system-v1-appswitch-${item.value}`" :value="item.value">{{ item.label }}</option>
                </select>
              </label>
              <label v-else class="field">
                <span class="field-label">接口类型</span>
                <select v-model="form.payment.system_checkout.appswitch">
                  <option v-for="item in paymentV2AppswitchOptions" :key="`system-v2-appswitch-${item.value}`" :value="item.value">{{ item.label }}</option>
                </select>
              </label>
              <label class="field field-span-2"><span class="field-label">支付 URL</span><input v-model="form.payment.system_checkout.payment_url" type="text" /></label>
              <label class="field"><span class="field-label">商户 ID</span><input v-model="form.payment.system_checkout.merchant_id" type="text" /></label>
              <label class="field"><span class="field-label">商户 MD5</span><input v-model="form.payment.system_checkout.merchant_md5" type="text" autocomplete="off" /></label>
              <template v-if="paymentUsesV2(form.payment.system_checkout)">
                <label class="field field-span-2">
                  <span class="field-label">平台公钥</span>
                  <textarea v-model="form.payment.system_checkout.platform_public_key" rows="4"></textarea>
                </label>
                <label class="field field-span-2">
                  <span class="field-label">商户私钥</span>
                  <textarea v-model="form.payment.system_checkout.merchant_private_key" rows="6"></textarea>
                </label>
              </template>
            </div>
            <div class="payment-method-editor">
              <div class="payment-method-editor__head">
                <strong>前台支付方式</strong>
                <button class="ghost-btn" type="button" @click="addPaymentMethod(paymentMethodRows(form.payment.system_checkout))">新增自定义方式</button>
              </div>
              <div class="payment-method-table">
                <div class="payment-method-table__head">
                  <span>启用</span>
                  <span>显示名称</span>
                  <span>图标</span>
                  <span>提交值</span>
                  <span>操作</span>
                </div>
                <div
                  v-for="item in paymentMethodRows(form.payment.system_checkout)"
                  :key="`system-method-${item.key}`"
                  class="payment-method-table__row"
                >
                  <label class="payment-switch">
                    <input v-model="item.enabled" type="checkbox" />
                    <span>{{ item.enabled ? '开启' : '关闭' }}</span>
                  </label>
                  <input v-model="item.name" type="text" placeholder="前台显示名称" />
                  <input v-model="item.icon" type="text" placeholder="图标路径，例如 payment-icons/alipay.png" />
                  <input v-model="item.code" type="text" placeholder="实际提交值，例如 alipay" />
                  <button v-if="!item.builtin" class="link-action" type="button" @click="removePaymentMethod(paymentMethodRows(form.payment.system_checkout), item.key)">删除</button>
                  <span v-else class="minor-copy">内置方式</span>
                </div>
              </div>
            </div>
          </section>

          <section class="payment-config-group">
            <div class="payment-config-head">
              <h4>前台测试支付配置</h4>
              <p>这个用于首页支付测试，给游客测试支付时使用。</p>
            </div>
            <div class="field-grid compact">
              <label class="field">
                <span class="field-label">接口选择</span>
                <select v-model="form.payment.frontend_test.provider">
                  <option v-for="item in paymentProviderOptions" :key="`frontend-provider-${item.value}`" :value="item.value">{{ item.label }}</option>
                </select>
              </label>
              <label class="field">
                <span class="field-label">接口模式</span>
                <select v-model="form.payment.frontend_test.mode">
                  <option v-for="item in paymentModeOptions" :key="`frontend-mode-${item.value}`" :value="item.value">{{ item.label }}</option>
                </select>
              </label>
              <label v-if="paymentUsesV1(form.payment.frontend_test)" class="field">
                <span class="field-label">是否使用mapi接口</span>
                <select v-model="form.payment.frontend_test.appswitch">
                  <option v-for="item in paymentV1AppswitchOptions" :key="`frontend-v1-appswitch-${item.value}`" :value="item.value">{{ item.label }}</option>
                </select>
              </label>
              <label v-else class="field">
                <span class="field-label">接口类型</span>
                <select v-model="form.payment.frontend_test.appswitch">
                  <option v-for="item in paymentV2AppswitchOptions" :key="`frontend-v2-appswitch-${item.value}`" :value="item.value">{{ item.label }}</option>
                </select>
              </label>
              <label class="field field-span-2"><span class="field-label">支付 URL</span><input v-model="form.payment.frontend_test.payment_url" type="text" /></label>
              <label class="field"><span class="field-label">商户 ID</span><input v-model="form.payment.frontend_test.merchant_id" type="text" /></label>
              <label class="field"><span class="field-label">商户 MD5</span><input v-model="form.payment.frontend_test.merchant_md5" type="text" autocomplete="off" /></label>
              <template v-if="paymentUsesV2(form.payment.frontend_test)">
                <label class="field field-span-2">
                  <span class="field-label">平台公钥</span>
                  <textarea v-model="form.payment.frontend_test.platform_public_key" rows="4"></textarea>
                </label>
                <label class="field field-span-2">
                  <span class="field-label">商户私钥</span>
                  <textarea v-model="form.payment.frontend_test.merchant_private_key" rows="6"></textarea>
                </label>
              </template>
              <label class="field">
                <span class="field-label">是否开启</span>
                <select v-model="form.payment.frontend_test.enabled">
                  <option :value="true">开启</option>
                  <option :value="false">关闭</option>
                </select>
              </label>
              <label class="field">
                <span class="field-label">测试金额</span>
                <input v-model="form.payment.frontend_test.amount" type="text" placeholder="留空则随机" />
              </label>
            </div>
            <div class="payment-method-editor">
              <div class="payment-method-editor__head">
                <strong>首页可选支付方式</strong>
                <button class="ghost-btn" type="button" @click="addPaymentMethod(paymentMethodRows(form.payment.frontend_test))">新增自定义方式</button>
              </div>
              <div class="payment-method-table">
                <div class="payment-method-table__head">
                  <span>启用</span>
                  <span>显示名称</span>
                  <span>图标</span>
                  <span>提交值</span>
                  <span>操作</span>
                </div>
                <div
                  v-for="item in paymentMethodRows(form.payment.frontend_test)"
                  :key="`frontend-method-${item.key}`"
                  class="payment-method-table__row"
                >
                  <label class="payment-switch">
                    <input v-model="item.enabled" type="checkbox" />
                    <span>{{ item.enabled ? '开启' : '关闭' }}</span>
                  </label>
                  <input v-model="item.name" type="text" placeholder="前台显示名称" />
                  <input v-model="item.icon" type="text" placeholder="图标路径，例如 payment-icons/wechat.png" />
                  <input v-model="item.code" type="text" placeholder="实际提交值，例如 wxpay" />
                  <button v-if="!item.builtin" class="link-action" type="button" @click="removePaymentMethod(paymentMethodRows(form.payment.frontend_test), item.key)">删除</button>
                  <span v-else class="minor-copy">内置方式</span>
                </div>
              </div>
            </div>
            <p class="payment-config-tip">测试金额不填写时，首页支付测试默认随机生成金额。</p>
          </section>
        </div>
      </div>

      <div v-else-if="activeSection === 'merchant'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">商户策略</h3>
            <p class="settings-block-copy">统一控制商户注册入口、收费策略、默认审核与实名要求。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('merchant')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">商户注册</span>
            <select v-model="form.merchant.register_enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field"><span class="field-label">注册方式</span><input v-model="form.merchant.register_mode" type="text" /></label>
          <label class="field">
            <span class="field-label">注册费开关</span>
            <select v-model="form.merchant.register_fee_enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field"><span class="field-label">注册费用</span><input v-model="form.merchant.register_fee" type="text" /></label>
          <label class="field">
            <span class="field-label">自动审核</span>
            <select v-model="form.merchant.register_auto_audit">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field"><span class="field-label">默认分组</span><input v-model="form.merchant.default_group" type="text" /></label>
          <label class="field"><span class="field-label">试用套餐</span><input v-model="form.merchant.trial_package" type="text" /></label>
          <label class="field">
            <span class="field-label">要求实名</span>
            <select v-model="form.merchant.require_realname">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">提现前要求实名</span>
            <select v-model="form.merchant.require_realname_before_withdraw">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
        </div>
      </div>

      <div v-else-if="activeSection === 'oauth'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">聚合登录服务</h3>
            <p class="settings-block-copy">选择第三方登录服务商，并在下方维护对应的运行参数。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('oauth')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">聚合登录开关</span>
            <select v-model="form.oauth.enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">服务商</span>
            <select :value="providerCode('oauth')" @change="changeProvider('oauth', String(($event.target as HTMLSelectElement).value))">
              <option v-for="item in providerList('oauth')" :key="item.code" :value="item.code">{{ item.name }}</option>
            </select>
          </label>
          <label class="field field-span-2"><span class="field-label">说明</span><input :value="providerDescription('oauth')" type="text" readonly /></label>
          <template v-for="field in providerFields('oauth')" :key="field.key">
            <label class="field" :class="{ 'field-span-2': field.type === 'textarea' }">
              <span class="field-label">{{ providerFieldLabel(field) }}</span>
              <input
                v-if="fieldControl(field) === 'input'"
                :type="fieldInputType(field)"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('oauth', field, String(($event.target as HTMLInputElement).value))"
              />
              <textarea
                v-else-if="fieldControl(field) === 'textarea'"
                :rows="4"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('oauth', field, String(($event.target as HTMLTextAreaElement).value))"
              ></textarea>
              <select
                v-else
                :value="fieldModelValue(field)"
                @change="setProviderFieldValue('oauth', field, String(($event.target as HTMLSelectElement).value))"
              >
                <option v-for="opt in fieldOptions(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </label>
          </template>
        </div>
      </div>

      <div v-else-if="activeSection === 'verify'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">极验配置</h3>
            <p class="settings-block-copy">绑定极验服务商，并控制哪些登录、注册、找回场景需要启用极验。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('verify')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">极验开关</span>
            <select v-model="form.verify.geetest_enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">服务商</span>
            <select :value="providerCode('geetest')" @change="changeProvider('geetest', String(($event.target as HTMLSelectElement).value))">
              <option v-for="item in providerList('geetest')" :key="item.code" :value="item.code">{{ item.name }}</option>
            </select>
          </label>
          <label class="field field-span-2"><span class="field-label">说明</span><input :value="providerDescription('geetest')" type="text" readonly /></label>
          <template v-for="field in providerFields('geetest')" :key="field.key">
            <label class="field" :class="{ 'field-span-2': field.type === 'textarea' }">
              <span class="field-label">{{ providerFieldLabel(field) }}</span>
              <input
                v-if="fieldControl(field) === 'input'"
                :type="fieldInputType(field)"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('geetest', field, String(($event.target as HTMLInputElement).value))"
              />
              <textarea
                v-else-if="fieldControl(field) === 'textarea'"
                :rows="4"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('geetest', field, String(($event.target as HTMLTextAreaElement).value))"
              ></textarea>
              <select
                v-else
                :value="fieldModelValue(field)"
                @change="setProviderFieldValue('geetest', field, String(($event.target as HTMLSelectElement).value))"
              >
                <option v-for="opt in fieldOptions(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </label>
          </template>
          <label v-for="scene in geetestSceneFields" :key="scene.key" class="field">
            <span class="field-label">{{ scene.label }}</span>
            <select v-model="form.verify[scene.key]">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
        </div>
      </div>

      <div v-else-if="activeSection === 'mail'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">邮件服务</h3>
            <p class="settings-block-copy">选择邮件服务商，并在这里维护发件人和 SMTP 相关参数。</p>
          </div>
          <div class="toolbar-actions">
            <button class="ghost-btn" @click="openProviderTest('mail')">测试邮件服务</button>
            <button class="primary-btn" :disabled="loading" @click="saveSection('mail')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">邮件开关</span>
            <select v-model="form.mail.enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">服务商</span>
            <select :value="providerCode('mail')" @change="changeProvider('mail', String(($event.target as HTMLSelectElement).value))">
              <option v-for="item in providerList('mail')" :key="item.code" :value="item.code">{{ item.name }}</option>
            </select>
          </label>
          <label class="field field-span-2"><span class="field-label">说明</span><input :value="providerDescription('mail')" type="text" readonly /></label>
          <template v-for="field in providerFields('mail')" :key="field.key">
            <label class="field" :class="{ 'field-span-2': field.type === 'textarea' }">
              <span class="field-label">{{ providerFieldLabel(field) }}</span>
              <input
                v-if="fieldControl(field) === 'input'"
                :type="fieldInputType(field)"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('mail', field, String(($event.target as HTMLInputElement).value))"
              />
              <textarea
                v-else-if="fieldControl(field) === 'textarea'"
                :rows="4"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('mail', field, String(($event.target as HTMLTextAreaElement).value))"
              ></textarea>
              <select
                v-else
                :value="fieldModelValue(field)"
                @change="setProviderFieldValue('mail', field, String(($event.target as HTMLSelectElement).value))"
              >
                <option v-for="opt in fieldOptions(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </label>
          </template>
        </div>
      </div>

      <div v-else-if="activeSection === 'telegram'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">电报机器人</h3>
            <p class="settings-block-copy">在这里维护电报机器人令牌、目标会话与通知开关。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('telegram')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field"><span class="field-label">机器人令牌</span><input v-model="form.telegram.bot_token" type="text" /></label>
          <label class="field"><span class="field-label">会话 ID</span><input v-model="form.telegram.chat_id" type="text" /></label>
          <label class="field">
            <span class="field-label">通知开关</span>
            <select v-model="form.telegram.notify_enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
        </div>
      </div>

      <div v-else-if="activeSection === 'sms'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">短信服务</h3>
            <p class="settings-block-copy">选择短信服务商，并在下方维护签名、模板和密钥参数。</p>
          </div>
          <div class="toolbar-actions">
            <button class="ghost-btn" @click="openProviderTest('sms')">测试短信服务</button>
            <button class="primary-btn" :disabled="loading" @click="saveSection('sms')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">短信开关</span>
            <select v-model="form.sms.enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">服务商</span>
            <select :value="providerCode('sms')" @change="changeProvider('sms', String(($event.target as HTMLSelectElement).value))">
              <option v-for="item in providerList('sms')" :key="item.code" :value="item.code">{{ item.name }}</option>
            </select>
          </label>
          <label class="field field-span-2"><span class="field-label">说明</span><input :value="providerDescription('sms')" type="text" readonly /></label>
          <template v-for="field in providerFields('sms')" :key="field.key">
            <label class="field" :class="{ 'field-span-2': field.type === 'textarea' }">
              <span class="field-label">{{ providerFieldLabel(field) }}</span>
              <input
                v-if="fieldControl(field) === 'input'"
                :type="fieldInputType(field)"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('sms', field, String(($event.target as HTMLInputElement).value))"
              />
              <textarea
                v-else-if="fieldControl(field) === 'textarea'"
                :rows="4"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('sms', field, String(($event.target as HTMLTextAreaElement).value))"
              ></textarea>
              <select
                v-else
                :value="fieldModelValue(field)"
                @change="setProviderFieldValue('sms', field, String(($event.target as HTMLSelectElement).value))"
              >
                <option v-for="opt in fieldOptions(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </label>
          </template>
        </div>
      </div>

      <div v-else-if="activeSection === 'realname'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">实名认证服务</h3>
            <p class="settings-block-copy">统一维护实名服务商、审核模式、阈值和接口访问限制。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('realname')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">实名认证开关</span>
            <select v-model="form.realname.enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">服务商</span>
            <select :value="providerCode('realname')" @change="changeProvider('realname', String(($event.target as HTMLSelectElement).value))">
              <option v-for="item in providerList('realname')" :key="item.code" :value="item.code">{{ item.name }}</option>
            </select>
          </label>
          <label class="field field-span-2"><span class="field-label">说明</span><input :value="providerDescription('realname')" type="text" readonly /></label>
          <template v-for="field in providerFields('realname')" :key="field.key">
            <label class="field" :class="{ 'field-span-2': field.type === 'textarea' }">
              <span class="field-label">{{ providerFieldLabel(field) }}</span>
              <input
                v-if="fieldControl(field) === 'input'"
                :type="fieldInputType(field)"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('realname', field, String(($event.target as HTMLInputElement).value))"
              />
              <textarea
                v-else-if="fieldControl(field) === 'textarea'"
                :rows="4"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('realname', field, String(($event.target as HTMLTextAreaElement).value))"
              ></textarea>
              <select
                v-else
                :value="fieldModelValue(field)"
                @change="setProviderFieldValue('realname', field, String(($event.target as HTMLSelectElement).value))"
              >
                <option v-for="opt in fieldOptions(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </label>
          </template>
          <label class="field"><span class="field-label">每日限制</span><input v-model="form.realname.daily_limit" type="text" /></label>
          <label class="field">
            <span class="field-label">自动审核</span>
            <select v-model="form.realname.auto_audit">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field field-span-2">
            <span class="field-label">接口调用前要求实名</span>
            <select v-model="form.realname.require_before_api">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
        </div>
      </div>

      <div v-else-if="activeSection === 'upload'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">上传策略</h3>
            <p class="settings-block-copy">控制上传大小、允许扩展名以及文件存储驱动。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('upload')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field"><span class="field-label">最大大小（MB）</span><input v-model="form.upload.max_size_mb" type="text" /></label>
          <label class="field"><span class="field-label">存储驱动</span><input v-model="form.upload.storage_driver" type="text" /></label>
          <label class="field field-span-2"><span class="field-label">允许扩展名</span><input v-model="form.upload.allowed_ext" type="text" /></label>
        </div>
      </div>

      <div v-else-if="activeSection === 'api'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">接口设置</h3>
            <p class="settings-block-copy">生码接口用于生成支付二维码，解码接口用于解析二维码内容，国内与国际接口分别独立选择。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('api')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">生码接口</span>
            <select v-model="form.api.encode_provider">
              <option v-for="item in qrProviderOptions" :key="`encode-${item.value}`" :value="item.value">{{ item.label }}</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">解码接口</span>
            <select v-model="form.api.decode_provider">
              <option v-for="item in qrProviderOptions" :key="`decode-${item.value}`" :value="item.value">{{ item.label }}</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">生码接口说明</span>
            <input :value="qrProviderCopy(form.api.encode_provider)" type="text" readonly />
          </label>
          <label class="field">
            <span class="field-label">解码接口说明</span>
            <input :value="qrProviderCopy(form.api.decode_provider)" type="text" readonly />
          </label>
          <label class="field field-span-2"><span class="field-label">回调重试次数</span><input v-model="form.api.notify_retry" type="text" /></label>
        </div>
      </div>

      <div v-else-if="activeSection === 'auth'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">认证策略</h3>
            <p class="settings-block-copy">统一控制注册、登录、找回密码、行为验证与验证码场景策略。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" :disabled="loading" @click="saveSection('auth')">保存当前设置</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">注册开关</span>
            <select v-model="form.auth.register_enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field"><span class="field-label">注册方式</span><input v-model="form.auth.register_type" type="text" /></label>
          <label class="field"><span class="field-label">登录方式</span><input v-model="form.auth.login_type" type="text" /></label>
          <label class="field"><span class="field-label">找回方式</span><input v-model="form.auth.recover_type" type="text" /></label>
          <label class="field">
            <span class="field-label">验证码开关</span>
            <select v-model="form.auth.captcha_enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">验证码服务商</span>
            <select :value="providerCode('captcha')" @change="changeProvider('captcha', String(($event.target as HTMLSelectElement).value))">
              <option v-for="item in providerList('captcha')" :key="item.code" :value="item.code">{{ item.name }}</option>
            </select>
          </label>
          <label class="field field-span-2"><span class="field-label">说明</span><input :value="providerDescription('captcha')" type="text" readonly /></label>
          <template v-for="field in providerFields('captcha')" :key="field.key">
            <label class="field" :class="{ 'field-span-2': field.type === 'textarea' }">
              <span class="field-label">{{ providerFieldLabel(field) }}</span>
              <input
                v-if="fieldControl(field) === 'input'"
                :type="fieldInputType(field)"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('captcha', field, String(($event.target as HTMLInputElement).value))"
              />
              <textarea
                v-else-if="fieldControl(field) === 'textarea'"
                :rows="4"
                :value="fieldModelValue(field)"
                @input="setProviderFieldValue('captcha', field, String(($event.target as HTMLTextAreaElement).value))"
              ></textarea>
              <select
                v-else
                :value="fieldModelValue(field)"
                @change="setProviderFieldValue('captcha', field, String(($event.target as HTMLSelectElement).value))"
              >
                <option v-for="opt in fieldOptions(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </label>
          </template>
          <label v-for="scene in captchaSceneFields" :key="scene.key" class="field">
            <span class="field-label">{{ scene.label }}</span>
            <select v-model="form.auth[scene.key]">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">行为验证</span>
            <select v-model="form.auth.behavior_verify">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">注册费开关</span>
            <select v-model="form.auth.merchant_register_fee_enabled">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field"><span class="field-label">注册费用</span><input v-model="form.auth.merchant_register_fee" type="text" /></label>
          <label class="field"><span class="field-label">试用天数</span><input v-model="form.auth.merchant_trial_days" type="text" /></label>
          <label class="field">
            <span class="field-label">注册后自动审核</span>
            <select v-model="form.auth.register_auto_audit">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
          <label class="field">
            <span class="field-label">注册后要求实名</span>
            <select v-model="form.auth.require_realname_after_register">
              <option :value="true">开启</option>
              <option :value="false">关闭</option>
            </select>
          </label>
        </div>
      </div>

      <div v-else class="settings-block settings-workspace__body settings-block--cleanup">
        <div class="settings-block-head">
          <h3 class="settings-block-title">缓存清理</h3>
          <p class="settings-block-copy">只清理系统真实存在的缓存、静态资源和历史记录，不改动业务配置与规则。</p>
        </div>

        <div class="cleanup-panel">
          <section class="cleanup-section">
            <div class="cleanup-section-head">
              <strong>常用清理</strong>
              <span>先处理系统缓存和高频历史记录。</span>
            </div>
            <div class="cleanup-action-list">
              <div v-for="item in cleanupWorkspace.quick_actions" :key="item.key" class="cleanup-action-row">
                <div class="cleanup-action-main">
                  <strong>{{ cleanupQuickActionTitle(item) }}</strong>
                  <p>{{ cleanupQuickActionCopy(item) }}</p>
                </div>
                <div class="cleanup-action-side">
                  <span v-if="item.default_days" class="cleanup-retain">保留 {{ item.default_days }} 天内数据</span>
                  <button
                    class="danger-btn"
                    :disabled="cleanupPending[cleanupActionKey(item)]"
                    @click="runQuickCleanup(item)"
                  >
                    {{ cleanupPending[cleanupActionKey(item)] ? '清理中...' : '立即清理' }}
                  </button>
                </div>
              </div>
            </div>
          </section>

          <section class="cleanup-section">
            <div class="cleanup-section-head">
              <strong>自定义清理</strong>
              <span>按项目单独指定保留天数，未到期数据不会被清除。</span>
            </div>
            <div class="cleanup-table">
              <div class="cleanup-table-head">
                <span>清理项目</span>
                <span>保留天数</span>
                <span>说明</span>
                <span>操作</span>
              </div>
              <div v-for="item in cleanupWorkspace.stores" :key="item.store" class="cleanup-table-row">
                <div class="cleanup-item-name">
                  <strong>{{ cleanupStoreTitle(item) }}</strong>
                </div>
                <label class="cleanup-day-input">
                  <input v-model.number="cleanupDays[item.store]" type="number" min="1" max="3650" />
                  <span>天</span>
                </label>
                <div class="cleanup-item-copy">{{ cleanupStoreCopy(item) }}</div>
                <button
                  class="ghost-btn"
                  :disabled="cleanupPending[`store:${item.store}`]"
                  @click="runStoreCleanup(item)"
                >
                  {{ cleanupPending[`store:${item.store}`] ? '处理中...' : '清理到期记录' }}
                </button>
              </div>
            </div>
          </section>
        </div>
      </div>
    </article>

    <el-dialog v-model="announcementDialog" title="公告" width="560px">
      <div class="dialog-form">
        <label class="field"><span class="field-label">标题</span><input v-model="announcementForm.title" type="text" /></label>
        <label class="field">
          <span class="field-label">摘要</span>
          <textarea v-model="announcementForm.summary" rows="3"></textarea>
        </label>
        <label class="field">
          <span class="field-label">内容</span>
          <textarea v-model="announcementForm.content" rows="4"></textarea>
        </label>
        <label class="field">
          <span class="field-label">投放位置</span>
          <select v-model="announcementForm.target">
            <option value="both">首页和商户中心</option>
            <option value="home">首页</option>
            <option value="merchant">商户中心</option>
          </select>
        </label>
        <label class="field"><span class="field-label">排序</span><input v-model="announcementForm.sort" type="number" /></label>
      </div>
      <template #footer>
        <button class="ghost-btn" @click="announcementDialog = false">取消</button>
        <button class="primary-btn" @click="submitAnnouncement">保存</button>
      </template>
    </el-dialog>

    <el-dialog v-model="providerTestDialog" title="服务商测试" width="460px">
      <div class="dialog-form">
        <label class="field">
          <span class="field-label">服务类型</span>
          <input :value="providerTestForm.type === 'mail' ? '邮件' : '短信'" type="text" readonly />
        </label>
        <label class="field">
          <span class="field-label">{{ providerTestForm.type === 'mail' ? '目标邮箱' : '目标手机号' }}</span>
          <input
            v-model="providerTestForm.target"
            :type="providerTestForm.type === 'mail' ? 'email' : 'text'"
            :placeholder="providerTestForm.type === 'mail' ? 'name@example.com' : '13800138000'"
          />
        </label>
        <p class="minor-copy">测试会直接使用当前已保存的服务商配置，并通过真实运行链路发出一条测试消息。</p>
      </div>
      <template #footer>
        <button class="ghost-btn" @click="providerTestDialog = false">取消</button>
        <button class="primary-btn" :disabled="providerTestLoading" @click="submitProviderTest">
          {{ providerTestLoading ? '发送中...' : '发送测试' }}
        </button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.announcement-grid {
  display: grid;
  grid-template-columns: 1.4fr 0.9fr 0.5fr 0.6fr 0.9fr 0.9fr;
  gap: 12px;
  align-items: center;
}

.payment-config-stack {
  display: grid;
  gap: 0;
}

.admin-settings-page .toolbar-actions {
  gap: 8px;
}

.settings-block-head--split {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}

.admin-settings-page .settings-block-head--payment {
  padding: 12px 16px 10px;
  gap: 4px;
  background: #fff;
}

.admin-settings-page .settings-block-head--payment .settings-block-title {
  font-size: 18px;
}

.admin-settings-page .settings-block-head--payment .settings-block-copy {
  line-height: 1.6;
}

.admin-settings-page .payment-config-stack {
  gap: 0;
}

.payment-config-group {
  display: grid;
  gap: 0;
  padding-top: 0;
  border-top: 1px solid #edf2f8;
}

.admin-settings-page .payment-config-group {
  gap: 0;
  padding-top: 0;
}

.payment-config-group:first-child {
  padding-top: 0;
  border-top: 0;
}

.payment-config-head {
  display: grid;
  gap: 6px;
}

.admin-settings-page .payment-config-head {
  grid-template-columns: minmax(150px, 210px) minmax(0, 1fr);
  gap: 6px 18px;
  align-items: center;
  padding: 12px 16px 10px;
  border-top: 1px solid #edf2f8;
  background: #fff;
}

.admin-settings-page .payment-config-group:first-child .payment-config-head {
  border-top: 0;
}

.payment-config-head h4 {
  margin: 0;
  color: #1a2842;
  font-size: 15px;
  font-weight: 700;
}

.admin-settings-page .payment-config-head h4 {
  font-size: 16px;
}

.payment-config-head p,
.payment-config-tip {
  margin: 0;
  color: #70819a;
  font-size: 13px;
  line-height: 1.75;
}

.admin-settings-page .payment-config-head p {
  line-height: 1.6;
  max-width: none;
}

.admin-settings-page .payment-config-group .field-grid {
  border-top: 1px solid #e7eef8;
}

.admin-settings-page .payment-config-group .field {
  padding: 12px 14px 10px;
  gap: 6px;
}

.admin-settings-page .payment-config-group .field-label {
  font-size: 12px;
}

.admin-settings-page .payment-config-group input,
.admin-settings-page .payment-config-group select {
  min-height: 38px;
  border-radius: 9px;
}

.admin-settings-page .payment-config-group textarea {
  min-height: 108px;
  border-radius: 9px;
}

.admin-settings-page .payment-config-tip {
  padding: 10px 16px 14px;
  border-top: 1px solid #edf3fa;
  background: #fff;
  line-height: 1.6;
}

.payment-method-editor {
  display: grid;
  gap: 0;
  border-top: 1px solid #edf3fa;
  background: #fff;
}

.payment-method-editor__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px 10px;
}

.payment-method-editor__head strong {
  color: #1a2842;
  font-size: 14px;
}

.payment-method-table {
  border-top: 1px solid #edf3fa;
}

.payment-method-table__head,
.payment-method-table__row {
  display: grid;
  grid-template-columns: 110px minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) 88px;
  gap: 12px;
  align-items: center;
  padding: 12px 16px;
}

.payment-method-table__head {
  color: #6f829d;
  font-size: 12px;
}

.payment-method-table__row {
  border-top: 1px solid #f0f5fb;
}

.payment-method-table__row input {
  min-height: 36px;
  border: 1px solid #d8e3f2;
  border-radius: 8px;
  padding: 0 12px;
}

.payment-switch {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #30445f;
  font-size: 12px;
}

.cleanup-panel {
  display: grid;
  gap: 0;
  padding: 0;
}

.cleanup-section {
  display: grid;
  gap: 0;
}

.cleanup-section + .cleanup-section {
  border-top: 1px solid #edf2f8;
}

.cleanup-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 16px 10px;
}

.cleanup-section-head strong {
  color: #1d2b45;
  font-size: 15px;
  font-weight: 700;
}

.cleanup-section-head span {
  color: #6f829d;
  font-size: 13px;
}

.cleanup-action-list {
  border-top: 1px solid #e7eef8;
}

.cleanup-action-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(176px, 220px);
  gap: 14px;
  align-items: center;
  padding: 14px 16px;
  border-bottom: 1px solid #edf3fa;
}

.cleanup-action-main strong {
  color: #1a2842;
  font-size: 14px;
  font-weight: 700;
}

.cleanup-action-main p {
  margin: 6px 0 0;
  color: #70819a;
  font-size: 13px;
  line-height: 1.75;
}

.cleanup-action-side {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  min-width: 0;
  gap: 12px;
}

.cleanup-retain {
  color: #6f829d;
  font-size: 12px;
  text-align: right;
}

.cleanup-table {
  border-top: 1px solid #e7eef8;
}

.cleanup-table-head,
.cleanup-table-row {
  display: grid;
  grid-template-columns: minmax(168px, 0.86fr) 128px minmax(260px, 1.36fr) 132px;
  gap: 16px;
  align-items: center;
}

.cleanup-table-head {
  padding: 0 16px 10px;
  color: #6f829d;
  font-size: 12px;
}

.cleanup-table-row {
  padding: 14px 16px;
  border-top: 1px solid #edf3fa;
}

.cleanup-item-name strong {
  color: #1a2842;
  font-size: 14px;
  font-weight: 700;
}

.cleanup-item-copy {
  color: #70819a;
  font-size: 13px;
  line-height: 1.75;
}

.cleanup-day-input {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #6f829d;
  font-size: 13px;
}

.cleanup-day-input input {
  width: 84px;
  height: 36px;
  border: 1px solid #d7e4f2;
  border-radius: 9px;
  background: #fff;
  padding: 0 12px;
  color: #1f2c45;
}

.cleanup-action-row button:disabled,
.cleanup-table-row button:disabled {
  cursor: not-allowed;
  opacity: 0.68;
}

@media (max-width: 1200px) {
  .announcement-grid {
    grid-template-columns: 1fr;
  }

  .cleanup-table-head,
  .cleanup-table-row {
    grid-template-columns: minmax(148px, 0.88fr) 116px minmax(200px, 1.2fr) 124px;
  }
}

@media (max-width: 900px) {
  .settings-block-head--split {
    flex-direction: column;
    align-items: stretch;
  }

  .settings-block-head--split .toolbar-actions {
    justify-content: flex-start;
  }

  .admin-settings-page .payment-config-head {
    grid-template-columns: 1fr;
    gap: 6px;
    padding: 12px 14px 10px;
  }

  .admin-settings-page .settings-block-head--payment,
  .admin-settings-page .payment-config-tip {
    padding-inline: 14px;
  }

  .admin-settings-page .payment-config-group .field {
    padding: 12px 14px;
  }

  .cleanup-panel {
    padding: 0;
  }

  .cleanup-section-head {
    flex-direction: column;
    align-items: flex-start;
  }

  .cleanup-action-row {
    grid-template-columns: 1fr;
  }

  .cleanup-action-side {
    justify-content: flex-start;
    flex-wrap: wrap;
  }

  .cleanup-table-head {
    display: none;
  }

  .cleanup-table-row {
    grid-template-columns: 1fr;
    gap: 12px;
  }
}
</style>
