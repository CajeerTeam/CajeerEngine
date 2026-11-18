import type { AdminIdentity } from '../types/admin'

export const useAdminAuth = () => {
  const api = useAdminApi()
  const identity = useState<AdminIdentity | null>('ce-admin-identity', () => null)
  const loading = useState<boolean>('ce-admin-auth-loading', () => false)
  const error = useState<string | null>('ce-admin-auth-error', () => null)

  const load = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await api.request<{ data: AdminIdentity }>('/auth/me')
      identity.value = response.data
      api.setCsrf((response.data as AdminIdentity & { csrf_token?: string }).csrf_token || null)
      return identity.value
    } catch (e) {
      identity.value = null
      api.setCsrf(null)
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
      api.setCsrf((response.data as { csrf_token?: string }).csrf_token || null)
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
    await api.request('/auth/logout', { method: 'POST' }).catch(() => null)
    api.setCsrf(null)
    identity.value = null
  }

  return { token: api.token, identity, loading, error, load, login, logout }
}
