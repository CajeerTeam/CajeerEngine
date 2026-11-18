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

  const token = useState<string | null>('ce-admin-token', () => null)
  const csrf = useState<string | null>('ce-admin-csrf', () => null)
  const setToken = (_value: string | null) => { token.value = null }
  const setCsrf = (value: string | null) => { csrf.value = value }

  const request = async <T = unknown>(path: string, options: AdminRequestOptions = {}): Promise<T> => {
    const headers = new Headers(options.headers)
    headers.set('Accept', 'application/json')
    if (options.body && !headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json')
    }
    if (csrf.value) headers.set('X-CSRF-Token', csrf.value)
    if (path === '/auth/login') headers.set('X-Cajeer-Browser', '1')

    const response = await fetch(`${baseUrl}${path}`, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
    const payload = await response.json().catch(() => null) as T & ApiErrorPayload | null

    if (!response.ok) {
      const message = payload?.error?.message || `Ошибка API: HTTP ${response.status}`
      throw new AdminApiError(message, response.status, payload)
    }

    return payload as T
  }

  return { baseUrl, token, setToken, csrf, setCsrf, request }
}
