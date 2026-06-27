<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getAdminLogs, retryAdminCallback } from '../lib/api'
import AppPagination from '../components/AppPagination.vue'
import { resetPagination, usePagination } from '../lib/pagination'

type LogSection = 'admin' | 'merchant' | 'callback' | 'provider' | 'realname' | 'plugin-notify'

const route = useRoute()
const data = ref<Record<string, any>>({
  admin_logs: [],
  merchant_logs: [],
  callback_summary: {},
  callback_logs: [],
  provider_logs: [],
  realname_logs: [],
  plugin_notify_logs: [],
})

const activeSection = computed<LogSection>(() =>
  route.meta.section === 'merchant'
    ? 'merchant'
    : route.meta.section === 'callback'
      ? 'callback'
      : route.meta.section === 'provider'
        ? 'provider'
        : route.meta.section === 'realname'
          ? 'realname'
          : route.meta.section === 'plugin-notify'
            ? 'plugin-notify'
            : 'admin',
)

const pageTitle = computed(() => {
  if (activeSection.value === 'callback') return '回调日志'
  if (activeSection.value === 'provider') return '服务商日志'
  if (activeSection.value === 'realname') return '实名日志'
  if (activeSection.value === 'plugin-notify') return '插件通知'
  return activeSection.value === 'admin' ? '管理员日志' : '商户日志'
})

const callbackSummary = computed<Record<string, any>>(() =>
  data.value.callback_summary && typeof data.value.callback_summary === 'object' ? data.value.callback_summary : {},
)

const adminLogs = computed<Record<string, any>[]>(() =>
  Array.isArray(data.value.admin_logs) ? data.value.admin_logs : [],
)

const merchantLogs = computed<Record<string, any>[]>(() =>
  Array.isArray(data.value.merchant_logs) ? data.value.merchant_logs : [],
)

const callbackLogs = computed<Record<string, any>[]>(() =>
  Array.isArray(data.value.callback_events) && data.value.callback_events.length
    ? data.value.callback_events
    : Array.isArray(data.value.callback_logs)
      ? data.value.callback_logs
      : [],
)

const providerLogs = computed<Record<string, any>[]>(() =>
  Array.isArray(data.value.provider_logs) ? data.value.provider_logs : [],
)

const realnameLogs = computed<Record<string, any>[]>(() =>
  Array.isArray(data.value.realname_logs) ? data.value.realname_logs : [],
)

const pluginNotifyLogs = computed<Record<string, any>[]>(() =>
  Array.isArray(data.value.plugin_notify_logs) ? data.value.plugin_notify_logs : [],
)

const currentRows = computed<Record<string, any>[]>(() => {
  if (activeSection.value === 'merchant') return merchantLogs.value
  if (activeSection.value === 'callback') return callbackLogs.value
  if (activeSection.value === 'provider') return providerLogs.value
  if (activeSection.value === 'realname') return realnameLogs.value
  if (activeSection.value === 'plugin-notify') return pluginNotifyLogs.value
  return adminLogs.value
})

const { pagination, total, pagedRows } = usePagination(() => currentRows.value, 20)

const callbackSummaryItems = computed(() => [
  { key: 'pending_due', label: '待立即执行', value: String(Number(callbackSummary.value.pending_due ?? 0)), tone: 'metric' },
  { key: 'pending_scheduled', label: '排队中', value: String(Number(callbackSummary.value.pending_scheduled ?? 0)), tone: 'metric' },
  { key: 'retry_exhausted', label: '已耗尽', value: String(Number(callbackSummary.value.retry_exhausted ?? 0)), tone: 'metric' },
  { key: 'attention_total', label: '需关注', value: String(Number(callbackSummary.value.attention_total ?? 0)), tone: 'metric' },
  { key: 'next_due_time', label: '下次执行', value: String(callbackSummary.value.next_due_time || '-'), tone: 'time' },
])

