export type ApiResponse<T> = {
  code: number
  data?: T
  message?: string
}

export async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(path, {
    ...init,
    headers: {
      'Content-Type': 'application/json',
      ...(init?.headers || {}),
    },
  })

  return (await response.json()) as T
}

export function authHeaders(tokenKey: string): Record<string, string> {
  const token = sessionStorage.getItem(tokenKey) || ''
  return token ? { Authorization: `Bearer ${token}` } : {}
}
