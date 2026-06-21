<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Message } from '@element-plus/icons-vue'
import GeetestChallenge from '../components/GeetestChallenge.vue'
import { useRouter } from 'vue-router'
import { forgotUserPassword, getUserAuthConfig, getUserCaptcha, sendUserForgotCode } from '../lib/api'
import { applyAuthConfig, applyCaptcha, applyGeetest, createAuthFlags, createAuthState, geetestPayload } from '../lib/auth-form'

const router = useRouter()
const loading = ref(false)
const codeLoading = ref(false)
const error = ref('')
const success = ref('')
const codeNotice = ref('')
const { authConfig, captcha, geetest } = createAuthState()
const { allowForgot, needCaptcha, needGeetest } = createAuthFlags(authConfig, {
  captchaField: 'merchant_forgot_captcha',
  geetestField: 'geetest_scene_forgot',
})

const form = reactive({
  username: '',
  email: '',
  verify_code: '',
  new_password: '',
  confirm_password: '',
})

const heroVisual = `${import.meta.env.BASE_URL}brand/hero-arch-light.png`

async function loadConfig() {
  const resp = await getUserAuthConfig()
  if (resp.code === 0 && resp.data) {
    applyAuthConfig(authConfig, resp.data)
    applyCaptcha(captcha, resp.data.captcha)
    applyGeetest(geetest, resp.data.geetest)
  }
}

async function refreshCaptcha() {
  const resp = await getUserCaptcha('merchant_forgot', Boolean(geetest.fallback_required))
  if (resp.code === 0 && resp.data) {
    applyCaptcha(captcha, resp.data)
  }
}

async function handleGeetestFallback() {
  await refreshCaptcha()
}

async function sendCode() {
  codeLoading.value = true
  error.value = ''
  success.value = ''
  codeNotice.value = ''

  try {
    const resp = await sendUserForgotCode({
      username: form.username,
      email: form.email,
      captcha_key: needCaptcha.value || geetest.fallback_required ? captcha.captcha_key : '',
      captcha_code: needCaptcha.value || geetest.fallback_required ? captcha.captcha_code : '',
      ...geetestPayload(geetest, needGeetest.value),
    })

    if (resp.code === 0) {
      codeNotice.value = resp.message || '验证码已发送'
      if (needCaptcha.value || geetest.fallback_required) {
        await refreshCaptcha()
      }
      return
    }

    error.value = resp.message || '验证码发送失败'
  } catch {
    error.value = '验证码发送失败'
  } finally {
    codeLoading.value = false
  }
}

