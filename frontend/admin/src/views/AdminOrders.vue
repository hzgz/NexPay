<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { getAdminOrders } from '../lib/api'

const activeTab = ref<'orders' | 'recharge' | 'package'>('orders')
const source = ref<Record<string, any>>({})

const tabs = [
  { key: 'orders', label: '订单列表' },
  { key: 'recharge', label: '充值订单' },
  { key: 'package', label: '套餐订单' },
] as const

const rows = computed(() => {
  if (activeTab.value === 'recharge') return source.value.recharge_orders || []
  if (activeTab.value === 'package') return source.value.package_orders || []
  return source.value.items || []
})

onMounted(async () => {
  const resp = await getAdminOrders()
  if (resp.code === 0 && resp.data) {
    source.value = resp.data
  }
})
</script>

<template>
  <article class="workbench-surface panel">
    <div class="toolbar">
      <div>
        <h2 class="section-title">订单中心</h2>
      </div>
      <div class="toolbar-actions">
        <button class="ghost-btn">状态</button>
        <button class="ghost-btn">时间</button>
        <button class="primary-btn">导出</button>
      </div>
    </div>

    <div class="tab-row">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="tab-btn"
        :class="{ active: activeTab === tab.key }"
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <template v-if="activeTab === 'orders'">
      <div class="table-head order-grid">
        <span>平台订单号</span>
        <span>商户订单号</span>
        <span>商户</span>
        <span>支付方式</span>
        <span>金额</span>
        <span>状态</span>
        <span>创建时间</span>
      </div>
      <div v-for="item in rows" :key="item.trade_no" class="table-row order-grid">
        <strong>{{ item.trade_no }}</strong>
        <span>{{ item.out_trade_no }}</span>
        <span>{{ item.merchant }}</span>
        <span>{{ item.channel_code }}</span>
        <span>{{ item.amount }}</span>
        <span>{{ item.status }}</span>
        <span>{{ item.created_at }}</span>
      </div>
    </template>

    <template v-else-if="activeTab === 'recharge'">
      <div class="table-head recharge-grid">
        <span>充值单号</span>
        <span>商户</span>
        <span>支付方式</span>
        <span>金额</span>
        <span>状态</span>
        <span>时间</span>
      </div>
      <div v-for="item in rows" :key="item.trade_no" class="table-row recharge-grid">
        <strong>{{ item.trade_no }}</strong>
        <span>{{ item.merchant }}</span>
        <span>{{ item.payment_type }}</span>
        <span>{{ item.amount }}</span>
        <span>{{ item.status }}</span>
        <span>{{ item.created_at }}</span>
      </div>
    </template>

    <template v-else>
      <div class="table-head package-grid">
        <span>订单号</span>
        <span>商户</span>
        <span>套餐</span>
        <span>金额</span>
        <span>状态</span>
        <span>时间</span>
      </div>
      <div v-for="item in rows" :key="item.trade_no" class="table-row package-grid">
        <strong>{{ item.trade_no }}</strong>
        <span>{{ item.merchant }}</span>
        <span>{{ item.package_name }}</span>
        <span>{{ item.amount }}</span>
        <span>{{ item.status }}</span>
        <span>{{ item.created_at }}</span>
      </div>
    </template>
  </article>
</template>

<style scoped>
.panel { padding: 18px 20px; }
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
  padding-bottom: 16px;
}
.toolbar-actions { display: flex; gap: 10px; }
.primary-btn,
.ghost-btn,
.tab-btn {
  min-height: 36px;
  padding: 0 14px;
  border-radius: 8px;
  border: 1px solid #dbe5f0;
  background: #fff;
  font-size: 13px;
}
.primary-btn {
  border-color: #1677ff;
  background: #1677ff;
  color: #fff;
}
.tab-row {
  display: flex;
  gap: 10px;
  padding-bottom: 14px;
}
.tab-btn.active {
  border-color: #1677ff;
  background: #e8f3ff;
  color: #1677ff;
  font-weight: 700;
}
.order-grid {
  display: grid;
  grid-template-columns: 1.15fr 1.1fr 1fr .8fr .7fr .7fr 1fr;
  gap: 12px;
  align-items: center;
}
.recharge-grid,
.package-grid {
  display: grid;
  grid-template-columns: 1.15fr 1fr .9fr .7fr .7fr 1fr;
  gap: 12px;
  align-items: center;
}
.table-head,
.table-row {
  min-height: 48px;
  border-bottom: 1px solid #edf2f7;
}
.table-head {
  color: #667085;
  font-size: 12px;
}
.table-row {
  font-size: 13px;
}
@media (max-width: 1200px) {
  .order-grid,
  .recharge-grid,
  .package-grid {
    grid-template-columns: 1fr;
  }
  .tab-row {
    flex-wrap: wrap;
  }
}
</style>
