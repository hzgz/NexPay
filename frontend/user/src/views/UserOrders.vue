<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { getUserOrders, getUserSessionUser } from '../lib/api'

type OrderSection = 'list' | 'callbacks'

type ListFilterState = {
  searchField: 'trade_no' | 'out_trade_no' | 'txid'
  keyword: string
  merchantNo: string
  payMethod: string
  channelId: string
  status: string
  startDate: string
  endDate: string
}

const route = useRoute()
const sessionUser = ref<Record<string, any>>(getUserSessionUser())
const orderData = ref<Record<string, any>>({
  items: [],
  callback_summary: {},
  callback_logs: [],
})
const listFiltersExpanded = ref(true)

const searchFieldOptions = [
  { value: 'trade_no', label: '系统订单号' },
  { value: 'out_trade_no', label: '商户订单号' },
  { value: 'txid', label: '交易流水号' },
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
  qqpay: 'QQ钱包',
  bank: '银联 / 云闪付',
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

const activeSection = computed<OrderSection>(() => route.meta.section === 'callbacks' ? 'callbacks' : 'list')

const orderItems = computed<Record<string, any>[]>(() => Array.isArray(orderData.value.items) ? orderData.value.items : [])
const callbackSummary = computed<Record<string, any>>(() =>
  orderData.value.callback_summary && typeof orderData.value.callback_summary === 'object' ? orderData.value.callback_summary : {},
)
const callbackItems = computed<Record<string, any>[]>(() => Array.isArray(orderData.value.callback_logs) ? orderData.value.callback_logs : [])

const paymentMethodOptions = computed(() =>
  Array.from(
    new Set(orderItems.value.map((item) => resolvePaymentMethodValue(item)).filter((value) => value !== '')),
  ).map((value) => {
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

function normalizeText(value: unknown) {
  return String(value ?? '').trim().toLowerCase()
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
    return item.txid || item.api_trade_no || ''
  }

  return item[field] || ''
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
            {{ activeSection === 'callbacks'
              ? '异步通知结果、响应内容和重试状态统一展示。'
              : '保留搜索和筛选，但去掉多余套娃容器，让内容区更干净。' }}
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
            <input v-model="listFilters.keyword" type="text" placeholder="搜索内容" />
          </label>

          <label class="orders-filter__field">
            <span class="orders-filter__label">商户号</span>
            <input v-model="listFilters.merchantNo" type="text" placeholder="商户号" />
          </label>

          <label class="orders-filter__field">
            <span class="orders-filter__label">支付方式</span>
            <select v-model="listFilters.payMethod">
              <option value="">选择支付方式</option>
              <option v-for="option in paymentMethodOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>
        </div>

        <div v-show="listFiltersExpanded" class="orders-filter__row orders-filter__row--secondary">
          <label class="orders-filter__field">
            <span class="orders-filter__label">通道ID</span>
            <input v-model="listFilters.channelId" type="text" placeholder="通道ID" />
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
          <span>平台订单号</span>
          <span>商户订单号</span>
          <span>支付方式</span>
          <span>金额</span>
          <span>状态</span>
          <span>创建时间</span>
        </div>
        <div v-for="item in filteredOrderItems" :key="item.trade_no" class="table-row order-grid">
          <strong>{{ item.trade_no }}</strong>
          <span>{{ item.out_trade_no }}</span>
          <span>{{ resolvePaymentMethodLabel(item) }}</span>
          <span>{{ item.amount }}</span>
          <span><span class="status-chip" :class="orderStatusClass(item)">{{ item.status }}</span></span>
          <span>{{ item.created_at }}</span>
        </div>
      </div>

      <div v-else class="table-wrap">
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
          <strong>{{ item.trade_no }}</strong>
          <span>{{ item.notify_url }}</span>
          <span><span class="status-chip" :class="callbackStatusClass(item)">{{ item.result }}</span></span>
          <span>{{ item.response || '-' }}</span>
          <span>{{ item.retry_count }}/{{ item.max_retry }}</span>
          <span>{{ item.next_time || '-' }}</span>
          <span>{{ item.updated_at || item.created_at }}</span>
        </div>
      </div>
    </section>
  </section>
</template>

<style scoped>
.workspace-page {
  display: grid;
  gap: 12px;
}

.callback-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 18px;
  padding: 0 0 16px;
  margin-bottom: 16px;
  border-bottom: 1px solid var(--brand-border);
}

.callback-summary__item {
  min-width: 0;
}

.callback-summary__label {
  display: block;
  color: var(--brand-subtle);
  font-size: 12px;
  margin-bottom: 6px;
}

.callback-summary__item strong {
  display: block;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.5;
  word-break: break-word;
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
  grid-template-columns: 1.2fr 1.15fr 0.82fr 0.7fr 0.82fr 1fr;
  gap: 12px;
  align-items: center;
}

.callback-grid {
  display: grid;
  grid-template-columns: 0.9fr 1.25fr 0.65fr 1fr 0.7fr 0.9fr 0.9fr;
  gap: 12px;
  align-items: center;
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

  .callback-summary,
  .orders-filter__row,
  .orders-filter__row--secondary,
  .order-grid,
  .callback-grid {
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
}
</style>
