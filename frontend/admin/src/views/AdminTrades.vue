<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  confirmAdminOrderPayment,
  confirmAdminRefund,
  getAdminOrders,
  reviewAdminSettlement,
  reviewAdminTransfer,
  syncAdminRefund,
  syncAdminRefundBatch,
  syncAdminTransfer,
  syncAdminTransferBatch,
} from '../lib/api'

const route = useRoute()
const keyword = ref('')
const orderStatusFilter = ref('all')
const payoutMode = ref<'all' | 'auto' | 'manual'>('all')
const batchSyncLoading = ref(false)
const data = ref<Record<string, any>>({
  items: [],
  refunds: [],
  transfers: [],
  payout_summary: { refunds: {}, transfers: {} },
  earnings: [],
  settlements: [],
})

const activeSection = computed<'orders' | 'refunds' | 'transfers' | 'earnings' | 'settlements'>(() => {
  const section = route.meta.section
  return section === 'refunds' || section === 'transfers' || section === 'earnings' || section === 'settlements' ? section : 'orders'
})

const sectionMeta = computed(() => {
  if (activeSection.value === 'refunds') {
    return {
      eyebrow: '退款审核',
      title: '退款列表',
      copy: '集中处理退款状态同步、人工确认和待处理积压。',
    }
  }

  if (activeSection.value === 'transfers') {
    return {
      eyebrow: '代付审核',
      title: '代付审核',
      copy: '集中处理代付状态同步、人工通过和驳回操作。',
    }
  }

  if (activeSection.value === 'settlements') {
    return {
      eyebrow: '结算审核',
      title: '结算审核',
      copy: '在这里处理商户提现申请和结算审核。',
    }
  }

  if (activeSection.value === 'earnings') {
    return {
      eyebrow: '收益记录',
      title: '收益列表',
      copy: '查看平台收益来源和最近入账情况。',
    }
  }

  return {
    eyebrow: '订单审核',
    title: '订单列表',
    copy: '在这里处理订单、收款凭证和人工确认。',
  }
})

const payoutSummary = computed<Record<string, any>>(() => {
  if (activeSection.value !== 'refunds' && activeSection.value !== 'transfers') {
    return {}
  }

  const source = data.value.payout_summary && typeof data.value.payout_summary === 'object' ? data.value.payout_summary : {}
  const section = source[activeSection.value]
  return section && typeof section === 'object' ? section : {}
})

const payoutSummaryCards = computed(() => {
  if (activeSection.value === 'refunds') {
    return [
      { label: '自动同步中', value: payoutSummary.value.plugin_pending || 0 },
      { label: '人工待退款', value: payoutSummary.value.manual_pending || 0 },
      { label: '退款成功', value: payoutSummary.value.success || 0 },
      { label: '退款失败', value: payoutSummary.value.failed || 0 },
    ]
  }

  if (activeSection.value === 'transfers') {
    return [
      { label: '自动同步中', value: payoutSummary.value.plugin_pending || 0 },
      { label: '人工待代付', value: payoutSummary.value.manual_pending || 0 },
      { label: '代付成功', value: payoutSummary.value.success || 0 },
      { label: '代付失败', value: (payoutSummary.value.failed || 0) + (payoutSummary.value.rejected || 0) },
    ]
  }

  return []
})

const sectionToolbarPlaceholder = computed(() => {
  if (activeSection.value === 'refunds') {
    return '搜索退款单号、原订单号或商户'
  }

  if (activeSection.value === 'transfers') {
    return '搜索代付单号、商户或收款人'
  }

  if (activeSection.value === 'settlements') {
    return '搜索结算单号、商户或账户'
  }

  if (activeSection.value === 'earnings') {
    return '搜索收益类型、商户或备注'
  }

  return '搜索平台单号、商户单号或商户'
})

const sectionEmptyText = computed(() => {
  if (activeSection.value === 'refunds') {
    return '暂无退款记录'
  }

  if (activeSection.value === 'transfers') {
    return '暂无代付记录'
  }

  if (activeSection.value === 'settlements') {
    return '暂无结算审核记录'
  }

  if (activeSection.value === 'earnings') {
    return '暂无收益记录'
  }

  return '暂无订单记录'
})

