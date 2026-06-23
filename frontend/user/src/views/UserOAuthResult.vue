<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { setUserSessionUser } from '../lib/api'

const route = useRoute()
const router = useRouter()
const statusText = ref('正在处理聚合登录结果...')
const failed = ref(false)
const mode = computed(() => String(route.query.mode || 'login'))

function decodeBase64Url(value: string): string {
  const normalized = value.replace(/-/g, '+').replace(/_/g, '/')
  const padded = normalized.padEnd(normalized.length + ((4 - (normalized.length % 4)) % 4), '=')
  return decodeURIComponent(
    Array.from(atob(padded))
      .map((char) => `%${char.charCodeAt(0).toString(16).padStart(2, '0')}`)
      .join(''),
  )
}

onMounted(() => {
  const code = Number(route.query.code ?? 1000)
  const message = String(route.query.message || '')
  if (code !== 0) {
    failed.value = true
    statusText.value = message || '聚合登录失败'
    return
  }

  if (mode.value === 'bind') {
    statusText.value = message || '第三方账号绑定成功'
    window.setTimeout(() => router.replace('/account/bindings'), 600)
    return
  }

  const token = String(route.query.token || '')
  const userPayload = String(route.query.user || '')
  if (!token || !userPayload) {
    failed.value = true
    statusText.value = '聚合登录结果缺少登录凭据'
    return
  }

  try {
    const user = JSON.parse(decodeBase64Url(userPayload))
    sessionStorage.setItem('user:token', token)
    setUserSessionUser(user || {}, { merge: false })
    statusText.value = message || '聚合登录成功'
    window.setTimeout(() => router.replace('/dashboard'), 600)
  } catch {
    failed.value = true
    statusText.value = '聚合登录用户信息解析失败'
  }
})
</script>

<template>
  <div class="auth-shell auth-shell-merchant">
    <section class="oauth-result-panel">
      <div class="brand-mark">
        <span class="brand-mark__icon">NX</span>
        <div>
          <p class="eyebrow">商户中心</p>
          <h1>NexPay</h1>
        </div>
      </div>
      <div class="notice-box" :class="{ 'oauth-result-panel__error': failed }">
        {{ statusText }}
      </div>
      <div v-if="failed" class="auth-links">
        <a href="/user/login">返回登录</a>
        <a v-if="mode === 'bind'" href="/user/account/bindings">返回绑定页</a>
      </div>
    </section>
  </div>
</template>

<style scoped>
.oauth-result-panel {
  width: min(460px, 100%);
  display: grid;
  gap: 18px;
  padding: 34px;
  border: 1px solid rgba(211, 223, 237, 0.9);
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 24px 70px rgba(22, 44, 77, 0.12);
}

.oauth-result-panel__error {
  border-color: rgba(240, 68, 56, 0.22);
  background: rgba(240, 68, 56, 0.05);
  color: #a73228;
}
</style>
