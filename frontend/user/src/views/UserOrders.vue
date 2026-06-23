<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteUserOrder, getUserOrders, getUserSessionUser, retryUserOrderCallback } from '../lib/api'

type OrderSection = 'list' | 'callbacks'

type ListFilterState = {
  searchField: 'trade_no' | 'out_trade_no' | 'txid' | 'subject'
  keyword: string
  merchantNo: string
  payMethod: string
  channelId: string
  status: string
  startDate: string
  endDate: string
}

type ManualActionType = '' | 'confirm' | 'retry'

const route = useRoute()
const sessionUser = ref<Record<string, any>>(getUserSessionUser())
const orderData = ref<Record<string, any>>({
  items: [],
  callback_summary: {},
  callback_logs: [],
})
const listFiltersExpanded = ref(true)
const manualDialogVisible = ref(false)
const manualSubmitting = ref(false)
const manualOrder = ref<Record<string, any> | null>(null)
const manualForm = reactive({
  trade_no: '',
  out_trade_no: '',
  proof_no: '',
  remark: '',
  manual_action: '' as ManualActionType,
})

const searchFieldOptions = [
  { value: 'trade_no', label: '平台订单号' },
  { value: 'out_trade_no', label: '商户订单号' },
  { value: 'txid', label: '交易订单号' },
  { value: 'subject', label: '商品名称' },
] as const

const paymentMethodAliasMap: Record<string, string> = {
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
  jdpay: 'jdpay',
  paypal: 'paypal',
  douyin: 'douyinpay',
  douyinpay: 'douyinpay',
  ecny: 'bank',
  usdt: 'usdttrc20',
  'usdt-trc20': 'usdttrc20',
  usdttrc20: 'usdttrc20',
  trc20: 'usdttrc20',
  usdtpolygon: 'usdtpolygon',
  polygon: 'usdtpolygon',
  matic: 'usdtpolygon',
  usdtaptos: 'usdtaptos',
  aptos: 'usdtaptos',
  trx: 'trx',
  'usdt-erc20': 'erc20',
  erc20: 'erc20',
  'usdt-bsc': 'bsc',
  bep20: 'bsc',
  bsc: 'bsc',
  avaxc: 'avaxc',
  avalanche: 'avaxc',
}

const paymentMethodLabelMap: Record<string, string> = {
  wxpay: '微信支付',
  alipay: '支付宝',
  qqpay: 'QQ 钱包',
  bank: '银行卡 / 云闪付',
  jdpay: '京东支付',
  paypal: 'PayPal',
  douyinpay: '抖音支付',
  usdtaptos: 'USDT-Aptos',
  usdtpolygon: 'USDT-Polygon',
  usdttrc20: 'USDT-TRC20',
  trx: 'TRX',
  erc20: 'USDT-ERC20',
  bsc: 'USDT-BSC',
  avaxc: 'USDT-AVAXC',
}

function createListFilterState(): ListFilterState {
  return {
    searchField: 'trade_no',
    keyword: '',
    merchantNo: '',
    payMethod: '',
    channelId: '',
    status: '',
    startDate: '',
    endDate: '',
  }
}

const listFilters = reactive<ListFilterState>(createListFilterState())
const appliedListFilters = reactive<ListFilterState>(createListFilterState())

