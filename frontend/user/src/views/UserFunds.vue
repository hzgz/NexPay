<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import AppPagination from '../components/AppPagination.vue'
import { createUserRecharge, createUserWithdraw, getUserFunds } from '../lib/api'
import { usePagination } from '../lib/pagination'

type RechargeOption = Record<string, any>
type WithdrawOption = Record<string, any>

const route = useRoute()
const loadingKey = ref('')
const withdrawLoading = ref(false)
const rechargeAmount = ref('')
const selectedRechargeMethod = ref('')
const fundData = ref<Record<string, any>>({
  balance: {},
  flow_stats: {},
  recharge_options: [],
  payout_summary: { refunds: {}, transfers: {} },
  pending_payouts: [],
  settlements: [],
  flows: [],
})
const withdrawForm = ref({
  money: '',
  account_type: 'alipay',
  account: '',
  account_name: '',
  remark: '',
})

const activeSection = computed<'recharge' | 'flows' | 'withdraw'>(() => {
  return route.meta.section === 'flows' || route.meta.section === 'withdraw' ? route.meta.section : 'recharge'
})

const rechargeOptions = computed<RechargeOption[]>(() => {
  return Array.isArray(fundData.value.recharge_options) ? fundData.value.recharge_options : []
})

const withdrawOptions = computed<WithdrawOption[]>(() => {
  const accountTypes = fundData.value.withdraw_options?.account_types
  if (!Array.isArray(accountTypes)) {
    return []
  }

  return accountTypes
    .map((item) => {
      if (typeof item === 'string') {
        const value = item.trim()
        return value ? { value, code: value, label: value } : null
      }

      if (!item || typeof item !== 'object') {
        return null
      }

      const value = String(item.value || item.code || '').trim()
      if (!value) {
        return null
      }

      return {
        ...item,
        value,
        code: value,
        label: String(item.label || item.name || value),
      }
    })
    .filter((item): item is WithdrawOption => Boolean(item))
})

const payoutSummaryCards = computed(() => [
  { label: '人工待退款', value: Number(fundData.value.payout_summary?.refunds?.manual_pending ?? 0) },
  { label: '自动退款同步', value: Number(fundData.value.payout_summary?.refunds?.plugin_pending ?? 0) },
  { label: '人工待代付', value: Number(fundData.value.payout_summary?.transfers?.manual_pending ?? 0) },
  { label: '自动代付同步', value: Number(fundData.value.payout_summary?.transfers?.plugin_pending ?? 0) },
])

const pendingPayouts = computed<Record<string, any>[]>(() => {
  return Array.isArray(fundData.value.pending_payouts) ? fundData.value.pending_payouts : []
})

const settlementRows = computed<Record<string, any>[]>(() => (
  Array.isArray(fundData.value.settlements) ? fundData.value.settlements : []
))

const flowRows = computed<Record<string, any>[]>(() => (
  Array.isArray(fundData.value.flows) ? fundData.value.flows : []
))

const flowSummaryCards = computed(() => {
  const stats = fundData.value.flow_stats || {}

  return [
    { label: '可用余额', value: `${formatSummaryMoney(stats.available_balance)} 元` },
    { label: '提现金额', value: `${formatSummaryMoney(stats.withdraw_amount)} 元` },
    { label: '订单数', value: String(Number(stats.order_count ?? 0)) },
  ]
})

const currentAvailableBalance = computed(() => {
  const balance = fundData.value.balance || {}
  return formatSummaryMoney(balance.available ?? fundData.value.flow_stats?.available_balance ?? 0)
})

const selectedRechargeOptionData = computed<RechargeOption | null>(() => {
  const methodCode = selectedRechargeMethod.value.trim()
  if (!methodCode) {
    return null
  }

  return rechargeOptions.value.find((item) => rechargeMethodCode(item) === methodCode) || null
})

const selectedRechargeActionKey = computed(() => {
  return selectedRechargeOptionData.value ? rechargeActionKey(selectedRechargeOptionData.value) : ''
})

