<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { createUserRecharge, createUserWithdraw, getUserFunds } from '../lib/api'

type RechargeOption = Record<string, any>
type WithdrawOption = Record<string, any>

const route = useRoute()
const loadingKey = ref('')
const withdrawLoading = ref(false)
const rechargeAmount = ref('')
const selectedRechargeMethod = ref('')
const fundData = ref<Record<string, any>>({
  balance: {},
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

async function loadFunds() {
  const resp = await getUserFunds()
  if (resp.code === 0 && resp.data) {
    fundData.value = resp.data

    if (rechargeAmount.value.trim() === '') {
      rechargeAmount.value = resolveDefaultRechargeAmount(resp.data.recharge_options || [])
    }

    if (!selectedRechargeMethod.value) {
      const firstMethod = Array.isArray(resp.data.recharge_options) && resp.data.recharge_options.length > 0
        ? String(resp.data.recharge_options[0]?.method_code || resp.data.recharge_options[0]?.code || '')
        : ''
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

function normalizedRechargeAmount() {
  return rechargeAmount.value.replace(/,/g, '').trim()
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

function selectedRechargeOption() {
  return rechargeOptions.value.find((item) => rechargeMethodCode(item) === selectedRechargeMethod.value) || null
}

async function recharge(item: RechargeOption | null = selectedRechargeOption()) {
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
        <label class="field funds-recharge-field">
          <span class="field-label">充值金额</span>
          <input v-model="rechargeAmount" type="number" min="0.01" step="0.01" placeholder="请输入充值金额" />
        </label>
        <p class="funds-recharge-hint">支持自由填写金额，提交时会自动按当前充值方式的限额校验。</p>
      </div>

      <div v-if="rechargeOptions.length" class="funds-option-list">
        <label
          v-for="item in rechargeOptions"
          :key="rechargeActionKey(item)"
          class="funds-option-row funds-option-row--select"
          :class="{ 'is-active': selectedRechargeMethod === rechargeMethodCode(item) }"
        >
          <div class="funds-option-copy">
            <strong>{{ item.name }}</strong>
            <span v-if="item.desc">{{ item.desc }}</span>
            <span>{{ rechargeLimitText(item) }}</span>
          </div>
          <div class="funds-option-side">
            <input v-model="selectedRechargeMethod" type="radio" :value="rechargeMethodCode(item)" />
          </div>
        </label>
        <div class="workspace-actions">
          <button class="primary-btn" :disabled="loadingKey === selectedRechargeMethod || !selectedRechargeMethod" @click="recharge()">
            {{ loadingKey === selectedRechargeMethod ? '创建中...' : '立即充值' }}
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
        <div v-for="item in pendingPayouts" :key="`${item.category}-${item.biz_no}`" class="table-row payout-grid">
          <strong>{{ item.category_label }}</strong>
          <span>{{ item.biz_no }}</span>
          <span>{{ item.trade_no || item.out_biz_no || '-' }}</span>
          <span>{{ item.amount }}</span>
          <span>{{ item.mode_label }}</span>
          <span>{{ item.status }}</span>
          <span>{{ item.errmsg || item.proof_no || '-' }}</span>
          <span>{{ item.created_at }}</span>
        </div>
      </div>

      <div class="table-wrap">
        <div class="table-head settlement-grid">
          <span>结算单号</span>
          <span>金额</span>
          <span>账户</span>
          <span>状态</span>
          <span>时间</span>
        </div>
        <div v-for="item in fundData.settlements || []" :key="item.settle_no" class="table-row settlement-grid">
          <strong>{{ item.settle_no }}</strong>
          <span>{{ item.money }}</span>
          <span>{{ item.account }}</span>
          <span><span class="status-chip" :class="settlementStatusClass(item)">{{ item.status }}</span></span>
          <span>{{ item.created_at }}</span>
        </div>
      </div>
    </section>

    <section v-else class="workspace-section">
      <header class="workspace-section__head">
        <div>
          <h2>资金流水</h2>
          <p>所有余额变动按时间顺序展示，便于排查订单、充值和结算影响。</p>
        </div>
      </header>

      <div class="table-wrap">
        <div class="table-head flow-grid">
          <span>类型</span>
          <span>变动金额</span>
          <span>变动后余额</span>
          <span>时间</span>
        </div>
        <div v-for="item in fundData.flows || []" :key="item.type + item.created_at" class="table-row flow-grid">
          <strong>{{ item.type }}</strong>
          <span>{{ item.amount }}</span>
          <span>{{ item.balance_after }}</span>
          <span>{{ item.created_at }}</span>
        </div>
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

.funds-recharge-field {
  max-width: 320px;
}

.funds-recharge-hint {
  margin: 0;
  color: #72829b;
  font-size: 12px;
  line-height: 1.6;
}

.funds-option-list {
  display: grid;
}

.funds-option-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 16px;
  align-items: center;
  padding: 16px 22px;
  border-top: 1px solid var(--brand-border);
}

.funds-option-copy {
  display: grid;
  gap: 6px;
}

.funds-option-copy strong {
  color: #20344f;
  font-size: 14px;
}

.funds-option-copy span {
  color: #72829b;
  font-size: 12px;
  line-height: 1.6;
}

.funds-summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
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

.flow-grid {
  display: grid;
  grid-template-columns: 1fr 0.8fr 0.8fr 1fr;
  gap: 12px;
  align-items: center;
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
  .funds-option-row,
  .flow-grid,
  .settlement-grid {
    grid-template-columns: 1fr;
  }

  .funds-option-row .primary-btn {
    width: 100%;
  }

  .workspace-actions {
    justify-content: flex-start;
  }
}
</style>
.funds-option-row--select {
  cursor: pointer;
}

.funds-option-row--select.is-active {
  background: #f7fbff;
}

.funds-option-side {
  display: flex;
  align-items: center;
  justify-content: flex-end;
}

.workspace-actions {
  display: flex;
  justify-content: flex-end;
  padding: 14px 22px 18px;
}
