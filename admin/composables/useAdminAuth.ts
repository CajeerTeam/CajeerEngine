import type { AdminIdentity } from '../types/admin'

export const useAdminAuth = () => {
  const api = useAdminApi()
  const identity = useState<AdminIdentity | null>('ce-admin-identity', () => null)
  const loading = useState<boolean>('ce-admin-auth-loading', () => false)
  const error = useState<string | null>('ce-admin-auth-error', () => null)

  const load = async () => {
    if (!api.token.value) {
      identity.value = null
      return null
    }
    loading.value = true
    error.value = null
    try {
      const response = await api.request<{ data: AdminIdentity }>('/auth/me')
      identity.value = response.data
      return identity.value
    } catch (e) {
      identity.value = null
      api.setToken(null)
      error.value = e instanceof Error ? e.message : 'Не удалось загрузить профиль.'
      return null
    } finally {
      loading.value = false
    }
  }

  const login = async (email: string, password: string, twoFactorCode = '') => {
    loading.value = true
    error.value = null
    try {
      const response = await api.request<{ data: { token?: string; plain_token?: string; access_token?: string } & AdminIdentity }>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password, two_factor_code: twoFactorCode || undefined }),
      })
      const token = response.data.token || response.data.plain_token || response.data.access_token
      if (!token) {
        throw new Error('API не вернул token для Admin UI.')
      }
      api.setToken(token)
      await load()
      return true
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Ошибка входа.'
      return false
    } finally {
      loading.value = false
    }
  }

  const logout = async () => {
    if (api.token.value) {
      await api.request('/auth/logout', { method: 'POST' }).catch(() => null)
    }
    api.setToken(null)
    identity.value = null
  }

  return { token: api.token, identity, loading, error, load, login, logout }
}