const sectionCopy = computed(() => {
  if (activeSection.value === 'callback') return '回调结果、重试次数和下次执行时间集中展示，便于统一排查异步通知。'
  if (activeSection.value === 'provider') return '邮件、短信和其他服务商调用结果按统一表格排布，方便快速定位失败记录。'
  if (activeSection.value === 'realname') return '在这里查看实名接口调用记录与审核结果，不改动任何审核逻辑。'
  if (activeSection.value === 'plugin-notify') return '插件通知阶段、结果和上下文信息统一展示，用于排查插件回调流程。'
  return activeSection.value === 'admin'
    ? '在这里查看管理员操作日志。'
    : '商户后台操作日志按相同结构展示，方便对比与排查。'
})

const emptyCopy = computed(() => {
  if (activeSection.value === 'callback') return '暂无回调日志。'
  if (activeSection.value === 'provider') return '暂无服务商日志。'
  if (activeSection.value === 'realname') return '暂无实名日志。'
  if (activeSection.value === 'plugin-notify') return '暂无插件通知日志。'
  return activeSection.value === 'admin' ? '暂无管理员日志。' : '暂无商户日志。'
})

async function load() {
  const resp = await getAdminLogs()
  if (resp.code === 0 && resp.data) {
    data.value = resp.data
  }
}

async function retryCallback(item: Record<string, any>) {
  const resp = await retryAdminCallback(Number(item.id || 0))
  if (resp.code === 0) {
    ElMessage.success(resp.message || '回调重试已执行')
    await load()
  } else if (resp.message) {
    ElMessage.error(resp.message)
  }
}

function toText(value: unknown) {
  return String(value ?? '').trim()
}

const logStatusTextMap: Record<string, string> = {
  success: '成功',
  failed: '失败',
  fail: '失败',
  error: '异常',
  skipped: '已跳过',
  pending: '待处理',
  processing: '处理中',
  retrying: '重试中',
  approved: '已通过',
  rejected: '已驳回',
}

const callbackResponseMap: Record<string, string> = {
  success: '回调成功',
  'request failed': '请求失败',
  'empty response': '空响应',
  'order not found': '未找到订单',
  'callback not found': '未找到回调记录',
  'callback already succeeded': '回调已成功',
}

const providerTypeMap: Record<string, string> = {
  mail: '邮件',
  sms: '短信',
  oauth: '聚合登录',
  captcha: '验证码',
  geetest: '极验',
  realname: '实名认证',
  telegram: '电报',
}

const providerSceneMap: Record<string, string> = {
  oauth_start: '发起授权',
  oauth_callback: '授权回调',
  server_validate: '服务端校验',
  admin_provider_test: '后台测试',
  merchant_login: '商户登录',
  merchant_register: '商户注册',
  merchant_forgot: '商户找回密码',
  admin_login: '后台登录',
  login: '登录',
  register: '注册',
  forgot: '找回密码',
  bind: '绑定',
}

const providerCodeMap: Record<string, string> = {
  'oauth-aggregate': '聚合登录接口',
  'mail-smtp': 'SMTP 邮件',
  'sms-aliyun': '阿里云短信',
  'captcha-slider': '滑块验证码',
  geetest: '极验验证码',
  manual: '人工审核',
  api: '实名接口',
  legacy_plugin_helper: '兼容插件助手',
}

const providerTargetTokenMap: Record<string, string> = {
  qq: 'QQ',
  wechat: '微信',
  alipay: '支付宝',
  google: '谷歌',
  telegram: '电报',
  bind: '绑定',
  login: '登录',
}

const realnameResultMap: Record<string, string> = {
  pending_manual_review: '待人工审核',
  provider_disabled: '实名认证未启用，已转入人工审核',
  provider_config_missing: '实名接口配置不完整，已转入人工审核',
  provider_request_failed: '实名接口请求失败',
  provider_approved: '实名接口核验通过',
  provider_rejected: '实名接口核验未通过',
  provider_pending: '实名接口处理中',
  manual_approved: '后台人工审核通过',
  manual_rejected: '后台人工审核驳回',
}

function formatLogStatus(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'
  return logStatusTextMap[raw.toLowerCase()] || raw
}

function formatLogOperator(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'

  const lower = raw.toLowerCase()
  if (lower === 'admin') return '管理员'
  if (lower === 'merchant') return '商户'
  if (lower === 'system') return '系统'
  if (lower.startsWith('admin:')) return `管理员 ${raw.slice(raw.indexOf(':') + 1).trim()}`.trim()
  if (lower.startsWith('merchant:')) return `商户 ${raw.slice(raw.indexOf(':') + 1).trim()}`.trim()

  return raw
}