async function submit() {
  loading.value = true
  error.value = ''
  success.value = ''

  try {
    const resp = await forgotUserPassword({
      ...form,
      captcha_key: needCaptcha.value || geetest.fallback_required ? captcha.captcha_key : '',
      captcha_code: needCaptcha.value || geetest.fallback_required ? captcha.captcha_code : '',
      ...geetestPayload(geetest, needGeetest.value),
    })

    if (resp.code === 0) {
      success.value = resp.message || '密码已更新'
      setTimeout(() => router.push('/login'), 900)
      return
    }

    error.value = resp.message || '重置失败'
  } catch {
    error.value = '重置失败'
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
  <div class="auth-shell auth-shell-merchant">
    <section class="auth-stage">
      <div class="auth-aside">
        <div class="auth-aside__top">
          <a class="auth-brand" href="/">
            <span class="auth-brand__mark">N</span>
            <span class="auth-brand__copy">
              <strong>NexPay</strong>
              <small>商户中心</small>
            </span>
          </a>
        </div>

        <div class="auth-hero">
          <span class="auth-hero__kicker">找回密码</span>
          <h1>重置密码这一步，也应该清晰、可靠、顺手。</h1>
          <p>通过商户账号和注册邮箱完成验证，再设置新的登录密码，恢复后即可继续进入商户中心。</p>
        </div>

        <div class="auth-preview">
          <div class="auth-preview__frame">
            <div class="auth-preview__line"></div>
            <div class="auth-preview__copy">
              <h2>
                验证身份，
                <br />
                安全<span>重新登录</span>
              </h2>
              <p>验证码、极验和找回开关继续按后台配置执行，页面层级则重新收干净，移动端下也不会挤压表单节奏。</p>
              <div class="auth-preview__actions">
                <a class="soft-btn" href="/user/login">返回登录</a>
                <a class="primary-btn" href="/user/register">注册商户</a>
              </div>
            </div>
            <div class="auth-preview__visual">
              <img :src="heroVisual" alt="NexPay 密码找回视觉" />
            </div>
            <div class="auth-preview__stats">
              <div>
                <strong>邮箱</strong>
                <span>发送验证码</span>
              </div>
              <div>
                <strong>验证</strong>
                <span>校验身份</span>
              </div>
              <div>
                <strong>重置</strong>
                <span>更新密码</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <form class="auth-card" @submit.prevent="submit">
        <div class="auth-card__head">
          <span class="auth-hero__kicker">找回密码</span>
          <h2>重置登录密码</h2>
          <p>{{ allowForgot ? '验证通过后即可更新商户登录密码。' : '当前系统已关闭密码找回功能。' }}</p>
        </div>

        <div v-if="!allowForgot" class="notice-box">当前已关闭密码找回功能。</div>

        <template v-else>
          <div class="auth-fields">
            <label class="auth-field">
              <span>商户账号</span>
              <input v-model="form.username" type="text" autocomplete="username" placeholder="请输入商户账号" />
            </label>

            <label class="auth-field">
              <span>注册邮箱</span>
              <div class="code-row">
                <input v-model="form.email" type="email" autocomplete="email" placeholder="请输入注册邮箱" />
                <button :disabled="codeLoading" class="code-button" type="button" @click="sendCode">
                  <Message class="button-icon" />
                  <span>{{ codeLoading ? '发送中...' : '发送验证码' }}</span>
                </button>
              </div>
            </label>

            <label class="auth-field">
              <span>邮箱验证码</span>
              <input v-model="form.verify_code" type="text" autocomplete="one-time-code" placeholder="请输入邮箱验证码" />
            </label>

            <label class="auth-field">
              <span>新密码</span>
              <input v-model="form.new_password" type="password" autocomplete="new-password" placeholder="请输入新密码" />
            </label>

            <label class="auth-field">
              <span>确认新密码</span>
              <input v-model="form.confirm_password" type="password" autocomplete="new-password" placeholder="请再次输入新密码" />
            </label>

            <label v-if="needCaptcha || geetest.fallback_required" class="auth-field">
              <span>验证码</span>
              <input v-model="captcha.captcha_code" type="text" autocomplete="one-time-code" placeholder="请输入验证码" />
            </label>

            <div v-if="needCaptcha || geetest.fallback_required" class="captcha-preview">
              <img v-if="captcha.captcha_image" :src="captcha.captcha_image" alt="图形验证码" />
              <span v-else>{{ captcha.captcha_hint || '验证码已刷新' }}</span>
              <button class="soft-btn" type="button" @click="refreshCaptcha">刷新</button>
            </div>

            <GeetestChallenge
              v-if="needGeetest"
              :state="geetest"
              @fallback-required="handleGeetestFallback"
              @error="(message) => { error = message }"
            />
          </div>

          <button class="auth-submit" :disabled="loading" type="submit">
            {{ loading ? '提交中...' : '确认重置' }}
          </button>
        </template>

        <p v-if="codeNotice" class="success">{{ codeNotice }}</p>
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="success" class="success">{{ success }}</p>

        <div class="auth-links">
          <a href="/user/login">返回登录</a>
          <a href="/user/register">注册商户</a>
        </div>
      </form>
    </section>
  </div>
</template>

<style scoped>
.code-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
  align-items: center;
}

@media (max-width: 560px) {
  .code-row {
    grid-template-columns: 1fr;
  }
}
</style>