const activeSection = computed<OrderSection>(() => (route.meta.section === 'callbacks' ? 'callbacks' : 'list'))
const orderItems = computed<Record<string, any>[]>(() => (Array.isArray(orderData.value.items) ? orderData.value.items : []))
const callbackSummary = computed<Record<string, any>>(() =>
  orderData.value.callback_summary && typeof orderData.value.callback_summary === 'object' ? orderData.value.callback_summary : {},
)
const callbackItems = computed<Record<string, any>[]>(() =>
  Array.isArray(orderData.value.callback_logs) ? orderData.value.callback_logs : [],
)
const paymentMethodOptions = computed(() =>
  Array.from(new Set(orderItems.value.map((item) => resolvePaymentMethodValue(item)).filter((value) => value !== ''))).map((value) => {
    const matchedItem = orderItems.value.find((item) => resolvePaymentMethodValue(item) === value)
    return {
      label: resolvePaymentMethodLabel(matchedItem || value),
      value,
    }
  }),
)
const orderStatusOptions = computed(() =>
  Array.from(new Set(orderItems.value.map((item) => String(item.status || '').trim()).filter((value) => value !== ''))).map((value) => ({
    label: value,
    value,
  })),
)
const filteredOrderItems = computed(() =>
  orderItems.value.filter((item) => {
    const keyword = normalizeText(appliedListFilters.keyword)
    if (keyword !== '' && !normalizeText(resolveSearchValue(item, appliedListFilters.searchField)).includes(keyword)) {
      return false
    }

    const merchantNo = normalizeText(appliedListFilters.merchantNo)
    if (merchantNo !== '' && !normalizeText(resolveMerchantIdentity(item)).includes(merchantNo)) {
      return false
    }

    const payMethod = String(appliedListFilters.payMethod || '').trim()
    if (payMethod !== '' && resolvePaymentMethodValue(item) !== payMethod) {
      return false
    }

    const channelId = normalizeText(appliedListFilters.channelId)
    if (channelId !== '' && !normalizeText(resolveChannelIdentity(item)).includes(channelId)) {
      return false
    }

    const status = String(appliedListFilters.status || '').trim()
    if (status !== '' && String(item.status || '').trim() !== status) {
      return false
    }

    const createdDate = String(item.created_at || '').slice(0, 10)
    if (appliedListFilters.startDate && (!createdDate || createdDate < appliedListFilters.startDate)) {
      return false
    }

    if (appliedListFilters.endDate && (!createdDate || createdDate > appliedListFilters.endDate)) {
      return false
    }

    return true
  }),
)
const manualDialogTitle = computed(() => (manualForm.manual_action === 'confirm' ? '人工确认成功并回调' : '手动回调'))
const manualDialogPrimaryText = computed(() => (manualForm.manual_action === 'confirm' ? '确认成功并回调' : '立即回调'))
const manualProofRequired = computed(() => manualForm.manual_action === 'confirm')
const manualProofPlaceholder = computed(() =>
  manualProofRequired.value ? '请输入第三方交易订单号' : '可填写第三方交易订单号，方便后续对账',
)
const manualDialogHint = computed(() => {
  if (manualForm.manual_action === 'confirm') {
    return '当前订单会被人工标记为支付成功，并立即发起一次异步回调。'
  }

  return '当前订单会立即重新发起一次异步回调。'
})

function normalizeText(value: unknown) {
  return String(value ?? '').trim().toLowerCase()
}

function displayText(value: unknown) {
  return String(value ?? '').trim() || '-'
}

function looksLikeUnknownText(value: unknown) {
  const text = String(value ?? '').trim()
  if (text === '') {
    return true
  }

  return /^\?{2,}$/u.test(text)
}

function normalizePaymentMethodCode(value: unknown) {
  const normalized = String(value ?? '').trim().toLowerCase()
  return paymentMethodAliasMap[normalized] || normalized
}

function resolvePaymentMethodValue(item: Record<string, any>) {
  return String(item.channel_code || item.pay_type || '').trim()
}

function resolvePaymentMethodLabel(source: Record<string, any> | string) {
  if (typeof source === 'object' && source !== null) {
    const explicitName = [source.channel_name, source.method_name, source.channel_label, source.showname]
      .map((value) => String(value || '').trim())
      .find((value) => value !== '')

    if (explicitName) {
      return explicitName
    }

    const rawValue = resolvePaymentMethodValue(source)
    const normalizedCode = normalizePaymentMethodCode(rawValue)
    return paymentMethodLabelMap[normalizedCode] || rawValue || '-'
  }

  const rawValue = String(source || '').trim()
  const normalizedCode = normalizePaymentMethodCode(rawValue)
  return paymentMethodLabelMap[normalizedCode] || rawValue || '-'
}

function resolveSearchValue(item: Record<string, any>, field: ListFilterState['searchField']) {
  if (field === 'txid') {
    return item.txid_raw || item.api_trade_no || item.txid || ''
  }

  return item[field] || ''
}

