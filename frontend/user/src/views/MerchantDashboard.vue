<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import VChart from 'vue-echarts'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart } from 'echarts/charts'
import { GridComponent, TooltipComponent } from 'echarts/components'
import { getUserDashboard } from '../lib/api'

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent])

const data = ref<Record<string, any>>({
  cards: {},
  todos: {},
  trend: [],
  latest_orders: [],
})

const cards = computed(() => [
  { label: '可用余额', value: data.value.cards?.balance ?? '0.00' },
  { label: '今日交易额', value: data.value.cards?.today_amount ?? '0.00' },
  { label: '今日订单数', value: data.value.cards?.today_orders ?? 0 },
  { label: '待支付订单', value: data.value.cards?.pending_orders ?? 0 },
])

const todoItems = computed(() => [
  { label: '待支付订单', value: Number(data.value.todos?.pending_orders ?? 0) },
  { label: '人工待退款', value: Number(data.value.todos?.manual_refunds ?? 0) },
  { label: '人工待代付', value: Number(data.value.todos?.manual_transfers ?? 0) },
  { label: '回调待执行', value: Number(data.value.todos?.callback_due ?? 0) },
  { label: '回调已耗尽', value: Number(data.value.todos?.callback_exhausted ?? 0) },
])

const lineOption = computed(() => ({
  tooltip: {
    trigger: 'axis',
    backgroundColor: 'rgba(17, 24, 39, 0.92)',
    borderWidth: 0,
    textStyle: { color: '#f8fafc' },
  },
  grid: {
    left: 10,
    right: 10,
    top: 16,
    bottom: 18,
    containLabel: true,
  },
  xAxis: {
    type: 'category',
    boundaryGap: false,
    data: (data.value.trend || []).map((item: Record<string, any>) => item.date),
    axisLine: { lineStyle: { color: '#d8e3f0' } },
    axisTick: { show: false },
    axisLabel: { color: '#7b8794', fontSize: 12 },
  },
  yAxis: [
    {
      type: 'value',
      splitLine: { lineStyle: { color: '#eef2f7' } },
      axisLabel: { color: '#7b8794', fontSize: 12 },
    },
    {
      type: 'value',
      splitLine: { show: false },
      axisLabel: { color: '#9aa7b4', fontSize: 12 },
    },
  ],
  series: [
    {
      name: '交易额',
      type: 'line',
      smooth: true,
      yAxisIndex: 0,
      symbol: 'none',
      lineStyle: {
        width: 4,
        color: '#1677ff',
      },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0,
          y: 0,
          x2: 0,
          y2: 1,
          colorStops: [
            { offset: 0, color: 'rgba(22,119,255,0.22)' },
            { offset: 1, color: 'rgba(22,119,255,0.03)' },
          ],
        },
      },
      data: (data.value.trend || []).map((item: Record<string, any>) => Number(item.amount || 0)),
    },
    {
      name: '成功订单数',
      type: 'line',
      smooth: true,
      yAxisIndex: 1,
      symbol: 'none',
      lineStyle: {
        width: 2,
        color: '#83b6ff',
      },
      itemStyle: { color: '#83b6ff' },
      data: (data.value.trend || []).map((item: Record<string, any>) => Number(item.orders || 0)),
    },
  ],
}))

onMounted(async () => {
  const resp = await getUserDashboard()
  if (resp.code === 0 && resp.data) {
    data.value = resp.data
  }
})
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-workspace__top">
        <div class="settings-workspace__intro">
          <span class="settings-workspace__eyebrow">商户总览</span>
          <h2 class="settings-workspace__title">商户概览</h2>
          <p class="settings-workspace__copy">这里集中查看余额、交易、订单与接口相关核心数据。</p>
        </div>
      </div>

      <div class="summary-grid dashboard-summary-grid">
        <article v-for="card in cards" :key="card.label" class="summary-card">
          <span class="workbench-label">{{ card.label }}</span>
          <strong class="summary-value">{{ card.value }}</strong>
          <span class="workbench-copy">实时同步商户中心当前经营数据</span>
        </article>
      </div>

      <div class="settings-stack settings-workspace__body">
        <div class="settings-block dashboard-panels">
          <div class="dashboard-main">
            <article class="dashboard-panel">
              <div class="settings-block-head">
                <h3 class="settings-block-title">近 7 日交易走势</h3>
                <p class="settings-block-copy">按最近 7 天交易额展示走势变化，方便快速判断业务波动。</p>
              </div>
              <VChart :option="lineOption" :theme="undefined" autoresize class="chart-canvas" />
            </article>

            <article class="dashboard-panel dashboard-panel--aside">
              <div class="settings-block-head">
                <h3 class="settings-block-title">订单状态</h3>
                <p class="settings-block-copy">右侧展示商户当前待处理事项，直接对应退款、代付和回调积压。</p>
              </div>
              <div class="notice-list">
                <div v-for="item in todoItems" :key="item.label" class="notice-item">
                  <strong>{{ item.label }}</strong>
                  <p>{{ item.value }}</p>
                </div>
              </div>
            </article>
          </div>
        </div>

        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">最新订单</h3>
            <p class="settings-block-copy">直接在首页查看最近订单情况。</p>
          </div>
          <div class="table-wrap">
            <div class="table-head order-grid">
              <span>订单号</span>
              <span>商品名称</span>
              <span>支付方式</span>
              <span>金额</span>
              <span>状态</span>
              <span>创建时间</span>
            </div>
            <div v-for="item in data.latest_orders || []" :key="item.trade_no" class="table-row order-grid">
              <div class="order-no-stack">
                <div class="order-no-line">
                  <span class="order-no-label">平台单号</span>
                  <strong>{{ item.trade_no || '-' }}</strong>
                </div>
                <div class="order-no-line">
                  <span class="order-no-label">商户单号</span>
                  <span>{{ item.out_trade_no || '-' }}</span>
                </div>
              </div>
              <span class="order-subject">{{ item.subject || '-' }}</span>
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
.dashboard-summary-grid {
  border-top: 0;
}

.dashboard-panels {
  padding: 0;
}

.dashboard-main {
  display: grid;
  grid-template-columns: minmax(0, 1.7fr) minmax(280px, 0.92fr);
  gap: 14px;
  padding: 20px;
}

.dashboard-panel {
  min-width: 0;
  border: 1px solid var(--brand-border);
  background: #fff;
}

.chart-canvas {
  height: 280px;
  padding: 0 20px 20px;
}

.notice-list {
  display: grid;
  gap: 0;
  padding: 0 20px 20px;
}

.notice-item {
  padding: 14px 0;
  border-bottom: 1px solid var(--brand-border);
}

.notice-item:last-child {
  border-bottom: 0;
}

.notice-item strong,
.notice-item p {
  margin: 0;
}

.notice-item p {
  margin-top: 6px;
  color: var(--brand-text-soft);
  font-size: 15px;
  line-height: 1.4;
}

.dashboard-panel--aside {
  align-content: start;
}

.order-grid {
  display: grid;
  grid-template-columns: 1.85fr 1fr 0.85fr 0.6fr 0.75fr 1fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.order-no-stack {
  display: grid;
  gap: 8px;
  min-width: 0;
}

.order-no-line {
  display: grid;
  grid-template-columns: 56px minmax(0, 1fr);
  gap: 8px;
  align-items: center;
  min-width: 0;
}

.order-no-label {
  color: var(--brand-subtle);
  font-size: 12px;
  line-height: 1.4;
}

.order-no-line > strong,
.order-no-line > span:last-child,
.order-subject {
  min-width: 0;
  word-break: break-all;
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

