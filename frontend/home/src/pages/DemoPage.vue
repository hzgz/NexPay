<script setup lang="ts">
import { computed } from 'vue'
import type { DemoConfig } from '../content'

const props = defineProps<{
  demoConfig: DemoConfig
  demoForm: {
    provider: string
    amount: string
    currency: string
    method: string
    tradeNo: string
  }
  demoState: {
    loading: boolean
    error: string
    result: string
    tradeNo: string
    payUrl: string
    submitUrl: string
    checkoutUrl: string
    statusUrl: string
    amount: string
    status: string
    statusText: string
    returnUrl: string
  }
  demoMethods: Array<{ code: string; name: string; icon: string }>
}>()

const emit = defineEmits<{
  submitDemo: []
  openPayWindow: []
  openCheckoutWindow: []
  resetDemo: []
}>()

const currentMethodName = computed(() => {
  return props.demoMethods.find((item) => item.code === props.demoForm.method)?.name || props.demoForm.method
})

const demoStatusTitle = computed(() => {
  switch (props.demoState.status) {
    case 'success':
      return '支付成功'
    case 'failed':
      return '支付失败'
    case 'expired':
      return '订单已过期'
    case 'closed':
      return '订单已关闭'
    case 'pending':
    case 'processing':
      return '等待支付'
    default:
      return '尚未创建订单'
  }
})

const hasOrder = computed(() => props.demoState.tradeNo !== '')
</script>

<template>
  <main class="page-surface">
    <section class="surface-shell">
      <div class="surface-head surface-head--split">
        <div>
          <h1>{{ demoConfig.title }}</h1>
          <span class="surface-badge">测试环境</span>
          <p>{{ demoConfig.subtitle }}</p>
        </div>
      </div>

      <div class="surface-grid surface-grid--demo">
        <div class="surface-main">
          <div class="field-block">
            <span>支付金额</span>
            <div class="inline-field">
              <select v-model="demoForm.currency" class="inline-field__code">
                <option value="CNY">CNY</option>
                <option value="USD">USD</option>
              </select>
              <input v-model="demoForm.amount" type="text" placeholder="留空则随机" />
            </div>
            <small>最低起付 {{ demoConfig.min_amount || '0.10' }} 元，不填写时按后台配置值或随机金额生成测试单。</small>
          </div>

          <div class="field-block">
            <span>支付方式</span>
            <div class="demo-method-grid">
              <label
                v-for="item in demoMethods"
                :key="item.code"
                class="method-radio"
                :class="{ 'is-active': demoForm.method === item.code }"
              >
                <input v-model="demoForm.method" type="radio" :value="item.code" />
                <img :src="item.icon" :alt="item.name" />
                <em>{{ item.name }}</em>
              </label>
            </div>
          </div>

          <label class="field-block">
            <span>商户订单号（可选）</span>
            <input v-model="demoForm.tradeNo" type="text" />
            <small>不填写时系统自动生成，填写时会沿用你输入的商户订单号。</small>
          </label>

          <button
            class="primary-button primary-button--full"
            type="button"
            :disabled="demoState.loading || !demoConfig.enabled"
            @click="emit('submitDemo')"
          >
            {{ demoState.loading ? '提交中...' : '提交支付' }}
          </button>

          <p v-if="demoState.error" class="feedback feedback--error">{{ demoState.error }}</p>
          <p v-if="demoState.result" class="feedback feedback--success">{{ demoState.result }}</p>

          <section class="demo-status-board">
            <div class="demo-status-board__head">
              <strong>{{ demoStatusTitle }}</strong>
              <span class="demo-status-board__tag">{{ demoState.statusText || '待创建' }}</span>
            </div>

            <div class="demo-status-board__grid">
              <div>
                <span>平台订单号</span>
                <strong>{{ demoState.tradeNo || '-' }}</strong>
              </div>
              <div>
                <span>订单金额</span>
                <strong>{{ demoState.amount || demoForm.amount || '随机生成' }}</strong>
              </div>
              <div>
                <span>支付方式</span>
                <strong>{{ currentMethodName }}</strong>
              </div>
            </div>

            <div class="demo-status-board__actions">
              <button class="secondary-link demo-action-btn" type="button" :disabled="!hasOrder" @click="emit('openPayWindow')">
                重新打开支付页
              </button>
              <button class="secondary-link demo-action-btn" type="button" :disabled="!hasOrder" @click="emit('openCheckoutWindow')">
                查看收银台
              </button>
              <button class="secondary-link demo-action-btn" type="button" @click="emit('resetDemo')">
                重新下单
              </button>
            </div>
          </section>
        </div>

        <aside class="surface-side">
          <h2>订单摘要</h2>
          <dl class="summary-list">
            <div v-if="demoConfig.merchant_id">
              <dt>测试商户 ID</dt>
              <dd>{{ demoConfig.merchant_id }}</dd>
            </div>
            <div v-if="demoConfig.merchant_name">
              <dt>测试商户</dt>
              <dd>{{ demoConfig.merchant_name }}</dd>
            </div>
            <div>
              <dt>支付方式</dt>
              <dd>{{ currentMethodName }}</dd>
            </div>
            <div>
              <dt>币种</dt>
              <dd>{{ demoForm.currency }}</dd>
            </div>
            <div>
              <dt>订单金额</dt>
              <dd>{{ demoState.amount || demoForm.amount || '随机生成' }}</dd>
            </div>
            <div>
              <dt>商户订单号</dt>
              <dd>{{ demoForm.tradeNo || '自动生成' }}</dd>
            </div>
            <div>
              <dt>当前状态</dt>
              <dd>{{ demoState.statusText || '待创建' }}</dd>
            </div>
          </dl>
        </aside>
      </div>
    </section>
  </main>
</template>
