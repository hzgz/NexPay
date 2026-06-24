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
  <main class="page-surface page-surface--demo">
    <section class="demo-hero">
      <div class="demo-hero__copy">
        <span class="demo-hero__eyebrow">首页支付测试</span>
        <h1>{{ demoConfig.title }}</h1>
        <p>{{ demoConfig.subtitle }}</p>
      </div>
    </section>

    <section class="demo-shell">
      <div class="demo-shell__main">
        <div class="demo-panel">
          <div class="demo-panel__head">
            <h2>发起测试订单</h2>
            <p>此页面直接调用后台首页测试支付配置，创建真实本地订单并跳转统一收银台。</p>
          </div>

          <div class="demo-form-grid">
            <label class="demo-field">
              <span>支付金额</span>
              <div class="demo-amount-input">
                <select v-model="demoForm.currency" class="demo-amount-input__currency">
                  <option value="CNY">CNY</option>
                  <option value="USD">USD</option>
                </select>
                <input v-model="demoForm.amount" type="text" placeholder="留空则按后台配置或随机金额生成" />
              </div>
              <small>最低起付 {{ demoConfig.min_amount || '0.10' }} 元，不填写时按后台设置或随机金额生成。</small>
            </label>

            <label class="demo-field">
              <span>商户订单号</span>
              <input v-model="demoForm.tradeNo" type="text" placeholder="可选，不填则系统自动生成" />
              <small>如果你填写商户订单号，系统会沿用该订单号创建测试订单。</small>
            </label>
          </div>

          <div class="demo-method-section">
            <div class="demo-method-section__head">
              <span>支付方式</span>
              <small>前台显示名称取自后台支付方式配置</small>
            </div>

            <div class="demo-method-grid">
              <label
                v-for="item in demoMethods"
                :key="item.code"
                class="demo-method"
                :class="{ 'is-active': demoForm.method === item.code }"
              >
                <input v-model="demoForm.method" type="radio" :value="item.code" />
                <img :src="item.icon" :alt="item.name" />
                <span>{{ item.name }}</span>
              </label>
            </div>
          </div>

          <div class="demo-panel__actions">
            <button
              class="primary-button demo-submit-button"
              type="button"
              :disabled="demoState.loading || !demoConfig.enabled"
              @click="emit('submitDemo')"
            >
              {{ demoState.loading ? '提交中...' : '提交支付测试' }}
            </button>
          </div>

          <p v-if="demoState.error" class="feedback feedback--error">{{ demoState.error }}</p>
          <p v-if="demoState.result" class="feedback feedback--success">{{ demoState.result }}</p>
        </div>

        <div class="demo-panel">
          <div class="demo-panel__head">
            <h2>{{ demoStatusTitle }}</h2>
            <p>下单成功后可直接重新打开支付页或查看统一收银台。</p>
          </div>

          <div class="demo-status-grid">
            <div class="demo-status-item">
              <span>平台订单号</span>
              <strong>{{ demoState.tradeNo || '-' }}</strong>
            </div>
            <div class="demo-status-item">
              <span>支付方式</span>
              <strong>{{ currentMethodName }}</strong>
            </div>
            <div class="demo-status-item">
              <span>订单金额</span>
              <strong>{{ demoState.amount || demoForm.amount || '随机生成' }}</strong>
            </div>
            <div class="demo-status-item">
              <span>当前状态</span>
              <strong>{{ demoState.statusText || '待创建' }}</strong>
            </div>
          </div>

          <div class="demo-status-actions">
            <button class="secondary-link" type="button" :disabled="!hasOrder" @click="emit('openPayWindow')">重新打开支付页</button>
            <button class="secondary-link" type="button" :disabled="!hasOrder" @click="emit('openCheckoutWindow')">查看收银台</button>
            <button class="secondary-link" type="button" @click="emit('resetDemo')">重新下单</button>
          </div>
        </div>
      </div>

      <aside class="demo-shell__side">
        <div class="demo-side-panel">
          <h2>测试摘要</h2>
          <dl class="demo-summary-list">
            <div v-if="demoConfig.merchant_id">
              <dt>测试商户 ID</dt>
              <dd>{{ demoConfig.merchant_id }}</dd>
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
              <dt>支付状态</dt>
              <dd>{{ demoState.statusText || '待创建' }}</dd>
            </div>
          </dl>
        </div>

        <div class="demo-side-panel demo-side-panel--tip">
          <h2>联调说明</h2>
          <ul class="demo-tips">
            <li>本页调用 `/api/home/demo-create` 创建首页测试订单。</li>
            <li>订单创建后统一跳转 `/pay/checkout/{trade_no}` 收银台。</li>
            <li>商户自己的通道测试支付不走这里，仍使用商户通道配置。</li>
          </ul>
        </div>
      </aside>
    </section>
  </main>
</template>
