<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getAdminFees } from '../lib/api'

const rules = ref<Array<Record<string, any>>>([])
const merchants = ref<Array<Record<string, any>>>([])

onMounted(async () => {
  const resp = await getAdminFees()
  if (resp.code === 0 && resp.data) {
    rules.value = resp.data.rules || []
    merchants.value = resp.data.merchant_rates || []
  }
})
</script>

<template>
  <section class="page-grid">
    <article class="workbench-surface panel">
      <h2 class="section-title">费率规则</h2>
      <div class="table-head rule-grid">
        <span>规则名称</span>
        <span>类型</span>
        <span>费率</span>
        <span>状态</span>
        <span>说明</span>
      </div>
      <div v-for="item in rules" :key="item.name" class="table-row rule-grid">
        <strong>{{ item.name }}</strong>
        <span>{{ item.type }}</span>
        <span>{{ item.rate }}</span>
        <span>{{ item.status }}</span>
        <span>{{ item.desc }}</span>
      </div>
    </article>

    <article class="workbench-surface panel">
      <h2 class="section-title">商户费率分配</h2>
      <div class="table-head merchant-grid">
        <span>商户</span>
        <span>费率规则</span>
        <span>生效时间</span>
      </div>
      <div v-for="item in merchants" :key="item.merchant + item.rule" class="table-row merchant-grid">
        <strong>{{ item.merchant }}</strong>
        <span>{{ item.rule }}</span>
        <span>{{ item.effective_time }}</span>
      </div>
    </article>
  </section>
</template>

<style scoped>
.page-grid { display: grid; gap: 16px; }
.panel { padding: 18px 20px; }
.rule-grid { display: grid; grid-template-columns: 1.2fr .8fr .8fr .7fr 1.5fr; gap: 12px; }
.merchant-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
.table-head,.table-row { min-height: 50px; border-bottom: 1px solid #edf2f7; align-items: center; }
.table-head { color: #667085; font-size: 12px; }
@media (max-width: 1200px) { .rule-grid,.merchant-grid { grid-template-columns: 1fr; } }
</style>
