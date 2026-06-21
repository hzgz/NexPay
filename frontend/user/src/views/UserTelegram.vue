<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getUserTelegram } from '../lib/api'

const info = ref<Record<string, any>>({})

onMounted(async () => {
  const resp = await getUserTelegram()
  if (resp.code === 0 && resp.data) {
    info.value = resp.data
  }
})
</script>

<template>
  <article class="workbench-surface panel">
    <h2 class="section-title">Telegram 绑定</h2>
    <div class="info-grid">
      <div><span>绑定码</span><strong>{{ info.bind_code || '-' }}</strong></div>
      <div><span>绑定状态</span><strong>{{ info.status || '-' }}</strong></div>
      <div><span>通知开关</span><strong>{{ info.notify_enabled ? '已开启' : '已关闭' }}</strong></div>
    </div>
  </article>
</template>

<style scoped>
.panel { padding: 18px 20px; }
.info-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 14px; border-top: 1px solid #edf2f7; }
.info-grid > div { padding: 16px 0; }
.info-grid > div + div { border-left: 1px solid #edf2f7; padding-left: 18px; }
.info-grid span { display: block; color: #667085; font-size: 12px; }
.info-grid strong { display: block; margin-top: 8px; font-size: 15px; }
@media (max-width: 960px) { .info-grid { grid-template-columns: 1fr; } }
</style>
