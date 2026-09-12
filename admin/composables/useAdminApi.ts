import type { ApiErrorPayload } from '../types/admin'

export type AdminRequestOptions = RequestInit & {
  auth?: boolean
}

export class AdminApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly payload: ApiErrorPayload | null,
  ) {
    super(message)
    this.name = 'AdminApiError'
  }
}

export const useAdminApi = () => {
  const config = useRuntimeConfig()
  const baseUrl = String(config.public.apiBase || '/api/v1').replace(/\/$/, '')

  const token = useState<string | null>('ce-admin-token', () => {
    if (import.meta.client) {
      return window.localStorage.getItem('ce_admin_token')
    }
    return null
  })

  const setToken = (value: string | null) => {
    token.value = value
    if (import.meta.client) {
      if (value) {
        window.localStorage.setItem('ce_admin_token', value)
      } else {
        window.localStorage.removeItem('ce_admin_token')
      }
    }
  }

  const request = async <T = unknown>(path: string, options: AdminRequestOptions = {}): Promise<T> => {
    const headers = new Headers(options.headers)
    headers.set('Accept', 'application/json')
    if (options.body && !headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json')
    }
    if (token.value) {
      headers.set('Authorization', `Bearer ${token.value}`)
    }

    const response = await fetch(`${baseUrl}${path}`, { ...options, headers })
    const payload = await response.json().catch(() => null) as T & ApiErrorPayload | null

    if (!response.ok) {
      const message = payload?.error?.message || `Ошибка API: HTTP ${response.status}`
      throw new AdminApiError(message, response.status, payload)
    }

    return payload as T
  }

  return { baseUrl, token, setToken, request }
}
