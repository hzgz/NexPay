import { computed, reactive, type Reactive } from 'vue'

export type AuthConfigState = Reactive<Record<string, any>>
export type CaptchaState = Reactive<Record<string, any>>
export type GeetestState = Reactive<Record<string, any>>

type AuthFlagOptions = {
  captchaField?: string
  geetestField?: string
}

export function createAuthState() {
  const authConfig = reactive<Record<string, any>>({ auth: {}, merchant: {}, verify: {}, captcha: {}, geetest: {}, scenes: {} })
  const captcha = reactive<Record<string, any>>({ captcha_key: '', captcha_code: '', challenge_code: '' })
  const geetest = reactive<Record<string, any>>({
    verify_token: '',
    lot_number: '',
    captcha_output: '',
    pass_token: '',
    gen_time: '',
    fallback_required: false,
  })

  return { authConfig, captcha, geetest }
}

export function createAuthFlags(authConfig: AuthConfigState, options: AuthFlagOptions = {}) {
  const allowRegister = computed(() => Boolean(authConfig.auth?.register_enabled))
  const allowForgot = computed(() => Boolean(authConfig.auth?.recover_type) && authConfig.auth?.recover_type !== '关闭')
  const needCaptcha = computed(() => {
    if (!authConfig.auth?.captcha_enabled) return false
    if (!options.captchaField) return true
    return Boolean(authConfig.auth?.[options.captchaField])
  })
  const needGeetest = computed(() => {
    if (!authConfig.verify?.geetest_enabled) return false
    if (!options.geetestField) return true
    return Boolean(authConfig.verify?.[options.geetestField])
  })

  return {
    allowRegister,
    allowForgot,
    needCaptcha,
    needGeetest,
  }
}

export function applyAuthConfig(target: AuthConfigState, source?: Record<string, any>) {
  if (!source) return
  Object.assign(target, source)
}

export function applyCaptcha(target: CaptchaState, source?: Record<string, any>) {
  if (!source) return
  Object.assign(target, { ...source, challenge_code: source.captcha_code || '' })
  target.captcha_code = ''
}

export function applyGeetest(target: GeetestState, source?: Record<string, any>) {
  if (!source) return
  Object.assign(target, {
    ...source,
    verify_token: '',
    lot_number: '',
    captcha_output: '',
    pass_token: '',
    gen_time: '',
    fallback_required: false,
  })
}

export function geetestPayload(geetest: GeetestState, enabled: boolean) {
  if (!enabled || geetest.fallback_required) {
    return {
      verify_token: '',
      lot_number: '',
      captcha_output: '',
      pass_token: '',
      gen_time: '',
    }
  }

  return {
    verify_token: geetest.verify_token || '',
    lot_number: geetest.lot_number || '',
    captcha_output: geetest.captcha_output || '',
    pass_token: geetest.pass_token || '',
    gen_time: geetest.gen_time || '',
  }
}
