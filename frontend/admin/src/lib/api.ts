import { authHeaders as createAuthHeaders, request, type ApiResponse } from './request'

function authHeaders(): Record<string, string> {
  return createAuthHeaders('admin:token')
}

export async function loginAdmin(payload: Record<string, any>) {
  const resp = await request<ApiResponse<{ token?: string; user?: Record<string, any> }>>('/api/admin/auth/login', {
    method: 'POST',
    body: JSON.stringify(payload),
  })

  if (resp.code === 0 && resp.data?.token) {
    sessionStorage.setItem('admin:token', resp.data.token)
    sessionStorage.setItem('admin:user', JSON.stringify(resp.data.user || {}))
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

export async function saveAdminPassword(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/admin/profile/password', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  })
}
