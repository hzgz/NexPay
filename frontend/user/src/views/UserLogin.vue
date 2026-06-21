<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import GeetestChallenge from '../components/GeetestChallenge.vue'
import { getUserAuthConfig, getUserCaptcha, loginUser, startUserOAuth } from '../lib/api'
import { applyAuthConfig, applyCaptcha, applyGeetest, createAuthFlags, createAuthState, geetestPayload } from '../lib/auth-form'

const router = useRouter()
const loading = ref(false)
const error = ref('')
const { authConfig, captcha, geetest } = createAuthState()
const { showRegister, showForgot, needCaptcha, needGeetest } = createAuthFlags(authConfig, {
  captchaField: 'merchant_login_captcha',
  geetestField: 'geetest_scene_login',
})

const form = reactive({
  username: '',
  password: '',
})

const heroVisual = `${import.meta.env.BASE_URL}brand/hero-arch-light-clean.png`

const oauthLoading = ref('')
const oauthChannels = [
  { key: 'qq', label: 'QQ' },
  { key: 'wechat', label: '微信' },
  { key: 'alipay', label: '支付宝' },
  { key: 'google', label: 'Google' },
  { key: 'telegram', label: 'Telegram' },
]

const enabledOAuthChannels = computed(() => {
  if (!authConfig.oauth?.enabled) return []
  return oauthChannels.filter((item) => Boolean(authConfig.oauth?.[`${item.key}_enabled`]))
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
  const resp = await getUserCaptcha('merchant_login', Boolean(geetest.fallback_required))
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
    const resp = await loginUser({
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

async function oauthLogin(channel: string) {
  oauthLoading.value = channel
  error.value = ''

  try {
    const resp = await startUserOAuth(channel, 'login')
    if (resp.code === 0 && resp.data?.auth_url) {
      window.location.href = String(resp.data.auth_url)
      return
    }

    error.value = resp.message || '聚合登录发起失败'
  } catch {
    error.value = '聚合登录发起失败'
  } finally {
    oauthLoading.value = ''
  }
}

onMounted(async () => {
  await loadConfig()
  if (needCaptcha.value && !captcha.captcha_key) {
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
          <span class="auth-hero__kicker">商户登录</span>
          <h1>欢迎回来，继续把每一笔支付跑得更稳。</h1>
          <p>使用商户账号登录后即可继续管理订单、资金、通道与接口资料，验证码与聚合登录能力保持原有规则。</p>
        </div>

        <div class="auth-preview">
          <div class="auth-preview__frame">
            <div class="auth-preview__line"></div>
            <div class="auth-preview__copy">
              <h2>
                让每一次支付，
                <br />
                都成为<span>增长的力量</span>
              </h2>
              <p>支付、订单、通道、资金与接口信息全部按同一套后台结构梳理，让登录后的操作路径更直接。</p>
              <div class="auth-preview__actions">
                <a class="primary-btn" href="/user/register">立即接入</a>
                <a class="soft-btn" href="/doc">开发文档</a>
              </div>
            </div>
            <div class="auth-preview__visual">
              <img :src="heroVisual" alt="NexPay 商户视觉" />
            </div>
            <div class="auth-preview__stats">
              <div>
                <strong>99.99%</strong>
                <span>支付成功率</span>
              </div>
              <div>
                <strong>&lt; 200ms</strong>
                <span>平均响应时间</span>
              </div>
              <div>
                <strong>80+</strong>
                <span>支付方式</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <form class="auth-card" @submit.prevent="submit">
        <div class="auth-card__head">
          <span class="auth-hero__kicker">商户平台</span>
          <h2>进入商户中心</h2>
          <p>使用商户账号与密码登录，继续管理订单、资金、通道和接口信息。</p>
        </div>

        <div class="auth-fields">
          <label class="auth-field">
            <span>商户账号</span>
            <input v-model="form.username" type="text" autocomplete="username" placeholder="请输入商户账号 / 邮箱 / 手机号" />
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
            <button class="soft-btn" type="button" @click="refreshCaptcha">刷新</button>
          </div>

          <GeetestChallenge
            v-if="needGeetest"
            :state="geetest"
            @fallback-required="handleGeetestFallback"
            @error="(message) => { error = message }"
          />
        </div>

        <p v-if="error" class="error">{{ error }}</p>

        <button class="auth-submit" :disabled="loading" type="submit">
          {{ loading ? '登录中...' : '进入商户中心' }}
        </button>

        <div v-if="enabledOAuthChannels.length" class="oauth-entry">
          <span>第三方登录</span>
          <div class="oauth-actions">
            <button
              v-for="item in enabledOAuthChannels"
              :key="item.key"
              class="oauth-btn"
              type="button"
              :disabled="Boolean(oauthLoading)"
              @click="oauthLogin(item.key)"
            >
              {{ oauthLoading === item.key ? '跳转中...' : item.label }}
            </button>
          </div>
        </div>

        <div class="auth-links">
          <a v-if="showRegister" href="/user/register">立即注册</a>
          <a v-if="showForgot" href="/user/forgot-password">找回密码</a>
        </div>
      </form>
    </section>
  </div>
</template>
