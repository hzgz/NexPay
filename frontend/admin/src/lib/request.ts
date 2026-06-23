export type ApiResponse<T> = {
  code: number
  data?: T
  message?: string
}

const LOGIN_PATH = '/admin/login'
const TOKEN_KEY = 'admin:token'
const USER_KEY = 'admin:user'

function hasAuthorizationHeader(headers?: HeadersInit): boolean {
  if (!headers) return false

  if (headers instanceof Headers) {
    return headers.has('Authorization')
  }

  if (Array.isArray(headers)) {
    return headers.some(([key]) => key.toLowerCase() === 'authorization')
  }

  return Object.keys(headers).some((key) => key.toLowerCase() === 'authorization')
}

function handleUnauthorized(): void {
  sessionStorage.removeItem(TOKEN_KEY)
  sessionStorage.removeItem(USER_KEY)

  if (window.location.pathname !== LOGIN_PATH) {
    window.location.replace(LOGIN_PATH)
  }
}

export async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const headers: Record<string, string> = { ...((init?.headers as Record<string, string>) || {}) }
  const isFormData = typeof FormData !== 'undefined' && init?.body instanceof FormData

  if (!isFormData && !Object.keys(headers).some((key) => key.toLowerCase() === 'content-type')) {
    headers['Content-Type'] = 'application/json'
  }

  const response = await fetch(path, {
    ...init,
    headers,
  })

  const payload = (await response.json()) as ApiResponse<unknown>

  if (hasAuthorizationHeader(headers) && (response.status === 401 || payload.code === 401)) {
    handleUnauthorized()
  }

  return payload as T
}

export function authHeaders(tokenKey: string): Record<string, string> {
  const token = sessionStorage.getItem(tokenKey) || ''
  return token ? { Authorization: `Bearer ${token}` } : {}
}