const { pagination: payoutPagination, total: payoutTotal, pagedRows: pagedPendingPayouts } = usePagination(() => pendingPayouts.value, 20)
const { pagination: settlementPagination, total: settlementTotal, pagedRows: pagedSettlements } = usePagination(() => settlementRows.value, 20)
const { pagination: flowPagination, total: flowTotal, pagedRows: pagedFlows } = usePagination(() => flowRows.value, 20)

async function loadFunds() {
  const resp = await getUserFunds()
  if (resp.code === 0 && resp.data) {
    fundData.value = resp.data

    if (String(rechargeAmount.value ?? '').trim() === '') {
      rechargeAmount.value = resolveDefaultRechargeAmount(resp.data.recharge_options || [])
    }

    const availableMethods = Array.isArray(resp.data.recharge_options)
      ? resp.data.recharge_options
        .map((item: Record<string, any>) => String(item?.method_code || item?.code || item?.channel_code || '').trim())
        .filter((value: string) => value)
      : []

    if (!selectedRechargeMethod.value || !availableMethods.includes(selectedRechargeMethod.value)) {
      const firstMethod = availableMethods[0] || ''
      selectedRechargeMethod.value = firstMethod
    }

    const accountTypes = Array.isArray(resp.data.withdraw_options?.account_types) ? resp.data.withdraw_options.account_types : []
    const normalizedTypes = accountTypes
      .map((item: Record<string, any> | string) => typeof item === 'string' ? item.trim() : String(item?.value || item?.code || '').trim())
      .filter((value: string) => value)

    if (normalizedTypes.length > 0 && !normalizedTypes.includes(withdrawForm.value.account_type)) {
      withdrawForm.value.account_type = normalizedTypes[0]
    }
  }
}

function resolveDefaultRechargeAmount(options: RechargeOption[]) {
  const minList = options
    .map((item) => Number(item?.min || item?.single_min_amount || 0))
    .filter((value) => Number.isFinite(value) && value > 0)

  if (minList.length === 0) {
    return ''
  }

  return Math.min(...minList).toFixed(2)
}

function formatMoney(value: string | number) {
  const amount = Number(value)
  if (!Number.isFinite(amount) || amount <= 0) {
    return '0.00'
  }

  return amount.toFixed(2)
}

function formatSummaryMoney(value: string | number) {
  const amount = Number(value)
  if (!Number.isFinite(amount)) {
    return '0.00'
  }

  return amount.toFixed(2)
}

function rechargeActionKey(item: RechargeOption) {
  return String(item.method_code || item.code || item.channel_code || item.name || 'recharge')
}

function rechargeMethodCode(item: RechargeOption) {
  return String(item.method_code || item.code || item.channel_code || '').trim()
}

function rechargeLimitText(item: RechargeOption) {
  const minAmount = Number(item.min || item.single_min_amount || 0)
  const maxAmount = Number(item.max || item.single_max_amount || 0)

  if (minAmount > 0 && maxAmount > 0) {
    return `单笔范围 ${formatMoney(minAmount)} - ${formatMoney(maxAmount)} 元`
  }

  if (minAmount > 0) {
    return `单笔最低 ${formatMoney(minAmount)} 元`
  }

  if (maxAmount > 0) {
    return `单笔最高 ${formatMoney(maxAmount)} 元`
  }

  return '支持自由填写充值金额'
}

function rechargeMethodSummary(item: RechargeOption) {
  const desc = String(item.desc || '').trim()
  if (desc) {
    return desc
  }

  return '使用后台系统业务支付配置创建充值订单。'
}

function normalizedRechargeAmount() {
  return String(rechargeAmount.value ?? '').replace(/,/g, '').trim()
}

function validateRechargeAmount(item: RechargeOption) {
  const amountText = normalizedRechargeAmount()
  if (amountText === '') {
    ElMessage.warning('请先填写充值金额')
    return ''
  }

  const amount = Number(amountText)
  if (!Number.isFinite(amount) || amount <= 0) {
    ElMessage.error('充值金额必须大于 0')
    return ''
  }

  const minAmount = Number(item.min || item.single_min_amount || 0)
  if (minAmount > 0 && amount < minAmount) {
    ElMessage.error(`当前充值方式最低金额为 ${formatMoney(minAmount)} 元`)
    return ''
  }

  const maxAmount = Number(item.max || item.single_max_amount || 0)
  if (maxAmount > 0 && amount > maxAmount) {
    ElMessage.error(`当前充值方式最高金额为 ${formatMoney(maxAmount)} 元`)
    return ''
  }

  return amount.toFixed(2)
}

