<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Message } from '@element-plus/icons-vue'
import GeetestChallenge from '../components/GeetestChallenge.vue'
import AuthTopHeader from '../components/AuthTopHeader.vue'
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
      <AuthTopHeader />

      <div class="auth-stage__surface">
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
      </div>
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