const orderStatusOptions = computed(() => {
  const items = (data.value.items || []) as Record<string, any>[]
  return Array.from(
    new Set(
      items
        .map((item) => String(item.status || '').trim())
        .filter(Boolean),
    ),
  )
})

const currentRows = computed(() => {
  const rows = activeSection.value === 'orders'
    ? data.value.items || []
    : activeSection.value === 'refunds'
      ? data.value.refunds || []
      : activeSection.value === 'transfers'
        ? data.value.transfers || []
        : activeSection.value === 'settlements'
          ? data.value.settlements || []
          : data.value.earnings || []

  let scopedRows = rows

  if (activeSection.value === 'refunds' || activeSection.value === 'transfers') {
    scopedRows = rows.filter((row: Record<string, any>) => {
      if (payoutMode.value === 'auto') {
        return String(row.mode || '').trim() === 'auto'
      }
      if (payoutMode.value === 'manual') {
        return String(row.mode || '').trim() === 'manual'
      }
      return true
    })
  }

  if (activeSection.value === 'orders' && orderStatusFilter.value !== 'all') {
    scopedRows = scopedRows.filter((row: Record<string, any>) => String(row.status || '').trim() === orderStatusFilter.value)
  }

  const query = keyword.value.trim().toLowerCase()
  if (!query) return scopedRows

  return scopedRows.filter((row: Record<string, any>) => Object.values(row).some((value) => String(value).toLowerCase().includes(query)))
})

const batchSyncCandidates = computed<Record<string, any>[]>(() => {
  if (activeSection.value === 'refunds') {
    return (currentRows.value || []).filter((item: Record<string, any>) => canSyncRefund(item))
  }

  if (activeSection.value === 'transfers') {
    return (currentRows.value || []).filter((item: Record<string, any>) => canSyncTransfer(item))
  }

  return []
})

async function loadData() {
  const resp = await getAdminOrders()
  if (resp.code === 0 && resp.data) {
    data.value = resp.data
  }
}