function formatLogIp(value: unknown) {
  return toText(value) || '-'
}

function formatLogSummary(item: Record<string, any>) {
  const summary = toText(item.summary)
  if (summary) return summary

  const detail = item?.detail
  if (!detail || typeof detail !== 'object' || Array.isArray(detail)) {
    return '-'
  }

  const priorityKeys = [
    'refund_no',
    'biz_no',
    'trade_no',
    'out_trade_no',
    'out_refund_no',
    'out_biz_no',
    'bucket',
    'mode',
    'amount',
    'proof_no',
    'reason',
    'remark',
    'result',
    'message',
    'counts',
    'status',
  ]

  const parts = priorityKeys
    .map((key) => {
      const value = detail[key]
      if (value === undefined || value === null) return ''

      if (typeof value === 'object' && !Array.isArray(value)) {
        const inner = Object.entries(value)
          .map(([innerKey, innerValue]) => {
            const text = toText(innerValue)
            return text ? `${innerKey}:${text}` : ''
          })
          .filter(Boolean)
          .slice(0, 3)
          .join(',')

        return inner ? `${key}=${inner}` : ''
      }

      const text = toText(value)
      return text ? `${key}=${text}` : ''
    })
    .filter(Boolean)

  return parts.length > 0 ? parts.slice(0, 4).join(' / ') : '-'
}

function formatCallbackResult(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'
  return formatLogStatus(raw)
}

function formatCallbackResponse(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'

  const lower = raw.toLowerCase()
  return callbackResponseMap[lower] || raw
}

function displayText(value: unknown) {
  return toText(value) || '-'
}

async function copyCellText(value: unknown, label: string) {
  const text = toText(value)
  if (!text) {
    ElMessage.warning(`${label}为空`)
    return
  }

  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success(`${label}已复制`)
  } catch {
    ElMessage.error(`${label}复制失败`)
  }
}

function callbackStatusClass(item: Record<string, any>) {
  if (Number(item.status_code) === 2) return 'success'
  if (Number(item.status_code) === 1) return 'danger'
  if (item.runtime_exception || item.due_now) return 'warning'
  return 'muted'
}

function callbackHint() {
  return ''
}

function formatProviderType(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'
  return providerTypeMap[raw.toLowerCase()] || raw
}

function formatProviderScene(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'
  return providerSceneMap[raw.toLowerCase()] || raw
}

function formatProviderCode(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'
  return providerCodeMap[raw.toLowerCase()] || raw
}

function formatProviderTarget(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'

  return raw
    .split('/')
    .map((part) => {
      const token = toText(part)
      if (!token) return ''
      return providerTargetTokenMap[token.toLowerCase()] || token
    })
    .filter(Boolean)
    .join(' / ') || raw
}

function formatProviderMessage(value: unknown) {
  return toText(value) || '-'
}

function formatRealnameProvider(value: unknown) {
  return formatProviderCode(value)
}

function formatRealnameStatus(value: unknown) {
  return formatLogStatus(value)
}

function formatRealnameResult(item: Record<string, any>) {
  const message = toText(item.message)
  if (message) return message

  const result = toText(item.result)
  if (!result) return '-'

  return realnameResultMap[result.toLowerCase()] || result
}

const pluginNotifyActionMap: Record<string, string> = {
  refundnotify: '退款回调通知',
  transfernotify: '代付回调通知',
  notify: '支付结果通知',
  'software-report': '监控上报',
  'software-pcnotify': '监控回调',
}

const pluginNotifyStageMap: Record<string, string> = {
  'legacy-gateway': '兼容网关',
  'software-compat': '监控软件兼容',
  'order-process': '订单处理',
  gateway: '网关处理',
}

