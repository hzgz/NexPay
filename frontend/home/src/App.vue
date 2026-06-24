<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, watch } from 'vue'
import PublicHeader from './components/PublicHeader.vue'
import DemoPage from './pages/DemoPage.vue'
import DocPage from './pages/DocPage.vue'
import HomePage from './pages/HomePage.vue'
import { initialDemoConfig, pageTitles, type DemoConfig, type PageKind } from './content'
import { createDemoOrder, getDemoConfig, getDemoOrderStatus } from './lib/public-auth'

const pagePath = window.location.pathname.replace(/\/+$/, '') || '/'
const baseUrl = import.meta.env.BASE_URL
const normalizedBaseUrl = baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`

let demoStatusTimer: number | null = null

const pageKind = computed<PageKind>(() => {
  if (pagePath === '/demo') return 'demo'
  if (pagePath === '/doc') return 'doc'
  return 'home'
})

const demoConfig = reactive<DemoConfig>({ ...initialDemoConfig })

const demoForm = reactive({
  provider: 'system',
  amount: '',
  currency: 'CNY',
  method: 'alipay',
  tradeNo: '',
})

const demoState = reactive({
  loading: false,
  error: '',
  result: '',
  tradeNo: '',
  payUrl: '',
  submitUrl: '',
  checkoutUrl: '',
  statusUrl: '',
  amount: '',
  status: 'idle',
  statusText: '',
  returnUrl: '',
})

function resolveAsset(path: string) {
  return `${normalizedBaseUrl}${path}`.replace(/([^:]\/)\/+/g, '$1')
}

const siteLogo = computed(() => resolveAsset('brand/logo-light.png'))
const legacyHeroVisual = computed(() => resolveAsset('theme-index4/bg-uaspay5.jpg'))
const legacySceneOne = computed(() => resolveAsset('theme-index4/landing-1.jpg'))
const legacySceneTwo = computed(() => resolveAsset('theme-index4/landing-2.jpg'))
const legacySceneThree = computed(() => resolveAsset('theme-index4/landing-3.jpg'))

const demoMethods = computed(() => {
  const iconMap: Record<string, string> = {
    alipay: 'payment-icons/alipay.png',
    wechat: 'payment-icons/wechat.png',
    wxpay: 'payment-icons/wechat.png',
    unionpay: 'payment-icons/unionpay.png',
    bank: 'payment-icons/unionpay.png',
    qqpay: 'payment-icons/qqpay.png',
    paypal: 'payment-icons/paypal.png',
  }

  return demoConfig.methods.map((item) => ({
    ...item,
    icon: resolveAsset(item.icon || iconMap[item.code] || 'payment-icons/alipay.png'),
  }))
})

function resetDemoState() {
  demoState.error = ''
  demoState.result = ''
}

function resetDemoOrderState() {
  stopDemoPolling()
  demoState.tradeNo = ''
  demoState.payUrl = ''
  demoState.submitUrl = ''
  demoState.checkoutUrl = ''
  demoState.statusUrl = ''
  demoState.amount = ''
  demoState.status = 'idle'
  demoState.statusText = ''
  demoState.returnUrl = ''
}

function stopDemoPolling() {
  if (demoStatusTimer !== null) {
    window.clearInterval(demoStatusTimer)
    demoStatusTimer = null
  }
}

function shouldKeepPolling(statusKey: string) {
  return ['pending', 'processing', 'idle'].includes(statusKey)
}

async function refreshDemoStatus() {
  if (!demoState.tradeNo) return

  try {
    const response = await getDemoOrderStatus(demoState.tradeNo)
    if (response.code !== 0 || !response.data) {
      if (response.message) {
        demoState.error = response.message
      }
      return
    }

    const statusKey = String(response.data.status_key || '')
    demoState.status = statusKey || 'unknown'
    demoState.statusText = String(response.data.status_text || '')
    demoState.amount = String(response.data.amount || demoState.amount)
    demoState.returnUrl = String(response.data.return_url || '')

    if (!shouldKeepPolling(demoState.status)) {
      stopDemoPolling()
    }
  } catch {
    demoState.error = '支付结果查询失败。'
  }
}

function startDemoPolling() {
  stopDemoPolling()
  void refreshDemoStatus()
  demoStatusTimer = window.setInterval(() => {
    void refreshDemoStatus()
  }, 3000)
}

function openDemoPayWindow() {
  if (!demoState.payUrl) return
  window.open(demoState.payUrl, '_blank', 'noopener')
}

function openDemoCheckoutWindow() {
  if (!demoState.checkoutUrl) return
  window.open(demoState.checkoutUrl, '_blank', 'noopener')
}

function resetDemoForRetry() {
  resetDemoState()
  resetDemoOrderState()
}

async function loadDemoPage() {
  demoState.loading = true
  resetDemoState()

  try {
    const response = await getDemoConfig()
    if (response.code === 0 && response.data) {
      Object.assign(demoConfig, response.data)
      demoForm.amount = typeof response.data.default_amount === 'string' ? response.data.default_amount : ''
      demoState.error = response.data.disabled_reason || ''

      if (Array.isArray(response.data.providers) && response.data.providers.length > 0) {
        demoForm.provider = response.data.providers[0].value
      }

      if (Array.isArray(response.data.methods) && response.data.methods.length > 0) {
        demoForm.method = response.data.methods[0].code
      }

      return
    }

    demoState.error = response.message || '支付测试配置加载失败。'
  } catch {
    demoState.error = '支付测试配置加载失败。'
  } finally {
    demoState.loading = false
  }
}

async function submitDemo() {
  demoState.loading = true
  resetDemoState()

  try {
    const response = await createDemoOrder({
      provider: demoForm.provider,
      amount: demoForm.amount,
      method: demoForm.method,
      subject: 'NexPay 支付测试订单',
      trade_no: demoForm.tradeNo,
      currency: demoForm.currency,
    })

    if (response.code === 0 && response.data?.pay_url) {
      demoState.tradeNo = String(response.data.trade_no || '')
      demoState.payUrl = String(response.data.pay_url || '')
      demoState.submitUrl = String(response.data.submit_url || response.data.pay_url || '')
      demoState.checkoutUrl = String(response.data.checkout_url || '')
      demoState.statusUrl = String(response.data.status_url || '')
      demoState.amount = String(response.data.amount || '')
      demoState.status = 'pending'
      demoState.statusText = '待支付'
      demoState.result = '支付订单已创建，请在新窗口完成支付。'
      openDemoPayWindow()
      startDemoPolling()
      return
    }

    demoState.error = response.message || '支付测试下单失败。'
  } catch {
    demoState.error = '支付测试下单失败。'
  } finally {
    demoState.loading = false
  }
}

watch(
  pageKind,
  async (kind) => {
    document.title = pageTitles[kind]

    if (kind === 'demo') {
      await loadDemoPage()
      return
    }

    stopDemoPolling()
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  stopDemoPolling()
})
</script>

<template>
  <div class="public-shell" :class="`public-shell--${pageKind}`">
    <div class="white-stage" :class="{ 'white-stage--home': pageKind === 'home' }">
      <PublicHeader :page-kind="pageKind" :logo-src="siteLogo" />

      <HomePage
        v-if="pageKind === 'home'"
        :legacy-hero-visual="legacyHeroVisual"
        :legacy-scene-one="legacySceneOne"
        :legacy-scene-two="legacySceneTwo"
        :legacy-scene-three="legacySceneThree"
        :resolve-asset="resolveAsset"
      />
      <DemoPage
        v-else-if="pageKind === 'demo'"
        :demo-config="demoConfig"
        :demo-form="demoForm"
        :demo-state="demoState"
        :demo-methods="demoMethods"
        @submit-demo="submitDemo"
        @open-pay-window="openDemoPayWindow"
        @open-checkout-window="openDemoCheckoutWindow"
        @reset-demo="resetDemoForRetry"
      />
      <DocPage v-else />
    </div>
  </div>
</template>
