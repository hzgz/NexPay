<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import GeetestChallenge from '../components/GeetestChallenge.vue'
import { getAdminAuthConfig, getAdminCaptcha, loginAdmin } from '../lib/api'
import { applyAuthConfig, applyCaptcha, applyGeetest, createAuthFlags, createAuthState, geetestPayload } from '../lib/auth-form'

const router = useRouter()
const loading = ref(false)
const error = ref('')
const { authConfig, captcha, geetest } = createAuthState()
const { needCaptcha, needGeetest } = createAuthFlags(authConfig, {
  captchaField: 'admin_login_captcha',
  geetestField: 'geetest_scene_admin',
})

const form = reactive({
  username: '',
  password: '',
})

async function loadConfig() {
  const resp = await getAdminAuthConfig()
  if (resp.code === 0 && resp.data) {
    applyAuthConfig(authConfig, resp.data)
    applyCaptcha(captcha, resp.data.captcha)
    applyGeetest(geetest, resp.data.geetest)
  }
}

async function refreshCaptcha() {
  const resp = await getAdminCaptcha('admin_login', Boolean(geetest.fallback_required))
  if (resp.code === 0 && resp.data) {
    applyCaptcha(captcha, resp.data)
  }
}

async function handleGeetestFallback() {
  await refreshCaptcha()
}

async function submit() {
  loading.value = true
  error.value = ''

  try {
    const resp = await loginAdmin({
      ...form,
      captcha_key: needCaptcha.value || geetest.fallback_required ? captcha.captcha_key : '',
      captcha_code: needCaptcha.value || geetest.fallback_required ? captcha.captcha_code : '',
      ...geetestPayload(geetest, needGeetest.value),
    })

    if (resp.code === 0) {
      router.push('/dashboard')
      return
    }

    error.value = resp.message || '登录失败'
  } catch {
    error.value = '登录失败'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadConfig()
  if (needCaptcha.value) {
    await refreshCaptcha()
  }
})
</script>

<template>
  <div class="admin-auth-shell">
    <section class="admin-auth-stage">
      <div class="admin-auth-aside">
        <div class="auth-aside__top">
          <a class="auth-brand" href="/">
            <span class="auth-brand__mark">N</span>
            <span class="auth-brand__copy">
              <strong>NexPay</strong>
              <small>管理后台</small>
            </span>
          </a>
        </div>

        <div class="admin-auth-hero">
          <span class="auth-hero__kicker">管理员登录</span>
          <h1>进入后台，把商户、订单与系统状态放回同一视野。</h1>
          <p class="admin-hero-text">管理员入口只保留后台管理语义，版式与商户端保持统一，但视觉更克制、密度更高。</p>
        </div>

        <div class="admin-status-grid">
          <article>
            <strong>统一入口</strong>
            <span>/admin</span>
          </article>
          <article>
            <strong>系统范围</strong>
            <span>商户 / 订单 / 配置</span>
          </article>
          <article>
            <strong>运行视图</strong>
            <span>风控 / 资金 / 通知</span>
          </article>
        </div>
      </div>

      <form class="admin-auth-card" @submit.prevent="submit">
        <div class="auth-card__head">
          <span class="auth-hero__kicker">管理后台</span>
          <h2>进入管理后台</h2>
          <p>登录后继续处理商户、订单、插件、支付配置与系统安全。</p>
        </div>

        <div class="auth-fields">
          <label class="auth-field">
            <span>管理员账号</span>
            <input v-model="form.username" type="text" autocomplete="username" placeholder="请输入管理员账号" />
          </label>

          <label class="auth-field">
            <span>登录密码</span>
            <input v-model="form.password" type="password" autocomplete="current-password" placeholder="请输入登录密码" />
          </label>

          <label v-if="needCaptcha || geetest.fallback_required" class="auth-field">
            <span>验证码</span>
            <input v-model="captcha.captcha_code" type="text" autocomplete="one-time-code" placeholder="请输入验证码" />
          </label>

          <div v-if="needCaptcha || geetest.fallback_required" class="captcha-preview">
            <img v-if="captcha.captcha_image" :src="captcha.captcha_image" alt="图形验证码" />
            <span v-else>{{ captcha.captcha_hint || '验证码已刷新' }}</span>
            <button class="ghost-btn" type="button" @click="refreshCaptcha">刷新</button>
          </div>

          <GeetestChallenge
            v-if="needGeetest"
            :state="geetest"
            @fallback-required="handleGeetestFallback"
            @error="(message) => { error = message }"
          />
        </div>

        <p v-if="error" class="admin-error">{{ error }}</p>

        <button class="auth-submit" :disabled="loading" type="submit">
          {{ loading ? '登录中...' : '进入后台' }}
        </button>
      </form>
    </section>
  </div>
</template>