const pluginNotifyMessageMap: Record<string, string> = {
  'generic refund notify fallback skipped: refund not found': '退款回调兜底已跳过：未找到退款单',
  'generic refund notify fallback skipped: route trade mismatch': '退款回调兜底已跳过：路由订单不匹配',
  'generic refund notify fallback skipped: channel mismatch': '退款回调兜底已跳过：通道不匹配',
  'generic refund notify fallback skipped: plugin mismatch': '退款回调兜底已跳过：插件不匹配',
  'generic refund notify fallback skipped: already processed': '退款回调兜底已跳过：该退款已处理',
  'generic refund notify fallback skipped: status not recognized': '退款回调兜底已跳过：状态无法识别',
  'generic transfer notify fallback skipped: transfer not found': '代付回调兜底已跳过：未找到代付单',
  'generic transfer notify fallback skipped: invalid route channel': '代付回调兜底已跳过：路由通道无效',
  'generic transfer notify fallback skipped: channel mismatch': '代付回调兜底已跳过：通道不匹配',
  'generic transfer notify fallback skipped: plugin mismatch': '代付回调兜底已跳过：插件不匹配',
  'generic transfer notify fallback skipped: already processed': '代付回调兜底已跳过：该代付已处理',
  'generic transfer notify fallback skipped: status not recognized': '代付回调兜底已跳过：状态无法识别',
  'transfer already processed': '代付已处理',
  'refund already processed': '退款已处理',
}

const pluginNotifyContextLabelMap: Record<string, string> = {
  biz_no: '平台代付单号',
  out_biz_no: '商户代付单号',
  refund_no: '平台退款单号',
  out_refund_no: '商户退款单号',
  trade_no: '平台订单号',
  out_trade_no: '商户订单号',
  amount: '金额',
  channel_order_no: '通道流水号',
  balance_after: '处理后余额',
  current_status: '当前状态',
  status: '回调状态',
}

function formatPluginNotifyAction(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'
  return pluginNotifyActionMap[raw.toLowerCase()] || raw
}

function formatPluginNotifyStage(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'
  return pluginNotifyStageMap[raw.toLowerCase()] || raw
}

function formatPluginNotifyStatus(value: unknown) {
  return formatLogStatus(value)
}

function formatPluginNotifyMessage(value: unknown) {
  const raw = toText(value)
  if (!raw) return '-'

  const lower = raw.toLowerCase()
  if (pluginNotifyMessageMap[lower]) return pluginNotifyMessageMap[lower]

  if (raw.startsWith('支付插件未实现动作:')) {
    const action = raw.split(':').slice(1).join(':').trim()
    return `支付插件未实现该通知动作：${formatPluginNotifyAction(action)}`
  }

  return raw
}

function formatPluginNotifyTarget(item: Record<string, any>) {
  const tradeNo = toText(item.trade_no)
  if (tradeNo) return tradeNo

  const channelId = toText(item.channel_id)
  if (channelId && channelId !== '0') return `通道 #${channelId}`

  return '-'
}

function formatPluginNotifyPlugin(item: Record<string, any>) {
  const pluginCode = toText(item.plugin_code).toLowerCase()
  if (pluginCode === 'wechat-qrcode') return '微信码支付'
  if (pluginCode === 'alipay-qrcode') return '支付宝码支付'
  if (pluginCode === 'epay') return '易支付'
  if (pluginCode === 'epayn') return '易支付 V2'
  if (pluginCode) return pluginCode

  const methodCode = toText(item.method_code).toLowerCase()
  if (methodCode === 'wxpay') return '微信支付'
  if (methodCode === 'alipay') return '支付宝'
  if (methodCode) return methodCode

  return '-'
}

function getPluginNotifyContextSource(item: Record<string, any>) {
  if (item?.context && typeof item.context === 'object' && !Array.isArray(item.context) && Object.keys(item.context).length > 0) {
    return item.context
  }

  if (item?.request?.form && typeof item.request.form === 'object' && Object.keys(item.request.form).length > 0) {
    return item.request.form
  }

  if (item?.request?.query && typeof item.request.query === 'object' && Object.keys(item.request.query).length > 0) {
    return item.request.query
  }

  return null
}

function formatPluginNotifyContextPair(key: string, value: unknown) {
  if (value === undefined || value === null) return ''

  const text =
    typeof value === 'object'
      ? ''
      : key.toLowerCase().includes('status')
        ? formatPluginNotifyStatus(value)
        : toText(value)

  if (!text) return ''

  return `${pluginNotifyContextLabelMap[key] || key}=${text}`
}

