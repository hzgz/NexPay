<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

type GeetestResult = {
  lot_number?: string
  captcha_output?: string
  pass_token?: string
  gen_time?: string
}

type GeetestInstance = {
  appendTo: (target: HTMLElement | string) => GeetestInstance
  getValidate: () => GeetestResult | false
  reset?: () => void
  destroy?: () => void
  onReady: (callback: () => void) => GeetestInstance
  onSuccess: (callback: () => void) => GeetestInstance
  onError: (callback: (error?: unknown) => void) => GeetestInstance
}

declare global {
  interface Window {
    initGeetest4?: (config: Record<string, any>, callback: (captcha: GeetestInstance) => void) => void
    __nexpayGeetestLoader?: Promise<void>
  }
}

const props = defineProps<{
  state: Record<string, any>
}>()

const emit = defineEmits<{
  (event: 'fallback-required'): void
  (event: 'error', message: string): void
}>()

const container = ref<HTMLElement | null>(null)
const loading = ref(false)
const ready = ref(false)
const verified = ref(false)
const localError = ref('')
let captchaObj: GeetestInstance | null = null

const enabled = computed(() => Boolean(props.state?.enabled || props.state?.required))
const captchaId = computed(() => String(props.state?.captcha_id || '').trim())
const canFailback = computed(() => Boolean(props.state?.failback))

function resetValidation() {
  Object.assign(props.state, {
    verify_token: '',
    lot_number: '',
    captcha_output: '',
    pass_token: '',
    gen_time: '',
  })
  verified.value = false
}

function requestFallback(message: string) {
  resetValidation()
  localError.value = message
  emit('error', message)
  if (canFailback.value) {
    props.state.fallback_required = true
    emit('fallback-required')
  }
}

function loadGeetestScript() {
  if (window.initGeetest4) {
    return Promise.resolve()
  }

  if (!window.__nexpayGeetestLoader) {
    window.__nexpayGeetestLoader = new Promise<void>((resolve, reject) => {
      const script = document.createElement('script')
      script.src = 'https://static.geetest.com/v4/gt4.js'
      script.async = true
      script.onload = () => resolve()
      script.onerror = () => reject(new Error('极验客户端加载失败'))
      document.head.appendChild(script)
    })
  }

  return window.__nexpayGeetestLoader
}

async function initGeetest() {
  if (!enabled.value) return

  resetValidation()
  localError.value = ''

  if (!captchaId.value) {
    requestFallback('极验配置不完整')
    return
  }

  loading.value = true
  ready.value = false

  try {
    await loadGeetestScript()
    await nextTick()
    if (!window.initGeetest4 || !container.value) {
      throw new Error('极验客户端未就绪')
    }

    window.initGeetest4(
      {
        captchaId: captchaId.value,
        product: 'popup',
        language: 'zho',
        protocol: 'https://',
        nativeButton: { width: '100%', height: '42px' },
      },
      (captcha) => {
        captchaObj = captcha
        captcha
          .appendTo(container.value as HTMLElement)
          .onReady(() => {
            loading.value = false
            ready.value = true
          })
          .onSuccess(() => {
            const result = captcha.getValidate()
            if (!result) {
              requestFallback('极验校验结果为空')
              return
            }

            Object.assign(props.state, {
              verify_token: 'geetest-v4',
              lot_number: result.lot_number || '',
              captcha_output: result.captcha_output || '',
              pass_token: result.pass_token || '',
              gen_time: result.gen_time || '',
              fallback_required: false,
            })

            verified.value = Boolean(props.state.lot_number && props.state.captcha_output && props.state.pass_token && props.state.gen_time)
            localError.value = verified.value ? '' : '极验校验参数不完整'
          })
          .onError(() => {
            requestFallback('极验校验暂不可用')
          })
      },
    )
  } catch (error) {
    requestFallback(error instanceof Error ? error.message : '极验初始化失败')
  } finally {
    if (!ready.value) {
      loading.value = false
    }
  }
}

watch(captchaId, () => {
  initGeetest()
})

onMounted(initGeetest)

onBeforeUnmount(() => {
  captchaObj?.destroy?.()
  captchaObj = null
})
</script>

<template>
  <div v-if="enabled" class="geetest-panel" :class="{ 'is-ready': ready, 'is-verified': verified }">
    <div ref="container" class="geetest-box"></div>
    <span class="geetest-status">
      {{ verified ? '极验已通过' : loading ? '极验加载中' : localError || '等待极验校验' }}
    </span>
  </div>
</template>
