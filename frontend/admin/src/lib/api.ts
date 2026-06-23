import { authHeaders as createAuthHeaders, request, type ApiResponse } from './request'

export const ADMIN_SESSION_UPDATED_EVENT = 'admin-user-updated'

function authHeaders(): Record<string, string> {
  return createAuthHeaders('admin:token')
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

export function resolveAdminAvatarUrl(value: unknown, version?: unknown): string {
  return withVersion(normalizeAvatarValue(value), version)
}

export function setAdminSessionUser(
  payload: Record<string, any>,
  options: { merge?: boolean; bumpAvatarVersion?: boolean } = {},
) {
  const current = getAdminSessionUser()
  const merge = options.merge !== false
  const next = merge ? { ...current, ...(payload || {}) } : { ...(payload || {}) }
  const avatarChanged = Object.prototype.hasOwnProperty.call(payload || {}, 'avatar')
    && String(payload?.avatar || '').trim() !== String(current.avatar || '').trim()

  if (options.bumpAvatarVersion || avatarChanged) {
    next.avatar_version = Date.now()
  } else if (current.avatar_version && !Object.prototype.hasOwnProperty.call(next, 'avatar_version')) {
    next.avatar_version = current.avatar_version
  }

  sessionStorage.setItem('admin:user', JSON.stringify(next))
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(ADMIN_SESSION_UPDATED_EVENT))
  }

  return next
}

export async function loginAdmin(payload: Record<string, any>) {
  const resp = await request<ApiResponse<{ token?: string; user?: Record<string, any> }>>('/api/admin/auth/login', {
    method: 'POST',
    body: JSON.stringify(payload),
  })

  if (resp.code === 0 && resp.data?.token) {
    sessionStorage.setItem('admin:token', resp.data.token)
    setAdminSessionUser(resp.data.user || {}, { merge: false })
  }

  return resp
}

export async function getAdminAuthConfig() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/auth/config')
}

export async function getAdminCaptcha(scene: string, force = false) {
  const forceQuery = force ? '&force=1' : ''
  return request<ApiResponse<Record<string, any>>>(`/api/admin/auth/captcha?scene=${encodeURIComponent(scene)}${forceQuery}`)
}

export function getAdminSessionUser() {
  try {
    return JSON.parse(sessionStorage.getItem('admin:user') || '{}')
  } catch {
    return {}
  }
}

export async function getAdminDashboard() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/dashboard/overview', { headers: authHeaders() })
}

export async function getAdminMerchants() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/merchants', { headers: authHeaders() })
}

export async function createAdminMerchant(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/merchants/create', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function reviewAdminMerchant(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/merchants/review', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function reviewAdminMerchantRealname(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/merchants/realname/review', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function saveAdminMerchantGroup(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/merchants/groups/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function deleteAdminMerchantGroup(id: number) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/merchants/groups/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id }),
  })
}

export async function getAdminOrders() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/orders', { headers: authHeaders() })
}

export async function confirmAdminOrderPayment(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/orders/manual-confirm', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function retryAdminOrderCallback(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/orders/callback-retry', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function deleteAdminOrder(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/orders/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function confirmAdminRefund(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/refunds/manual-confirm', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function syncAdminRefund(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/refunds/sync', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function syncAdminRefundBatch(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/refunds/sync-batch', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function reviewAdminTransfer(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/transfers/review', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function syncAdminTransfer(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/transfers/sync', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function syncAdminTransferBatch(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/transfers/sync-batch', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function reviewAdminSettlement(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/settlements/review', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function getAdminFees() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/fees', { headers: authHeaders() })
}

export async function getAdminPackages() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/packages', { headers: authHeaders() })
}

export async function saveAdminPackage(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/packages/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function getAdminSettings() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/settings', { headers: authHeaders() })
}

export async function saveAdminSettings(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/settings/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function clearAdminCache() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/settings/cache-clear', {
    method: 'POST',
    headers: authHeaders(),
  })
}

export async function runAdminCleanup(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/settings/cleanup', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function testAdminProvider(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/settings/provider-test', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function saveAdminAnnouncement(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/announcements/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function toggleAdminAnnouncement(id: number, statusCode: number) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/announcements/toggle', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id, status_code: statusCode }),
  })
}

export async function deleteAdminAnnouncement(id: number) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/announcements/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id }),
  })
}

export async function getAdminTickets() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/tickets', { headers: authHeaders() })
}

export async function getAdminTicketDetail(id: number) {
  return request<ApiResponse<Record<string, any>>>(`/api/admin/tickets/detail?id=${id}`, { headers: authHeaders() })
}

export async function createAdminTicket(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/tickets/create', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function updateAdminTicket(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/tickets/update', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function replyAdminTicket(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/tickets/reply', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function saveAdminTicketCategory(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/tickets/category/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function deleteAdminTicketCategory(id: number) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/tickets/category/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id }),
  })
}

export async function getAdminFiles() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/files', { headers: authHeaders() })
}

export async function deleteAdminFile(id: number) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/files/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id }),
  })
}

export async function getAdminPlugins() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/plugins', { headers: authHeaders() })
}

export async function scanAdminPlugins() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/plugins/scan', {
    method: 'POST',
    headers: authHeaders(),
  })
}

export async function toggleAdminPlugin(code: string, statusCode: number) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/plugins/toggle', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ code, status_code: statusCode }),
  })
}

export async function deleteAdminPlugin(code: string) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/plugins/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ code }),
  })
}

export async function saveAdminPlugin(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/plugins/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function saveAdminPaymentMethod(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/plugins/method/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function toggleAdminPaymentMethod(code: string, statusCode: number) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/plugins/method/toggle', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ code, status_code: statusCode }),
  })
}

export async function deleteAdminPaymentMethod(code: string) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/plugins/method/delete', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ code }),
  })
}

export async function getAdminTasks() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/tasks', { headers: authHeaders() })
}

export async function runAdminTask(key: string) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/tasks/run', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ key }),
  })
}

export async function saveAdminTask(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/tasks/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function getAdminTaskLogs(key: string) {
  return request<ApiResponse<Record<string, any>>>(`/api/admin/tasks/logs?key=${encodeURIComponent(key)}`, {
    headers: authHeaders(),
  })
}

export async function getAdminLogs() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/logs', { headers: authHeaders() })
}

export async function retryAdminCallback(id: number) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/callbacks/retry', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ id }),
  })
}

export async function getAdminProfile() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/profile', { headers: authHeaders() })
}

export async function saveAdminProfile(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/profile/save', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}

export async function uploadAdminAvatar(file: File) {
  const form = new FormData()
  form.append('file', file)

  return request<ApiResponse<Record<string, any>>>('/api/admin/profile/avatar/upload', {
    method: 'POST',
    headers: authHeaders(),
    body: form,
  })
}

export async function saveAdminPassword(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/profile/password', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}
