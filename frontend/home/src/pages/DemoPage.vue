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

const currentProviderName = computed(() => {
  return props.demoConfig.providers.find((item) => item.value === props.demoForm.provider)?.label || props.demoForm.provider
})

const demoStatusMeta = computed(() => {
  const status = props.demoState.status

  switch (status) {
    case 'success':
      return {
        tone: 'success',
        title: '支付成功',
        copy: '测试订单已完成支付，你可以继续查看收银台状态或重新发起一笔新订单。',
      }
    case 'failed':
      return {
        tone: 'error',
        title: '支付失败',
        copy: '上游返回了失败结果，请检查支付配置、通道参数或重新发起测试。',
      }
    case 'expired':
      return {
        tone: 'warning',
        title: '订单已过期',
        copy: '这笔测试单已经过期，可以重新下单生成新的支付链接。',
      }
    case 'closed':
      return {
        tone: 'warning',
        title: '订单已关闭',
        copy: '这笔测试单已经关闭，如需继续测试请重新创建订单。',
      }
    case 'pending':
    case 'processing':
      return {
        tone: 'pending',
        title: '等待支付',
        copy: '当前页会自动刷新订单状态，你可以在新窗口继续完成支付。',
      }
    default:
      return {
        tone: 'idle',
        title: '尚未创建订单',
        copy: '填写参数后创建测试订单，当前页会保留完整结果状态。',
      }
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
          <label class="field-block">
            <span>接口选择</span>
            <input v-if="demoConfig.providers.length <= 1" :value="currentProviderName" type="text" readonly />
            <select v-else v-model="demoForm.provider">
              <option v-for="item in demoConfig.providers" :key="item.value" :value="item.value">
                {{ item.label }}
              </option>
            </select>
          </label>

          <div class="field-block">
            <span>支付金额</span>
            <div class="inline-field">
              <select v-model="demoForm.currency" class="inline-field__code">
                <option value="CNY">CNY</option>
                <option value="USD">USD</option>
              </select>
              <input v-model="demoForm.amount" type="text" placeholder="留空则随机" />
            </div>
            <small>支持自定义金额，不填写时按后台配置值或随机金额生成测试单。</small>
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

          <section class="demo-status-board" :class="`is-${demoStatusMeta.tone}`">
            <div class="demo-status-board__head">
              <div>
                <strong>{{ demoStatusMeta.title }}</strong>
                <p>{{ demoStatusMeta.copy }}</p>
              </div>
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
              <div>
                <span>接口来源</span>
                <strong>{{ currentProviderName }}</strong>
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
              <dt>接口来源</dt>
              <dd>{{ currentProviderName }}</dd>
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

          <div class="minor-links">
            <a href="/doc">查看开发文档</a>
            <a href="/user/login">进入商户中心</a>
          </div>
        </aside>
      </div>
    </section>
  </main>
</template>
