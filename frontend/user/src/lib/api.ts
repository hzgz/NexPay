import { authHeaders as createAuthHeaders, request, type ApiResponse } from './request'

export const USER_SESSION_UPDATED_EVENT = 'user-user-updated'

function authHeaders(): Record<string, string> {
  return createAuthHeaders('user:token')
}

function normalizeAvatarValue(value: unknown): string {
  const raw = String(value || '').trim()
  if (!raw) return ''
  if (/^https?:\/\//i.test(raw) || raw.startsWith('data:')) return raw
  return raw.startsWith('/') ? raw : `/${raw}`
}

function withVersion(url: string, version: unknown): string {
  const stamp = String(version || '').trim()
  if (!url || !stamp || url.startsWith('data:')) {
    return url
  }

  const [base, hash = ''] = url.split('#', 2)
  const separator = base.includes('?') ? '&' : '?'
  const next = `${base}${separator}v=${encodeURIComponent(stamp)}`
  return hash ? `${next}#${hash}` : next
}

export function resolveUserAvatarUrl(value: unknown, version?: unknown): string {
  return withVersion(normalizeAvatarValue(value), version)
}

export function setUserSessionUser(
  payload: Record<string, any>,
  options: { merge?: boolean; bumpAvatarVersion?: boolean } = {},
) {
  const current = getUserSessionUser()
  const merge = options.merge !== false
  const next = merge ? { ...current, ...(payload || {}) } : { ...(payload || {}) }
  const avatarChanged = Object.prototype.hasOwnProperty.call(payload || {}, 'avatar')
    && String(payload?.avatar || '').trim() !== String(current.avatar || '').trim()

  if (options.bumpAvatarVersion || avatarChanged) {
    next.avatar_version = Date.now()
  } else if (current.avatar_version && !Object.prototype.hasOwnProperty.call(next, 'avatar_version')) {
    next.avatar_version = current.avatar_version
  }

  sessionStorage.setItem('user:user', JSON.stringify(next))
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(USER_SESSION_UPDATED_EVENT))
  }

  return next
}

export async function loginUser(payload: Record<string, any>) {
  const resp = await request<ApiResponse<{ token?: string; user?: Record<string, any> }>>('/api/merchant/auth/login', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  if (resp.code === 0 && resp.data?.token) {
    sessionStorage.setItem('user:token', resp.data.token)
    setUserSessionUser(resp.data.user || {}, { merge: false })
  }
  return resp
}

export async function getUserAuthConfig() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/auth/config')
}

export async function getUserCaptcha(scene: string, force = false) {
  const forceQuery = force ? '&force=1' : ''
  return request<ApiResponse<Record<string, any>>>(`/api/merchant/auth/captcha?scene=${encodeURIComponent(scene)}${forceQuery}`)
}

export async function startUserOAuth(channel: string, mode: 'login' | 'bind' = 'login') {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/auth/oauth/start', {
    method: 'POST',
    headers: mode === 'bind' ? authHeaders() : undefined,
    body: JSON.stringify({ channel, mode }),
  })
}

export async function registerUser(payload: Record<string, any>) {
  const resp = await request<
    ApiResponse<{
      token?: string
      user?: Record<string, any>
      payment_required?: boolean
      payment_order?: Record<string, any>
    }>
  >('/api/merchant/auth/register', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  if (resp.code === 0 && resp.data?.token) {
    sessionStorage.setItem('user:token', resp.data.token)
    setUserSessionUser(resp.data.user || {}, { merge: false })
  }
  return resp
}

export async function forgotUserPassword(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/auth/forgot-password', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function sendUserForgotCode(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/auth/forgot-code', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function getUserSessionUser() {
  try {
    return JSON.parse(sessionStorage.getItem('user:user') || '{}')
  } catch {
    return {}
  }
}

export async function getUserDashboard() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/dashboard/overview', { headers: authHeaders() })
}

export async function getUserChannels() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels', { headers: authHeaders() })
}

export async function saveUserChannel(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function toggleUserChannel(id: number, statusCode: number) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels/toggle', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id, status_code: statusCode }),
  })
}

export async function deleteUserChannel(id: number) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id }),
  })
}

export async function testUserChannel(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels/test', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function uploadUserChannelConfigFile(payload: {
  id?: number
  method_code: string
  plugin_code: string
  field_key: string
  plugin_config?: Record<string, any>
  file: File
}) {
  const form = new FormData()
  form.append('id', String(payload.id || 0))
  form.append('method_code', String(payload.method_code || ''))
  form.append('plugin_code', String(payload.plugin_code || ''))
  form.append('field_key', String(payload.field_key || ''))
  form.append('plugin_config', JSON.stringify(payload.plugin_config || {}))
  form.append('file', payload.file)

  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels/config/upload', {
    method: 'POST',
    headers: authHeaders(),
    body: form,
  })
}

export async function refreshUserAlipayCkQrcode(id: number) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels/alipay-ck/qrcode', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id }),
  })
}

export async function syncUserAlipayCkStatus(id: number) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels/alipay-ck/status', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id }),
  })
}

export async function saveUserChannelRotation(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels/rotation/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function saveUserPaymentSettings(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/channels/payment-settings/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function getUserOrders() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/orders', { headers: authHeaders() })
}

export async function retryUserOrderCallback(payload: {
  trade_no?: string
  out_trade_no?: string
  proof_no?: string
  txid?: string
  remark?: string
}) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/orders/callback-retry', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function deleteUserOrder(payload: { trade_no?: string; out_trade_no?: string }) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/orders/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function getUserFunds() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/funds', { headers: authHeaders() })
}

export async function createUserRecharge(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/funds/recharge', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function createUserWithdraw(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/funds/withdraw', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function getUserPackages() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/packages', { headers: authHeaders() })
}

export async function buyUserPackage(packageId: number, methodCode = '') {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/packages/buy', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ package_id: packageId, method_code: methodCode }),
  })
}

export async function getUserApiInfo() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/api-info', { headers: authHeaders() })
}

export async function resetUserApiMd5Key() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/api-info/md5/reset', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({}),
  })
}

export async function generateUserApiRsaKeyPair() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/api-info/rsa/generate', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({}),
  })
}

export async function saveUserApiSignMode(signMode: string) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/api-info/sign-mode/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ sign_mode: signMode }),
  })
}

export async function getUserTelegram() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/telegram', { headers: authHeaders() })
}

export async function getUserProfile() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/profile', { headers: authHeaders() })
}

export async function saveUserProfile(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/profile/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function uploadUserAvatar(file: File) {
  const form = new FormData()
  form.append('file', file)

  return request<ApiResponse<Record<string, any>>>('/api/merchant/profile/avatar/upload', {
    method: 'POST',
    headers: authHeaders(),
    body: form,
  })
}

export async function saveUserPassword(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/profile/password', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function getUserTickets() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/tickets', { headers: authHeaders() })
}

export async function getUserTicketDetail(id: number) {
  return request<ApiResponse<Record<string, any>>>(`/api/merchant/tickets/detail?id=${id}`, { headers: authHeaders() })
}

export async function createUserTicket(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/tickets/create', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function replyUserTicket(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/tickets/reply', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function getUserFiles() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/files', { headers: authHeaders() })
}

export async function deleteUserFile(id: number) {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/files/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id }),
  })
}
