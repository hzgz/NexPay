<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthTopHeader from '../components/AuthTopHeader.vue'
import GeetestChallenge from '../components/GeetestChallenge.vue'
import { getUserAuthConfig, getUserCaptcha, registerUser } from '../lib/api'
import { applyAuthConfig, applyCaptcha, applyGeetest, createAuthFlags, createAuthState, geetestPayload } from '../lib/auth-form'

const router = useRouter()
const loading = ref(false)
const error = ref('')
const success = ref('')
const paymentOrder = ref<Record<string, any> | null>(null)
const { authConfig, captcha, geetest } = createAuthState()
const { allowRegister, needCaptcha, needGeetest } = createAuthFlags(authConfig, {
  captchaField: 'merchant_register_captcha',
  geetestField: 'geetest_scene_register',
})
const requireRealname = computed(() => Boolean(authConfig.auth?.require_realname_after_register))
const registerPaymentMethods = computed<Array<Record<string, any>>>(() => {
  const items = authConfig.payment?.system_checkout_methods
  return Array.isArray(items) ? items : []
})

const form = reactive({
  merchant_name: '',
  contact_name: '',
  username: '',
  email: '',
  phone: '',
  password: '',
  confirm_password: '',
  register_fee_method_code: '',
})

async function loadConfig() {
  const resp = await getUserAuthConfig()
  if (resp.code === 0 && resp.data) {
    applyAuthConfig(authConfig, resp.data)
    applyCaptcha(captcha, resp.data.captcha)
    applyGeetest(geetest, resp.data.geetest)
    if (!form.register_fee_method_code && registerPaymentMethods.value.length > 0) {
      form.register_fee_method_code = String(registerPaymentMethods.value[0].method_code || registerPaymentMethods.value[0].code || '')
    }
  }
}

async function refreshCaptcha() {
  const resp = await getUserCaptcha('merchant_register', Boolean(geetest.fallback_required))
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
  success.value = ''
  paymentOrder.value = null

  try {
    const resp = await registerUser({
      ...form,
      register_fee_method_code: form.register_fee_method_code,
      captcha_key: needCaptcha.value || geetest.fallback_required ? captcha.captcha_key : '',
      captcha_code: needCaptcha.value || geetest.fallback_required ? captcha.captcha_code : '',
      ...geetestPayload(geetest, needGeetest.value),
    })

    if (resp.code === 0) {
      if (resp.data?.payment_required) {
        paymentOrder.value = resp.data.payment_order || resp.data.user?.payment_order || null
        success.value = resp.message || '注册成功，请先完成注册费用支付。'
        return
      }

      if (resp.data?.token) {
        router.push('/dashboard')
      } else {
        success.value = resp.message || '注册成功，请等待管理员审核。'
      }
      return
    }

    error.value = resp.message || '注册失败'
  } catch {
    error.value = '注册失败'
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

      <div class="auth-stage__surface auth-stage__surface--wide">
        <form class="auth-card auth-card--wide" @submit.prevent="submit">
          <div class="auth-card__head">
            <span class="auth-hero__kicker">商户接入</span>
            <h2>创建商户账户</h2>
            <p>填写基础资料后即可开始接入 NexPay。</p>
          </div>

          <div v-if="!allowRegister" class="notice-box">商户注册已关闭，请联系管理员开通。</div>

          <template v-else>
            <div class="auth-fields auth-fields--grid">
              <label class="auth-field auth-field--span-2">
                <span>商户名称</span>
                <input v-model="form.merchant_name" type="text" placeholder="请输入商户名称" />
              </label>

              <label class="auth-field">
                <span>联系人</span>
                <input v-model="form.contact_name" type="text" placeholder="请输入联系人" />
              </label>

              <label class="auth-field">
                <span>商户账号</span>
                <input v-model="form.username" type="text" autocomplete="username" placeholder="请输入商户账号" />
              </label>

              <label class="auth-field">
                <span>邮箱</span>
                <input v-model="form.email" type="email" autocomplete="email" placeholder="请输入邮箱" />
              </label>

              <label class="auth-field">
                <span>手机号</span>
                <input v-model="form.phone" type="tel" autocomplete="tel" placeholder="请输入手机号" />
              </label>

              <label class="auth-field">
                <span>登录密码</span>
                <input v-model="form.password" type="password" autocomplete="new-password" placeholder="请输入登录密码" />
              </label>

              <label class="auth-field">
                <span>确认密码</span>
                <input v-model="form.confirm_password" type="password" autocomplete="new-password" placeholder="请再次输入密码" />
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

            <div v-if="registerPaymentMethods.length" class="register-methods">
              <span class="register-methods__label">注册费支付方式</span>
              <div class="register-methods__list">
                <label
                  v-for="item in registerPaymentMethods"
                  :key="item.key || item.code || item.method_code"
                  class="register-method"
                  :class="{ 'is-active': form.register_fee_method_code === String(item.method_code || item.code || '') }"
                >
                  <input v-model="form.register_fee_method_code" type="radio" :value="String(item.method_code || item.code || '')" />
                  <img v-if="item.icon" :src="`/${String(item.icon).replace(/^\/+/, '')}`" :alt="item.name" />
                  <span>{{ item.name }}</span>
                </label>
              </div>
            </div>

            <button class="auth-submit" :disabled="loading" type="submit">
              {{ loading ? '提交中...' : '注册商户' }}
            </button>
          </template>

          <p v-if="success" class="success">{{ success }}</p>
          <a v-if="paymentOrder?.pay_url" class="pay-link" :href="paymentOrder.pay_url">去支付 {{ paymentOrder.amount }}</a>
          <p v-if="error" class="error">{{ error }}</p>

          <div class="auth-links">
            <a href="/user/login">返回登录</a>
            <a v-if="requireRealname" href="/user/login">注册后需实名</a>
          </div>
        </form>
      </div>
    </section>
  </div>
</template>

<style scoped>
.register-methods {
  display: grid;
  gap: 7px;
  margin: 9px 0 0;
}

.register-methods__label {
  color: #6f829d;
  font-size: 11px;
}

.register-methods__list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.register-method {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 30px;
  padding: 0 9px;
  border: 1px solid #d8e3f2;
  background: #fff;
  color: #223a58;
  font-size: 11px;
}

.register-method.is-active {
  border-color: #2f6bff;
  background: #f4f8ff;
}

.register-method input {
  display: none;
}

.register-method img {
  width: 13px;
  height: 13px;
  object-fit: contain;
}
</style>