function resolveOrderSubjectFallback(item: Record<string, any>) {
  switch (String(item.source_key || '').trim()) {
    case 'channel_test':
      return '通道测试订单'
    case 'homepage_payment_test':
      return '首页支付测试订单'
    case 'software_compat_test':
      return '监控软件测试订单'
    default:
      return '支付订单'
  }
}

function resolveOrderSubject(item: Record<string, any>) {
  const text = String(item.subject || item.name || '').trim()
  if (!looksLikeUnknownText(text)) {
    return text
  }

  return resolveOrderSubjectFallback(item)
}

function resolveOrderIdentityPayload(item: Record<string, any>) {
  return {
    trade_no: String(item.trade_no || '').trim(),
    out_trade_no: String(item.out_trade_no || '').trim(),
  }
}

function resolveMerchantIdentity(item: Record<string, any>) {
  return [
    item.merchant,
    item.merchant_id,
    sessionUser.value.merchant_id,
    sessionUser.value.id,
    sessionUser.value.uid,
    sessionUser.value.username,
    sessionUser.value.nickname,
  ]
    .filter((value) => value !== undefined && value !== null && String(value).trim() !== '')
    .join(' ')
}

function resolveChannelIdentity(item: Record<string, any>) {
  return [item.channel_id, item.channel_code, item.pay_type]
    .filter((value) => value !== undefined && value !== null && String(value).trim() !== '')
    .join(' ')
}

function applyListFilters() {
  Object.assign(appliedListFilters, listFilters)
}

function resetListFilters() {
  Object.assign(listFilters, createListFilterState())
  Object.assign(appliedListFilters, createListFilterState())
  listFiltersExpanded.value = true
}

function orderStatusClass(item: Record<string, any>) {
  switch (Number(item.status_code)) {
    case 1:
      return 'success'
    case 2:
      return 'danger'
    case 3:
    case 4:
      return 'muted'
    default:
      return 'warning'
  }
}

function callbackStatusClass(item: Record<string, any>) {
  if (Number(item.status_code) === 2) return 'success'
  if (Number(item.status_code) === 1) return 'danger'
  if (item.runtime_exception || item.due_now) return 'warning'
  return 'muted'
}