function summarizeContext(item: Record<string, any>) {
  const context = getPluginNotifyContextSource(item)
  if (!context) return '-'

  const priorityKeys = [
    'biz_no',
    'out_biz_no',
    'refund_no',
    'out_refund_no',
    'trade_no',
    'out_trade_no',
    'amount',
    'channel_order_no',
    'balance_after',
    'current_status',
    'status',
  ]
  const parts = priorityKeys
    .map((key) => formatPluginNotifyContextPair(key, context[key]))
    .filter(Boolean)

  if (parts.length > 0) return parts.slice(0, 4).join(' / ')

  return Object.entries(context)
    .map(([key, value]) => formatPluginNotifyContextPair(key, value))
    .filter(Boolean)
    .slice(0, 4)
    .join(' / ') || '-'
}

onMounted(load)

watch(activeSection, () => {
  resetPagination(pagination)
})
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-workspace__top">
        <div class="settings-workspace__intro">
          <span class="settings-workspace__eyebrow">日志中心</span>
          <h2 class="settings-workspace__title">{{ pageTitle }}</h2>
          <p class="settings-workspace__copy">{{ sectionCopy }}</p>
        </div>
      </div>

      <div class="settings-stack settings-workspace__body">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">{{ pageTitle }}</h3>
            <p class="settings-block-copy">日志数据结构保持不变，这里只统一内容区的展示节奏与视觉层次。</p>
          </div>

          <div v-if="activeSection === 'admin' || activeSection === 'merchant'" class="table-wrap">
            <template v-if="(activeSection === 'admin' ? adminLogs : merchantLogs).length">
              <div class="table-head log-grid">
                <span>操作人</span>
                <span>动作</span>
                <span>详情</span>
                <span>IP地址</span>
                <span>时间</span>
              </div>
              <div
                v-for="item in pagedRows"
                :key="`${item.operator}-${item.created_at}`"
                class="table-row log-grid"
              >
                <strong>{{ formatLogOperator(item.operator) }}</strong>
                <span>{{ item.action }}</span>
                <span>{{ formatLogSummary(item) }}</span>
                <span>{{ formatLogIp(item.ip) }}</span>
                <span>{{ item.created_at }}</span>
              </div>
            </template>
            <p v-else class="empty-note log-empty">{{ emptyCopy }}</p>
          </div>

          <div v-else-if="activeSection === 'callback'" class="table-wrap">
            <div class="log-summary">
              <div
                v-for="item in callbackSummaryItems"
                :key="item.key"
                class="log-summary__item"
                :class="`is-${item.tone}`"
              >
                <span class="log-summary__label">{{ item.label }}</span>
                <strong>{{ item.value }}</strong>
              </div>
            </div>

            <template v-if="callbackLogs.length">
              <div class="table-head callback-grid">
                <span>平台订单号</span>
                <span>回调地址</span>
                <span>结果</span>
                <span>响应</span>
                <span>重试</span>
                <span>下次执行</span>
                <span>更新时间</span>
                <span>操作</span>
              </div>
              <div
                v-for="item in pagedRows"
                :key="`${item.id}-${item.updated_at}`"
                class="table-row callback-grid"
              >
                <button
                  class="table-copy-text table-copy-text--strong"
                  type="button"
                  :title="displayText(item.trade_no || item.order_id)"
                  @click="copyCellText(item.trade_no || item.order_id, '平台订单号')"
                >
                  {{ displayText(item.trade_no || item.order_id) }}
                </button>
                <button
                  class="table-copy-text"
                  type="button"
                  :title="displayText(item.notify_url)"
                  @click="copyCellText(item.notify_url, '回调地址')"
                >
                  {{ displayText(item.notify_url) }}
                </button>
                <span class="callback-result">
                  <span class="status-chip" :class="callbackStatusClass(item)">{{ formatCallbackResult(item.result) }}</span>
                  <small v-if="callbackHint()" class="callback-note">{{ callbackHint() }}</small>
                </span>
                <button
                  class="table-copy-text"
                  type="button"
                  :title="displayText(formatCallbackResponse(item.response))"
                  @click="copyCellText(formatCallbackResponse(item.response), '响应内容')"
                >
                  {{ displayText(formatCallbackResponse(item.response)) }}
                </button>
                <span>{{ item.retry_count }}/{{ item.max_retry }}</span>
                <span>{{ item.next_time || '-' }}</span>
                <span>{{ item.updated_at || item.created_at }}</span>
                <span>
                  <button
                    v-if="item.can_retry"
                    class="link-action"
                    type="button"
                    @click="retryCallback(item)"
                  >
                    立即重试
                  </button>
                  <span v-else>-</span>
                </span>
              </div>
            </template>
            <p v-else class="empty-note log-empty">{{ emptyCopy }}</p>
          </div>

          <div v-else-if="activeSection === 'provider'" class="table-wrap">
            <template v-if="providerLogs.length">
              <div class="table-head provider-grid">
                <span>类型</span>
                <span>场景</span>
                <span>服务商</span>
                <span>目标</span>
                <span>状态</span>
                <span>结果</span>
                <span>操作人</span>
                <span>时间</span>
              </div>
              <div
                v-for="item in pagedRows"
                :key="`${item.id}-${item.created_at}`"
                class="table-row provider-grid"
              >
                <strong>{{ formatProviderType(item.type) }}</strong>
                <span>{{ formatProviderScene(item.scene) }}</span>
                <span>{{ formatProviderCode(item.provider_code) }}</span>
                <span>{{ formatProviderTarget(item.target) }}</span>
                <span>{{ formatLogStatus(item.status) }}</span>
                <span>{{ formatProviderMessage(item.message) }}</span>
                <span>{{ formatLogOperator(item.operator) }}</span>
                <span>{{ item.created_at }}</span>
              </div>
            </template>
            <p v-else class="empty-note log-empty">{{ emptyCopy }}</p>
          </div>

          <div v-else-if="activeSection === 'realname'" class="table-wrap">
            <template v-if="realnameLogs.length">
              <div class="table-head realname-grid">
                <span>商户</span>
                <span>服务商</span>
                <span>姓名</span>
                <span>证件</span>
                <span>状态</span>
                <span>结果</span>
                <span>操作人</span>
                <span>时间</span>
              </div>
              <div
                v-for="item in pagedRows"
                :key="`${item.id}-${item.created_at}`"
                class="table-row realname-grid"
              >
                <strong>{{ item.merchant_id || '-' }}</strong>
                <span>{{ formatRealnameProvider(item.provider) }}</span>
                <span>{{ item.real_name || '-' }}</span>
                <span>{{ item.id_card || '-' }}</span>
                <span>{{ formatRealnameStatus(item.status) }}</span>
                <span>{{ formatRealnameResult(item) }}</span>
                <span>{{ formatLogOperator(item.operator) }}</span>
                <span>{{ item.created_at }}</span>
              </div>
            </template>
            <p v-else class="empty-note log-empty">{{ emptyCopy }}</p>
          </div>

          <div v-else class="table-wrap">
            <template v-if="pluginNotifyLogs.length">
              <div class="table-head plugin-notify-grid">
                <span>动作</span>
                <span>阶段</span>
                <span>订单/通道</span>
                <span>插件</span>
                <span>状态</span>
                <span>结果</span>
                <span>时间</span>
                <span>上下文</span>
              </div>
              <div
                v-for="item in pagedRows"
                :key="`${item.id}-${item.created_at}`"
                class="table-row plugin-notify-grid"
              >
                <strong class="plugin-notify-cell" :title="formatPluginNotifyAction(item.action)">{{ formatPluginNotifyAction(item.action) }}</strong>
                <span class="plugin-notify-cell" :title="formatPluginNotifyStage(item.stage)">{{ formatPluginNotifyStage(item.stage) }}</span>
                <span class="plugin-notify-cell" :title="formatPluginNotifyTarget(item)">{{ formatPluginNotifyTarget(item) }}</span>
                <span class="plugin-notify-cell" :title="formatPluginNotifyPlugin(item)">{{ formatPluginNotifyPlugin(item) }}</span>
                <span class="plugin-notify-cell" :title="formatPluginNotifyStatus(item.status)">{{ formatPluginNotifyStatus(item.status) }}</span>
                <span class="plugin-notify-cell" :title="formatPluginNotifyMessage(item.message || item.result_type)">{{ formatPluginNotifyMessage(item.message || item.result_type) }}</span>
                <span class="plugin-notify-cell" :title="item.created_at || '-'">{{ item.created_at }}</span>
                <span class="plugin-notify-cell" :title="summarizeContext(item)">{{ summarizeContext(item) }}</span>
              </div>
            </template>
            <p v-else class="empty-note log-empty">{{ emptyCopy }}</p>
          </div>

          <AppPagination
            :total="total"
            :page="pagination.page"
            :page-size="pagination.pageSize"
            @update:page="pagination.page = $event"
            @update:page-size="pagination.pageSize = $event"
          />
        </div>
      </div>
    </article>
  </section>