async function recharge(item: RechargeOption | null = selectedRechargeOptionData.value) {
  if (!item) {
    ElMessage.warning('请先选择充值方式')
    return
  }

  const amount = validateRechargeAmount(item)
  if (!amount) return

  const methodCode = rechargeMethodCode(item)
  const actionKey = rechargeActionKey(item)

  loadingKey.value = actionKey
  const resp = await createUserRecharge({
    amount,
    type: methodCode,
    method_code: methodCode,
  })
  loadingKey.value = ''

  if (resp.code === 0 && resp.data?.pay_url) {
    ElMessage.success('充值订单已创建，请在新窗口完成支付，支付完成后刷新查看余额')
    window.open(String(resp.data.pay_url), '_blank', 'noopener')
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

async function withdraw() {
  withdrawLoading.value = true
  const resp = await createUserWithdraw(withdrawForm.value)
  withdrawLoading.value = false
  if (resp.code === 0) {
    ElMessage.success(resp.message || '提现申请已提交')
    withdrawForm.value.money = ''
    withdrawForm.value.remark = ''
    await loadFunds()
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

function settlementStatusClass(item: Record<string, any>) {
  if (Number(item.status_code) === 1) return 'success'
  if (Number(item.status_code) === 2) return 'danger'
  return 'warning'
}

function flowStatusClass(item: Record<string, any>) {
  const theme = String(item.status_theme || '').trim()
  if (theme) return theme

  const status = String(item.status || '').trim()
  if (status === '成功') return 'success'
  if (status === '失败' || status === '已关闭' || status === '已驳回') return 'danger'
  return 'warning'
}

function displayText(value: unknown) {
  return String(value ?? '').trim() || '-'
}

function flowOrderTitle(item: Record<string, any>) {
  return [item.trade_no, item.out_trade_no].map((value) => String(value || '').trim()).filter(Boolean).join('\n') || '-'
}

onMounted(loadFunds)
</script>

<template>
  <section class="workspace-page">
    <section v-if="activeSection === 'recharge'" class="workspace-section">
      <header class="workspace-section__head">
        <div>
          <h2>在线充值</h2>
          <p>先输入金额，再选择充值方式创建订单，支付页会在新窗口打开，当前页保留资金视图。</p>
        </div>
      </header>

      <div class="funds-recharge-editor">
        <div class="funds-recharge-row">
          <label class="funds-recharge-inline">
            <span class="field-label funds-recharge-inline__label">充值金额</span>
            <input v-model="rechargeAmount" type="number" min="0.01" step="0.01" placeholder="请输入充值金额" />
          </label>
          <div class="funds-balance-panel">
            <span class="funds-balance-panel__label">当前余额</span>
            <strong class="funds-balance-panel__value">{{ currentAvailableBalance }} 元</strong>
          </div>
        </div>
        <p class="funds-recharge-hint">支持自由填写金额，提交时会自动按当前充值方式的限额校验。</p>
      </div>

      <div v-if="rechargeOptions.length" class="funds-option-section">
        <div class="funds-option-section__head">
          <div>
            <h3>支付方式</h3>
            <p>选择一个当前可用的充值方式后创建订单。</p>
          </div>
          <span v-if="selectedRechargeOptionData" class="status-chip success funds-option-current">
            已选 {{ selectedRechargeOptionData.name }}
          </span>
        </div>

        <div class="funds-option-grid">
          <label
            v-for="item in rechargeOptions"
            :key="rechargeActionKey(item)"
            class="funds-option-card funds-option-card--select"
            :class="{ 'is-active': selectedRechargeMethod === rechargeMethodCode(item) }"
          >
            <div class="funds-option-card__top">
              <div class="funds-option-copy">
                <strong>{{ item.name }}</strong>
                <span>{{ rechargeMethodSummary(item) }}</span>
              </div>
              <span class="funds-option-card__radio">
                <input v-model="selectedRechargeMethod" type="radio" :value="rechargeMethodCode(item)" />
              </span>
            </div>
            <div class="funds-option-meta">
              <span class="status-chip muted funds-option-meta__chip">{{ rechargeLimitText(item) }}</span>
            </div>
          </label>
        </div>

        <div class="workspace-actions workspace-actions--recharge">
          <button class="primary-btn" :disabled="loadingKey === selectedRechargeActionKey || !selectedRechargeMethod" @click="recharge()">
            {{ loadingKey === selectedRechargeActionKey ? '创建中...' : '立即充值' }}
          </button>
        </div>
      </div>
      <div v-else class="empty-note funds-empty">暂无可用充值方式。</div>
    </section>

    <section v-else-if="activeSection === 'withdraw'" class="workspace-section">
      <header class="workspace-section__head">
        <div>
          <h2>申请提现</h2>
          <p>填写收款信息后提交申请，审核完成后会进入结算记录。</p>
        </div>
      </header>

      <div v-if="payoutSummaryCards.some((item) => item.value > 0)" class="funds-summary-grid">
        <article v-for="item in payoutSummaryCards" :key="item.label" class="funds-summary-card">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
      </div>

      <form class="field-grid compact" @submit.prevent="withdraw">
        <label class="field">
          <span class="field-label">提现金额</span>
          <input v-model="withdrawForm.money" type="number" min="0.01" step="0.01" />
        </label>
        <label class="field">
          <span class="field-label">账户类型</span>
          <select v-model="withdrawForm.account_type">
            <option v-for="item in withdrawOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
          </select>
        </label>
        <label class="field">
          <span class="field-label">收款账号</span>
          <input v-model="withdrawForm.account" type="text" autocomplete="off" />
        </label>
        <label class="field">
          <span class="field-label">收款姓名</span>
          <input v-model="withdrawForm.account_name" type="text" autocomplete="off" />
        </label>
        <label class="field field-span-2">
          <span class="field-label">备注</span>
          <input v-model="withdrawForm.remark" type="text" />
        </label>
      </form>

      <div class="workspace-actions">
        <button class="primary-btn" :disabled="withdrawLoading" type="button" @click="withdraw">
          {{ withdrawLoading ? '提交中...' : '提交提现申请' }}
        </button>
      </div>

      <div v-if="pendingPayouts.length" class="table-wrap">
        <div class="table-head payout-grid">
          <span>类型</span>
          <span>业务单号</span>
          <span>关联单号</span>
          <span>金额</span>
          <span>处理方式</span>
          <span>状态</span>
          <span>原因</span>
          <span>时间</span>
        </div>
        <div v-for="item in pagedPendingPayouts" :key="`${item.category}-${item.biz_no}`" class="table-row payout-grid">
          <strong>{{ item.category_label }}</strong>
          <span>{{ item.biz_no }}</span>
          <span>{{ item.trade_no || item.out_biz_no || '-' }}</span>
          <span>{{ item.amount }}</span>
          <span>{{ item.mode_label }}</span>
          <span>{{ item.status }}</span>
          <span>{{ item.errmsg || item.proof_no || '-' }}</span>
          <span>{{ item.created_at }}</span>
        </div>
        <AppPagination
          :total="payoutTotal"
          :page="payoutPagination.page"
          :page-size="payoutPagination.pageSize"
          @update:page="payoutPagination.page = $event"
          @update:page-size="payoutPagination.pageSize = $event"
        />
      </div>

      <div class="table-wrap">
        <div class="table-head settlement-grid">
          <span>结算单号</span>
          <span>金额</span>
          <span>账户</span>
          <span>状态</span>
          <span>时间</span>
        </div>
        <div v-for="item in pagedSettlements" :key="item.settle_no" class="table-row settlement-grid">
          <strong>{{ item.settle_no }}</strong>
          <span>{{ item.money }}</span>
          <span>{{ item.account }}</span>
          <span><span class="status-chip" :class="settlementStatusClass(item)">{{ item.status }}</span></span>
          <span>{{ item.created_at }}</span>
        </div>
        <AppPagination
          :total="settlementTotal"
          :page="settlementPagination.page"
          :page-size="settlementPagination.pageSize"
          @update:page="settlementPagination.page = $event"
          @update:page-size="settlementPagination.pageSize = $event"
        />
      </div>
    </section>

    <section v-else class="workspace-section">
      <header class="workspace-section__head">
        <div>
          <h2>资金流水</h2>
          <p>系统内商户消费和余额变动统一在这里记录；商户通道业务订单仍归订单管理。</p>
        </div>
      </header>

      <div class="funds-overview-grid">
        <article v-for="item in flowSummaryCards" :key="item.label" class="funds-summary-card funds-summary-card--metric">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
      </div>

      <div class="table-wrap">
        <div class="table-head flow-grid">
          <span>类型</span>
          <span>订单号</span>
          <span>商品名称</span>
          <span>支付方式</span>
          <span>变动金额</span>
          <span>变动后余额</span>
          <span>状态</span>
          <span>备注</span>
          <span>时间</span>
        </div>
        <div v-for="item in pagedFlows" :key="item.row_key || (item.type + item.created_at + item.remark)" class="table-row flow-grid">
          <div class="flow-type-stack">
            <strong>{{ item.type }}</strong>
            <span class="flow-type-stack__meta">{{ item.source_label || '-' }}</span>
          </div>
          <div class="flow-order-stack" :title="flowOrderTitle(item)">
            <span class="ellipsis-text">{{ displayText(item.trade_no) }}</span>
            <span v-if="item.out_trade_no" class="flow-order-stack__sub ellipsis-text">{{ displayText(item.out_trade_no) }}</span>
          </div>
          <span class="ellipsis-text" :title="displayText(item.subject)">{{ displayText(item.subject) }}</span>
          <span class="ellipsis-text" :title="displayText(item.method_name)">{{ displayText(item.method_name) }}</span>
          <span>{{ item.amount }}</span>
          <span>{{ item.balance_after || '-' }}</span>
          <span><span class="status-chip" :class="flowStatusClass(item)">{{ item.status || '-' }}</span></span>
          <span class="ellipsis-text" :title="displayText(item.remark)">{{ displayText(item.remark) }}</span>
          <span>{{ item.created_at }}</span>
        </div>
        <AppPagination
          :total="flowTotal"
          :page="flowPagination.page"
          :page-size="flowPagination.pageSize"
          @update:page="flowPagination.page = $event"
          @update:page-size="flowPagination.pageSize = $event"
        />
      </div>
    </section>
  </section>
</template>

<style scoped>
.workspace-page {
  display: grid;
  gap: 16px;
}

.workspace-section {
  border: 1px solid var(--brand-border);
  background: #fff;
}

.workspace-section__head {
  padding: 18px 22px 14px;
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

.funds-recharge-editor {
  display: grid;
  gap: 10px;
  padding: 18px 22px 12px;
}

.funds-recharge-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}

.funds-recharge-inline {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1 1 0;
  min-width: 0;
  max-width: none;
}

.funds-recharge-inline__label {
  flex: 0 0 auto;
  margin: 0;
  white-space: nowrap;
}

.funds-recharge-inline input {
  flex: 1 1 0;
  min-width: 0;
}

.funds-balance-panel {
  display: grid;
  gap: 6px;
  flex: 0 0 auto;
  min-width: 148px;
  padding: 10px 12px;
  border: 1px solid var(--brand-border);
  background: #f8fbff;
}

.funds-balance-panel__label {
  color: #72829b;
  font-size: 12px;
  line-height: 1.4;
}

.funds-balance-panel__value {
  color: #20344f;
  font-size: 18px;
  line-height: 1.2;
}

.funds-recharge-hint {
  margin: 0;
  color: #72829b;
  font-size: 12px;
  line-height: 1.6;
}

.funds-option-section {
  display: grid;
  gap: 0;
  border-top: 1px solid var(--brand-border);
}

.funds-option-section__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  padding: 16px 22px 14px;
  border-bottom: 1px solid var(--brand-border);
  background: #fbfdff;
}

.funds-option-section__head h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
}

.funds-option-section__head p {
  margin: 6px 0 0;
  color: #72829b;
  font-size: 12px;
  line-height: 1.6;
}

.funds-option-current {
  min-width: auto;
  white-space: nowrap;
}

.funds-option-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  padding: 16px 22px 0;
}

.funds-option-card {
  display: grid;
  gap: 12px;
  min-width: 0;
  padding: 14px 16px;
  border: 1px solid var(--brand-border);
  border-radius: 8px;
  background: #fff;
  transition: border-color 0.18s ease, background-color 0.18s ease, box-shadow 0.18s ease;
}

.funds-option-card__top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.funds-option-card__radio {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  padding-top: 2px;
}

.funds-option-card__radio input {
  width: 16px;
  height: 16px;
  min-height: 16px;
  margin: 0;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: transparent;
  box-shadow: none;
  accent-color: var(--brand-primary);
  cursor: pointer;
}

.funds-option-card__radio input:focus {
  box-shadow: none;
}

.funds-option-copy {
  display: grid;
  gap: 4px;
  min-width: 0;
}

.funds-option-copy strong {
  color: #20344f;
  font-size: 14px;
  line-height: 1.35;
}

.funds-option-copy span {
  color: #72829b;
  font-size: 12px;
  line-height: 1.55;
}

.funds-option-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.funds-option-meta__chip {
  justify-content: flex-start;
  min-width: 0;
  max-width: 100%;
  padding: 0 9px;
  font-size: 11px;
  font-weight: 600;
}

.funds-summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  padding: 18px 22px 0;
}

