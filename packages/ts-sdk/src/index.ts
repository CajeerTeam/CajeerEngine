export type CajeerEngineClientOptions = {
  baseUrl: string
  token?: string
}

export type ApiEnvelope<T> = {
  data: T
  meta?: Record<string, unknown>
}

export type LoginInput = {
  email: string
  password: string
  two_factor_code?: string
}

export type UserInput = {
  email: string
  name: string
  password: string
  roles?: string[]
}

export type ApiTokenInput = {
  name?: string
  scopes?: string[]
  expires_at?: string
}

export type ContentTypeInput = {
  handle: string
  name: string
  fields?: Array<Record<string, unknown>>
  blocks?: Array<Record<string, unknown>>
  localized?: boolean
  revisionable?: boolean
}

export type ContentEntryInput = {
  title?: string
  slug?: string
  status?: 'draft' | 'published' | 'archived'
  locale?: string
  translation_group?: string
  data: Record<string, unknown>
}

export type ExtensionInstallInput = {
  target?: string
  name?: string
  enable?: boolean
}

export type ExtensionConfigInput = {
  name: string
  config: Record<string, unknown>
}

export type MediaUploadInput = {
  filename: string
  content_base64: string
  mime_type?: string
  title?: string
  alt?: string
}

export class CajeerEngineClient {
  constructor(private readonly options: CajeerEngineClientOptions) {}

  setToken(token: string | undefined): void {
    this.options.token = token
  }

  async health(): Promise<unknown> { return this.request('/health') }
  async system(): Promise<unknown> { return this.request('/system') }
  async runtime(): Promise<unknown> { return this.request('/runtime') }
  async database(): Promise<unknown> { return this.request('/database') }
  async security(): Promise<unknown> { return this.request('/security') }
  async adminBootstrap(): Promise<unknown> { return this.request('/admin/bootstrap') }
  async adminDashboard(): Promise<unknown> { return this.request('/admin/dashboard') }

  async installerRequirements(): Promise<unknown> { return this.request('/installer/requirements') }
  async runInstaller(options: { migrate?: boolean } = {}): Promise<unknown> {
    return this.request('/installer/run', { method: 'POST', body: JSON.stringify(options) })
  }
  async exports(): Promise<unknown> { return this.request('/import-export/exports') }
  async createExport(input: { sections?: string[]; filename?: string } = {}): Promise<unknown> {
    return this.request('/import-export/export', { method: 'POST', body: JSON.stringify(input) })
  }
  async runImport(input: { file: string; dry_run?: boolean }): Promise<unknown> {
    return this.request('/import-export/import', { method: 'POST', body: JSON.stringify(input) })
  }
  async updates(): Promise<unknown> { return this.request('/updates') }
  async checkUpdates(): Promise<unknown> { return this.request('/updates/check', { method: 'POST', body: '{}' }) }
  async rcReadiness(): Promise<unknown> { return this.request('/rc/readiness') }
  async rcChecks(): Promise<unknown> { return this.request('/rc/checks') }
  async runRcChecks(): Promise<unknown> { return this.request('/rc/run', { method: 'POST', body: '{}' }) }

  async stableStatus(): Promise<unknown> { return this.request('/stable') }
  async stableSmokeTest(): Promise<unknown> { return this.request('/stable/smoke-test', { method: 'POST', body: '{}' }) }
  async stableReleaseLock(): Promise<unknown> { return this.request('/stable/release-lock', { method: 'POST', body: '{}' }) }
  async stableBackup(input: { include_uploads?: boolean } = {}): Promise<unknown> {
    return this.request('/stable/backup', { method: 'POST', body: JSON.stringify(input) })
  }
  async stableSupportBundle(input: { include_logs?: boolean } = {}): Promise<unknown> {
    return this.request('/stable/support-bundle', { method: 'POST', body: JSON.stringify(input) })
  }

  async login(input: LoginInput): Promise<unknown> {
    return this.request('/auth/login', { method: 'POST', body: JSON.stringify(input) })
  }

  async logout(): Promise<unknown> { return this.request('/auth/logout', { method: 'POST' }) }
  async me(): Promise<unknown> { return this.request('/auth/me') }

