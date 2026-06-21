import { request, type ApiResponse } from './request'

type UserLoginResponse = {
  token?: string
  user?: Record<string, any>
}

type UserRegisterResponse = {
  token?: string
  user?: Record<string, any>
  payment_required?: boolean
  payment_order?: Record<string, any>
}

type AdminLoginResponse = {
  token?: string
  user?: Record<string, any>
}

function saveSession(tokenKey: string, userKey: string, payload?: { token?: string; user?: Record<string, any> }) {
  if (!payload?.token) {
    return
  }

  sessionStorage.setItem(tokenKey, payload.token)
  sessionStorage.setItem(userKey, JSON.stringify(payload.user || {}))
}

export async function getDemoConfig() {
  return request<ApiResponse<Record<string, any>>>('/api/home/demo-config')
}

export async function createDemoOrder(payload: Record<string, any>) {
  return request<ApiResponse<Record<string, any>>>('/api/home/demo-create', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function getDemoOrderStatus(tradeNo: string) {
  return request<ApiResponse<Record<string, any>>>(`/api/home/demo-status/${encodeURIComponent(tradeNo)}`)
}

export async function getUserAuthConfig() {
  return request<ApiResponse<Record<string, any>>>('/api/merchant/auth/config')
}

export async function getUserCaptcha(scene: string, force = false) {
  const forceQuery = force ? '&force=1' : ''
  return request<ApiResponse<Record<string, any>>>(`/api/merchant/auth/captcha?scene=${encodeURIComponent(scene)}${forceQuery}`)
}

export async function loginUser(payload: Record<string, any>) {
  const response = await request<ApiResponse<UserLoginResponse>>('/api/merchant/auth/login', {
    method: 'POST',
    body: JSON.stringify(payload),
  })

  if (response.code === 0) {
    saveSession('user:token', 'user:user', response.data)
  }

  return response
}

export async function registerUser(payload: Record<string, any>) {
  const response = await request<ApiResponse<UserRegisterResponse>>('/api/merchant/auth/register', {
    method: 'POST',
    body: JSON.stringify(payload),
  })

  if (response.code === 0) {
    saveSession('user:token', 'user:user', response.data)
  }

  return response
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

export async function getAdminAuthConfig() {
  return request<ApiResponse<Record<string, any>>>('/api/admin/auth/config')
}

export async function getAdminCaptcha(scene: string, force = false) {
  const forceQuery = force ? '&force=1' : ''
  return request<ApiResponse<Record<string, any>>>(`/api/admin/auth/captcha?scene=${encodeURIComponent(scene)}${forceQuery}`)
}

export async function loginAdmin(payload: Record<string, any>) {
  const response = await request<ApiResponse<AdminLoginResponse>>('/api/admin/auth/login', {
    method: 'POST',
    body: JSON.stringify(payload),
  })

  if (response.code === 0) {
    saveSession('admin:token', 'admin:user', response.data)
  }

  return response
}