</template>

<style scoped>
.log-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr)) minmax(180px, 1.35fr);
  gap: 0;
  padding: 8px 16px;
  border-bottom: 1px solid var(--brand-border);
  background: rgba(247, 250, 255, 0.78);
}

.log-summary__item {
  min-width: 0;
  padding: 12px 16px;
}

.log-summary__item + .log-summary__item {
  border-left: 1px solid var(--brand-border);
}

.log-summary__label {
  display: block;
  color: var(--brand-subtle);
  font-size: 12px;
  margin-bottom: 8px;
}

.log-summary__item strong {
  display: block;
  color: var(--brand-text);
  font-size: 22px;
  font-weight: 800;
  line-height: 1.2;
  word-break: break-word;
}

.log-summary__item.is-time strong {
  font-size: 14px;
  font-weight: 700;
  line-height: 1.5;
}

.callback-result {
  display: grid;
  gap: 6px;
  justify-items: start;
}

.callback-note {
  color: var(--brand-subtle);
  font-size: 12px;
  line-height: 1.5;
}

.table-copy-text {
  min-width: 0;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--brand-text);
  text-align: left;
  font: inherit;
}

.table-copy-text:hover {
  color: var(--brand-primary);
}

.table-copy-text--strong {
  font-weight: 700;
}

