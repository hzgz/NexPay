<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import VChart from 'vue-echarts'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart, PieChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import { getAdminDashboard } from '../lib/api'

use([CanvasRenderer, LineChart, PieChart, GridComponent, TooltipComponent, LegendComponent])

const error = ref('')
const data = ref<Record<string, any>>({
  cards: {},
  todos: {},
  trend: [],
  latest_orders: [],
})

const cards = computed(() => [
  { label: '交易总额', value: data.value.cards?.total_amount ?? '0.00' },
  { label: '商户总数', value: data.value.cards?.merchant_count ?? 0 },
  { label: '订单总数', value: data.value.cards?.order_count ?? 0 },
  { label: '充值总额', value: data.value.cards?.recharge_amount ?? '0.00' },
])

const todos = computed(() => [
  { label: '待审核商户', value: Number(data.value.todos?.pending_merchants ?? 0) },
  { label: '待处理订单', value: Number(data.value.todos?.pending_orders ?? 0) },
  { label: '待回复工单', value: Number(data.value.todos?.pending_tickets ?? 0) },
  { label: '风控提醒', value: Number(data.value.todos?.risk_alerts ?? 0) },
])

const opsItems = computed(() => [
  { label: '人工待退款', value: Number(data.value.payout_summary?.refunds?.manual_pending ?? 0) },
  { label: '人工待代付', value: Number(data.value.payout_summary?.transfers?.manual_pending ?? 0) },
  { label: '回调待执行', value: Number(data.value.callback_summary?.pending_due ?? 0) },
  { label: '回调已耗尽', value: Number(data.value.callback_summary?.retry_exhausted ?? 0) },
])

const overviewRows = computed(() => [
  cards.value.map((item) => ({
    ...item,
    copy:
      item.label === '交易总额'
        ? '平台累计交易金额概览。'
        : item.label === '商户总数'
          ? '当前已创建的商户账号总量。'
          : item.label === '订单总数'
            ? '平台累计订单笔数。'
            : '平台累计充值金额概览。',
  })),
])

const lineOption = computed(() => ({
  tooltip: {
    trigger: 'axis',
    backgroundColor: 'rgba(15, 23, 42, 0.92)',
    borderWidth: 0,
    textStyle: { color: '#f8fafc' },
  },
  grid: { left: 10, right: 10, top: 18, bottom: 18, containLabel: true },
  xAxis: {
    type: 'category',
    boundaryGap: false,
    data: (data.value.trend || []).map((item: Record<string, any>) => item.date),
    axisLine: { lineStyle: { color: '#d8e3f0' } },
    axisTick: { show: false },
    axisLabel: { color: '#607089', fontSize: 12 },
  },
  yAxis: [
    {
      type: 'value',
      splitLine: { lineStyle: { color: '#eef2f7' } },
      axisLabel: { color: '#607089', fontSize: 12 },
    },
    {
      type: 'value',
      splitLine: { show: false },
      axisLabel: { color: '#8aa0b8', fontSize: 12 },
    },
  ],
  series: [
    {
      name: '交易额',
      type: 'line',
      smooth: 0.42,
      yAxisIndex: 0,
      symbol: 'none',
      lineStyle: { width: 4, color: '#1677ff' },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: 'rgba(22,119,255,0.24)' },
            { offset: 1, color: 'rgba(22,119,255,0.02)' },
          ],
        },
      },
      data: (data.value.trend || []).map((item: Record<string, any>) => Number(item.amount || 0)),
    },
    {
      name: '成功订单数',
      type: 'line',
      smooth: 0.42,
      yAxisIndex: 1,
      symbol: 'none',
      lineStyle: { width: 2, color: '#7cb1ff' },
      itemStyle: { color: '#7cb1ff' },
      data: (data.value.trend || []).map((item: Record<string, any>) => Number(item.orders || 0)),
    },
  ],
}))

const donutOption = computed(() => ({
  tooltip: { trigger: 'item' },
  legend: {
    bottom: 0,
    left: 'center',
    itemWidth: 10,
    itemHeight: 10,
    textStyle: { color: '#607089', fontSize: 12 },
  },
  series: [
    {
      type: 'pie',
      radius: ['54%', '74%'],
      center: ['50%', '42%'],
      avoidLabelOverlap: true,
      label: { show: false },
      labelLine: { show: false },
      data: todos.value.map((item, index) => ({
        name: item.label,
        value: item.value,
        itemStyle: {
          color: ['#1677ff', '#4f9bff', '#8bbdff', '#d2e7ff'][index],
        },
      })),
    },
  ],
}))