async function reviewSettlement(item: Record<string, any>, action: 'approve' | 'reject') {
  let reason = ''
  if (action === 'reject') {
    try {
      const value = await ElMessageBox.prompt('请输入驳回原因', '驳回提现', {
        confirmButtonText: '确认驳回',
        cancelButtonText: '取消',
      })
      reason = String(value.value || '').trim()
    } catch {
      return
    }
  }

  const resp = await reviewAdminSettlement({
    settle_no: item.settle_no,
    action,
    reason,
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '已处理')
    await loadData()
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

async function confirmOrder(item: Record<string, any>) {
  let proofNo = ''
  try {
    const value = await ElMessageBox.prompt('请输入真实收款凭证号或流水号', '人工确认收款', {
      confirmButtonText: '确认收款',
      cancelButtonText: '取消',
      inputPlaceholder: '例如银行流水号、支付宝或微信交易号',
      inputPattern: /\S{4,}/,
      inputErrorMessage: '凭证号至少 4 个字符',
    })
    proofNo = String(value.value || '').trim()
  } catch {
    return
  }

  let remark = ''
  try {
    const value = await ElMessageBox.prompt('可选填写核对说明，留空也可以', '收款备注', {
      confirmButtonText: '提交',
      cancelButtonText: '跳过',
      inputPlaceholder: '例如线下转账已核对',
    })
    remark = String(value.value || '').trim()
  } catch {
    remark = ''
  }

  const resp = await confirmAdminOrderPayment({
    trade_no: item.trade_no,
    proof_no: proofNo,
    remark,
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '订单已确认收款')
    await loadData()
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

async function confirmRefund(item: Record<string, any>) {
  let proofNo = ''
  try {
    const value = await ElMessageBox.prompt('请输入真实退款凭证号或流水号', '人工确认退款', {
      confirmButtonText: '确认退款',
      cancelButtonText: '取消',
      inputPlaceholder: '例如银行、支付宝或微信退款流水号',
      inputPattern: /\S{4,}/,
      inputErrorMessage: '凭证号至少 4 个字符',
    })
    proofNo = String(value.value || '').trim()
  } catch {
    return
  }

  let remark = ''
  try {
    const value = await ElMessageBox.prompt('可选填写核对说明，留空也可以', '退款备注', {
      confirmButtonText: '提交',
      cancelButtonText: '跳过',
      inputPlaceholder: '例如已核对原支付渠道退款回单',
    })
    remark = String(value.value || '').trim()
  } catch {
    remark = ''
  }

  const resp = await confirmAdminRefund({
    refund_no: item.refund_no,
    proof_no: proofNo,
    remark,
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '退款已确认')
    await loadData()
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

async function syncRefundStatus(item: Record<string, any>) {
  const resp = await syncAdminRefund({ refund_no: item.refund_no })
  if (resp.code === 0) {
    const bucket = String(resp.data?.bucket || '').trim()
    const errmsg = String(resp.data?.errmsg || '').trim()
    const message = bucket === 'completed'
      ? '退款状态已同步成功'
      : bucket === 'manualized'
        ? '退款已转入人工待处理'
        : errmsg
          ? `退款状态已同步：${errmsg}`
          : (resp.message || '退款状态已同步')
    ElMessage.success(message)
    await loadData()
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

async function reviewTransfer(item: Record<string, any>, action: 'approve' | 'reject') {
  let proofNo = ''
  let remark = ''

  if (action === 'approve') {
    try {
      const value = await ElMessageBox.prompt('请输入真实代付凭证号或流水号', '人工确认代付', {
        confirmButtonText: '确认代付',
        cancelButtonText: '取消',
        inputPlaceholder: '例如银行、支付宝或微信提现流水号',
        inputPattern: /\S{4,}/,
        inputErrorMessage: '凭证号至少 4 个字符',
      })
      proofNo = String(value.value || '').trim()
    } catch {
      return
    }

    try {
      const value = await ElMessageBox.prompt('可选填写核对说明，留空也可以', '代付备注', {
        confirmButtonText: '提交',
        cancelButtonText: '跳过',
        inputPlaceholder: '例如已核对收款账户与渠道回单',
      })
      remark = String(value.value || '').trim()
    } catch {
      remark = ''
    }
  } else {
    try {
      const value = await ElMessageBox.prompt('请输入驳回原因', '驳回代付', {
        confirmButtonText: '确认驳回',
        cancelButtonText: '取消',
        inputPlaceholder: '例如收款账户信息不完整',
      })
      remark = String(value.value || '').trim()
    } catch {
      return
    }
  }

  const resp = await reviewAdminTransfer({
    biz_no: item.biz_no,
    action,
    proof_no: proofNo,
    reason: remark,
    remark,
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '代付审核已处理')
    await loadData()
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

async function syncTransferStatus(item: Record<string, any>) {
  const resp = await syncAdminTransfer({ biz_no: item.biz_no })
  if (resp.code === 0) {
    const bucket = String(resp.data?.bucket || '').trim()
    const errmsg = String(resp.data?.errmsg || '').trim()
    const message = bucket === 'completed'
      ? '代付状态已同步成功'
      : bucket === 'manualized'
        ? '代付已转入人工待处理'
        : errmsg
          ? `代付状态已同步：${errmsg}`
          : (resp.message || '代付状态已同步')
    ElMessage.success(message)
    await loadData()
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

async function syncBatchStatus() {
  const candidates = batchSyncCandidates.value
  if (!candidates.length) {
    ElMessage.warning(activeSection.value === 'refunds' ? '当前没有可批量同步的退款记录' : '当前没有可批量同步的代付记录')
    return
  }

  batchSyncLoading.value = true
  const resp = activeSection.value === 'refunds'
    ? await syncAdminRefundBatch({
      refund_nos: candidates.map((item) => item.refund_no),
      limit: candidates.length,
    })
    : await syncAdminTransferBatch({
      biz_nos: candidates.map((item) => item.biz_no),
      limit: candidates.length,
    })
  batchSyncLoading.value = false

  if (resp.code === 0) {
    ElMessage.success(`批量同步完成：成功 ${Number(resp.data?.completed ?? 0)}，转人工 ${Number(resp.data?.manualized ?? 0)}，待重试 ${Number(resp.data?.deferred ?? 0)}，失败 ${Number(resp.data?.failed ?? 0)}`)
    await loadData()
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

function canSyncRefund(item: Record<string, any>) {
  return Number(item.status_code) === 0 && String(item.result || '').trim() === 'plugin_refund_pending'
}

function canSyncTransfer(item: Record<string, any>) {
  return Number(item.status_code) === 0 && String(item.result || '').trim() === 'plugin_transfer_pending'
}

function modeLabel(item: Record<string, any>) {
  const mode = String(item.mode || '').trim()
  if (mode === 'manual') return '人工处理'
  if (mode === 'auto') return '自动同步'
  return '-'
}

function statusClass(item: Record<string, any>) {
  if (Number(item.status_code) === 1) return 'success'
  if (Number(item.status_code) === 2) return 'danger'
  return 'warning'
}

onMounted(loadData)
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-stack settings-workspace__body">
        <div class="settings-block trade-panel">
          <div class="trade-panel__head">
            <div class="trade-panel__intro">
              <h3 class="settings-block-title">{{ sectionMeta.title }}</h3>
              <p class="settings-block-copy">{{ sectionMeta.copy }}</p>
            </div>
            <div class="toolbar-actions trade-panel__actions">
              <input
                v-model="keyword"
                type="text"
                :placeholder="sectionToolbarPlaceholder"
                class="search-input trade-toolbar-input"
              />
              <select
                v-if="activeSection === 'orders'"
                v-model="orderStatusFilter"
                class="mode-select trade-toolbar-select"
              >
                <option value="all">全部状态</option>
                <option v-for="status in orderStatusOptions" :key="status" :value="status">{{ status }}</option>
              </select>
              <template v-if="activeSection === 'refunds' || activeSection === 'transfers'">
                <select v-model="payoutMode" class="mode-select trade-toolbar-select">
                  <option value="all">全部处理方式</option>
                  <option value="auto">自动同步</option>
                  <option value="manual">人工处理</option>
                </select>
                <button
                  v-if="batchSyncCandidates.length"
                  class="primary-btn"
                  type="button"
                  :disabled="batchSyncLoading"
                  @click="syncBatchStatus"
                >
                  {{ batchSyncLoading ? '同步中...' : `批量同步 ${batchSyncCandidates.length} 条` }}
                </button>
              </template>
            </div>
          </div>

          <div v-if="payoutSummaryCards.length" class="payout-summary">
            <div v-for="card in payoutSummaryCards" :key="card.label" class="payout-summary__item">
              <span class="payout-summary__label">{{ card.label }}</span>
              <strong>{{ card.value }}</strong>
            </div>
          </div>

          <div class="table-wrap trade-table">
            <template v-if="activeSection === 'orders'">
              <div class="table-head order-grid">
                <span>平台单号</span>
                <span>商户单号</span>
                <span>商户</span>
                <span>通道</span>
                <span>金额</span>
                <span>状态</span>
                <span>时间</span>
                <span>操作</span>
              </div>
              <div v-for="item in currentRows" :key="item.trade_no" class="table-row order-grid">
                <strong>{{ item.trade_no }}</strong>
                <span>{{ item.out_trade_no }}</span>
                <span>{{ item.merchant }}</span>
                <span>{{ item.channel_code }}</span>
                <span>{{ item.amount }}</span>
                <span><span class="status-chip" :class="statusClass(item)">{{ item.status }}</span></span>
                <span>{{ item.created_at }}</span>
                <span class="inline-actions">
                  <button v-if="Number(item.status_code) === 0" class="link-action" type="button" @click="confirmOrder(item)">确认收款</button>
                  <span v-else>{{ item.pay_time || item.txid || '-' }}</span>
                </span>
              </div>
              <div v-if="!currentRows.length" class="table-empty">{{ sectionEmptyText }}</div>
            </template>

            <template v-else-if="activeSection === 'refunds'">
              <div class="table-head refund-grid">
                <span>退款单号</span>
                <span>原订单号</span>
                <span>商户</span>
                <span>金额</span>
                <span>处理方式</span>
                <span>状态</span>
                <span>时间</span>
                <span>操作</span>
              </div>
              <div v-for="item in currentRows" :key="item.refund_no" class="table-row refund-grid">
                <strong>{{ item.refund_no }}</strong>
                <span>{{ item.trade_no }}</span>
                <span>{{ item.merchant }}</span>
                <span>{{ item.amount }}</span>
                <span>{{ modeLabel(item) }}</span>
                <span><span class="status-chip" :class="statusClass(item)">{{ item.status }}</span></span>
                <span>{{ item.created_at }}</span>
                <span class="inline-actions">
                  <button v-if="canSyncRefund(item)" class="link-action" type="button" @click="syncRefundStatus(item)">同步状态</button>
                  <button v-if="Number(item.status_code) === 0" class="link-action" type="button" @click="confirmRefund(item)">确认退款</button>
                  <span v-else>{{ item.proof_no || item.operator || '-' }}</span>
                </span>
              </div>
              <div v-if="!currentRows.length" class="table-empty">{{ sectionEmptyText }}</div>
            </template>

            <template v-else-if="activeSection === 'transfers'">
              <div class="table-head transfer-grid">
                <span>代付单号</span>
                <span>外部单号</span>
                <span>商户</span>
                <span>收款人</span>
                <span>金额</span>
                <span>处理方式</span>
                <span>状态</span>
                <span>时间</span>
                <span>操作</span>
              </div>
              <div v-for="item in currentRows" :key="item.biz_no" class="table-row transfer-grid">
                <strong>{{ item.biz_no }}</strong>
                <span>{{ item.out_biz_no }}</span>
                <span>{{ item.merchant }}</span>
                <span>{{ item.name || item.account }}</span>
                <span>{{ item.amount }}</span>
                <span>{{ modeLabel(item) }}</span>
                <span><span class="status-chip" :class="statusClass(item)">{{ item.status }}</span></span>
                <span>{{ item.created_at }}</span>
                <span class="inline-actions">
                  <button v-if="canSyncTransfer(item)" class="link-action" type="button" @click="syncTransferStatus(item)">同步状态</button>
                  <button v-if="Number(item.status_code) === 0" class="link-action" type="button" @click="reviewTransfer(item, 'approve')">通过</button>
                  <button v-if="Number(item.status_code) === 0" class="link-action danger-text" type="button" @click="reviewTransfer(item, 'reject')">驳回</button>
                  <span v-else>{{ item.proof_no || item.operator || '-' }}</span>
                </span>
              </div>
              <div v-if="!currentRows.length" class="table-empty">{{ sectionEmptyText }}</div>
            </template>

            <template v-else-if="activeSection === 'settlements'">
              <div class="table-head settlement-grid">
                <span>结算单号</span>
                <span>商户</span>
                <span>金额</span>
                <span>账户</span>
                <span>状态</span>
                <span>时间</span>
                <span>操作</span>
              </div>
              <div v-for="item in currentRows" :key="item.settle_no" class="table-row settlement-grid">
                <strong>{{ item.settle_no }}</strong>
                <span>{{ item.merchant || item.merchant_id }}</span>
                <span>{{ item.money }}</span>
                <span>{{ item.account }}</span>
                <span><span class="status-chip" :class="statusClass(item)">{{ item.status }}</span></span>
                <span>{{ item.created_at }}</span>
                <span class="inline-actions">
                  <button v-if="Number(item.status_code) === 0" class="link-action" type="button" @click="reviewSettlement(item, 'approve')">通过</button>
                  <button v-if="Number(item.status_code) === 0" class="link-action danger-text" type="button" @click="reviewSettlement(item, 'reject')">驳回</button>
                  <span v-else>{{ item.operator || '-' }}</span>
                </span>
              </div>
              <div v-if="!currentRows.length" class="table-empty">{{ sectionEmptyText }}</div>
            </template>

            <template v-else>
              <div class="table-head earning-grid">
                <span>类型</span>
                <span>商户</span>
                <span>金额</span>
                <span>备注</span>
                <span>时间</span>
              </div>
              <div v-for="item in currentRows" :key="`${item.type}-${item.created_at}`" class="table-row earning-grid">
                <strong>{{ item.type }}</strong>
                <span>{{ item.merchant }}</span>
                <span>{{ item.amount }}</span>
                <span>{{ item.remark }}</span>
                <span>{{ item.created_at }}</span>
              </div>
              <div v-if="!currentRows.length" class="table-empty">{{ sectionEmptyText }}</div>
            </template>
          </div>
        </div>
      </div>
    </article>
  </section>
</template>

<style scoped>
.search-input {
  width: 280px;
}

.mode-select {
  min-width: 152px;
}

.trade-panel {
  background: #fff;
}

.trade-panel__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 18px;
  padding: 16px 18px 14px;
  border-bottom: 1px solid var(--brand-border);
}

.trade-panel__intro {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.trade-panel__actions {
  justify-content: flex-end;
  flex-wrap: nowrap;
  align-items: center;
}

.trade-toolbar-input {
  flex: 1 1 420px;
  min-width: 320px;
  max-width: 460px;
  border-color: var(--brand-border-strong);
  background: #f8fbff;
}

.trade-toolbar-select {
  width: 168px;
  min-width: 168px;
  flex: 0 0 168px;
  border-color: var(--brand-border-strong);
  background: #f8fbff;
}

.trade-panel__intro :deep(.settings-block-copy) {
  max-width: 60ch;
}

.trade-table {
  border-top: 0;
}

.payout-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  border-bottom: 1px solid var(--brand-border);
  background: #fff;
}

.payout-summary__item {
  min-width: 0;
  padding: 14px 18px 12px;
}

.payout-summary__item + .payout-summary__item {
  border-left: 1px solid var(--brand-border);
}

.payout-summary__label {
  display: block;
  color: var(--brand-subtle);
  font-size: 12px;
  margin-bottom: 8px;
}

.payout-summary__item strong {
  display: block;
  font-size: 24px;
  font-weight: 800;
  line-height: 1;
  word-break: break-word;
  letter-spacing: -0.03em;
}

.table-empty {
  display: grid;
  place-items: center;
  min-height: 124px;
  padding: 18px;
  color: var(--brand-subtle);
  font-size: 13px;
  background: #fff;
}

.order-grid {
  display: grid;
  grid-template-columns: 1.15fr 1.1fr 1fr 0.8fr 0.7fr 0.75fr 1fr 0.9fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.refund-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 0.65fr 0.85fr 0.75fr 1fr 0.8fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.earning-grid {
  display: grid;
  grid-template-columns: 0.9fr 1fr 0.7fr 1.2fr 1fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.transfer-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 0.9fr 0.95fr 0.6fr 0.85fr 0.75fr 1fr 0.9fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.settlement-grid {
  display: grid;
  grid-template-columns: 1.15fr 0.65fr 0.65fr 0.9fr 0.7fr 1fr 0.9fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.danger-text {
  color: var(--brand-danger);
}

@media (max-width: 820px) {
  .search-input,
  .mode-select {
    width: 100%;
  }

  .trade-toolbar-input,
  .trade-toolbar-select {
    min-width: 100%;
    max-width: none;
  }

  .trade-panel__head {
    grid-template-columns: 1fr;
    display: grid;
    align-items: stretch;
  }

  .trade-panel__actions {
    flex-wrap: wrap;
    justify-content: stretch;
  }

  .payout-summary,
  .order-grid,
  .refund-grid,
  .earning-grid,
  .transfer-grid,
  .settlement-grid {
    grid-template-columns: 1fr;
  }

  .payout-summary__item + .payout-summary__item {
    border-left: 0;
    border-top: 1px solid var(--brand-border);
  }
}
</style>