.log-empty {
  padding: 28px 16px 32px;
  text-align: center;
  border-bottom: 1px solid var(--brand-border);
}

.log-grid {
  display: grid;
  grid-template-columns: 0.7fr 1.1fr 1.6fr 0.7fr 0.9fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.callback-grid {
  display: grid;
  grid-template-columns: 0.9fr 1.2fr 0.55fr 1.2fr 0.55fr 0.9fr 0.9fr 0.7fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.provider-grid {
  display: grid;
  grid-template-columns: 0.45fr 0.75fr 0.75fr 0.9fr 0.5fr 1.35fr 0.65fr 0.9fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.plugin-notify-grid {
  display: grid;
  grid-template-columns: 0.85fr 0.95fr 1.05fr 0.95fr 0.55fr 1.2fr 0.95fr 1.2fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.plugin-notify-cell {
  display: block;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.realname-grid {
  display: grid;
  grid-template-columns: 0.45fr 0.7fr 0.75fr 0.95fr 0.55fr 1.4fr 0.8fr 0.95fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

@media (max-width: 1180px) {
  .log-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    padding: 0;
  }

  .log-summary__item + .log-summary__item {
    border-left: 0;
  }

  .log-summary__item:nth-child(2n) {
    border-left: 1px solid var(--brand-border);
  }

  .log-summary__item:nth-child(n + 3) {
    border-top: 1px solid var(--brand-border);
  }
}

@media (max-width: 900px) {
  .log-summary,
  .log-grid,
  .callback-grid,
  .provider-grid,
  .plugin-notify-grid,
  .realname-grid {
    grid-template-columns: 1fr;
  }

  .log-summary {
    background: #fff;
  }

  .log-summary__item,
  .log-summary__item + .log-summary__item,
  .log-summary__item:nth-child(2n) {
    border-left: 0;
    border-top: 1px solid var(--brand-border);
  }

  .log-summary__item:first-child {
    border-top: 0;
  }
}
</style>