.funds-overview-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  padding: 18px 22px 0;
}

.funds-summary-card {
  display: grid;
  gap: 6px;
  padding: 14px 16px;
  border: 1px solid var(--brand-border);
  background: #f8fbff;
}

.funds-summary-card span {
  color: #72829b;
  font-size: 12px;
}

.funds-summary-card strong {
  color: #20344f;
  font-size: 18px;
}

.funds-summary-card--metric strong {
  font-size: 20px;
}

.flow-grid {
  display: grid;
  grid-template-columns: 0.9fr 1.3fr 1.1fr 0.95fr 0.72fr 0.82fr 0.86fr 1.15fr 1fr;
  gap: 12px;
  align-items: center;
}

.flow-type-stack,
.flow-order-stack {
  display: grid;
  gap: 4px;
  min-width: 0;
}

.flow-type-stack__meta,
.flow-order-stack__sub {
  color: var(--brand-text-soft);
  font-size: 12px;
}

.ellipsis-text {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.payout-grid {
  display: grid;
  grid-template-columns: 0.7fr 1fr 1fr 0.7fr 0.8fr 0.9fr 1.2fr 1fr;
  gap: 12px;
  align-items: center;
}

.settlement-grid {
  display: grid;
  grid-template-columns: 1.2fr 0.7fr 1fr 0.7fr 1fr;
  gap: 12px;
  align-items: center;
}

.funds-empty {
  padding: 18px 22px 22px;
  color: #72829b;
  font-size: 12px;
}

@media (max-width: 900px) {
  .funds-option-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 540px) {
  .funds-option-grid,
  .funds-overview-grid,
  .flow-grid,
  .settlement-grid {
    grid-template-columns: 1fr;
  }

  .funds-recharge-row {
    flex-wrap: wrap;
    align-items: stretch;
  }

  .funds-recharge-inline {
    width: 100%;
    max-width: none;
    flex-wrap: wrap;
  }

  .funds-recharge-inline input {
    flex: 1 1 100%;
    min-width: 0;
  }

  .funds-balance-panel {
    width: 100%;
    min-width: 0;
  }

  .funds-option-section__head,
  .workspace-actions--recharge {
    flex-direction: column;
    align-items: stretch;
  }

  .funds-option-current,
  .workspace-actions__hint {
    white-space: normal;
  }

  .workspace-actions--recharge .primary-btn {
    width: 100%;
  }

  .workspace-actions {
    justify-content: flex-start;
  }
}

.funds-option-card--select {
  cursor: pointer;
}

.funds-option-card--select:hover {
  border-color: #cfe0ff;
  background: #fbfdff;
}

.funds-option-card--select.is-active {
  border-color: rgba(13, 102, 255, 0.34);
  background: rgba(13, 102, 255, 0.05);
  box-shadow: inset 0 0 0 1px rgba(13, 102, 255, 0.08);
}

.workspace-actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 12px;
  padding: 14px 22px 18px;
}

.workspace-actions--recharge {
  padding-top: 2px;
}
</style>