async function copyCellText(value: unknown, label: string) {
  const text = String(value ?? '').trim()
  if (text === '') {
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

function canManualCallback(item: Record<string, any>) {
  return Boolean(item?.can_manual_callback) || ['confirm', 'retry'].includes(String(item?.manual_action || '').trim())
}

function shouldShowDelete(item: Record<string, any>) {
  return Boolean(item?.can_delete)
}

function manualActionLabel(item: Record<string, any>) {
  return String(item?.manual_action || '').trim() === 'confirm' ? '人工确认' : '手动回调'
}

function closeManualDialog() {
  manualDialogVisible.value = false
  manualSubmitting.value = false
  manualOrder.value = null
  manualForm.trade_no = ''
  manualForm.out_trade_no = ''
  manualForm.proof_no = ''
  manualForm.remark = ''
  manualForm.manual_action = ''
}

function openManualDialog(item: Record<string, any>) {
  if (!canManualCallback(item)) {
    ElMessage.warning(String(item.action_hint || '当前订单暂不支持手动回调'))
    return
  }

  manualOrder.value = item
  manualForm.trade_no = String(item.trade_no || '').trim()
  manualForm.out_trade_no = String(item.out_trade_no || '').trim()
  manualForm.proof_no = String(item.txid_raw || item.api_trade_no || item.txid || '').trim()
  manualForm.remark = ''
  manualForm.manual_action = String(item.manual_action || '').trim() === 'confirm' ? 'confirm' : 'retry'
  manualDialogVisible.value = true
}

async function submitManualCallback() {
  if (!manualOrder.value) {
    return
  }

  const proofNo = String(manualForm.proof_no || '').trim()
  if (manualProofRequired.value && proofNo === '') {
    ElMessage.warning('请填写交易订单号')
    return
  }

  manualSubmitting.value = true
  const resp = await retryUserOrderCallback({
    ...resolveOrderIdentityPayload(manualOrder.value),
    proof_no: proofNo,
    txid: proofNo,
    remark: String(manualForm.remark || '').trim(),
  })
  manualSubmitting.value = false

  if (resp.code !== 0) {
    ElMessage.error(resp.message || '手动回调失败')
    return
  }

  ElMessage.success(
    resp.message || (manualForm.manual_action === 'confirm' ? '订单已人工确认成功并发起回调' : '手动回调已执行'),
  )
  closeManualDialog()
  await loadOrders()
}

async function removeOrder(item: Record<string, any>) {
  const orderNo = String(item.trade_no || item.out_trade_no || '').trim()
  try {
    await ElMessageBox.confirm(`确认删除订单 ${orderNo || '-'} 吗？`, '删除确认', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }

  const resp = await deleteUserOrder(resolveOrderIdentityPayload(item))
  if (resp.code !== 0) {
    ElMessage.error(resp.message || '删除订单失败')
    return
  }

  ElMessage.success(resp.message || '订单已删除')
  await loadOrders()
}

async function loadOrders() {
  const resp = await getUserOrders()
  if (resp.code === 0 && resp.data) {
    orderData.value = resp.data
  }
}

onMounted(loadOrders)
</script>

<template>
  <section class="workspace-page">
    <section class="workspace-section">
      <header class="workspace-section__head">
        <div>
          <h2>{{ activeSection === 'callbacks' ? '回调日志' : '订单列表' }}</h2>
          <p>
            {{
              activeSection === 'callbacks'
                ? '统一查看异步通知结果、响应内容和重试状态。'
                : '支持筛选订单；成功订单可重发回调，未过期测试订单可人工确认成功并回调。'
            }}
          </p>
        </div>
      </header>

      <form v-if="activeSection === 'list'" class="orders-filter" @submit.prevent="applyListFilters">
        <div class="orders-filter__row">
          <label class="orders-filter__field">
            <span class="orders-filter__label">搜索字段</span>
            <select v-model="listFilters.searchField">
              <option v-for="option in searchFieldOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>

          <label class="orders-filter__field">
            <span class="orders-filter__label">搜索内容</span>
            <input v-model="listFilters.keyword" type="text" placeholder="请输入关键词" />
          </label>

          <label class="orders-filter__field">
            <span class="orders-filter__label">商户号</span>
            <input v-model="listFilters.merchantNo" type="text" placeholder="请输入商户号" />
          </label>

          <label class="orders-filter__field">
            <span class="orders-filter__label">支付方式</span>
            <select v-model="listFilters.payMethod">
              <option value="">请选择支付方式</option>
              <option v-for="option in paymentMethodOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>
        </div>

        <div v-show="listFiltersExpanded" class="orders-filter__row orders-filter__row--secondary">
          <label class="orders-filter__field">
            <span class="orders-filter__label">通道 ID</span>
            <input v-model="listFilters.channelId" type="text" placeholder="请输入通道 ID" />
          </label>

          <label class="orders-filter__field">
            <span class="orders-filter__label">订单状态</span>
            <select v-model="listFilters.status">
              <option value="">全部状态</option>
              <option v-for="option in orderStatusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>

          <label class="orders-filter__field orders-filter__field--range">
            <span class="orders-filter__label">时间范围</span>
            <div class="orders-filter__range">
              <input v-model="listFilters.startDate" type="date" />
              <span class="orders-filter__separator">-</span>
              <input v-model="listFilters.endDate" type="date" />
            </div>
          </label>

          <div class="orders-filter__actions">
            <button class="ghost-btn" type="button" @click="resetListFilters">重置</button>
            <button class="primary-btn" type="submit">查询</button>
            <button class="orders-filter__toggle" type="button" @click="listFiltersExpanded = false">收起</button>
          </div>
        </div>

        <div v-show="!listFiltersExpanded" class="orders-filter__footer">
          <button class="ghost-btn" type="button" @click="resetListFilters">重置</button>
          <button class="primary-btn" type="submit">查询</button>
          <button class="orders-filter__toggle" type="button" @click="listFiltersExpanded = true">展开</button>
        </div>
      </form>

        <div v-if="activeSection === 'list'" class="table-wrap">
          <div class="table-head order-grid">
          <span>订单号</span>
          <span>商品名称</span>
          <span>支付方式</span>
          <span>金额</span>
          <span>状态</span>
          <span>创建时间</span>
          <span>操作</span>
        </div>

        <div v-for="item in filteredOrderItems" :key="item.trade_no || item.out_trade_no" class="table-row order-grid">
          <div class="order-no-stack">
            <div class="order-no-line">
              <button
                class="table-copy-text order-copy-text order-no-value"
                type="button"
                :title="displayText(item.trade_no)"
                @click="copyCellText(item.trade_no, '平台订单号')"
              >
                {{ displayText(item.trade_no) }}
              </button>
            </div>
            <div class="order-no-line">
              <button
                class="table-copy-text table-copy-text--muted order-copy-text order-no-value"
                type="button"
                :title="displayText(item.out_trade_no)"
                @click="copyCellText(item.out_trade_no, '商户订单号')"
              >
                {{ displayText(item.out_trade_no) }}
              </button>
            </div>
          </div>

          <span class="order-subject ellipsis-text" :title="resolveOrderSubject(item)">{{ resolveOrderSubject(item) }}</span>

          <span class="ellipsis-text" :title="resolvePaymentMethodLabel(item)">{{ resolvePaymentMethodLabel(item) }}</span>
          <span>{{ item.amount }}</span>
          <span><span class="status-chip" :class="orderStatusClass(item)">{{ item.status }}</span></span>
          <span>{{ item.created_at || '-' }}</span>

          <div class="inline-actions order-actions">
            <button
              v-if="canManualCallback(item)"
              class="link-action"
              type="button"
              @click="openManualDialog(item)"
            >
              {{ manualActionLabel(item) }}
            </button>
            <span v-else class="order-actions__hint">{{ item.action_hint || '当前不可操作' }}</span>
            <button
              v-if="shouldShowDelete(item)"
              class="link-action danger-text"
              type="button"
              @click="removeOrder(item)"
            >
              删除
            </button>
          </div>
        </div>
        <div v-if="!filteredOrderItems.length" class="callback-empty">
          <strong class="callback-empty__title">暂无订单记录</strong>
          <span class="callback-empty__copy">当商户通道发起订单后，这里会显示平台单号、商户单号、支付方式和状态。</span>
        </div>
      </div>

      <div v-else class="callback-surface">
        <div class="callback-summary">
          <div class="callback-summary__item">
            <span class="callback-summary__label">待执行</span>
            <strong>{{ callbackSummary.pending_due || 0 }}</strong>
          </div>
          <div class="callback-summary__item">
            <span class="callback-summary__label">排队中</span>
            <strong>{{ callbackSummary.pending_scheduled || 0 }}</strong>
          </div>
          <div class="callback-summary__item">
            <span class="callback-summary__label">已耗尽</span>
            <strong>{{ callbackSummary.retry_exhausted || 0 }}</strong>
          </div>
          <div class="callback-summary__item">
            <span class="callback-summary__label">下次执行</span>
            <strong>{{ callbackSummary.next_due_time || '-' }}</strong>
          </div>
        </div>

        <div class="callback-table">
          <div class="table-head callback-grid">
            <span>平台订单号</span>
            <span>回调地址</span>
            <span>结果</span>
            <span>响应</span>
            <span>重试次数</span>
            <span>下次执行</span>
            <span>更新时间</span>
          </div>

          <div v-for="item in callbackItems" :key="`${item.trade_no}-${item.created_at}`" class="table-row callback-grid">
            <button
              class="table-copy-text"
              type="button"
              :title="displayText(item.trade_no)"
              @click="copyCellText(item.trade_no, '平台订单号')"
            >
              {{ displayText(item.trade_no) }}
            </button>
            <button
              class="table-copy-text"
              type="button"
              :title="displayText(item.notify_url)"
              @click="copyCellText(item.notify_url, '回调地址')"
            >
              {{ displayText(item.notify_url) }}
            </button>
            <span><span class="status-chip" :class="callbackStatusClass(item)">{{ item.result }}</span></span>
            <button
              class="table-copy-text"
              type="button"
              :title="displayText(item.response)"
              @click="copyCellText(item.response, '响应内容')"
            >
              {{ displayText(item.response) }}
            </button>
            <span>{{ item.retry_count }}/{{ item.max_retry }}</span>
            <span>{{ item.next_time || '-' }}</span>
            <span>{{ item.updated_at || item.created_at }}</span>
          </div>

          <div v-if="!callbackItems.length" class="callback-empty">
            <strong class="callback-empty__title">暂无回调记录</strong>
            <span class="callback-empty__copy">当订单触发异步通知后，这里会显示回调结果、响应内容和重试状态。</span>
          </div>
        </div>
      </div>
    </section>

    <el-dialog v-model="manualDialogVisible" :title="manualDialogTitle" width="520px" @closed="closeManualDialog">
      <div class="dialog-form">
        <div class="manual-note">
          <strong>{{ manualDialogHint }}</strong>
          <span>平台订单号：{{ manualForm.trade_no || '-' }}</span>
          <span>商户订单号：{{ manualForm.out_trade_no || '-' }}</span>
        </div>

        <div class="field-grid">
          <label class="field field-inline">
            <span class="field-label field-label--inline">交易订单号</span>
            <input
              v-model="manualForm.proof_no"
              type="text"
              :placeholder="manualProofPlaceholder"
            />
          </label>

          <label class="field field-span-2">
            <span class="field-label">备注</span>
            <textarea
              v-model="manualForm.remark"
              rows="4"
              :placeholder="manualForm.manual_action === 'confirm' ? '可填写人工确认说明' : '可填写本次重发回调备注'"
            />
          </label>
        </div>
      </div>

      <template #footer>
        <button class="ghost-btn" type="button" @click="closeManualDialog">取消</button>
        <button class="primary-btn" type="button" :disabled="manualSubmitting" @click="submitManualCallback">
          {{ manualSubmitting ? '提交中...' : manualDialogPrimaryText }}
        </button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.workspace-page {
  display: grid;
  gap: 12px;
}

.workspace-section {
  border: 1px solid var(--brand-border);
  background: #fff;
  border-radius: 10px;
  overflow: hidden;
}

.workspace-section__head {
  padding: 14px 16px 10px;
  border-bottom: 1px solid var(--brand-border);
}

.workspace-section__head h2 {
  margin: 0;
  font-size: 16px;
}

.workspace-section__head p {
  margin: 8px 0 0;
  color: var(--brand-subtle);
  font-size: 12px;
  line-height: 1.75;
}

.orders-filter {
  display: grid;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--brand-border);
  background: #fff;
}

.orders-filter__row {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px 12px;
  align-items: center;
}

.orders-filter__row--secondary {
  grid-template-columns: minmax(0, 0.9fr) minmax(0, 0.95fr) minmax(0, 1.22fr) auto;
}

.orders-filter__field {
  display: grid;
  grid-template-columns: 58px minmax(0, 1fr);
  gap: 10px;
  align-items: center;
  min-width: 0;
}

.orders-filter__field--range {
  grid-template-columns: 58px minmax(0, 1fr);
}

.orders-filter__label {
  color: var(--brand-text);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}

.orders-filter__range {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  align-items: center;
  gap: 10px;
}

.orders-filter__separator {
  color: var(--brand-subtle);
  font-size: 12px;
  text-align: center;
}

.orders-filter__actions,
.orders-filter__footer {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.orders-filter__toggle {
  min-height: 42px;
  padding: 0 4px;
  border: 0;
  background: transparent;
  color: var(--brand-primary);
  font-weight: 700;
}

.order-grid {
  display: grid;
  grid-template-columns: 1.8fr 1.18fr 0.92fr 0.62fr 0.84fr 0.96fr 0.96fr;
  gap: 12px;
  align-items: center;
}

.order-no-head {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.order-no-stack {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.order-no-line {
  display: block;
  min-width: 0;
}

.order-no-line + .order-no-line {
  margin-top: 2px;
}

.order-no-value {
  min-width: 0;
}

.order-subject {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.order-subject {
  color: var(--brand-text);
}

.order-hint {
  color: var(--brand-subtle);
  font-size: 12px;
  line-height: 1.5;
}

.order-actions {
  justify-content: flex-start;
  flex-wrap: wrap;
}

.order-actions__hint {
  color: var(--brand-subtle);
  font-size: 12px;
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

.table-copy-text--muted {
  color: var(--brand-text-soft);
}

.order-copy-text {
  font-size: 12px;
  font-weight: 600;
}

.ellipsis-text {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.callback-surface {
  display: grid;
  gap: 14px;
  padding: 16px;
  background: #fff;
}

.callback-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.callback-summary__item {
  min-width: 0;
  display: grid;
  gap: 6px;
  padding: 14px 16px;
  border: 1px solid var(--brand-border);
  border-radius: 10px;
  background: var(--brand-panel-soft);
}

.callback-summary__label {
  display: block;
  color: var(--brand-subtle);
  font-size: 12px;
}

.callback-summary__item strong {
  display: block;
  font-size: 28px;
  font-weight: 700;
  line-height: 1.2;
  word-break: break-word;
  color: var(--brand-text);
}

.callback-table {
  border: 1px solid var(--brand-border);
  border-radius: 10px;
  overflow: hidden;
  background: #fff;
}

.callback-grid {
  display: grid;
  grid-template-columns: 0.9fr 1.25fr 0.65fr 1fr 0.7fr 0.9fr 0.9fr;
  gap: 12px;
  align-items: center;
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

.callback-empty {
  display: grid;
  justify-items: center;
  gap: 8px;
  padding: 40px 16px 44px;
  text-align: center;
  color: var(--brand-subtle);
}

.callback-empty__title {
  color: var(--brand-text);
  font-size: 15px;
  font-weight: 700;
}

.callback-empty__copy {
  max-width: 560px;
  font-size: 12px;
  line-height: 1.8;
}

.dialog-form {
  display: grid;
  gap: 14px;
}

.manual-note {
  display: grid;
  gap: 6px;
  padding: 12px 14px;
  border: 1px solid var(--brand-border);
  border-radius: 10px;
  background: var(--brand-panel-soft);
  color: var(--brand-text-soft);
  font-size: 12px;
  line-height: 1.6;
}

.manual-note strong {
  color: var(--brand-text);
  font-size: 13px;
}

.field-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.field {
  display: grid;
  gap: 8px;
}

.field-inline {
  grid-column: 1 / -1;
  grid-template-columns: 92px minmax(0, 1fr);
  align-items: center;
  gap: 12px;
}

.field-span-2 {
  grid-column: 1 / -1;
}

.field-label {
  color: var(--brand-text);
  font-size: 12px;
  font-weight: 700;
}

.field-label--inline {
  white-space: nowrap;
}

.field input,
.field textarea {
  width: 100%;
}

@media (max-width: 1180px) {
  .callback-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .orders-filter__row,
  .orders-filter__row--secondary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .orders-filter__actions {
    grid-column: 1 / -1;
  }
}

@media (max-width: 900px) {
  .orders-filter {
    padding: 16px;
  }

  .callback-surface {
    padding: 14px;
  }

  .callback-summary,
  .orders-filter__row,
  .orders-filter__row--secondary,
  .order-grid,
  .callback-grid,
  .field-grid {
    grid-template-columns: 1fr;
  }

  .orders-filter__field,
  .orders-filter__field--range {
    grid-template-columns: 1fr;
    gap: 8px;
  }

  .orders-filter__range {
    grid-template-columns: 1fr;
    gap: 8px;
  }

  .orders-filter__separator {
    display: none;
  }

  .orders-filter__actions,
  .orders-filter__footer {
    justify-content: flex-start;
  }

  .field-inline {
    grid-template-columns: 1fr;
    gap: 8px;
  }
}
</style>