onMounted(async () => {
  try {
    const resp = await getAdminDashboard()
    if (resp.code === 0 && resp.data) {
      data.value = resp.data
    } else {
      error.value = resp.message || ''
    }
  } catch {
    error.value = ''
  }
})
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-workspace__top">
        <div class="settings-workspace__intro">
          <span class="settings-workspace__eyebrow">平台总览</span>
          <h2 class="settings-workspace__title">控制台总览</h2>
          <p class="settings-workspace__copy">这里集中查看平台指标、趋势、待办事项与最新订单。</p>
        </div>
      </div>

      <div class="workbench-shell dashboard-overview">
        <div v-for="(row, rowIndex) in overviewRows" :key="rowIndex" class="workbench-row">
          <div v-for="card in row" :key="card.label" class="workbench-cell">
            <span class="workbench-label">{{ card.label }}</span>
            <strong class="workbench-value">{{ card.value }}</strong>
            <span class="workbench-copy">{{ card.copy }}</span>
          </div>
        </div>
      </div>

      <div class="settings-stack settings-workspace__body">
        <div class="settings-block dashboard-panels">
          <div class="dashboard-main">
            <article class="dashboard-panel">
              <div class="settings-block-head">
                <div class="toolbar-row">
                  <h3 class="settings-block-title">交易趋势</h3>
                  <span class="status-chip success">近 7 日</span>
                </div>
                <p class="settings-block-copy">按最近 7 天交易走势展示，用于快速观察业务波动。</p>
              </div>
              <VChart :option="lineOption" autoresize class="chart-canvas" />
            </article>

            <article class="dashboard-panel">
              <div class="settings-block-head">
                <div class="toolbar-row">
                  <h3 class="settings-block-title">待办事项</h3>
                  <strong class="todo-total">{{ todos.reduce((sum, item) => sum + item.value, 0) }}</strong>
                </div>
                <p class="settings-block-copy">右侧展示待审核商户、待处理订单和风险提醒等关键事项。</p>
              </div>
              <VChart :option="donutOption" autoresize class="donut-canvas" />
              <div class="todo-list">
                <div v-for="item in todos" :key="item.label" class="todo-row">
                  <span>{{ item.label }}</span>
                  <strong>{{ item.value }}</strong>
                </div>
              </div>
              <div class="ops-list">
                <div v-for="item in opsItems" :key="item.label" class="ops-row">
                  <span>{{ item.label }}</span>
                  <strong>{{ item.value }}</strong>
                </div>
              </div>
              <p v-if="error" class="empty-note dashboard-error">{{ error }}</p>
            </article>
          </div>
        </div>

        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">最新订单</h3>
            <p class="settings-block-copy">直接查看最近订单与交易状态。</p>
          </div>
          <div class="table-wrap">
            <div class="table-head order-grid">
              <div class="order-no-head">
                <span>平台单号</span>
                <span>商户单号</span>
              </div>
              <span>商品名称</span>
              <span>商户</span>
              <span>支付方式</span>
              <span>金额</span>
              <span>状态</span>
              <span>时间</span>
            </div>
            <div v-for="item in data.latest_orders || []" :key="item.trade_no" class="table-row order-grid">
              <div class="order-no-stack order-no-stack--plain">
                <div class="order-meta-line">
                  <strong>{{ item.trade_no || '-' }}</strong>
                </div>
                <div class="order-meta-line">
                  <span>{{ item.out_trade_no || '-' }}</span>
                </div>
              </div>
              <span class="order-subject">{{ item.subject || '-' }}</span>
              <span>{{ item.merchant }}</span>
              <span>{{ item.method_name || item.channel_code || '-' }}</span>
              <span>{{ item.amount }}</span>
              <span><span class="status-chip">{{ item.status }}</span></span>
              <span>{{ item.created_at }}</span>
            </div>
          </div>
        </div>
      </div>
    </article>
  </section>
</template>

<style scoped>
.dashboard-overview {
  border-bottom: 1px solid var(--brand-border);
}

.dashboard-panels {
  padding: 0;
}

.dashboard-main {
  display: grid;
  grid-template-columns: minmax(0, 1.75fr) minmax(300px, 0.92fr);
  gap: 14px;
  padding: 22px;
}

.dashboard-panel {
  min-width: 0;
  border: 1px solid var(--brand-border);
  background: linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(249, 252, 255, 0.92));
}

.todo-total {
  color: #20344f;
  font-size: 20px;
  font-weight: 800;
}

.dashboard-error {
  padding: 0 22px 22px;
}

.chart-canvas {
  height: 290px;
  padding: 0 22px 22px;
}

.donut-canvas {
  height: 220px;
  padding: 0 22px;
}

.todo-list {
  display: grid;
  gap: 0;
  padding: 0 22px 22px;
}

.ops-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  padding: 0 22px 22px;
}

.ops-row {
  display: grid;
  gap: 4px;
  padding: 12px 14px;
  border: 1px solid #edf2f7;
  background: #f8fbff;
}

.todo-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  min-height: 46px;
  padding: 0;
  border-bottom: 1px solid #edf2f7;
}

.todo-row:last-child {
  border-bottom: 0;
}

.order-grid {
  display: grid;
  grid-template-columns: 1.85fr 1fr 0.8fr 0.85fr 0.6fr 0.75fr 1fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.order-no-head {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.order-no-head span {
  color: inherit;
  line-height: 1.4;
}

.order-no-stack {
  display: grid;
  gap: 8px;
  min-width: 0;
}

.order-no-stack--plain {
  gap: 6px;
}

.order-meta-line {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0;
  align-items: center;
  min-width: 0;
}

.order-no-stack--plain strong,
.order-no-stack--plain span:last-child,
.order-subject {
  min-width: 0;
  word-break: break-all;
}

.order-no-stack--plain span:last-child {
  color: var(--brand-text-soft);
}

@media (max-width: 1180px) {
  .dashboard-main {
    grid-template-columns: 1fr;
    padding: 16px;
  }

  .order-grid {
    grid-template-columns: 1fr;
  }
}
</style>