  async contentTypes(): Promise<unknown> { return this.request('/content-types') }
  async createContentType(input: ContentTypeInput): Promise<unknown> {
    return this.request('/content-types', { method: 'POST', body: JSON.stringify(input) })
  }
  async updateContentType(handle: string, input: Partial<ContentTypeInput>): Promise<unknown> {
    return this.request(`/content-types/${encodeURIComponent(handle)}`, { method: 'PATCH', body: JSON.stringify(input) })
  }
  async deleteContentType(handle: string): Promise<unknown> {
    return this.request(`/content-types/${encodeURIComponent(handle)}`, { method: 'DELETE' })
  }
  async contentTypeSchema(handle: string): Promise<unknown> {
    return this.request(`/content-types/${encodeURIComponent(handle)}/schema`)
  }

  async contentEntries(type: string, query: Record<string, string> = {}): Promise<unknown> {
    const search = new URLSearchParams(query).toString()
    return this.request(`/content/${encodeURIComponent(type)}${search ? `?${search}` : ''}`)
  }
  async createContentEntry(type: string, input: ContentEntryInput): Promise<unknown> {
    return this.request(`/content/${encodeURIComponent(type)}`, { method: 'POST', body: JSON.stringify(input) })
  }
  async updateContentEntry(type: string, id: string, input: Partial<ContentEntryInput>): Promise<unknown> {
    return this.request(`/content/${encodeURIComponent(type)}/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(input) })
  }
  async publishContentEntry(type: string, id: string): Promise<unknown> {
    return this.request(`/content/${encodeURIComponent(type)}/${encodeURIComponent(id)}/publish`, { method: 'POST' })
  }
  async unpublishContentEntry(type: string, id: string): Promise<unknown> {
    return this.request(`/content/${encodeURIComponent(type)}/${encodeURIComponent(id)}/unpublish`, { method: 'POST' })
  }
  async archiveContentEntry(type: string, id: string): Promise<unknown> {
    return this.request(`/content/${encodeURIComponent(type)}/${encodeURIComponent(id)}/archive`, { method: 'POST' })
  }
  async contentEntryRevisions(type: string, id: string): Promise<unknown> {
    return this.request(`/content/${encodeURIComponent(type)}/${encodeURIComponent(id)}/revisions`)
  }


  async media(query: Record<string, string> = {}): Promise<unknown> {
    const search = new URLSearchParams(query).toString()
    return this.request(`/media${search ? `?${search}` : ''}`)
  }
  async mediaDiagnostics(): Promise<unknown> { return this.request('/media/diagnostics') }
  async uploadMedia(input: MediaUploadInput): Promise<unknown> {
    return this.request('/media', { method: 'POST', body: JSON.stringify(input) })
  }
  async deleteMedia(id: string): Promise<unknown> {
    return this.request(`/media/${encodeURIComponent(id)}`, { method: 'DELETE' })
  }
  async publicSitemap(): Promise<unknown> { return this.request('/public/sitemap') }
  async previewContent(type: string, id: string): Promise<unknown> {
    return this.request(`/public/preview/${encodeURIComponent(type)}/${encodeURIComponent(id)}?format=json`)
  }



  async search(q: string, options: { index?: string; limit?: number } = {}): Promise<unknown> {
    const query = new URLSearchParams({ q })
    if (options.index) query.set('index', options.index)
    if (options.limit) query.set('limit', String(options.limit))
    return this.request(`/search?${query.toString()}`)
  }
  async reindexSearch(): Promise<unknown> { return this.request('/search/reindex', { method: 'POST' }) }
  async searchDiagnostics(): Promise<unknown> { return this.request('/search/diagnostics') }

  async queueDiagnostics(): Promise<unknown> { return this.request('/queue') }
  async pushQueueJob(name: string, payload: Record<string, unknown> = {}, queue = 'default'): Promise<unknown> {
    return this.request('/queue/jobs', { method: 'POST', body: JSON.stringify({ name, payload, queue }) })
  }
  async workQueue(queue = 'default', limit = 10): Promise<unknown> {
    return this.request('/queue/work', { method: 'POST', body: JSON.stringify({ queue, limit }) })
  }
  async failedQueueJobs(queue = 'default'): Promise<unknown> {
    return this.request(`/queue/failed?queue=${encodeURIComponent(queue)}`)
  }
  async retryQueueJob(id: string, queue = 'default'): Promise<unknown> {
    return this.request(`/queue/failed/${encodeURIComponent(id)}/retry?queue=${encodeURIComponent(queue)}`, { method: 'POST' })
  }

  async schedulerTasks(): Promise<unknown> { return this.request('/scheduler/tasks') }
  async runScheduler(): Promise<unknown> { return this.request('/scheduler/run', { method: 'POST' }) }

  async webhooks(): Promise<unknown> { return this.request('/webhooks') }
  async createWebhook(input: { name?: string; url: string; events?: string[]; enabled?: boolean }): Promise<unknown> {
    return this.request('/webhooks', { method: 'POST', body: JSON.stringify(input) })
  }
  async updateWebhook(id: string, input: { name?: string; url?: string; events?: string[]; enabled?: boolean }): Promise<unknown> {
    return this.request(`/webhooks/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(input) })
  }
  async deleteWebhook(id: string): Promise<unknown> { return this.request(`/webhooks/${encodeURIComponent(id)}`, { method: 'DELETE' }) }
  async testWebhook(id: string): Promise<unknown> { return this.request(`/webhooks/${encodeURIComponent(id)}/test`, { method: 'POST' }) }
  async webhookDeliveries(webhookId?: string): Promise<unknown> {
    return this.request(`/webhook-deliveries${webhookId ? `?webhook_id=${encodeURIComponent(webhookId)}` : ''}`)
  }
  async dispatchEvent(event: string, payload: Record<string, unknown> = {}): Promise<unknown> {
    return this.request('/events/dispatch', { method: 'POST', body: JSON.stringify({ event, payload }) })
  }


  async extensions(query: Record<string, string> = {}): Promise<unknown> {
    const search = new URLSearchParams(query).toString()
    return this.request(`/extensions${search ? `?${search}` : ''}`)
  }
  async extensionDiagnostics(): Promise<unknown> { return this.request('/extensions/diagnostics') }
  async extensionPermissions(enabled = true): Promise<unknown> {
    return this.request(`/extensions/permissions?enabled=${enabled ? '1' : '0'}`)
  }
  async extensionEvents(enabled = true): Promise<unknown> {
    return this.request(`/extensions/events?enabled=${enabled ? '1' : '0'}`)
  }
  async validateExtension(target: string): Promise<unknown> {
    return this.request('/extensions/validate', { method: 'POST', body: JSON.stringify({ target }) })
  }
  async installExtension(input: ExtensionInstallInput): Promise<unknown> {
    return this.request('/extensions/install', { method: 'POST', body: JSON.stringify(input) })
  }
  async enableExtension(name: string): Promise<unknown> {
    return this.request('/extensions/enable', { method: 'POST', body: JSON.stringify({ name }) })
  }
  async disableExtension(name: string): Promise<unknown> {
    return this.request('/extensions/disable', { method: 'POST', body: JSON.stringify({ name }) })
  }
  async uninstallExtension(name: string): Promise<unknown> {
    return this.request('/extensions/uninstall', { method: 'POST', body: JSON.stringify({ name }) })
  }
  async updateExtensionConfig(input: ExtensionConfigInput): Promise<unknown> {
    return this.request('/extensions/config', { method: 'PATCH', body: JSON.stringify(input) })
  }
  async dispatchExtensionEvent(event: string, payload: Record<string, unknown> = {}): Promise<unknown> {
    return this.request('/extensions/events/dispatch', { method: 'POST', body: JSON.stringify({ event, payload }) })
  }

  async users(): Promise<unknown> { return this.request('/users') }
  async createUser(input: UserInput): Promise<unknown> {
    return this.request('/users', { method: 'POST', body: JSON.stringify(input) })
  }
  async disableUser(id: string): Promise<unknown> {
    return this.request(`/users/${encodeURIComponent(id)}`, { method: 'DELETE' })
  }
  async roles(): Promise<unknown> { return this.request('/roles') }
  async apiTokens(): Promise<unknown> { return this.request('/api-tokens') }
  async createApiToken(input: ApiTokenInput): Promise<unknown> {
    return this.request('/api-tokens', { method: 'POST', body: JSON.stringify(input) })
  }
  async revokeApiToken(id: string): Promise<unknown> {
    return this.request(`/api-tokens/${encodeURIComponent(id)}`, { method: 'DELETE' })
  }

  private async request(path: string, init: RequestInit = {}): Promise<unknown> {
    const headers = new Headers(init.headers)
    headers.set('Accept', 'application/json')
    if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
    if (this.options.token) headers.set('Authorization', `Bearer ${this.options.token}`)

    const base = this.options.baseUrl.replace(/\/$/, '')
    const response = await fetch(`${base}${path}`, { ...init, headers })
    const payload = await response.json().catch(() => null)
    if (!response.ok) {
      const message = payload && typeof payload === 'object' && 'error' in payload
        ? JSON.stringify((payload as { error: unknown }).error)
        : `CajeerEngine API error: ${response.status}`
      throw new Error(message)
    }
    return payload
  }
}
