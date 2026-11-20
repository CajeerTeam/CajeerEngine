const ADMIN_VERSION = '1.9.7'
let oidcEnabled = false
const API_BASE = '/api/v1'
const TOKEN_KEY = 'cajeerengine.admin.token'
localStorage.removeItem(TOKEN_KEY) // remove legacy bearer credentials
const app = document.getElementById('app')

const nav = [
  ['dashboard', '📊', 'Обзор', 'Состояние продукта'],
  ['content-types', '🧩', 'Типы контента', 'Field builder и schema'],
  ['content', '📝', 'Контент', 'Записи, публикации, ревизии'],
  ['media', '🖼️', 'Media', 'Загрузка и управление файлами'],
  ['cms', '🌐', 'CMS', 'Тема, меню, редиректы, preview'],
  ['users', '👥', 'Доступ', 'Пользователи, роли, tokens'],
  ['identity', '🔑', 'Identity Center', 'SkiF4er ID: привязки, пользователи и сессии'],
  ['ai', '◈', 'AI Assistant', 'ChatGPT через OpenAI API'],
  ['seo', '🔎', 'AI SEO Assistant', 'Аудит страниц и SEO-оптимизация'],
  ['translations', '🌐', 'AI Translation Studio', 'Переводы и локализации'],
  ['diagnostics', '⚙️', 'AI CMS Diagnostics', 'Проверка состояния CMS и объяснение проблем'],
  ['knowledge', '📚', 'AI Knowledge Base', 'Документы, категории, импорт, поиск'],
  ['knowledge-chat', '◈', 'AI Knowledge Chat / RAG', 'Ответы с проверенными источниками'],
  ['support', '✉', 'AI Support Center', 'Обращения и передача оператору'],
  ['tracking', '📈', 'Счётчики', 'Яндекс Метрика, GA4, GTM, Matomo и Plausible'],
  ['analytics-hub', '📊', 'Analytics Hub', 'Отчёты трафика и поисковой статистики'],
  ['analytics-insights', '📈', 'AI External Analytics', 'AI-анализ внешней статистики'],
  ['analytics-events', '📊', 'События', 'Правила, цели и диагностика аналитики'],
  ['privacy', '🔒', 'Приватность', 'Согласия, политика и категории'],
  ['integrations', '🔗', 'Интеграции', 'Провайдеры, подключения и секреты'],
  ['extensions', '🔌', 'Extensions', 'Runtime, lifecycle, events'],
  ['updates', '⬆️', 'Updates', 'Maintenance и rollback'],
  ['search', '🔎', 'Search', 'Поиск и reindex'],
  ['migration', '↔', 'Migration Center', 'DLE / WordPress Discovery'],
  ['import-export', '📦', 'Import/export', 'Пакеты, diff, run'],
  ['system', '⚙️', 'System', 'Диагностика API/runtime'],
]

const state = {
  route: location.hash.replace(/^#\/?/, '') || 'dashboard',
  token: '',
  csrf: '',
  identity: null,
  loading: false,
  notice: null,
  cache: {},
  forms: {
    contentType: defaultContentType(),
    migrationCopilot: {report:null,explanation:'',usage:null,busy:false,remediation:null,remediationOffset:0},
    entryType: 'pages',
    entryStatus: 'all',
    entrySearch: '',
    entryOffset: 0, parentSearch: '',
    entry: defaultEntry(),
    mediaTitle: '',
    mediaAlt: '',
    user: { name: '', email: '', password: '', roles: 'admin' },
    identitySearch: '', identityLinked: 'all', identityOffset: 0, identitySelected: '',
    seo: {type:'',entry:'',field:'seo_title',instructions:'',audit:null,batch:null,proposal:null,busy:false,insights:null,insightSource:'',insightFrom:'',insightTo:'',insightProposal:null,insightItem:null},
    diagnostics: {window:60,focus:'',explanation:null,usage:null,busy:false},
    knowledge: {selected:null, category:'', query:'', offset:0, search:'', visibility:'all', results:[], busy:false, kind:'', entry:''},
    knowledgeChat: {active:null,messages:[],busy:false,visibility:'all',locale:'',question:''},
    support: {selected:null,status:'',offset:0,busy:false,reply:'',draft:'',draftSources:[],assignee:'',priority:'normal',ticketStatus:'open'},
    translation: {type:'',entry:'',locale:'en',instructions:'',updateExisting:false,overview:null,glossary:[],proposal:null,busy:false,batch:null,batchSelection:[],batchUpdateExisting:false},
    aiAutomationSelected: null, aiAutomationEditing: false, aiAutomationRuns: [], aiSelected: null, aiMessages: [], aiBusy: false, aiToolsEnabled: false, aiActionsEnabled: false, aiActionsBusy: false, aiToolsRunning: [], aiEditor: {field:'title',mode:'generate',instructions:'',selection:'',busy:false,proposal:null},
    role: { handle: '', name: '', permissions: 'content:read,content:write' },
    token: { name: 'Admin token', scopes: 'content:read,content:write' },
    redirect: { from: '', to: '/', status: 301, enabled: true },
    navigationHandle: 'primary',
    navigationJson: JSON.stringify({ handle: 'primary', name: 'Основное меню', items: [{ label: 'Главная', url: '/' }] }, null, 2),
    themeJson: JSON.stringify({ active_theme: 'default', default_layout: 'app', cache_ttl: 300 }, null, 2),
    importPath: '',
    importMode: 'merge',
    updatePackage: '',
    rollbackBackup: '',
    searchQuery: '',
    extensionSlug: '',
    extensionEvent: 'content.saved',
    extensionPayload: '{"id":"demo"}',
  }
}

function defaultContentType() {
  return {handle:'',name:'',fields:'[]',blocks:'[]',localized:false,revisionable:true}
}

function defaultEntry() {
  return {
    id: '',
    title: '',
    slug: '',
    status: 'draft',
    locale: 'ru',
    path: '',
    parent: '',
    fields: JSON.stringify({ body: '<p>Текст страницы</p>' }, null, 2),
    seo: JSON.stringify({ title: '', description: '', canonical: '', noindex: false }, null, 2),
  }
}

function setNotice(type, text, details = null) {
  state.notice = { type, text, details }
  render()
}

function clearNotice() { state.notice = null }
const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ch]))
const fmt = (value) => typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value ?? '')
const asArray = (payload) => Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : []
const dataOf = (payload, fallback = null) => payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : (payload ?? fallback)
const idOf = (item) => item?.id || item?.handle || item?.slug || item?.name || ''

function jsonParse(text, fallback = {}) {
  try { return text.trim() ? JSON.parse(text) : fallback } catch (error) { throw new Error(`JSON: ${error.message}`) }
}

async function api(path, options = {}) {
  const headers = new Headers(options.headers || {})
  headers.set('Accept', 'application/json')
  const hasBody = options.body !== undefined && !(options.body instanceof FormData)
  if (hasBody && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
  if (state.csrf) headers.set('X-CSRF-Token', state.csrf)
  if (path === '/auth/login') headers.set('X-Cajeer-Browser', '1')
  const response = await fetch(`${API_BASE}${path}`, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
  const text = await response.text()
  let payload = null
  if (text) {
    try { payload = JSON.parse(text) } catch { payload = { raw: text } }
  }
  if (response.status === 401) {
    state.identity = null
    state.cache = {}; state.forms.hubPeriod = null; state.forms.webmasterResult = null
    localStorage.removeItem(TOKEN_KEY)
    state.token = ''
    state.csrf = ''
    if (state.route !== 'login') {
      state.route = 'login'
      location.hash = '#/login'
    }
  }
  if (response.status === 403 && payload?.error?.code === 'step_up_required' && !options.assuranceRetry) {
    // Safe flow: perform explicit reauthentication, then retry the same mutation once.
    const sessions = dataOf(await api('/auth/sessions'), [])
    const current = Array.isArray(sessions) ? sessions.find(s => s.current) : null
    if (current?.provider === 'skif4er') {
      if (confirm('Для критической операции требуется новый вход SkiF4er ID с MFA. Продолжить?')) {
        const result = dataOf(await api('/identity/step-up/start', {method:'POST',body:'{}'}))
        const url = new URL(result.authorization_url)
        if (url.origin !== 'https://id.skif4er.ru' || url.pathname !== '/realms/skif4er/protocol/openid-connect/auth' || url.searchParams.get('max_age') !== '0') throw new Error('Недопустимый адрес повторного входа')
        window.location.assign(url.toString())
      }
      throw new Error('Повторите операцию после прохождения SkiF4er ID MFA.')
    }
    const password = prompt('Повторно введите локальный пароль для критической операции')
    if (!password) throw new Error('Повторное подтверждение отменено.')
    const twoFactorCode = prompt('Код TOTP (оставьте пустым, если 2FA не включена)')
    if (twoFactorCode === null) throw new Error('Повторное подтверждение отменено.')
    await api('/auth/assurance/confirm', {method:'POST',body:JSON.stringify({password,two_factor_code:twoFactorCode}),assuranceRetry:true})
    return api(path, {...options,assuranceRetry:true})
  }
  if (!response.ok) {
    const message = payload?.error?.message || payload?.message || response.statusText || `HTTP ${response.status}`
    const err = new Error(message)
    err.payload = payload
    err.status = response.status
    throw err
  }
  return payload
}

async function load(name, path, options = {}) {
  state.loading = true
  render()
  try {
    const payload = await api(path, options)
    state.cache[name] = payload
    clearNotice()
    return payload
  } catch (error) {
    setNotice('error', error.message, error.payload)
    throw error
  } finally {
    state.loading = false
    render()
  }
}

async function bootstrap() {
  try { const result = await api('/auth/oidc/status'); oidcEnabled = result?.data?.enabled === true } catch { oidcEnabled = false }
  try {
    const payload = await api('/auth/me')
    state.identity = dataOf(payload)
    state.csrf = state.identity?.csrf_token || ''
    state.token = state.identity ? 'session' : ''
  } catch {}
  if (!state.identity && state.token) {
    try {
      const payload = await api('/admin/bootstrap')
      state.identity = dataOf(payload)?.identity || null
    } catch {}
  }
  await routeLoad(state.route)
  render()
}

async function routeLoad(route) {
  if (route === 'login') return
  if (!state.token && route !== 'dashboard') return
  try {
    if (route === 'dashboard') await Promise.allSettled([loadSilent('dashboard', '/admin/dashboard'), loadSilent('bootstrap', '/admin/bootstrap')])
    if (route === 'content-types') await loadSilent('contentTypes', '/content-types')
    if (route === 'content') await Promise.allSettled([loadSilent('contentTypes', '/content-types'), loadSilent('entries', `/content/${encodeURIComponent(state.forms.entryType)}?status=${encodeURIComponent(state.forms.entryStatus)}&q=${encodeURIComponent(state.forms.entrySearch)}&limit=50&offset=${state.forms.entryOffset}`), ...(state.forms.entryType === 'pages' ? [loadSilent('pageParents', `/content/pages?status=all&limit=50&q=${encodeURIComponent(state.forms.parentSearch)}`)] : [])])
    if (route === 'media') await loadSilent('media', mediaQuery())
    if (route === 'cms') await Promise.allSettled([loadSilent('cmsOverview', '/cms'), loadSilent('theme', '/cms/theme'), loadSilent('navigation', '/cms/navigation'), loadSilent('redirects', '/cms/redirects'), loadSilent('publicRoutes', `/cms/routes?limit=100&offset=${state.forms.routeOffset || 0}`)])
    if (route === 'users') await Promise.allSettled([loadSilent('users', '/users'), loadSilent('roles', '/roles'), loadSilent('tokens', '/api-tokens')])
    if (route === 'identity') await Promise.allSettled([loadSilent('identity', '/identity/account'), loadSilent('sessions', '/auth/sessions'), ...(integrationScope('users:read') ? [loadIdentityUsers()] : [])])
    if (route === 'ai') await Promise.allSettled([loadSilent('aiStatus','/ai/status'),loadSilent('aiConversations','/ai/conversations'),loadSilent('aiTools','/ai/tools'),loadSilent('aiActions','/ai/actions'),loadSilent('aiAutomations','/ai/automations'),...(integrationScope('system:read')?[loadSilent('aiCosts','/ai/costs?days=30')]:[])])
    if (route === 'diagnostics') await loadSilent('aiDiagnostics','/ai/diagnostics?window_minutes='+state.forms.diagnostics.window)
    if (route === 'knowledge') await Promise.allSettled([loadSilent('kbCategories','/knowledge/categories'),loadSilent('kbDocuments','/knowledge/documents?limit=30&offset='+state.forms.knowledge.offset),loadSilent('kbTypes','/content-types')])
    if (route === 'knowledge-chat') await Promise.allSettled([loadSilent('kbChatConversations','/knowledge/chat/conversations'),loadSilent('aiStatus','/ai/status')])
    if (route === 'support') await loadSilent('supportTickets','/support/tickets?'+new URLSearchParams({status:state.forms.support.status,offset:String(state.forms.support.offset),limit:'30'}))
    if (route === 'seo') {
      await loadSilent('seoTypes','/content-types')
      const available=asArray(state.cache.seoTypes)
      if(!available.some(t=>t.handle===state.forms.seo.type))state.forms.seo.type=available[0]?.handle||''
      if(state.forms.seo.type)await loadSilent('seoEntries','/content/'+encodeURIComponent(state.forms.seo.type)+'?status=all&limit=200&offset=0')
      if(integrationScope('analytics:read'))await loadSilent('analyticsHub','/analytics/reports')
    }
    if (route === 'translations') {
      await loadSilent('translationTypes','/content-types')
      const f=state.forms.translation,types=asArray(state.cache.translationTypes)
      if(!types.some(t=>t.handle===f.type))f.type=types[0]?.handle||''
      if(f.type){
        await loadSilent('translationEntries','/content/'+encodeURIComponent(f.type)+'?status=all&limit=200&offset=0')
        const entries=asArray(state.cache.translationEntries)
        if(!entries.some(e=>e.id===f.entry))f.entry=entries[0]?.id||''
        if(f.entry){
          await loadSilent('translationOverview','/ai/translations/overview?'+new URLSearchParams({type:f.type,entry_id:f.entry}))
          f.overview=dataOf(state.cache.translationOverview)
          if(f.overview)await loadSilent('translationGlossary','/ai/translations/glossary?'+new URLSearchParams({type:f.type,source_locale:f.overview.source.locale,target_locale:f.locale}))
          f.glossary=asArray(state.cache.translationGlossary)
        }
      }
    }
    if (route === 'tracking') await loadSilent('tracking', '/tracking/counters')
    if (route === 'analytics-insights') await loadSilent('analyticsHub','/analytics/reports')
    if (route === 'analytics-hub') {await loadSilent('analyticsHub','/analytics/reports');const previous=dataOf(state.cache.analyticsHubReport,{});if(previous.connection?.id){const r=state.forms.hubPeriod;await loadSilent('analyticsHubReport',`/analytics/reports/${encodeURIComponent(previous.connection.id)}/data${r?'?'+new URLSearchParams(r):''}`)}}
    if (route === 'analytics-events') await Promise.allSettled([loadSilent('analyticsEvents','/tracking/events'),loadSilent('analyticsJournal','/tracking/events/journal')])
    if (route === 'privacy') await loadSilent('privacy', '/privacy/consent')
    if (route === 'integrations') await loadSilent('integrations', '/integrations')
    if (route === 'extensions') await Promise.allSettled([loadSilent('extensions', '/extensions'), loadSilent('extensionsRuntime', '/extensions/runtime'), loadSilent('extensionsDiagnostics', '/extensions/diagnostics')])
    if (route === 'updates') await Promise.allSettled([loadSilent('updates', '/updates'), loadSilent('maintenance', '/updates/maintenance')])
    if (route === 'search') await loadSilent('searchDiagnostics', '/search/diagnostics')
    if (route === 'migration') { await loadSilent('migrationProjects','/migration/projects'); await loadSilent('migrationCatalog','/migration/catalog') }
    if (route === 'import-export') await Promise.allSettled([loadSilent('exports', '/import-export/exports'),loadSilent('importRequests','/import-export/requests')])
    if (route === 'system') await loadSilent('operations', '/system/operations')
  } catch {}
}

async function loadIdentityUsers() {
  const f = state.forms
  const path = '/identity/admin/users?' + new URLSearchParams({ q: f.identitySearch, linked: f.identityLinked, limit: '30', offset: String(f.identityOffset) })
  await loadSilent('identityAdmin', path)
  const items = dataOf(state.cache.identityAdmin, {}).items || []
  if (f.identitySelected && !items.some(x => x.id === f.identitySelected)) {
    f.identitySelected = ''; state.cache.identityAdminSessions = null
  }
}

async function loadSilent(name, path, options = {}) {
  try { state.cache[name] = await api(path, options) } catch (error) { state.cache[name] = { error: { message: error.message, payload: error.payload } } }
}

function go(route) {
  state.route = route
  location.hash = '#/' + route
  routeLoad(route).then(render)
}

window.addEventListener('hashchange', () => {
  state.route = location.hash.replace(/^#\/?/, '') || 'dashboard'
  routeLoad(state.route).then(render)
})

function shell(title, subtitle, body, toolbar = '') {
  return `<div class="shell"><aside class="sidebar"><div class="brand"><div class="brand-mark">CE</div><div><div class="brand-title">CajeerEngine</div><div class="brand-sub">Admin UI ${ADMIN_VERSION}</div></div></div><nav class="nav">${nav.filter(([id]) => (id !== 'migration' || integrationScope('migration:read')) && (id !== 'integrations' || integrationScope('integrations:read')) && (id !== 'privacy' || integrationScope('privacy:read')) && (!['analytics-hub','analytics-insights'].includes(id) || integrationScope('analytics:read')) && (id !== 'diagnostics' || integrationScope('system:read')) && (!['tracking','analytics-events'].includes(id) || integrationScope('tracking:read'))).map(([id, icon, label]) => `<button class="${state.route === id ? 'active' : ''}" data-route="${id}"><span class="emoji">${icon}</span><span>${label}</span></button>`).join('')}</nav><div class="footer-note">API: <span class="code-inline">${API_BASE}</span><br>${state.identity ? `User: ${escapeHtml(state.identity.email || state.identity.name || state.identity.user_id || 'auth')}` : 'Без активной сессии'}</div></aside><main class="main"><div class="topbar"><div><h1>${escapeHtml(title)}</h1><p>${escapeHtml(subtitle || '')}</p></div><div class="toolbar">${toolbar}<button class="btn secondary" data-action="refresh">Обновить</button>${state.token ? '<button class="btn ghost" data-action="logout">Выйти</button>' : '<button class="btn" data-route="login">Войти</button>'}</div></div>${noticeHtml()}${state.loading ? '<div class="notice">Загрузка...</div>' : ''}${body}</main></div>`
}

function noticeHtml() {
  if (!state.notice) return ''
  return `<div class="notice ${state.notice.type === 'error' ? 'error' : state.notice.type === 'ok' ? 'ok' : ''}"><strong>${escapeHtml(state.notice.text)}</strong>${state.notice.details ? `<pre class="json">${escapeHtml(JSON.stringify(state.notice.details, null, 2))}</pre>` : ''}</div>`
}

function render() {
  if (state.route === 'login' || (!state.token && location.hash.includes('login'))) return renderLogin()
  const item = nav.find(([id]) => id === state.route) || nav[0]
  const title = item[2]
  const subtitle = item[3]
  let body = ''
  if (state.route === 'dashboard') body = dashboardView()
  else if (state.route === 'content-types') body = contentTypesView()
  else if (state.route === 'content') body = contentView()
  else if (state.route === 'media') body = mediaView()
  else if (state.route === 'cms') body = cmsView()
  else if (state.route === 'users') body = usersView()
  else if (state.route === 'identity') body = identityView()
  else if (state.route === 'ai') body = aiView()
  else if (state.route === 'seo') body = seoView()
  else if (state.route === 'translations') body = translationsView()
  else if (state.route === 'diagnostics') body = diagnosticsView()
  else if (state.route === 'knowledge') body = knowledgeView()
  else if (state.route === 'knowledge-chat') body = knowledgeChatView()
  else if (state.route === 'support') body = supportView()
  else if (state.route === 'tracking') body = trackingView()
  else if (state.route === 'analytics-hub') body = analyticsHubView()
  else if (state.route === 'analytics-insights') body = analyticsInsightsView()
  else if (state.route === 'analytics-events') body = analyticsEventsView()
  else if (state.route === 'privacy') body = privacyView()
  else if (state.route === 'integrations') body = integrationsView()
  else if (state.route === 'extensions') body = extensionsView()
  else if (state.route === 'updates') body = updatesView()
  else if (state.route === 'search') body = searchView()
  else if (state.route === 'migration') body = migrationView()
  else if (state.route === 'import-export') body = importExportView()
  else if (state.route === 'system') body = systemView()
  else body = dashboardView()
  app.innerHTML = shell(title, subtitle, body)
  bindCommon()
  bindForms()
  bindAi()
  bindKnowledge()
  bindKnowledgeChat()
  bindSupport()
}

function renderLogin() {
  app.innerHTML = `<div class="login-shell"><form class="login-card" data-form="login"><div class="brand" style="color:var(--text)"><div class="brand-mark">CE</div><div><div class="brand-title">CajeerEngine Admin</div><div class="brand-sub" style="color:var(--muted)">Production static SPA ${ADMIN_VERSION}</div></div></div>${noticeHtml()}<div class="form"><div class="form-row"><label>Email</label><input class="input" name="email" type="email" autocomplete="username" required></div><div class="form-row"><label>Пароль</label><input class="input" name="password" type="password" autocomplete="current-password" required></div><div class="form-row"><label>2FA код, если включён</label><input class="input" name="two_factor_code" inputmode="numeric"></div><button class="btn" type="submit">Войти</button>${oidcEnabled ? '<a class="btn secondary" style="display:block;text-align:center" href="/auth/oidc/start">Войти через SkiF4er ID</a>' : ''}<p class="muted">Вход использует защищённую HttpOnly-сессию.</p></div></form></div>`
  document.querySelector('[data-form="login"]').addEventListener('submit', async (event) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    try {
      const payload = await api('/auth/login', { method: 'POST', body: JSON.stringify(Object.fromEntries(form.entries())) })
      const session = dataOf(payload, {})
      if (!session.csrf_token) throw new Error('API не вернул CSRF token.')
      state.token = 'session'
      state.csrf = session.csrf_token
      state.identity = session.user || null
      state.cache = {}; state.forms.hubPeriod = null; state.forms.webmasterResult = null
      state.route = 'dashboard'
      location.hash = '#/dashboard'
      await routeLoad('dashboard')
      setNotice('ok', 'Вход выполнен.')
    } catch (error) { setNotice('error', error.message, error.payload) }
  })
}

function bindCommon() {
  document.querySelectorAll('[data-route]').forEach((button) => button.addEventListener('click', () => go(button.dataset.route)))
  document.querySelectorAll('[data-action="refresh"]').forEach((button) => button.addEventListener('click', () => routeLoad(state.route).then(render)))
  document.querySelectorAll('[data-action="logout"]').forEach((button) => button.addEventListener('click', async () => { try { await api('/auth/logout', { method: 'POST', body: '{}' }) } catch (error) { setNotice('error', error.message); return } state.token = ''; state.csrf = ''; state.identity = null; state.cache = {}; state.forms.hubPeriod = null; state.forms.webmasterResult = null; state.forms.entry=defaultEntry();for(const key of Object.keys(sessionStorage))if(key.startsWith('ce-editor-draft:'))sessionStorage.removeItem(key);localStorage.removeItem(TOKEN_KEY); go('login') }))
  document.querySelectorAll('[data-json]').forEach((el) => el.addEventListener('click', () => navigator.clipboard?.writeText(el.dataset.json || '')))
}


function integrationScope(scope) {
  const scopes = state.identity?.scopes || []
  return scopes.includes('*') || scopes.includes(scope)
}

function trackingView() {
  if (!integrationScope('tracking:read')) return '<div class="notice error">Недостаточно прав.</div>'
  const cached=state.cache.tracking
  if(cached?.error)return `<div class="notice error">${escapeHtml(cached.error.message)}</div>`
  const c=dataOf(cached);if(!c)return '<div class="card">Загрузка счётчиков…</div>'
  const writable=integrationScope('tracking:write'),disabled=writable?'':'disabled'
  const input=(name,label,placeholder,value=c[name])=>`<div class="form-row"><label for="tracking-${name}">${label}</label><input class="input" id="tracking-${name}" name="${name}" value="${escapeHtml(value)}" placeholder="${placeholder}" ${disabled}></div>`
  return `<div class="card"><h2>Tracking & Counters · редакция ${escapeHtml(c.revision)}</h2><p class="muted">Счётчики загружаются только на публичных страницах разрешённых доменов после согласия. Изменение настроек требует повторного согласия. Для статического сайта выполните повторный экспорт.</p><form class="form" data-tracking-form><label><input type="checkbox" name="enabled" ${c.enabled?'checked':''} ${disabled}> Включить счётчики</label>${input('hosts','Разрешённые домены (через запятую)','example.com, www.example.com',c.hosts.join(', '))}${input('metrika_id','Яндекс Метрика — номер счётчика','12345678')}${input('ga4_id','GA4 — Measurement ID','G-XXXXXXXXXX')}${input('gtm_id','GTM — Container ID','GTM-XXXXXXX')}${input('matomo_id','Matomo — Site ID','1')}${input('matomo_url','Matomo — HTTPS base URL','https://analytics.example.com/matomo')}${input('plausible_id','Plausible — домен сайта','example.com')}${input('plausible_url','Plausible — HTTPS base URL','https://plausible.io')}${input('cloudflare_token','Cloudflare Web Analytics — публичный Site Token','32 hex characters')}<h3>Рекламные пиксели и конверсии</h3><p>Требуется согласие на аналитику и маркетинг. GTM несовместим с прямыми тегами. Включите только подтверждённые события, не клики по кнопкам.</p>${input('google_ads_id','Google Ads — Conversion ID','AW-123456789')}${input('meta_id','Meta Pixel — ID','123456789012345')}${input('tiktok_id','TikTok Pixel — ID','C1234567890123456789')}${input('vk_id','VK Ads / Top Mail.Ru — ID','12345678')}${['generate_lead','form_submit','sign_up'].map(n=>`<label><input type="checkbox" name="ads_events" value="${n}" ${(c.ads_events||[]).includes(n)?'checked':''} ${disabled}> ${n}</label>`).join('')}${input('ads_lead_label','Google Ads label — generate_lead','AbC-D_efG')}${input('ads_form_label','Google Ads label — form_submit','AbC-D_efG')}${input('ads_signup_label','Google Ads label — sign_up','AbC-D_efG')}${input('ads_yandex_lead_goal','Яндекс Директ / Метрика — цель generate_lead','ad_lead')}${input('ads_yandex_form_goal','Яндекс Директ / Метрика — цель form_submit','ad_form')}${input('ads_yandex_signup_goal','Яндекс Директ / Метрика — цель sign_up','ad_signup')}<label><input type="checkbox" name="ads_attribution" ${c.ads_attribution?'checked':''} ${disabled}> Сохранять UTM source / medium / campaign в пределах вкладки после согласия</label><p>Если цель Метрики совпадает с рекламной, она отправляется только с маркетинговым согласием. UTM доступны через CajeerAnalytics.getAttribution(), автоматически не передаются.</p><p>Meta / TikTok / VK: имена событий фиксированы, настройте соответствующие цели у провайдера. Google Ads: без label конверсия не отправляется. Покупки — только доверенный серверный контур. Диагностика: События → Журнал отправки.</p><p>Cloudflare: отключите автоматическую вставку beacon на стороне Cloudflare, используйте ручной режим.</p><label><input type="checkbox" name="ga4_debug" ${c.ga4_debug?'checked':''} ${disabled}> GA4 DebugView</label><p>GTM используется отдельно от прямых счётчиков и требует согласия на все необязательные категории. Удалите дублирующие теги из темы. Вебвизор отключён. Рекламные пиксели управляются отдельными настройками выше.</p>${writable?'<button type="submit" class="btn">Опубликовать</button>':'<p>Только чтение</p>'}</form></div><div class="card"><h2>Проверка подключения</h2><p>Откройте публичный сайт на разрешённом домене, примите согласие и проверьте Network / DebugView. В консоли <code>CajeerTracking.getStatus()</code> показывает состояние загрузки библиотек, не подтверждение приёма события сервисом.</p><a href="/" target="_blank" rel="noopener noreferrer">Открыть публичный сайт</a></div>`
}
function bindTracking(){
  const form=document.querySelector('[data-tracking-form]');if(!form||!integrationScope('tracking:write'))return
  form.addEventListener('submit',async e=>{e.preventDefault();const c=dataOf(state.cache.tracking),f=new FormData(form),button=form.querySelector('button[type=submit]');button.disabled=true
    const body={revision:c.revision,enabled:f.has('enabled'),hosts:[...new Set(String(f.get('hosts')).split(',').map(v=>v.trim().toLowerCase()).filter(Boolean))],metrika_id:String(f.get('metrika_id')).trim(),ga4_id:String(f.get('ga4_id')).trim().toUpperCase(),gtm_id:String(f.get('gtm_id')).trim().toUpperCase(),ga4_debug:f.has('ga4_debug'),matomo_id:String(f.get('matomo_id')).trim(),matomo_url:String(f.get('matomo_url')).trim(),plausible_id:String(f.get('plausible_id')).trim().toLowerCase(),plausible_url:String(f.get('plausible_url')).trim(),cloudflare_token:String(f.get('cloudflare_token')).trim().toLowerCase(),google_ads_id:String(f.get('google_ads_id')).trim().toUpperCase(),meta_id:String(f.get('meta_id')).trim(),tiktok_id:String(f.get('tiktok_id')).trim().toUpperCase(),vk_id:String(f.get('vk_id')).trim(),ads_lead_label:String(f.get('ads_lead_label')).trim(),ads_form_label:String(f.get('ads_form_label')).trim(),ads_signup_label:String(f.get('ads_signup_label')).trim(),ads_yandex_lead_goal:String(f.get('ads_yandex_lead_goal')).trim(),ads_yandex_form_goal:String(f.get('ads_yandex_form_goal')).trim(),ads_yandex_signup_goal:String(f.get('ads_yandex_signup_goal')).trim(),ads_attribution:f.has('ads_attribution'),ads_events:f.getAll('ads_events')}
    try{state.cache.tracking=await api('/tracking/counters',{method:'PUT',body:JSON.stringify(body)});setNotice('ok','Настройки счётчиков опубликованы.');render()}catch(err){setNotice('error',err.message,err.payload);button.disabled=false}
  })
}

function eventRuleRow(r,disabled='') {
  const input=k=>`<input class="input" name="${k}" aria-label="${k}" value="${escapeHtml(r[k])}" maxlength="40" ${disabled}>`
  return `<tr data-event-rule><td>${input('name')}</td><td><input type="checkbox" name="enabled" aria-label="Включено" ${r.enabled?'checked':''} ${disabled}></td><td><input type="checkbox" name="automatic" aria-label="Автоматически" ${r.automatic?'checked':''} ${disabled}></td><td>${input('ga4')}</td><td>${input('metrika')}</td><td>${input('gtm')}</td><td>${input('matomo')}</td><td>${input('plausible')}</td><td>${disabled?'':`<button type="button" class="btn ghost" data-rule-remove>Удалить</button>`}</td></tr>`
}
function analyticsEventsView(){
  if(!integrationScope('tracking:read'))return '<div class="notice error">Недостаточно прав.</div>'
  const cached=state.cache.analyticsEvents;if(cached?.error)return `<div class="notice error">${escapeHtml(cached.error.message)}</div>`
  const c=dataOf(cached);if(!c)return '<div class="card">Загрузка событий…</div>'
  const writable=integrationScope('tracking:write'),disabled=writable?'':'disabled',journal=dataOf(state.cache.analyticsJournal,{items:[]})
  return `<div class="card"><h2>Правила событий · редакция ${escapeHtml(c.revision)}</h2><p>Пустое имя провайдера отключает маршрут. Автоматические события: search, file_download, outbound_click, scroll. page_view имеет фиксированный маппинг. Для отправки необходимо согласие и активный счётчик. Рекламные конверсии настраиваются в разделе «Счётчики»; правила событий должны быть включены.</p><form class="form" data-event-form><label><input type="checkbox" name="eventsEnabled" ${c.enabled?'checked':''} ${disabled}> Включить события</label><label><input type="checkbox" name="allowCustom" ${c.allow_custom?'checked':''} ${disabled}> Разрешить произвольные имена ручных событий</label><label><input type="checkbox" name="collectDiagnostics" ${c.collect_diagnostics?'checked':''} ${disabled}> Сохранять обезличенную диагностику на сервере (до 2000 записей, 7 дней)</label><div style="overflow-x:auto"><table class="table"><thead><tr><th>Событие</th><th>Вкл.</th><th>Авто</th><th>GA4</th><th>Метрика</th><th>GTM</th><th>Matomo</th><th>Plausible</th><th></th></tr></thead><tbody data-event-rows>${c.rules.map(r=>eventRuleRow(r,disabled)).join('')}</tbody></table></div>${writable?'<div><button type="button" class="btn secondary" data-rule-add>Добавить правило</button> <button type="submit" class="btn">Опубликовать</button></div>':'<p>Только чтение</p>'}</form></div><div class="card"><h2>Проверка маршрутизации</h2><p>Предпросмотр сохранённых правил не отправляет событие. Для реальной проверки откройте публичную страницу, примите согласие и используйте <code>CajeerAnalytics.emit('generate_lead')</code> / <code>CajeerAnalytics.getJournal()</code>.</p><form data-event-preview class="form"><label>Имя события <input class="input" name="name" required value="generate_lead" maxlength="40"></label><button class="btn secondary" type="submit">Проверить правило</button></form><pre class="json" data-event-preview-result></pre><a href="/" target="_blank" rel="noopener noreferrer">Открыть сайт</a></div><div class="card"><h2>Журнал отправки</h2><p>queued — команда в очереди SDK; submitted — передана загруженной библиотеке; failed — ошибка. Это не подтверждение приёма события внешним сервисом. Диагностика не содержит параметров и URL.</p>${state.cache.analyticsJournal?.error?`<div class="notice error">${escapeHtml(state.cache.analyticsJournal.error.message)}</div>`:''}<div style="overflow-x:auto"><table class="table"><thead><tr><th>Время</th><th>Событие</th><th>Провайдеры</th><th>ID</th></tr></thead><tbody>${(journal?.items||[]).map(r=>`<tr><td>${escapeHtml(new Date(r.at*1000).toLocaleString())}</td><td>${escapeHtml(r.name)}</td><td>${escapeHtml(Object.entries(r.outcomes).map(([k,v])=>k+': '+v).join(', '))}</td><td><code>${escapeHtml(r.id)}</code></td></tr>`).join('')||'<tr><td colspan="4">Нет записей</td></tr>'}</tbody></table></div>${writable?'<button class="btn secondary" data-event-clear>Очистить журнал</button>':''}</div>`
}
function bindAnalyticsEvents(){
  const form=document.querySelector('[data-event-form]');if(!form)return
  const config=dataOf(state.cache.analyticsEvents),rows=form.querySelector('[data-event-rows]')
  form.querySelector('[data-rule-add]')?.addEventListener('click',()=>{if(rows.children.length<100)rows.insertAdjacentHTML('beforeend',eventRuleRow({name:'',enabled:true,automatic:false,ga4:'',metrika:'',gtm:'',matomo:'',plausible:''}))})
  rows.addEventListener('click',e=>{const b=e.target.closest('[data-rule-remove]');if(b)b.closest('tr').remove()})
  form.addEventListener('submit',async e=>{e.preventDefault();if(!integrationScope('tracking:write'))return
    const field=(row,k)=>row.querySelector(`[name="${k}"]`),btn=form.querySelector('[type=submit]');btn.disabled=true
    const rules=[...rows.querySelectorAll('[data-event-rule]')].map(r=>({name:field(r,'name').value.trim(),enabled:field(r,'enabled').checked,automatic:field(r,'automatic').checked,ga4:field(r,'ga4').value.trim(),metrika:field(r,'metrika').value.trim(),gtm:field(r,'gtm').value.trim(),matomo:field(r,'matomo').value.trim(),plausible:field(r,'plausible').value.trim()}))
    try{state.cache.analyticsEvents=await api('/tracking/events',{method:'PUT',body:JSON.stringify({revision:config.revision,enabled:field(form,'eventsEnabled').checked,allow_custom:field(form,'allowCustom').checked,collect_diagnostics:field(form,'collectDiagnostics').checked,rules})});setNotice('ok','Правила опубликованы. Статический сайт необходимо экспортировать повторно.');render()}catch(err){setNotice('error',err.message,err.payload)}finally{btn.disabled=false}
  })
  document.querySelector('[data-event-preview]')?.addEventListener('submit',e=>{e.preventDefault();const name=new FormData(e.target).get('name'),r=config.rules.find(r=>r.name===name);const valid=/^[a-z][a-z0-9_]{0,39}$/.test(name)&&! /^(ce_|google_|ga_|firebase_|gtm_|session_|first_)/.test(name)&&!['purchase','refund','add_to_cart','begin_checkout','view_item'].includes(name);document.querySelector('[data-event-preview-result]').textContent=JSON.stringify(config.enabled&&valid?(r?(r.enabled?r:null):config.allow_custom?{ga4:name,metrika:name,gtm:name,matomo:name,plausible:name==='pageview'?'':name}:null):null,null,2)})
  document.querySelector('[data-event-clear]')?.addEventListener('click',async()=>{if(!confirm('Очистить журнал событий?'))return;try{state.cache.analyticsJournal=await api('/tracking/events/journal',{method:'DELETE'});setNotice('ok','Журнал очищен.')}catch(err){setNotice('error',err.message,err.payload)}})
}

function privacyView() {
  if (!integrationScope('privacy:read')) return '<div class="notice error">Недостаточно прав.</div>'
  const cached=state.cache.privacy
  if (cached?.error) return `<div class="notice error">${escapeHtml(cached.error.message)}</div>`
  const policy=dataOf(cached)
  if (!policy) return '<div class="card">Загрузка политики…</div>'
  const writable=integrationScope('privacy:write')
  const disabled=writable?'':'disabled'
  const field=(name,label,area=false)=>`<div class="form-row"><label for="privacy-${name}">${escapeHtml(label)}</label>${area?`<textarea class="input" id="privacy-${name}" name="${name}" rows="4" required ${disabled}>${escapeHtml(policy[name])}</textarea>`:`<input class="input" id="privacy-${name}" name="${name}" value="${escapeHtml(policy[name])}" required ${disabled}>`}</div>`
  return `<div class="card"><h2>Политика согласий · редакция ${escapeHtml(policy.revision)}</h2><p class="muted">Необязательные технологии отключены по умолчанию. Сохранение новой редакции запрашивает согласие повторно. Необходимые технологии нельзя отключить.</p><form class="form" data-privacy-form>${field('title_ru','Заголовок (RU)')}${field('message_ru','Текст (RU)',true)}${field('title_en','Заголовок (EN)')}${field('message_en','Текст (EN)',true)}<div class="form-row"><label for="privacy-url">Страница политики (локальный путь)</label><input class="input" id="privacy-url" name="policy_url" value="${escapeHtml(policy.policy_url)}" placeholder="/privacy-policy" ${disabled}></div><div class="form-row"><label for="privacy-ttl">Срок согласия (дней)</label><input class="input" id="privacy-ttl" name="ttl_days" type="number" min="1" max="365" value="${escapeHtml(policy.ttl_days)}" required ${disabled}></div>${writable?'<button class="btn" type="submit">Опубликовать новую редакцию</button>':'<p>Только чтение</p>'}</form></div><div class="card"><h2>Категории</h2><p>Необходимые — всегда разрешены. Аналитика, маркетинг и функциональные — только после выбора посетителя.</p><p>Согласия хранятся в браузере, без передачи идентификаторов посетителя на сервер. Для статического сайта после изменения политики выполните повторный экспорт.</p><a href="/" target="_blank" rel="noopener noreferrer">Открыть публичный сайт</a></div>`
}
function bindPrivacy() {
  const form=document.querySelector('[data-privacy-form]')
  if(!form||!integrationScope('privacy:write')) return
  form.addEventListener('submit',async event=>{
    event.preventDefault();const policy=dataOf(state.cache.privacy);const fields=Object.fromEntries(new FormData(form).entries());const submit=form.querySelector('button[type=submit]');submit.disabled=true
    try {state.cache.privacy=await api('/privacy/consent',{method:'PUT',body:JSON.stringify({...fields,revision:policy.revision,ttl_days:Number(fields.ttl_days)})});setNotice('ok','Политика опубликована. Посетители должны подтвердить новый выбор.');render()}
    catch(e){setNotice('error',e.message,e.payload);submit.disabled=false}
  })
}

function integrationsView() {
  const cached = state.cache.integrations
  if (cached?.error) return `<div class="notice error">${escapeHtml(cached.error.message)}</div>`
  if (!integrationScope('integrations:read')) return '<div class="notice error">Недостаточно прав.</div>'
  const center = dataOf(cached, {}) || {}
  const providers = center.providers || []
  const items = center.items || []
  const write = integrationScope('integrations:write')
  const secrets = write && integrationScope('integrations:secrets')
  const run = integrationScope('integrations:run') && center.enabled
  const create = secrets ? `<div class="card"><h2>Новое подключение</h2><form class="form" data-integration-create><label>Провайдер</label><select class="input" name="provider">${providers.map(p=>`<option value="${escapeHtml(p.id)}">${escapeHtml(p.name)}</option>`).join('')}</select><label>Название</label><input class="input" name="name" maxlength="200" required><label>HTTPS URL (без query и credentials)</label><input class="input" name="url" type="url" required><label>TikTok Events API — Pixel ID (только для TikTok)</label><input class="input" name="pixel_id" placeholder="C1234567890123456789"><p>Meta CAPI URL: https://graph.facebook.com/v25.0/PIXEL_ID/events; TikTok: https://business-api.tiktok.com/open_api/v1.3/event/track/. Для рекламных API используется только доверенная очередь с маркетинговым согласием.</p><label>Bearer / рекламный API token или HMAC secret</label><input class="input" name="secret" type="password" autocomplete="new-password"><label><input type="checkbox" name="enabled"> Включить отправку</label><button class="btn" type="submit">Создать</button></form></div>` : ''
  return `<div class="card"><h2>Центр интеграций</h2><p>${center.enabled ? 'Отправка разрешена' : 'Отправка отключена серверной конфигурацией'}. Тест отправляет реальный POST. Секреты не возвращаются API.</p><p>Провайдеры: ${providers.map(p=>escapeHtml(p.name)).join(', ')}</p>${integrationScope('tracking:read')?'<button class="btn secondary" data-route="tracking">Настроить Метрику, GA4 и GTM</button>':''}</div>${create}<div class="grid two">${items.map(item=>`<div class="card"><h2>${escapeHtml(item.name)}</h2><p>${escapeHtml(item.provider)} · ${item.enabled?'Включено':'Выключено'} · ${item.secret_configured?'Секрет сохранён':'Без секрета'}</p><p class="code-inline">${escapeHtml(item.config.url)}${item.config.pixel_id?' · Pixel '+escapeHtml(item.config.pixel_id):''}</p>${item.last_run ? `<p>Последний запуск: ${escapeHtml(item.last_run.created_at)} · ${item.last_run.ok?'Успешно':'Ошибка'} · HTTP ${escapeHtml(item.last_run.status_code)}</p>` : '<p>Запусков пока нет.</p>'}${write?`<form class="form" data-integration-edit="${escapeHtml(item.id)}"><label>Название</label><input class="input" name="name" value="${escapeHtml(item.name)}" required maxlength="200"><label>HTTPS URL</label><input class="input" name="url" type="url" value="${escapeHtml(item.config.url)}" ${item.secret_configured?'readonly':''} required><label><input type="checkbox" name="enabled" ${item.enabled?'checked':''}> Включено</label><button class="btn small" type="submit">Сохранить</button><button class="btn small danger" type="button" data-integration-delete="${escapeHtml(item.id)}">Удалить</button></form>`:''}${secrets?`<form class="form" data-integration-secret="${escapeHtml(item.id)}"><label>Новый секрет (пустой — удалить токен HTTP JSON)</label><input class="input" name="secret" type="password" autocomplete="new-password"><button class="btn small" type="submit">Обновить секрет</button></form>`:''}${run&&!['meta-capi','tiktok-events'].includes(item.provider)?`<button class="btn small secondary" data-integration-test="${escapeHtml(item.id)}">Тест подключения</button>${item.enabled?`<form class="form" data-integration-send="${escapeHtml(item.id)}"><label>Событие</label><input class="input" name="event" value="manual.dispatch" required><label>JSON payload</label><textarea class="input" name="payload" required>{}</textarea><button class="btn small" type="submit">Отправить</button></form>`:''}`:''}</div>`).join('') || '<div class="card">Подключений пока нет.</div>'}</div>`
}

function bindIntegrations() {
  if (state.route !== 'integrations') return
  const items = dataOf(state.cache.integrations, {})?.items || []
  const find = id => items.find(x => x.id === id)
  const request = async (el, path, method, body) => {
    if (el.dataset.busy) return
    el.dataset.busy = '1'
    const controls = [...el.querySelectorAll('button,input,textarea,select')]
    if (el.tagName === 'BUTTON') controls.push(el)
    controls.forEach(c=>c.disabled=true)
    try {
      const result = await api(path,{method,body:JSON.stringify(body)})
      const data = dataOf(result)
      await routeLoad('integrations')
      if (state.route === 'integrations') setNotice(data?.ok===false?'error':'ok',data?.ok===false?'Отправка не удалась':'Операция выполнена',data?.event_id?data:null)
    } catch (e) { if(state.route==='integrations') setNotice('error',e.message,e.payload) }
    finally { delete el.dataset.busy; controls.forEach(c=>c.disabled=false) }
  }
  const bind = (selector,handler) => document.querySelectorAll(selector).forEach(form=>form.addEventListener('submit', e=>{e.preventDefault(); const fd=new FormData(form); try{handler(form,fd)}catch(err){setNotice('error',err.message)}}))
  bind('[data-integration-create]',(f,fd)=>request(f,'/integrations','POST',{provider:fd.get('provider'),name:fd.get('name'),config:{url:fd.get('url'),...(fd.get('provider')==='tiktok-events'?{pixel_id:String(fd.get('pixel_id')).trim().toUpperCase()}: {})},enabled:fd.has('enabled'),secret:fd.get('secret')}))
  bind('[data-integration-edit]',(f,fd)=>{const i=find(f.dataset.integrationEdit);request(f,`/integrations/${encodeURIComponent(i.id)}`,'PATCH',{revision:i.revision,name:fd.get('name'),config:{url:fd.get('url'),...(i.provider==='tiktok-events'?{pixel_id:i.config.pixel_id}: {})},enabled:fd.has('enabled')})})
  bind('[data-integration-secret]',(f,fd)=>{const i=find(f.dataset.integrationSecret);request(f,`/integrations/${encodeURIComponent(i.id)}/secret`,'PUT',{revision:i.revision,secret:fd.get('secret')})})
  bind('[data-integration-send]',(f,fd)=>{const i=find(f.dataset.integrationSend);const payload=JSON.parse(fd.get('payload'));if(!payload||typeof payload!=='object'||Array.isArray(payload))throw new Error('Payload должен быть JSON-объектом');request(f,`/integrations/${encodeURIComponent(i.id)}/send`,'POST',{revision:i.revision,event:fd.get('event'),payload})})
  document.querySelectorAll('[data-integration-delete]').forEach(b=>b.addEventListener('click',()=>{const i=find(b.dataset.integrationDelete);if(confirm('Удалить подключение?'))request(b,`/integrations/${encodeURIComponent(i.id)}`,'DELETE',{revision:i.revision})}))
  document.querySelectorAll('[data-integration-test]').forEach(b=>b.addEventListener('click',()=>{const i=find(b.dataset.integrationTest);request(b,`/integrations/${encodeURIComponent(i.id)}/test`,'POST',{revision:i.revision})}))
}

function analyticsInsightsView(){
  if(!integrationScope('analytics:read'))return '<div class="notice error">Недостаточно прав.</div>'
  const sources=dataOf(state.cache.analyticsHub,{items:[]}).items||[], selected=state.forms.analyticsInsightsSource||sources[0]?.id||''
  const item=state.forms.analyticsInsightsData, result=item?.available?item:null
  const f=state.forms.analyticsInsightsPeriod||{}, fmt=v=>v===null||v===undefined?'—':Number(v).toLocaleString('ru-RU',{maximumFractionDigits:2})
  const quality=q=>q&&!q.complete?' <span class="notice">Детализация может быть неполной</span>':''
  const changes=result?.changes?.length?table(['Метрика','Текущий период','Предыдущий период','Δ (%)'],result.changes.map(c=>`<tr><td>${escapeHtml(c.label)}</td><td>${escapeHtml(fmt(c.current))}</td><td>${escapeHtml(fmt(c.previous))}</td><td>${escapeHtml(fmt(c.percent))}%</td></tr>`)):'<p>Нет сопоставимых метрик. Синхронизируйте предыдущий равный период в Analytics Hub.</p>'
  const segments=result?.segments?Object.entries(result.segments).map(([section,group])=>`<h3>${escapeHtml(section)}${quality(group.quality)}</h3>${group.items?.length?table(['Измерение','Показатели'],group.items.map(x=>`<tr><td>${escapeHtml(x.label)}</td><td>${escapeHtml(Object.entries(x.values).map(([k,v])=>k+': '+fmt(v)).join('; '))}</td></tr>`)):'<p>Нет измерений.</p>'}`).join(''):''
  return `<div class="card"><h2>AI External Analytics</h2><p>Анализ только ранее синхронизированных отчётов: отдельные ресурсы и метрики не суммируются. AI получает только ограниченные числовые сведения.</p><form class="form" data-insights-query><label>Источник</label><select class="input" name="connection_id">${sources.map(x=>`<option value="${escapeHtml(x.id)}" ${x.id===selected?'selected':''}>${escapeHtml(x.name)} · ${escapeHtml(x.provider)}</option>`).join('')}</select><label>Начало (пусто — последний снимок)</label><input class="input" type="date" name="from" value="${escapeHtml(f.from||'')}"><label>Конец</label><input class="input" type="date" name="to" value="${escapeHtml(f.to||'')}"><button class="btn" ${selected?'':'disabled'}>Проанализировать</button></form></div>
  ${item?`<div class="card"><h2>${item.available?'Аналитические выводы':'Отчёт недоступен'}</h2>${result?`<p>${escapeHtml(result.provider)} · ${escapeHtml(result.from)} — ${escapeHtml(result.to)} · Получено ${escapeHtml(result.fetched_at)}</p><p>Сравнение: ${escapeHtml(result.comparison_status)}</p>${result.warnings?.map(w=>`<p class="notice">${escapeHtml(w)}</p>`).join('')||''}${changes}<h3>Изменения</h3>${result.observations?.map(o=>`<p>${escapeHtml(o.message)} ${escapeHtml(fmt(o.percent))}%</p>`).join('')||''}<h3>Сигналы по дням</h3>${result.daily_signals?.map(x=>`<p>${escapeHtml(x.metric)}: ${escapeHtml(fmt(x.previous_average))} → ${escapeHtml(fmt(x.recent_average))}, ${escapeHtml(fmt(x.percent))}% за равные 7-дневные окна</p>`).join('')||''}${segments}<button class="btn secondary" data-insights-explain>AI-объяснение через OpenAI</button>`:`<p>${escapeHtml(item.message)}</p>`}</div>`:''}
  ${state.forms.analyticsInsightsAnswer?`<div class="card"><h2>Объяснение модели (не подтверждает причинность)</h2><pre style="white-space:pre-wrap">${escapeHtml(state.forms.analyticsInsightsAnswer)}</pre></div>`:''}`
}
function bindAnalyticsInsights(){
  const f=document.querySelector('[data-insights-query]')
  f?.addEventListener('submit',async e=>{
    e.preventDefault();const button=f.querySelector('button');if(button.disabled)return;button.disabled=true
    try{
      const fd=new FormData(f), connection_id=fd.get('connection_id'),from=fd.get('from'),to=fd.get('to')
      if(Boolean(from)!==Boolean(to))throw new Error('Выберите обе даты или оставьте пустыми.')
      state.forms.analyticsInsightsSource=connection_id;state.forms.analyticsInsightsPeriod=from&&to?{from,to}:{}
      const query=new URLSearchParams({connection_id,...state.forms.analyticsInsightsPeriod})
      state.forms.analyticsInsightsData=dataOf(await api('/ai/analytics/insights?'+query))
      state.forms.analyticsInsightsAnswer='';render()
    }catch(e){setNotice('error',e.message)}finally{button.disabled=false}
  })
  document.querySelector('[data-insights-explain]')?.addEventListener('click',async e=>{
    const button=e.currentTarget;if(button.disabled)return;button.disabled=true
    try{
      const payload={connection_id:state.forms.analyticsInsightsSource,...state.forms.analyticsInsightsPeriod}
      const data=dataOf(await api('/ai/analytics/explain',{method:'POST',body:JSON.stringify(payload)}))
      state.forms.analyticsInsightsData=data.insights;state.forms.analyticsInsightsAnswer=data.explanation;render()
    }catch(e){setNotice('error',e.message)}finally{button.disabled=false}
  })
}

function analyticsHubView(){
  if(!integrationScope('analytics:read'))return '<div class="notice error">Недостаточно прав.</div>'
  const payload=state.cache.analyticsHub, hub=dataOf(payload,{items:[]}), items=hub.items||[], writable=integrationScope('analytics:write')&&integrationScope('integrations:secrets'), runnable=integrationScope('analytics:run')
  if(payload?.error)return `<div class="notice error">${escapeHtml(payload.error.message)}</div>`
  const fields=i=>`<label>Название</label><input class="input" name="name" required maxlength="200" value="${escapeHtml(i?.name||'')}"><label>Провайдер</label><select class="input" name="provider"><option value="metrika" ${i?.provider==='metrika'?'selected':''}>Яндекс Метрика</option><option value="ga4" ${i?.provider==='ga4'?'selected':''}>GA4</option><option value="webmaster" ${i?.provider==='webmaster'?'selected':''}>Яндекс Вебмастер</option><option value="search_console" ${i?.provider==='search_console'?'selected':''}>Google Search Console</option><option value="matomo" ${i?.provider==='matomo'?'selected':''}>Matomo</option><option value="plausible" ${i?.provider==='plausible'?'selected':''}>Plausible</option><option value="cf_zone" ${i?.provider==='cf_zone'?'selected':''}>Cloudflare Edge Analytics</option><option value="cf_web" ${i?.provider==='cf_web'?'selected':''}>Cloudflare Web Analytics / RUM</option></select><label>Ресурс: ID счётчика, https:example.com:443 (Вебмастер), https://example.com/ или sc-domain:example.com (Search Console), Matomo Site ID, Plausible domain (example.com), Cloudflare Zone ID / Web Analytics Site Tag</label><input class="input" name="resource" required maxlength="512" value="${escapeHtml(i?.resource||'')}"><label>Goal ID (только Метрика, необязательно)</label><input class="input" name="goal" pattern="[1-9][0-9]{0,19}" value="${escapeHtml(i?.goal||'')}"><label>Credential JSON ${i?'(пусто — сохранить существующий)':''}</label><textarea class="input" name="credential" autocomplete="off" ${i?'':'required'} placeholder='{"type":"oauth_token","token":"…"}'></textarea><label><input type="checkbox" name="enabled" ${!i||i.enabled?'checked':''}> Активно</label>`
  const report=dataOf(state.cache.analyticsHubReport,{}), r=report.report, selected=report.connection?.id
  const dateAt=n=>new Date(Date.now()-n*86400000).toISOString().slice(0,10), period=state.forms.hubPeriod||r||{from:dateAt(30),to:dateAt(1)}
  const fmt=v=>v===null||v===undefined?'—':Number(v).toLocaleString('ru-RU',{maximumFractionDigits:3})
  const comparison=()=>{const c=report.comparison;if(!c)return '';const status={missing:'Предыдущий период ещё не синхронизирован.',incomplete:'Периоды неполные или данные ограничены провайдером.',incompatible:'Метрики, ресурс или часовой пояс различаются.'};return `<h3>Сравнение с предыдущим периодом</h3><p>${escapeHtml(c.from)} — ${escapeHtml(c.to)}${c.fetched_at?' · Получено '+escapeHtml(c.fetched_at):''}</p>${c.status==='ready'?`${c.sampled?'<p class="notice">Сравнение использует семплированные данные.</p>':''}${table(['Метрика','Текущий','Предыдущий','Изменение','Изменение (%)'],c.metrics.map(m=>{const ratio=m.metric==='ctr',scale=ratio?100:1;return `<tr><td>${escapeHtml(m.label)}</td><td>${escapeHtml(m.current===null?'—':fmt(m.current*scale)+(ratio?'%':''))}</td><td>${escapeHtml(m.previous===null?'—':fmt(m.previous*scale)+(ratio?'%':''))}</td><td>${escapeHtml(m.delta===null?'—':fmt(m.delta*scale)+(ratio?' п.п.':''))}</td><td>${escapeHtml(m.percent===null?'—':fmt(m.percent)+'%')}</td></tr>`}))}`:`<p>${escapeHtml(status[c.status]||'Нет сопоставимых данных.')}</p>`}${runnable&&hub.enabled&&report.connection.enabled?`<button class="btn secondary" data-hub-compare-sync data-from="${escapeHtml(c.from)}" data-to="${escapeHtml(c.to)}">Обновить предыдущий период через API</button>`:''}`}
  const syncHistory=()=>`<h3>История синхронизации</h3>${report.sync_history?.length?table(['Период','Статус','Попытки','Начало','Завершение','Ошибка'],report.sync_history.map(h=>`<tr><td>${escapeHtml(h.date_from)} — ${escapeHtml(h.date_to)}</td><td>${escapeHtml(({running:'Выполняется',success:'Успешно',failed:'Ошибка',interrupted:'Прервана'})[h.status]||h.status)}</td><td>${escapeHtml(h.attempts)}</td><td>${escapeHtml(h.started_at)}</td><td>${escapeHtml(h.finished_at||'—')}</td><td>${escapeHtml(h.error||'—')}</td></tr>`)):'<p>Новых попыток синхронизации пока нет.</p>'}`
  const fresh=()=>{if(!r)return '';const age=Date.now()-Date.parse(r.fetched_at);return age>2*3600000?'<p class="notice">Снимок получен более двух часов назад. Это время загрузки, не гарантия полноты данных провайдера.</p>':''}
  const graph=()=>{const daily=r?.sections?.daily?.rows||[],idx=r?.provider==='cf_zone'?0:1;const points=daily.filter(x=>Number.isFinite(x.values[idx]));if(!points.length)return '';const max=Math.max(1,...points.map(x=>x.values[idx])),first=Date.parse(r.from),span=Math.max(86400000,Date.parse(r.to)-first);const dots=points.map(x=>({x:20+600*(Date.parse(x.label)-first)/span,y:130-110*x.values[idx]/max})).filter(p=>Number.isFinite(p.x));return `<div class="card"><h3>${escapeHtml(r.labels[idx])} по дням</h3><svg viewBox="0 0 640 150" width="100%" role="img" aria-label="${escapeHtml(r.labels[idx])}"><path d="M20 20 V130 H620" stroke="currentColor" fill="none" opacity=".4"/>${dots.map(p=>`<circle cx="${p.x}" cy="${p.y}" r="3" fill="#2563eb"/>`).join('')}</svg><p>${escapeHtml(r.from)} — ${escapeHtml(r.to)} · max ${escapeHtml(max.toLocaleString('ru-RU'))}. Дни без данных не отображаются.</p></div>`}
  const rows=(section)=>{const s=r.sections[section];if(!s)return '';return `<h3>${({overview:'Итоги',daily:'Динамика по дням',channels:'Каналы',pages:'Страницы',queries:'Поисковые запросы',devices:'Устройства',countries:'Страны',events:'События и цели',cache:'Статусы кеша',status:'HTTP-статусы',summary:'Кеш и HTTP-ошибки',vitals:'Core Web Vitals (P75)',performance:'Производительность',security:'Security Events'})[section]}</h3>${s.quality?.unavailable?`<p class="notice error">Раздел недоступен: ${escapeHtml(s.quality.unavailable)}</p>`:''}${s.quality?.available_days!==undefined?`<p>Дней с доступными данными: ${escapeHtml(s.quality.available_days)}. Итог только по доступным дням.</p>`:''}${s.quality?.limited_by_provider?'<p>API может возвращать неполную детализацию.</p>':''}${s.quality?.actual_from?`<p>Доступный период: ${escapeHtml(s.quality.actual_from)} — ${escapeHtml(s.quality.actual_to||'—')}</p>`:''}${s.truncated?'<p>Выдача ограничена провайдером / первыми 100 строками.</p>':''}${s.quality?.sampled||s.quality?.thresholded||s.quality?.data_loss?'<p class="notice">Провайдер сообщает о семплировании, порогах или потере детализации.</p>':''}${table(['Измерение',...(s.labels||r.labels).map((l,j)=>(s.metrics||r.metrics)[j]==='ctr'?'CTR (%)':l)],s.rows.map(x=>`<tr><td>${escapeHtml(x.label)}</td>${x.values.map((v,j)=>`<td>${escapeHtml(v===null?'—':(s.metrics||r.metrics)[j]==='ctr'?(100*v).toLocaleString('ru-RU',{maximumFractionDigits:2})+'%':v.toLocaleString('ru-RU',{maximumFractionDigits:3}))}</td>`).join('')}</tr>`))}`}
  const webmasterDetails=()=>{const w=r?.webmaster;if(!w)return '';const maps=w.sitemaps||[],samples=w.index_samples||[];return `<h3>Sitemap — состояние на момент синхронизации</h3>${w.sitemaps_limited?'<p>Список ограничен 100 записями.</p>':''}${table(['URL','Состояние / тип','Ошибки','Последний обход'],maps.map(m=>`<tr><td>${escapeHtml(m.url)}</td><td>${escapeHtml(m.status||'—')}</td><td>${escapeHtml(m.errors??'—')}</td><td>${escapeHtml(m.last_read||'—')}</td></tr>`))}${w.index_samples?`<h3>Примеры страниц, известных роботу (${escapeHtml(w.index_sample_count??'—')})</h3>${table(['URL','Статус','HTTP','Обход'],samples.map(m=>`<tr><td>${escapeHtml(m.url)}</td><td>${escapeHtml(m.status||'—')}</td><td>${escapeHtml(m.http_code??'—')}</td><td>${escapeHtml(m.last_read||'—')}</td></tr>`))}`:''}`}
  const actionForm=i=>['webmaster','search_console'].includes(i.provider)&&integrationScope('analytics:write')&&runnable&&i.enabled&&hub.enabled?`<form class="form" data-webmaster-action="${escapeHtml(i.id)}"><label>URL страницы / sitemap</label><input class="input" type="url" name="url" required maxlength="2048" placeholder="https://example.com/sitemap.xml"><button class="btn secondary" name="action" value="submit_sitemap">Отправить sitemap</button>${i.provider==='search_console'?'<button class="btn secondary" name="action" value="inspect">Проверить URL в индексе Google</button>':''}</form>${state.forms.webmasterResult?.id===i.id?`<pre>${escapeHtml(JSON.stringify(state.forms.webmasterResult.data,null,2))}</pre>`:''}`:''
  const details=state.cache.analyticsHubReport?.error?`<div class="notice error">${escapeHtml(state.cache.analyticsHubReport.error.message)}</div>`:selected?`<div class="card"><h2>${escapeHtml(report.connection.name)} — отчёты</h2><p>Последняя попытка: ${escapeHtml(report.connection.last_attempt||'—')} · Последний успех: ${escapeHtml(report.connection.last_success||'—')}</p>${report.connection.last_error?`<p class="notice error">${escapeHtml(report.connection.last_error)}. Последний успешный снимок сохранён.</p>`:''}<form class="form" data-hub-period="${escapeHtml(selected)}"><label>Начало</label><input class="input" name="from" type="date" value="${escapeHtml(period.from)}" required><label>Конец</label><input class="input" name="to" type="date" value="${escapeHtml(period.to)}" required><button class="btn secondary" name="mode" value="read">Открыть сохранённый период</button>${runnable&&hub.enabled&&report.connection.enabled?'<button class="btn" name="mode" value="sync">Запросить период через API</button>':''}</form>${report.periods?.length?`<p>Сохранённые периоды:</p>${report.periods.map(p=>`<button class="btn small secondary" data-hub-period-open="${escapeHtml(selected)}" data-from="${escapeHtml(p.date_from)}" data-to="${escapeHtml(p.date_to)}">${escapeHtml(p.date_from)} — ${escapeHtml(p.date_to)}</button>`).join(' ')}`:''}${r?`<p>${escapeHtml(r.from)} — ${escapeHtml(r.to)} · Получено ${escapeHtml(r.fetched_at)} · TZ ${escapeHtml(r.timezone||'счётчика')}</p><button class="btn secondary" data-hub-export>CSV</button>${fresh()}${comparison()}${graph()}${Object.keys(r.sections).map(rows).join('')}${webmasterDetails()}`:'<p>Нет сохранённого отчёта за выбранный период. Запустите синхронизацию и дождитесь обработки очереди.</p>'}${syncHistory()}<button class="btn secondary" data-hub-refresh>Обновить состояние</button></div>`:''
  return `<div class="card"><h2>Analytics Hub</h2><p>Источников: ${items.length} · Активных: ${items.filter(i=>i.enabled).length} · С ошибками: ${items.filter(i=>i.last_error).length} · Синхронизируются: ${items.filter(i=>i.syncing).length}</p><p>Реальные агрегированные данные API. Источники не суммируются. Автоматическое обновление — последние 30 завершённых UTC-дней, ежечасно. ${hub.enabled?'':'Отправка отключена оператором.'}</p><p>Метрика: OAuth token с правом чтения. GA4: числовой Property ID, сервисный аккаунт или OAuth refresh token. Вебмастер: Host ID и OAuth token. Search Console: URL-prefix или домен, OAuth / сервисный аккаунт. Matomo / Plausible: credential JSON {&quot;type&quot;:&quot;api_token&quot;,&quot;endpoint&quot;:&quot;https://analytics.example.com&quot;,&quot;token&quot;:&quot;…&quot;}. Cloudflare Edge: Zone ID + {&quot;type&quot;:&quot;api_token&quot;,&quot;token&quot;:&quot;…&quot;}. Web Analytics: Site Tag + тот же JSON с &quot;account_id&quot;. Форматы — docs/infrastructure-analytics.md. Для Cloudflare период до 30 дней; отчёты отражают доступные данные и ограничения тарифа.</p></div>${writable?`<div class="card"><h2>Добавить источник</h2><form class="form" data-hub-create>${fields(null)}<button class="btn">Создать</button></form></div>`:''}<div class="grid two">${items.map(i=>`<div class="card"><h2>${escapeHtml(i.name)}</h2><p>${escapeHtml(i.provider)} · ${escapeHtml(i.resource)} · ${i.enabled?'Активно':'Отключено'}</p><p>Последний успех: ${escapeHtml(i.last_success||'—')} ${i.syncing?'· Синхронизация':''}</p>${i.last_error?`<p class="notice error">${escapeHtml(i.last_error)}</p>`:''}<button class="btn secondary" data-hub-open="${escapeHtml(i.id)}">Отчёты</button>${runnable&&i.enabled&&hub.enabled?`<button class="btn" data-hub-sync="${escapeHtml(i.id)}">Синхронизировать</button>`:''}${actionForm(i)}${writable?`<details><summary>Настройки</summary><form class="form" data-hub-edit="${escapeHtml(i.id)}">${fields(i)}<button class="btn">Сохранить</button><button type="button" class="btn danger" data-hub-delete="${escapeHtml(i.id)}">Удалить</button></form></details>`:''}</div>`).join('')||'<p>Источники не настроены.</p>'}</div>${details}`
}
function bindAnalyticsHub(){
  const items=()=>dataOf(state.cache.analyticsHub,{items:[]}).items||[], find=id=>items().find(i=>i.id===id)
  const run=async(el,fn)=>{if(el.dataset.busy)return;el.dataset.busy='1';try{await fn();if(state.route==='analytics-hub')render()}catch(e){setNotice('error',e.message)}finally{delete el.dataset.busy}}
  const open=async(id,from='',to='')=>{state.forms.hubPeriod=from&&to?{from,to}:null;state.cache.analyticsHubReport=await api(`/analytics/reports/${encodeURIComponent(id)}/data${from&&to?'?'+new URLSearchParams({from,to}):''}`)}
  const refresh=()=>loadSilent('analyticsHub','/analytics/reports')
  const save=(form,id)=>{const fd=new FormData(form);const body={name:fd.get('name'),provider:fd.get('provider'),resource:fd.get('resource'),goal:fd.get('goal')||null,enabled:fd.has('enabled')};if(id)body.revision=find(id).revision;if(fd.get('credential').trim())body.credential=JSON.parse(fd.get('credential'));return api('/analytics/reports'+(id?'/'+encodeURIComponent(id):''),{method:id?'PUT':'POST',body:JSON.stringify(body)})}
  document.querySelectorAll('[data-webmaster-action]').forEach(f=>f.addEventListener('submit',e=>{e.preventDefault();const action=e.submitter?.value;if(!action)return;const id=f.dataset.webmasterAction,url=new FormData(f).get('url');run(f,async()=>{state.forms.webmasterResult=null;const res=await api(`/analytics/reports/${encodeURIComponent(id)}/webmaster`,{method:'POST',body:JSON.stringify({revision:find(id).revision,action,url})});state.forms.webmasterResult={id,data:dataOf(res,{})};setNotice('ok',action==='submit_sitemap'?'Sitemap принят API. Обновите отчёт после обработки.':'Результат проверки получен')})}))
  document.querySelectorAll('[data-hub-create],[data-hub-edit]').forEach(f=>f.addEventListener('submit',e=>{e.preventDefault();run(f,async()=>{await save(f,f.dataset.hubEdit);state.cache.analyticsHubReport=null;await refresh();setNotice('ok','Источник сохранён')})}))
  document.querySelectorAll('[data-hub-open]').forEach(b=>b.addEventListener('click',()=>run(b,()=>open(b.dataset.hubOpen))))
  document.querySelectorAll('[data-hub-period-open]').forEach(b=>b.addEventListener('click',()=>run(b,()=>open(b.dataset.hubPeriodOpen,b.dataset.from,b.dataset.to))))
  const sync=async(id,body)=>{await api(`/analytics/reports/${encodeURIComponent(id)}/sync`,{method:'POST',body:JSON.stringify(body)});await refresh();setNotice('ok','Задача поставлена в очередь. После выполнения worker обновите отчёт.')}
  document.querySelector('[data-hub-refresh]')?.addEventListener('click',e=>run(e.currentTarget,async()=>{const r=dataOf(state.cache.analyticsHubReport,{}),period=state.forms.hubPeriod||r.report||{};await refresh();await open(r.connection.id,period.from,period.to)}))
  document.querySelector('[data-hub-compare-sync]')?.addEventListener('click',e=>{const b=e.currentTarget;run(b,async()=>{const r=dataOf(state.cache.analyticsHubReport,{});await sync(r.connection.id,{from:b.dataset.from,to:b.dataset.to});await open(r.connection.id,r.report.from,r.report.to)})})
  document.querySelectorAll('[data-hub-sync]').forEach(b=>b.addEventListener('click',()=>run(b,()=>sync(b.dataset.hubSync,{}))))
  document.querySelectorAll('[data-hub-delete]').forEach(b=>b.addEventListener('click',()=>{if(confirm('Удалить источник и все его отчёты?'))run(b,async()=>{await api(`/analytics/reports/${encodeURIComponent(b.dataset.hubDelete)}`,{method:'DELETE',body:JSON.stringify({revision:find(b.dataset.hubDelete).revision})});state.cache.analyticsHubReport=null;await refresh()})}))
  document.querySelector('[data-hub-period]')?.addEventListener('submit',e=>{e.preventDefault();const f=e.currentTarget,fd=new FormData(f),from=fd.get('from'),to=fd.get('to');run(f,async()=>{if(e.submitter?.value==='sync')await sync(f.dataset.hubPeriod,{from,to});await open(f.dataset.hubPeriod,from,to)})})
  document.querySelector('[data-hub-export]')?.addEventListener('click',()=>{const r=dataOf(state.cache.analyticsHubReport,{}).report;if(!r)return;const cell=v=>'"'+String(v).replace(/^\s*[=+\-@]|^[\t\r\n]/,"'$&").replace(/"/g,'""')+'"';const lines=['matomo','plausible','cf_zone','cf_web'].includes(r.provider)?[['provider','from','to','section','dimension','metric','value'],...Object.entries(r.sections).flatMap(([s,x])=>x.rows.flatMap(row=>row.values.map((v,j)=>[r.provider,r.from,r.to,s,row.label,(x.metrics||r.metrics)[j],v??''])))]:[['provider','from','to','section','dimension',...r.metrics],...Object.entries(r.sections).flatMap(([s,x])=>x.rows.map(row=>[r.provider,r.from,r.to,s,row.label,...row.values]))];const url=URL.createObjectURL(new Blob(['\uFEFF'+lines.map(x=>x.map(cell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='analytics-'+r.provider+'-'+r.from+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)})
}

function migrationView(){
  if(!integrationScope('migration:read'))return '<div class="notice error">Недостаточно прав.</div>'
  const cached=state.cache.migrationProjects;if(cached?.error)return `<div class="notice error">${escapeHtml(cached.error.message)}</div>`
  const projects=dataOf(cached,{projects:[]}).projects||[], selected=projects.find(p=>p.id===state.cache.migrationSelected), editing=selected&&state.cache.migrationEditing
  const write=integrationScope('migration:write'), manage=write&&integrationScope('integrations:secrets'), run=integrationScope('migration:run'), completed=selected?.status==='completed'
  const kinds={'dle-db':'DataLife Engine — MySQL/MariaDB','wordpress-db':'WordPress — MySQL/MariaDB','wordpress-rest':'WordPress — REST API','wordpress-wxr':'WordPress — WXR XML'}
  const source=editing?selected.source:'wordpress-wxr', c=editing?selected.config:{}, report=selected?.report
  const field=(name,label,value='',type='text')=>`<label>${escapeHtml(label)}<input name="${name}" type="${type}" value="${escapeHtml(value)}" ${type==='password'?'autocomplete="new-password"':''}></label>`
  const sourceFields=`<div data-migration-db>${field('host','IPv4 базы (разрешённый оператором)',c.host)}${field('port','Порт',c.port||3306,'number')}${field('database','Имя базы',c.database)}${field('prefix','Префикс таблиц (пусто = авто)',c.prefix)}<label>Кодировка соединения<select name="charset">${['utf8mb4','utf8','cp1251'].map(v=>`<option ${v===c.charset?'selected':''}>${v}</option>`).join('')}</select></label></div><div data-migration-rest>${field('url','HTTPS URL сайта WordPress',c.url,'url')}</div><div data-migration-credentials>${field('username','Пользователь (REST: необязательно)')}${field('password','Пароль БД / WordPress Application Password','','password')}</div>`
  return `<div class="grid two"><div class="card"><h2>1. Проекты миграции</h2><p>Анализ источника без изменения сайта назначения.</p>${projects.map(p=>`<div class="notice"><strong>${escapeHtml(p.name)}</strong> · ${escapeHtml(kinds[p.source])} · ${escapeHtml(p.status)} <button class="btn secondary" data-migration-open="${p.id}">Открыть</button></div>`).join('')||'<p>Нет проектов.</p>'}${manage?`<button class="btn secondary" data-migration-new>Новый проект</button><h3>${editing?'Изменить подключение':'Создать проект'}</h3><form class="form" data-migration-form data-id="${editing?selected.id:''}">${field('name','Название',editing?selected.name:'')}<label>Источник<select name="source">${Object.entries(kinds).map(([k,v])=>`<option value="${k}" ${k===source?'selected':''}>${escapeHtml(v)}</option>`).join('')}</select></label>${sourceFields}<p>Изменение подключения сбрасывает предыдущий отчёт; credentials нужно ввести заново.</p><button class="btn" type="submit">Сохранить проект</button></form>`:''}</div><div class="card"><h2>2. Анализ источника</h2>${selected?`<h3>${escapeHtml(selected.name)}</h3><p>${escapeHtml(kinds[selected.source])} · ревизия ${selected.revision}</p>${manage&&!completed?'<button class="btn secondary" data-migration-edit>Изменить подключение</button>':''}${write&&!completed?'<button class="btn ghost" data-migration-delete>Удалить</button>':''}${run&&!completed?`<button class="btn" data-migration-scan>Запустить / повторить анализ</button>${selected.source==='wordpress-wxr'&&write?'<form class="form" data-migration-upload><label>WXR XML (до 32 MiB)<input type="file" name="file" accept=".xml,text/xml,application/xml" required></label><button class="btn" type="submit">Загрузить и анализировать</button></form>':''}`:''}${selected.error?`<div class="notice error">${escapeHtml(selected.error)}${report?' (ниже предыдущий успешный отчёт)':''}</div>`:''}${report?`<h2>3. Отчёт совместимости</h2><p>Последний успешный анализ: ${escapeHtml(report.scanned_at)}</p><table><thead><tr><th>Сущность</th><th>Количество</th><th>Совместимость</th></tr></thead><tbody>${Object.entries(report.counts||{}).map(([k,v])=>`<tr><td>${escapeHtml(k)}</td><td>${v===null?'Недоступно':escapeHtml((report.details?.lower_bound_counts||[]).includes(k)?'≥ '+v:v)}</td><td>${escapeHtml(report.compatibility?.[k])}</td></tr>`).join('')}</tbody></table><h3>Типы, статусы, поля и структура</h3><pre>${escapeHtml(JSON.stringify({types:report.types,statuses:report.statuses,fields:report.fields,details:report.details},null,2))}</pre><h3>Ограничения</h3><ul>${(report.warnings||[]).map(w=>`<li>${escapeHtml(w)}</li>`).join('')}</ul><button class="btn secondary" data-migration-export>Скачать JSON отчёт</button>`:'<p>Отчёт появится после успешного анализа.</p>'}`:'<p>Создайте или откройте проект.</p>'}</div></div>${selected&&report&&!selected.error?migrationPlanningView(selected,write&&!completed,run&&!completed)+migrationExecutionView(selected):''}${selected?migrationCopilotView(selected):''}`
}
function migrationCopilotView(project) {
  const cache=state.forms.migrationCopilot
  const report=cache.report?.project_id===project.id?cache.report:null
  const problems=report?.findings||[]
  const warnings=report?.warnings||[]
  return `<section class="card" style="margin-top:18px"><h2>AI Migration Copilot · после миграции</h2>
    <p class="muted">Ограниченная проверка текущих медиа, редиректов и маршрутов; отдельные исправления требуют явного подтверждения оператора.</p>
    <div class="row-actions"><button class="btn" data-migration-copilot-check ${cache.busy?'disabled':''}>Проверить проект</button>
    ${report?.import_complete?`<button class="btn secondary" data-migration-copilot-explain ${cache.busy?'disabled':''}>Объяснить через OpenAI</button>`:''}
    ${report?'<button class="btn secondary" data-migration-copilot-export>Экспорт JSON</button>':''}</div>
    ${report?`<p>Импорт: ${report.import_complete?'завершён':'не завершён'} · Сверка: ${report.validation_available?(report.validation_ready?'готово':'есть расхождения'):'нет отчёта'} · Cutover: ${report.cutover_confirmed?'подтверждён':'не подтверждён'}</p>
      <p class="muted">Проверено ${escapeHtml(report.checked_at)}; последний migration:validate: ${escapeHtml(report.validation_checked_at||'нет')}</p>
      ${warnings.map(w=>`<div class="notice">${escapeHtml(w)}</div>`).join('')}
      <h3>Счётчики</h3><pre>${escapeHtml(JSON.stringify({counts:report.counts,checks:report.checks,findings:report.finding_counts},null,2))}</pre>
      <h3>Отклонения (${Number(report.finding_total)||0})</h3>
      ${table(['Приоритет','Код','Источник','Действие'],problems.map(p=>`<tr><td>${escapeHtml(p.severity)}</td><td>${escapeHtml(p.code)}</td><td>${escapeHtml(p.source)}</td><td>${escapeHtml(p.action)}</td></tr>`))}
      <p>Повторная операторская проверка:</p><pre>${escapeHtml(report.next_step)}</pre>`:''}
    ${report?.import_complete&&integrationScope('migration:write')?`<h3>Migration Remediation · после миграции</h3>
      <p class="muted">Разрешено только восстановление отсутствующих 301-редиректов из сохранённой карты и пересборка индекса маршрутов. Медиафайлы и редакторские изменения исправляются вручную.</p>
      <button class="btn secondary" data-migration-remediation-refresh>Обновить варианты исправлений</button>
      ${(cache.remediation?.options?.warnings||[]).map(w=>`<div class="notice">${escapeHtml(w)}</div>`).join('')}
      ${table(['Действие','Старый путь','Назначение',''],(cache.remediation?.options?.candidates||[]).map((item,i)=>`<tr><td>${escapeHtml(item.description)}</td><td>${escapeHtml(item.source_path)}</td><td>${escapeHtml(item.target_path)}</td><td><button class="btn secondary" data-migration-remediation-prepare="${i}">Подготовить</button></td></tr>`))}
      <div class="row-actions"><button class="btn secondary" data-migration-remediation-prev ${cache.remediationOffset>0?'':'disabled'}>Предыдущая партия</button><button class="btn secondary" data-migration-remediation-next ${cache.remediation?.options?.next_offset===null||cache.remediation?.options?.next_offset===undefined?'disabled':''}>Следующая партия</button></div>
      <h3>История исправлений</h3>
      ${table(['Операция','Статус',''],(cache.remediation?.history||[]).map(item=>`<tr><td>${escapeHtml(item.preview?.description)}: ${escapeHtml(item.preview?.source_path)}</td><td>${escapeHtml(item.state)}</td><td>${item.kind==='restore_redirect'&&item.state==='applied'&&integrationScope('migration:run')?`<button class="btn secondary" data-migration-remediation-rollback="${escapeHtml(item.id)}">Откатить 301</button>`:''}</td></tr>`))}
      <h3>Ожидают подтверждения</h3>
      ${table(['Предложение','Срок',''],(cache.remediation?.pending||[]).map(item=>`<tr><td>${escapeHtml(item.preview?.description)}: ${escapeHtml(item.preview?.source_path)} → ${escapeHtml(item.preview?.target_path)}</td><td>${escapeHtml(new Date(item.expires_at*1000).toLocaleString())}</td><td><button class="btn" data-migration-remediation-apply="${escapeHtml(item.id)}" ${integrationScope('migration:run')?'':'disabled'}>Применить</button> <button class="btn secondary" data-migration-remediation-reject="${escapeHtml(item.id)}">Отклонить</button></td></tr>`))}`:''}
    ${report&&cache.explanation?`<h3>AI-объяснение</h3><p style="white-space:pre-wrap">${escapeHtml(cache.explanation)}</p><p class="muted">${cache.usage?'Токены: '+Number(cache.usage.input_tokens)+' / '+Number(cache.usage.output_tokens):''}</p>`:''}</section>`
}

function migrationValidationView(p){
  const v=p.validation, id=p.id
  if(!v)return `<div class="card"><h2>9. Validation & Cutover</h2><p>После завершения миграции выполните серверную сверку содержимого, маршрутов, sitemap и файлов.</p><pre>${escapeHtml('php bin/cajeer migration:validate '+id)}</pre><button class="btn secondary" data-migration-refresh>Обновить результат</button></div>`
  const problem=(v.problems||[]).slice(0,25)
  const status=v.ready?'Проверено: готово к переключению':`Нужны действия: ${problem.length} ошибок, ${(v.counts?.new||0)+(v.counts?.changed||0)} новых/изменённых записей`
  const sync=v.synchronization,cut=v.cutover
  return `<div class="card"><h2>9. Проверка результата и переключение</h2><p>${escapeHtml(status)} · проверено ${escapeHtml(v.checked_at||'')}</p><pre>${escapeHtml(JSON.stringify(v.counts||{},null,2))}</pre>${problem.length?`<h3>Обнаруженные проблемы</h3><table><thead><tr><th>Объект</th><th>Причина</th></tr></thead><tbody>${problem.map(x=>`<tr><td>${escapeHtml(x.source)}</td><td>${escapeHtml(x.code)}</td></tr>`).join('')}</tbody></table>`:''}<p>Сверка повторно читает источник и назначения. Перенос изменений выполняется только после просмотра SHA-256.</p><pre>${escapeHtml('php bin/cajeer migration:validate '+id)}</pre>${v.sha256?`<p>SHA-256 сверки: <code>${escapeHtml(v.sha256)}</code></p>${(v.counts?.new||0)+(v.counts?.changed||0)?`<h3>Дозагрузка контента</h3><pre>${escapeHtml('php bin/cajeer migration:sync '+id+' '+v.sha256+' --yes --batch=50')}</pre>${sync?`<p>Пакетная синхронизация: ${escapeHtml(sync.state)} · ${escapeHtml(sync.cursor_index)}/${escapeHtml(sync.total)}. Backup: ${escapeHtml(sync.backup_id)}.</p><pre>${escapeHtml('php bin/cajeer migration:sync-rollback '+id+' '+sync.backup_id+' --yes')}</pre>`:''}`:''}${v.ready?`<h3>10. Подтверждённый cutover</h3><pre>${escapeHtml('php bin/cajeer migration:cutover '+id+' '+v.sha256+' --yes --target=https://new.example.com')}</pre><p>Укажите реальный APP_URL. Переключение DNS/балансировщика остаётся отдельной инфраструктурной операцией.</p>`:''}`:''}${cut?`<div class="notice">Активировано ${escapeHtml(cut.activated_at)} для ${escapeHtml(cut.target_url)}. Backup: ${escapeHtml(cut.backup_id)}.</div>`:''}<button class="btn secondary" data-migration-refresh>Обновить результат</button></div>`
}
function migrationExecutionView(p){
  const wp=p.source.startsWith('wordpress-'),label=wp?'WordPress':'DLE',command=wp?'migration:wordpress-import':'migration:dle-import'
  const r=p.execution
  const batch=p.batch_execution
  if(batch&&batch.state!=='completed')return `<div class="card"><h2>6. Пакетный перенос ${label}</h2><p>Статус: ${escapeHtml(batch.state)} · обработано ${escapeHtml(batch.cursor_index)} из ${escapeHtml(batch.total)} (${escapeHtml(batch.percent)}%). Сайт остаётся в режиме обслуживания до завершения или восстановления.</p><pre>${escapeHtml('php bin/cajeer migration:status '+p.id)}</pre><pre>${escapeHtml('php bin/cajeer migration:resume '+p.id+' '+p.plan.sha256+' --yes --batch=50')}</pre><p>Для полного отката к проверенной копии, включая все изменения базы и файлов после её создания:</p><pre>${escapeHtml('php bin/cajeer migration:rollback '+p.id+' '+batch.backup_id+' --yes')}</pre><button class="btn secondary" data-migration-refresh>Обновить статус</button></div>`
  if(r)return migrationValidationView(p)+`<div class="card"><h2>6. Перенос ${label} завершён</h2><p>Завершено: ${escapeHtml(r.completed_at)} · план ${escapeHtml(r.plan_sha256)}</p><pre>${escapeHtml(JSON.stringify(r.counts,null,2))}</pre><table><thead><tr><th>Исходная запись</th><th>Целевой тип / UUID</th><th>Действие</th></tr></thead><tbody>${(r.records||[]).slice(0,100).map(a=>`<tr><td>${escapeHtml(a.source_type)}:${escapeHtml(a.source_id)}</td><td>${escapeHtml(a.target_type||'')} / ${escapeHtml(a.target_id||'')}</td><td>${escapeHtml(a.action)}</td></tr>`).join('')}</tbody></table><p>Первые 100 записей. Полный журнал доступен в JSON-отчёте.</p><h3>Пользователи и авторы</h3><table><thead><tr><th>ID источника</th><th>UUID</th><th>Роль / результат</th><th>Активация при импорте</th></tr></thead><tbody>${(r.users||[]).map(u=>`<tr><td>${escapeHtml(u.source_id)}</td><td>${escapeHtml(u.target_id||'')}</td><td>${escapeHtml(u.role||u.action)}</td><td>${escapeHtml(u.activation||'')}</td></tr>`).join('')}</tbody></table><p>Для pending после проверки владельца: <code>php bin/cajeer migration:activate-user USER_UUID --yes</code> (новый пароль через защищённый stdin). blocked не активируется этой командой. Отчёт показывает состояние на момент импорта.</p><h3>7. Медиа и вложения</h3><p>Перед импортом файлов скопируйте исходный каталог uploads на сервер. Команда выполняется оператором после завершения контента и проверяет SHA-256, MIME, ревизии и ссылки.</p><pre>${escapeHtml('php bin/cajeer migration:media-import '+p.id+' /srv/source/uploads --yes --origin=https://old.example.com')}</pre><p>Загружено файлов: ${escapeHtml(p.media_migration?.imported_files??0)} · байт: ${escapeHtml(p.media_migration?.imported_bytes??0)} · обновлено записей: ${escapeHtml(p.media_migration?.rewritten_entries??0)} · ссылок: ${escapeHtml(p.media_migration?.rewritten_links??0)}</p><h3>8. SEO, старые URL и sitemap</h3><p>Оператор: сначала проверьте структуру старых URL. Если permalink неизвестен, используйте явный JSON-файл legacy-map. Миграция не угадывает исходные пути.</p><pre>${escapeHtml('php bin/cajeer migration:seo-import '+p.id+' --yes --origin=https://old.example.com --legacy-map=/srv/legacy-urls.json')}</pre><p>SEO-записей: ${escapeHtml(p.seo_migration?.metadata_entries??0)} · 301: ${escapeHtml(p.seo_migration?.new_redirects??0)} · WordPress query URL: ${escapeHtml(p.seo_migration?.new_query_redirects??0)} · заменено внутренних ссылок: ${escapeHtml(p.seo_migration?.rewritten_links??0)}</p><p>После выполнения откройте <a href="/sitemap.xml" target="_blank" rel="noopener noreferrer">sitemap.xml</a> и проверьте старые URL на 301.</p><p>Поддержка исходных файлов: JPEG, PNG, GIF, WebP, PDF, TXT, Markdown, ZIP. Неподдерживаемые файлы фиксируются в отчёте CLI и не выдаются за успешно перенесённые.</p><ul>${(r.warnings||[]).map(w=>`<li>${escapeHtml(w)}</li>`).join('')}</ul><button class="btn secondary" data-migration-result-export>Скачать результат JSON</button></div>`
  if(p.status!=='approved'||!p.plan?.approved_at)return ''
  return `<div class="card"><h2>6. Выполнение ${label}</h2><p>Оператор: примените миграции БД и выполните пакетную команду. Она создаст проверенный backup перед записью, включит maintenance и сохранит checkpoint каждого пакета. При сбое используйте migration:resume или migration:rollback.</p><pre>${escapeHtml('php bin/cajeer migration:execute '+p.id+' '+p.plan.sha256+' --yes --batch=50')}</pre><button class="btn secondary" data-migration-refresh>Обновить результат</button></div>`
}
function migrationPlanningFields(type,rule,sourceFields){
  if(!type)return '<p>Тип исключён из плана.</p>'
  const destinations=[{handle:'title',type:'text'},{handle:'slug',type:'text'},...(type.fields||[]).map(f=>({...f,handle:'data:'+f.handle}))]
  const transforms=['copy','text','number','boolean','date','datetime','json']
  return `<table><thead><tr><th>Целевое поле</th><th>Источник</th><th>Преобразование</th></tr></thead><tbody>${destinations.map(f=>{const def=rule?.fields?.[f.handle],base=f.handle.replace('data:',''),from=def?.from??(sourceFields.includes(base)?base:'');return `<tr data-plan-field="${escapeHtml(f.handle)}"><td>${escapeHtml(f.handle)} · ${escapeHtml(f.type)}${f.required?' *':''}</td><td><select data-plan-from><option value="">Не сопоставлять</option>${Array.from(new Set([...sourceFields,...(from?[from]:[])])).map(k=>`<option value="${escapeHtml(k)}" ${k===from?'selected':''}>${escapeHtml(k)}</option>`).join('')}</select></td><td><select data-plan-transform>${transforms.map(t=>`<option ${t===(def?.transform||'copy')?'selected':''}>${t}</option>`).join('')}</select></td></tr>`}).join('')}</tbody></table>`
}
function migrationPlanningView(p,write,run){
  const catalog=dataOf(state.cache.migrationCatalog,{types:[]}).types||[],report=p.report,m=p.mapping||{},plan=p.plan
  const sourceTypes=Array.from(new Set([...Object.keys(report.types||{}).filter(t=>!['attachment','revision','nav_menu_item','customize_changeset','wp_global_styles','wp_template','wp_template_part','wp_navigation','oembed_cache'].includes(t)),...Object.keys(m.types||{}),...(['dle-db','wordpress-rest'].includes(p.source)?['post','page']:[])]))
  const sourceFields=['title','body','excerpt','slug','date','categories','tags',...(report.fields||[]).map(k=>'meta:'+k)]
  const statuses=Array.from(new Set(['publish','draft',...Object.keys(report.statuses||{}),...Object.keys(m.statuses||{})].filter(k=>!['0','1'].includes(k))))
  const users=Object.fromEntries(Object.entries(m.users||{}));const known=plan?.users||[]
  return `<div class="card"><h2>4. Сопоставление и план миграции</h2><p>Dry Run читает реальные записи; контент назначения не изменяется. Выберите существующие типы контента и поля. Пустой тип исключает исходные записи.</p>${write?`<form class="form" data-migration-mapping><label>Локаль<input name="locale" value="${escapeHtml(m.locale||'ru')}" required></label><label>Конфликты slug<select name="conflict">${['fail','skip','update','create'].map(v=>`<option ${v===(m.conflict||'fail')?'selected':''}>${v}</option>`).join('')}</select></label>${sourceTypes.map(t=>{const rule=m.types?.[t]||{},target=rule.target||'';return `<div class="notice" data-plan-type="${escapeHtml(t)}"><h3>${escapeHtml(t)}</h3><label>Целевой тип<select data-plan-target><option value="">Исключить</option>${catalog.map(x=>`<option value="${escapeHtml(x.handle)}" ${x.handle===target?'selected':''}>${escapeHtml(x.handle)}</option>`).join('')}</select></label><div data-plan-fields>${migrationPlanningFields(catalog.find(x=>x.handle===target),rule,sourceFields)}</div></div>`}).join('')}<h3>Статусы</h3>${statuses.map(k=>`<label>${escapeHtml(k)}<select data-plan-status="${escapeHtml(k)}">${['draft','archived','skip',...(['publish','published'].includes(k)?['published']:[])].map(v=>`<option ${v===(m.statuses?.[k]||'draft')?'selected':''}>${v}</option>`).join('')}</select></label>`).join('')}<p>Первые 100 записей. Полный журнал доступен в JSON-отчёте.</p><h3>Пользователи и авторы</h3><p>Укажите UUID существующего пользователя, create (новый отключённый аккаунт) или skip. Сначала выполните Dry Run, чтобы получить ID источника. Совпадение email требует явного решения.</p>${known.length?`<table><thead><tr><th>ID источника / группа</th><th>Решение / роль</th><th>Причина</th></tr></thead><tbody>${known.map(u=>`<tr><td>${escapeHtml(u.source_id)} / ${escapeHtml(u.source_role||'неизвестно')}</td><td>${escapeHtml(u.action)} ${escapeHtml(u.target_id||u.role||'')}</td><td>${escapeHtml(u.reason)}</td></tr>`).join('')}</tbody></table>`:''}<label>Сопоставление ID → UUID / create / skip (JSON)<textarea name="users" rows="5">${escapeHtml(JSON.stringify(users,null,2))}</textarea></label><label>Роли новых аккаунтов (JSON: ID → viewer / editor)<textarea name="user_roles" rows="3">${escapeHtml(JSON.stringify(m.user_roles||{},null,2))}</textarea></label><p>По умолчанию viewer. Административные права не переносятся; editor назначается только явно. Активация оператором после проверки владельца.</p><button class="btn" type="submit">Сохранить сопоставление</button></form>`:''}${run&&integrationScope('users:read')&&m.types?'<button class="btn" data-migration-plan>Dry Run</button>':''}${plan?`<h3>5. Предварительный план</h3><p>${plan.approved_at?'Подтверждён: '+escapeHtml(plan.approved_at):plan.approvable?'Готов к подтверждению':'Есть нерешённые конфликты или ошибки'}</p><p>SHA-256: <code>${escapeHtml(plan.sha256)}</code></p><pre>${escapeHtml(JSON.stringify({counts:plan.counts,users:plan.user_counts,warnings:plan.warnings},null,2))}</pre><table><thead><tr><th>Источник</th><th>Цель</th><th>Действие</th><th>Причина</th></tr></thead><tbody>${(plan.records||[]).map(r=>`<tr><td>${escapeHtml(r.source_type)}:${escapeHtml(r.source_id)}</td><td>${escapeHtml(r.target_type||'')} / ${escapeHtml(r.slug||'')}</td><td>${escapeHtml(r.action)}</td><td>${escapeHtml(r.reason||'')}</td></tr>`).join('')}</tbody></table><button class="btn secondary" data-migration-plan-export>Скачать план JSON</button>${write&&run&&integrationScope('users:read')&&plan.approvable&&!plan.approved_at?'<button class="btn" data-migration-approve>Подтвердить план</button>':''}`:''}</div>`
}
function bindMigrationPlanning(selected,path,execute,refresh){
  const form=document.querySelector('[data-migration-mapping]'),catalog=dataOf(state.cache.migrationCatalog,{types:[]}).types||[]
  if(form){const fields=['title','body','excerpt','slug','date','categories','tags',...(selected.report?.fields||[]).map(k=>'meta:'+k)]
    form.querySelectorAll('[data-plan-type]').forEach(el=>{const select=el.querySelector('[data-plan-target]');select.addEventListener('change',()=>{el.querySelector('[data-plan-fields]').innerHTML=migrationPlanningFields(catalog.find(x=>x.handle===select.value),{},fields)})})
    form.addEventListener('submit',e=>{e.preventDefault();execute(form,async()=>{const types=Object.create(null),statuses=Object.create(null);form.querySelectorAll('[data-plan-type]').forEach(el=>{const rule={target:el.querySelector('[data-plan-target]').value,fields:Object.create(null)};el.querySelectorAll('[data-plan-field]').forEach(row=>{const from=row.querySelector('[data-plan-from]').value;if(from)rule.fields[row.dataset.planField]={from,transform:row.querySelector('[data-plan-transform]').value}});types[el.dataset.planType]=rule});form.querySelectorAll('[data-plan-status]').forEach(el=>statuses[el.dataset.planStatus]=el.value);const mapping={types,statuses,users:JSON.parse(form.elements.users.value||'{}'),user_roles:JSON.parse(form.elements.user_roles.value||'{}'),locale:form.elements.locale.value,conflict:form.elements.conflict.value};await api(path+'/mapping',{method:'PUT',body:JSON.stringify({revision:selected.revision,mapping})});await refresh()})})}
  document.querySelector('[data-migration-plan]')?.addEventListener('click',e=>execute(e.currentTarget,async()=>{await api(path+'/plan',{method:'POST',body:JSON.stringify({revision:selected.revision})});await refresh()}))
  document.querySelector('[data-migration-approve]')?.addEventListener('click',e=>{if(confirm('Подтвердить план без выполнения импорта? Источник и цель будут повторно проверены.'))execute(e.currentTarget,async()=>{await api(path+'/approve',{method:'POST',body:JSON.stringify({revision:selected.revision,sha256:selected.plan.sha256})});await refresh()})})
  document.querySelector('[data-migration-plan-export]')?.addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([JSON.stringify(selected.plan,null,2)],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download='migration-plan-'+selected.id+'.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)})
}
function bindMigration(){
  if(state.route!=='migration')return
  const projects=dataOf(state.cache.migrationProjects,{projects:[]}).projects||[], selected=projects.find(p=>p.id===state.cache.migrationSelected)
  const execute=async(el,fn)=>{if(el.dataset.busy)return;el.dataset.busy='1';el.disabled=true;try{await fn();if(state.route==='migration')render()}catch(e){setNotice('error',e.message)}finally{delete el.dataset.busy;el.disabled=false}}
  const refresh=()=>loadSilent('migrationProjects','/migration/projects')
  document.querySelectorAll('[data-migration-open]').forEach(b=>b.addEventListener('click',()=>{state.cache.migrationSelected=b.dataset.migrationOpen;state.cache.migrationEditing=false;state.forms.migrationCopilot={report:null,explanation:'',usage:null,busy:false,remediation:null,remediationOffset:0};render()}))
  document.querySelector('[data-migration-new]')?.addEventListener('click',()=>{state.cache.migrationSelected=null;state.cache.migrationEditing=false;render()})
  document.querySelector('[data-migration-edit]')?.addEventListener('click',()=>{state.cache.migrationEditing=true;render()})
  const f=document.querySelector('[data-migration-form]')
  if(f){const sync=()=>{const v=f.elements.source.value;for(const [sel,show] of [['[data-migration-db]',v.endsWith('-db')],['[data-migration-rest]',v==='wordpress-rest'],['[data-migration-credentials]',v!=='wordpress-wxr']]){const el=f.querySelector(sel);el.hidden=!show;el.querySelectorAll('input,select').forEach(i=>i.disabled=!show)}};sync();f.elements.source.addEventListener('change',sync);f.addEventListener('submit',e=>{e.preventDefault();execute(f,async()=>{const d=new FormData(f),source=d.get('source');const body={name:d.get('name'),source,config:source.endsWith('-db')?{host:d.get('host'),port:Number(d.get('port')),database:d.get('database'),prefix:d.get('prefix'),charset:d.get('charset')}:source==='wordpress-rest'?{url:d.get('url')}: {},credential:source==='wordpress-wxr'?{}:{username:d.get('username')||'',password:d.get('password')||''}};const id=f.dataset.id;if(id)body.revision=selected.revision;const p=dataOf(await api('/migration/projects'+(id?'/'+id:''),{method:id?'PUT':'POST',body:JSON.stringify(body)}));f.elements.password.value='';state.cache.migrationSelected=p.id;state.cache.migrationEditing=false;await refresh()})})}
  if(!selected)return
  const path='/migration/projects/'+selected.id
  bindMigrationPlanning(selected,path,execute,refresh)
  document.querySelector('[data-migration-copilot-check]')?.addEventListener('click',e=>execute(e.currentTarget,async()=>{
    const cop=state.forms.migrationCopilot
    cop.report=null;cop.explanation='';cop.usage=null;cop.busy=true
    try{cop.report=dataOf(await api('/ai/migration/projects/'+encodeURIComponent(selected.id)+'/report'));if(cop.report.import_complete&&integrationScope('migration:write')){cop.remediationOffset=0;cop.remediation=dataOf(await api('/ai/migration/projects/'+encodeURIComponent(selected.id)+'/remediation?offset=0'))}}
    finally{cop.busy=false}
  }))
  document.querySelector('[data-migration-copilot-explain]')?.addEventListener('click',e=>execute(e.currentTarget,async()=>{
    const cop=state.forms.migrationCopilot;cop.busy=true
    try{
      const result=dataOf(await api('/ai/migration/projects/'+encodeURIComponent(selected.id)+'/explain',{method:'POST',body:'{}'}))
      cop.report=result.report;cop.explanation=result.explanation;cop.usage=result.usage
    }finally{cop.busy=false}
  }))
  const remediationPath='/ai/migration/projects/'+encodeURIComponent(selected.id)+'/remediation'
  const refreshRemediation=async()=>{const cop=state.forms.migrationCopilot;cop.remediation=dataOf(await api(remediationPath+'?offset='+(cop.remediationOffset||0)))}
  document.querySelector('[data-migration-remediation-refresh]')?.addEventListener('click',e=>execute(e.currentTarget,refreshRemediation))
  document.querySelector('[data-migration-remediation-prev]')?.addEventListener('click',e=>execute(e.currentTarget,async()=>{state.forms.migrationCopilot.remediationOffset=Math.max(0,(state.forms.migrationCopilot.remediationOffset||0)-100);await refreshRemediation()}))
  document.querySelector('[data-migration-remediation-next]')?.addEventListener('click',e=>execute(e.currentTarget,async()=>{const next=state.forms.migrationCopilot.remediation?.options?.next_offset;if(next===null||next===undefined)return;state.forms.migrationCopilot.remediationOffset=next;await refreshRemediation()}))
  document.querySelectorAll('[data-migration-remediation-prepare]').forEach(b=>b.addEventListener('click',()=>{
    const item=state.forms.migrationCopilot.remediation?.options?.candidates[Number(b.dataset.migrationRemediationPrepare)]
    if(!item||!confirm('Подготовить исправление?\n'+item.description+'\n'+item.source_path+' → '+item.target_path))return
    execute(b,async()=>{await api(remediationPath,{method:'POST',body:JSON.stringify({kind:item.kind,source_path:item.source_path})});await refreshRemediation()})
  }))
  for(const decision of ['apply','reject'])document.querySelectorAll('[data-migration-remediation-'+decision+']').forEach(b=>b.addEventListener('click',()=>{
    const id=b.dataset[decision==='apply'?'migrationRemediationApply':'migrationRemediationReject']
    const item=state.forms.migrationCopilot.remediation?.pending?.find(p=>p.id===id)
    if(!item||!confirm((decision==='apply'?'Выполнить':'Отклонить')+' подтверждённое действие?\n'+item.preview?.description+'\n'+item.preview?.source_path+' → '+item.preview?.target_path))return
    execute(b,async()=>{
      await api(remediationPath+'/'+encodeURIComponent(id)+'/'+decision,{method:'POST',body:'{}'})
      await refreshRemediation()
      state.forms.migrationCopilot.report=dataOf(await api('/ai/migration/projects/'+encodeURIComponent(selected.id)+'/report'))
    })
  }))
  document.querySelectorAll('[data-migration-remediation-rollback]').forEach(b=>b.addEventListener('click',()=>{
    const id=b.dataset.migrationRemediationRollback
    if(!confirm('Откатить подтверждённый 301-редирект? Откат недоступен, если правило уже использовалось или было изменено.'))return
    execute(b,async()=>{
      await api(remediationPath+'/'+encodeURIComponent(id)+'/rollback',{method:'POST',body:'{}'})
      await refreshRemediation()
      state.forms.migrationCopilot.report=dataOf(await api('/ai/migration/projects/'+encodeURIComponent(selected.id)+'/report'))
    })
  }))
  document.querySelector('[data-migration-copilot-export]')?.addEventListener('click',()=>{
    const report=state.forms.migrationCopilot.report
    if(!report||report.project_id!==selected.id)return
    const url=URL.createObjectURL(new Blob([JSON.stringify(report,null,2)],{type:'application/json'}))
    const link=document.createElement('a');link.href=url;link.download='migration-copilot-'+selected.id+'.json';link.click()
    setTimeout(()=>URL.revokeObjectURL(url),1000)
  })
  document.querySelector('[data-migration-refresh]')?.addEventListener('click',e=>execute(e.currentTarget,refresh))
  document.querySelector('[data-migration-result-export]')?.addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([JSON.stringify(selected.execution,null,2)],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download='migration-result-'+selected.id+'.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)})
  document.querySelector('[data-migration-scan]')?.addEventListener('click',e=>execute(e.currentTarget,async()=>{await api(path+'/scan',{method:'POST',body:JSON.stringify({revision:selected.revision})});await refresh()}))
  document.querySelector('[data-migration-upload]')?.addEventListener('submit',e=>{e.preventDefault();const form=e.currentTarget;execute(form,async()=>{const body=new FormData(form);body.append('revision',String(selected.revision));await api(path+'/wxr',{method:'POST',body});await refresh()})})
  document.querySelector('[data-migration-delete]')?.addEventListener('click',e=>{if(confirm('Удалить проект, credentials и сохранённый WXR?'))execute(e.currentTarget,async()=>{await api(path,{method:'DELETE',body:JSON.stringify({revision:selected.revision})});state.cache.migrationSelected=null;state.cache.migrationEditing=false;await refresh()})})
  document.querySelector('[data-migration-export]')?.addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([JSON.stringify(selected.report,null,2)],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download='migration-discovery-'+selected.id+'.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)})
}

function bindForms() {
  bindMigration()
  bindContentTypes()
  bindContent()
  bindSeo()
  bindTranslations()
  bindDiagnostics()
  bindMedia()
  bindCms()
  bindUsers()
  bindIdentity()
  bindPrivacy()
  bindTracking()
  bindAnalyticsEvents()
  bindAnalyticsHub()
  bindAnalyticsInsights()
  bindIntegrations()
  bindExtensions()
  bindUpdates()
  bindSearch()
  bindImportExport()
}

function table(headers, rows) {
  if (!rows.length) return '<div class="empty">Данных пока нет.</div>'
  return `<div class="table-wrap"><table><thead><tr>${headers.map((h) => `<th>${escapeHtml(h)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`
}

function automationView() {
  const f=state.forms
  const items=asArray(state.cache.aiAutomations)
  const canWrite=integrationScope('content:write')
  const selected=items.find(x=>x.id===f.aiAutomationSelected)
  const lines=items.map(item=>`<article class="card" style="margin:10px 0;padding:12px"><strong>${escapeHtml(item.title)}</strong> · ${item.enabled?'Активен':'Пауза'}<p class="muted">Интервал: ${Number(item.interval_minutes)} мин · Следующий запуск: ${new Date(Number(item.next_run_at)*1000).toLocaleString()}</p><div class="row-actions"><button type="button" class="btn small secondary" data-ai-automation-edit="${escapeHtml(item.id)}">История / изменить</button>${canWrite?`<button type="button" class="btn small" data-ai-automation-run="${escapeHtml(item.id)}">Запустить</button><button type="button" class="btn small secondary" data-ai-automation-toggle="${escapeHtml(item.id)}">${item.enabled?'Пауза':'Возобновить'}</button><button type="button" class="btn small danger" data-ai-automation-delete="${escapeHtml(item.id)}">Удалить</button>`:''}</div></article>`).join('')
  const form = canWrite&&f.aiAutomationEditing?`<form data-ai-automation-form class="form" style="margin-top:14px"><h3>${selected?'Изменить':'Создать'} сценарий</h3><div class="form-row"><label>Название</label><input class="input" name="title" required maxlength="160" value="${escapeHtml(selected?.title||'')}"></div><div class="form-row"><label>Запрос для OpenAI</label><textarea class="input" name="prompt" required maxlength="12000" rows="4">${escapeHtml(selected?.prompt||'')}</textarea></div><div class="form-row"><label>Интервал</label><select class="input" name="interval_minutes">${[60,360,1440,10080].map(min=>`<option value="${min}" ${Number(selected?.interval_minutes||1440)===min?'selected':''}>${min===60?'Ежечасно':min===360?'Каждые 6 часов':min===1440?'Ежедневно':'Еженедельно'}</option>`).join('')}</select></div><label><input type="checkbox" name="enabled" ${selected?.enabled??true?'checked':''}> Включено</label><div class="row-actions"><button class="btn" type="submit">Сохранить</button><button type="button" class="btn secondary" data-ai-automation-cancel>Отмена</button></div></form>`:''
  const runs=selected?`<h3>История: ${escapeHtml(selected.title)}</h3><button type="button" class="btn small secondary" data-ai-automation-refresh-runs>Обновить историю</button>${table(['Время','Статус','Результат'],asArray(f.aiAutomationRuns).map(run=>`<tr><td>${new Date(Number(run.queued_at)*1000).toLocaleString()}</td><td>${escapeHtml(run.status)}${run.error?' · '+escapeHtml(run.error):''}</td><td>${run.conversation_id?`<button type="button" class="btn small" data-ai-automation-open-result="${escapeHtml(run.conversation_id)}">Открыть диалог</button>`:'—'}</td></tr>`))}`:''
  return `<section class="card" style="margin-top:18px"><h2>AI Background Automation</h2><p class="muted">Регулярные запросы обрабатываются очередью и Scheduler. Результаты — в личных AI-диалогах. Без AI-инструментов и автоматических изменений CMS. Используются действующие лимиты токенов и USD.</p><div class="row-actions">${canWrite?'<button class="btn" type="button" data-ai-automation-new>Новый сценарий</button>':''}<button class="btn secondary" type="button" data-ai-automation-refresh>Обновить</button></div>${lines||'<p class="muted">Сценариев нет.</p>'}${form}${runs}</section>`
}

function aiView() {
  const f = state.forms
  const status = dataOf(state.cache.aiStatus, {})
  const conversations = asArray(state.cache.aiConversations)
  const canManage = integrationScope('system:write') && integrationScope('integrations:secrets')
  const selected = f.aiSelected
  const title = selected ? conversations.find(c => c.id === selected)?.title || 'Диалог' : 'Новый диалог'
  const messages = Array.isArray(f.aiMessages) ? f.aiMessages : []
  const rows = conversations.map(c => `<div class="ai-thread"><button type="button" class="btn small ${selected === c.id ? '' : 'secondary'}" data-ai-open="${escapeHtml(c.id)}">${escapeHtml(c.title)}</button><button type="button" class="btn small ghost" data-ai-rename="${escapeHtml(c.id)}" title="Переименовать">✎</button><button type="button" class="btn small danger" data-ai-delete="${escapeHtml(c.id)}" title="Удалить">×</button></div>`).join('')
  const content = messages.map(m => `<article class="ai-bubble ${m.role === 'user' ? 'user' : 'assistant'}"><strong>${m.role === 'user' ? 'Вы' : 'ChatGPT'}</strong><div>${escapeHtml(m.content)}</div>${Array.isArray(m.tools)&&m.tools.length?'<small>Инструменты: '+escapeHtml(m.tools.map(t=>t.name+' ('+t.status+')').join(', '))+'</small>':''}</article>`).join('')
  const tools = asArray(state.cache.aiTools)
  const actionTools = tools.filter(t=>t.requires_approval)
  const canWrite = integrationScope('content:write')
  const pendingActions = asArray(state.cache.aiActions)
  const toolPanel = tools.length ? `<div class="ai-tools-panel"><label><input type="checkbox" data-ai-tools-toggle ${f.aiToolsEnabled?'checked':''} ${f.aiBusy?'disabled':''}> Разрешить инструментам читать данные CMS и передавать результаты в OpenAI</label>${canWrite&&actionTools.length?`<label><input type="checkbox" data-ai-actions-toggle ${f.aiActionsEnabled?'checked':''} ${f.aiBusy||!f.aiToolsEnabled?'disabled':''}> Разрешить AI подготовить действия для ручного подтверждения</label>`:''}<details><summary>Доступные инструменты (${tools.length})</summary><ul>${tools.map(t=>`<li><code>${escapeHtml(t.name)}</code> — ${escapeHtml(t.description)}</li>`).join('')}</ul></details></div>` : ''
  const actionsView = canWrite ? `<section class="card" style="margin-top:18px"><h2>AI Actions · Подтверждение (${pendingActions.length})</h2><p class="muted">Модель готовит только предложения. Применение требует вашего подтверждения.</p><button type="button" class="btn secondary small" data-ai-actions-refresh>Обновить</button>${pendingActions.map(a=>`<article class="card" style="margin-top:10px"><h3>${escapeHtml(a.action)}</h3><p class="muted">Ревизия: ${escapeHtml(String(a.expected_revision??'новый черновик'))}</p><div class="split"><div><strong>Было</strong><pre style="white-space:pre-wrap;overflow-wrap:anywhere">${escapeHtml(JSON.stringify(a.before,null,2))}</pre></div><div><strong>Предложено</strong><pre style="white-space:pre-wrap;overflow-wrap:anywhere">${escapeHtml(JSON.stringify(a.payload,null,2))}</pre></div></div><div class="row-actions"><button type="button" class="btn" data-ai-action-approve="${escapeHtml(a.id)}" ${f.aiActionsBusy?'disabled':''}>Применить</button><button type="button" class="btn secondary" data-ai-action-reject="${escapeHtml(a.id)}" ${f.aiActionsBusy?'disabled':''}>Отклонить</button></div></article>`).join('')||'<p>Нет ожидающих предложений.</p>'}</section>` : ''
  const mayReadCosts = integrationScope('system:read')
  const costs = dataOf(state.cache.aiCosts,{})
  const money = amount => new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',minimumFractionDigits:4,maximumFractionDigits:4}).format(Number(amount||0)/1000000)
  const balanceRows = Object.entries(costs.global_balances||{}).map(([period,value])=>`<li>${escapeHtml(period)}: использовано ${money(value.used_micro_usd)}, резерв ${money(value.reserved_micro_usd)}</li>`).join('')
  const alertsHtml = asArray(costs.alerts).map(a=>`<p class="notice">${escapeHtml(a.window)} — ${Number(a.utilization_percent)}% бюджета, порог ${Number(a.threshold_percent)}%</p>`).join('')
  const costRows = list => table(['Модуль / модель / пользователь','Запросы','USD'],asArray(list).map(row=>`<tr><td>${escapeHtml(row.label)}</td><td>${Number(row.requests)}</td><td>${money(row.cost_micro_usd)}</td></tr>`))
  const costView = mayReadCosts ? `<section class="card" style="margin-top:18px"><h2>AI Budget & Cost Control</h2><p class="muted">Оценочные расходы по зафиксированному usage OpenAI. Реальный счёт провайдера может отличаться.</p><button type="button" class="btn secondary small" data-ai-costs-refresh>Обновить</button>${alertsHtml}<ul>${balanceRows}</ul><h3>По модулям</h3>${costRows(costs.by_module)}<h3>По моделям</h3>${costRows(costs.by_model)}<details><summary>По пользователям</summary>${costRows(costs.by_user)}</details><p class="muted">${escapeHtml(costs.notice||'')}</p></section>` : ''
  const settingsView = canManage ? `<details class="card"><summary><strong>Настройки OpenAI</strong></summary><form data-ai-settings class="form" style="margin-top:14px"><div class="form-row"><label>API-ключ (пустое поле сохраняет текущий)</label><input class="input" name="api_key" type="password" autocomplete="off" placeholder="sk-..."></div><div class="form-row"><label>Модель</label><input class="input" name="model" required value="${escapeHtml(status.model || 'gpt-4.1-mini')}"></div><div class="form-row"><label>Лимит токенов ответа</label><input class="input" name="max_output_tokens" type="number" min="128" max="4096" value="${Number(status.max_output_tokens || 1024)}" required></div><div class="form-row"><label>Месячный бюджет токенов на пользователя</label><input class="input" name="monthly_token_limit" type="number" min="1000" max="10000000" value="${Number(status.monthly_token_limit || 250000)}" required></div><fieldset><legend>Лимиты USD cents (0 — без лимита)</legend>${[['global_monthly_limit_cents','CMS / месяц',10000],['global_daily_limit_cents','CMS / день',2000],['user_monthly_limit_cents','Пользователь / месяц',2000],['user_daily_limit_cents','Пользователь / день',500]].map(([key,label,defaultValue])=>`<div class="form-row"><label>${label}</label><input class="input" name="${key}" type="number" min="0" max="100000000" value="${Number(status[key]??defaultValue)}" required></div>`).join('')}</fieldset><fieldset><legend>Тарифы модели · центы USD / 1 млн токенов</legend><p>Текущие ставки: ${escapeHtml(JSON.stringify(status.model_pricing||null))}. Для новой модели задайте проверенную цену перед запросом.</p><label><input name="price_override" type="checkbox"> Изменить цены выбранной модели</label>${[['input','Входные'],['cached','Кешированные'],['output','Выходные']].map(([key,label])=>`<div class="form-row"><label>${label}</label><input class="input" name="price_${key}" type="number" min="0" max="10000000" value="${Number(status.model_pricing?.[key]??0)}"></div>`).join('')}</fieldset><div class="row-actions"><button type="submit" class="btn">Сохранить настройки</button><button type="button" class="btn danger" data-ai-remove-key>Удалить сохранённый ключ</button></div><p class="muted">Требуются system:write и integrations:secrets, CSRF и повторное подтверждение в Identity Center.</p></form></details>` : ''
  const html = `<div class="card"><p>OpenAI: <strong>${status.configured ? 'подключён' : 'не настроен'}</strong> · Модель: <code>${escapeHtml(status.model || '—')}</code> · Ключ: ${escapeHtml(status.credential_source || 'none')}</p>${!status.configured ? '<p class="muted">Администратор должен настроить OpenAI API-ключ.</p>' : ''}</div><div class="ai-layout"><aside class="card"><button class="btn" data-ai-new ${f.aiBusy ? 'disabled' : ''}>Новый диалог</button><div class="ai-threads">${rows || '<p class="muted">Диалогов нет</p>'}</div></aside><section class="card"><h2>${escapeHtml(title)}</h2><div class="ai-messages" aria-live="polite">${content || '<p class="muted">Отправьте первое сообщение.</p>'}<article class="ai-bubble assistant" data-ai-stream style="display:${f.aiBusy ? '' : 'none'}"><strong>ChatGPT</strong><div>Генерация…</div></article></div><form data-ai-chat class="form">${toolPanel}<textarea class="input" rows="4" maxlength="12000" name="message" placeholder="Сообщение для ChatGPT" ${!status.configured || f.aiBusy ? 'disabled' : ''} required></textarea><button class="btn" type="submit" ${!status.configured || f.aiBusy ? 'disabled' : ''}>${f.aiBusy ? 'Генерация…' : 'Отправить'}</button></form></section></div>${actionsView}${automationView()}${costView}${settingsView}`
  // Handlers are bound after render in bindForms; no raw model output is interpreted as HTML.
  return html
}

async function aiRefresh() {
  await Promise.all([loadSilent('aiStatus','/ai/status'),loadSilent('aiConversations','/ai/conversations'),loadSilent('aiTools','/ai/tools'),loadSilent('aiActions','/ai/actions'),loadSilent('aiAutomations','/ai/automations'),...(integrationScope('system:read')?[loadSilent('aiCosts','/ai/costs?days=30')]:[])])
}

function bindAi() {
  if (state.route !== 'ai') return
  const f = state.forms
  const automationGuard=async(fn)=>{try{await fn()}catch(e){setNotice('error',e.message,e.payload)}}
  const refreshRuns=async()=>{if(f.aiAutomationSelected)f.aiAutomationRuns=asArray(await api('/ai/automations/'+encodeURIComponent(f.aiAutomationSelected)+'/runs'))}
  document.querySelector('[data-ai-automation-refresh]')?.addEventListener('click',()=>automationGuard(async()=>{await loadSilent('aiAutomations','/ai/automations');await refreshRuns();render()}))
  document.querySelector('[data-ai-automation-new]')?.addEventListener('click',()=>{f.aiAutomationSelected=null;f.aiAutomationEditing=true;f.aiAutomationRuns=[];render()})
  document.querySelector('[data-ai-automation-cancel]')?.addEventListener('click',()=>{f.aiAutomationEditing=false;render()})
  document.querySelectorAll('[data-ai-automation-edit]').forEach(el=>el.addEventListener('click',()=>automationGuard(async()=>{
    f.aiAutomationSelected=el.dataset.aiAutomationEdit;f.aiAutomationEditing=true;await refreshRuns();render()
  })))
  document.querySelectorAll('[data-ai-automation-toggle]').forEach(el=>el.addEventListener('click',()=>automationGuard(async()=>{
    const item=asArray(state.cache.aiAutomations).find(x=>x.id===el.dataset.aiAutomationToggle);if(!item)return
    await api('/ai/automations/'+encodeURIComponent(item.id),{method:'PATCH',body:JSON.stringify({revision:item.revision,enabled:!item.enabled})})
    await loadSilent('aiAutomations','/ai/automations');await refreshRuns();render()
  })))
  document.querySelectorAll('[data-ai-automation-delete]').forEach(el=>el.addEventListener('click',()=>automationGuard(async()=>{
    const id=el.dataset.aiAutomationDelete;if(!confirm('Удалить AI-сценарий?'))return
    await api('/ai/automations/'+encodeURIComponent(id),{method:'DELETE'})
    if(f.aiAutomationSelected===id){f.aiAutomationSelected=null;f.aiAutomationEditing=false;f.aiAutomationRuns=[]}
    await loadSilent('aiAutomations','/ai/automations');render()
  })))
  document.querySelectorAll('[data-ai-automation-run]').forEach(el=>el.addEventListener('click',()=>automationGuard(async()=>{
    const id=el.dataset.aiAutomationRun;await api('/ai/automations/'+encodeURIComponent(id)+'/run',{method:'POST',body:'{}'})
    f.aiAutomationSelected=id;await refreshRuns();render();setNotice('ok','AI-задание добавлено в очередь.')
  })))
  document.querySelectorAll('[data-ai-automation-open-result]').forEach(el=>el.addEventListener('click',()=>automationGuard(async()=>{
    f.aiSelected=el.dataset.aiAutomationOpenResult;f.aiMessages=asArray(await api('/ai/conversations/'+encodeURIComponent(f.aiSelected)));await loadSilent('aiConversations','/ai/conversations');render()
  })))
  document.querySelector('[data-ai-automation-refresh-runs]')?.addEventListener('click',()=>automationGuard(async()=>{await refreshRuns();render()}))
  document.querySelector('[data-ai-automation-form]')?.addEventListener('submit',event=>{event.preventDefault();automationGuard(async()=>{
    const form=new FormData(event.currentTarget);const selected=asArray(state.cache.aiAutomations).find(x=>x.id===f.aiAutomationSelected)
    const body={title:String(form.get('title')),prompt:String(form.get('prompt')),interval_minutes:Number(form.get('interval_minutes')),enabled:form.has('enabled')}
    if(selected)await api('/ai/automations/'+encodeURIComponent(selected.id),{method:'PATCH',body:JSON.stringify({...body,revision:selected.revision})})
    else {const result=dataOf(await api('/ai/automations',{method:'POST',body:JSON.stringify(body)}));f.aiAutomationSelected=result.id}
    f.aiAutomationEditing=false;await loadSilent('aiAutomations','/ai/automations');await refreshRuns();render();setNotice('ok','AI-сценарий сохранён.')
  })})
  document.querySelector('[data-ai-tools-toggle]')?.addEventListener('change',e=>{f.aiToolsEnabled=!!e.target.checked;if(!f.aiToolsEnabled)f.aiActionsEnabled=false;render()})
  document.querySelector('[data-ai-actions-toggle]')?.addEventListener('change',e=>{f.aiActionsEnabled=!!e.target.checked})
  const guard = async (action) => { try { await action() } catch(e) { setNotice('error',e.message,e.payload) } }
  document.querySelector('[data-ai-actions-refresh]')?.addEventListener('click',()=>guard(async()=>{await loadSilent('aiActions','/ai/actions');render()}))
  document.querySelectorAll('[data-ai-action-approve]').forEach(el=>el.addEventListener('click',()=>guard(async()=>{
    if(f.aiActionsBusy)return
    const a=asArray(state.cache.aiActions).find(x=>x.id===el.dataset.aiActionApprove)
    if(!a||!confirm('Применить действие '+a.action+'?'))return
    let confirmPublished=false
    if(a.before?.status==='published'){
      if(!confirm('Подтвердите изменение опубликованной страницы.'))return
      confirmPublished=true
    }
    f.aiActionsBusy=true
    try{
      await api('/ai/actions/'+encodeURIComponent(a.id)+'/approve',{method:'POST',body:JSON.stringify({confirm:true,confirm_published:confirmPublished})})
      await aiRefresh();render();setNotice('ok','AI-действие применено.')
    }finally{f.aiActionsBusy=false}
  })))
  document.querySelectorAll('[data-ai-action-reject]').forEach(el=>el.addEventListener('click',()=>guard(async()=>{
    if(f.aiActionsBusy)return
    const id=el.dataset.aiActionReject
    await api('/ai/actions/'+encodeURIComponent(id)+'/reject',{method:'POST',body:'{}'})
    await aiRefresh();render();setNotice('ok','Предложение отклонено.')
  })))
  document.querySelector('[data-ai-new]')?.addEventListener('click', () => { if(f.aiBusy)return;f.aiSelected=null;f.aiMessages=[];render() })
  document.querySelectorAll('[data-ai-open]').forEach(el => el.addEventListener('click', () => guard(async()=>{
    if(f.aiBusy)return
    f.aiSelected=el.dataset.aiOpen
    f.aiMessages=asArray(await api('/ai/conversations/'+encodeURIComponent(f.aiSelected)))
    render()
  })))
  document.querySelectorAll('[data-ai-rename]').forEach(el => el.addEventListener('click', () => guard(async()=>{
    const current=asArray(state.cache.aiConversations).find(c=>c.id===el.dataset.aiRename)
    const title=prompt('Название диалога',current?.title||'')
    if (!title)return
    await api('/ai/conversations/'+encodeURIComponent(el.dataset.aiRename),{method:'PATCH',body:JSON.stringify({title})})
    await aiRefresh();render()
  })))
  document.querySelectorAll('[data-ai-delete]').forEach(el => el.addEventListener('click', () => guard(async()=>{
    if(f.aiBusy||!confirm('Удалить диалог и историю?'))return
    await api('/ai/conversations/'+encodeURIComponent(el.dataset.aiDelete),{method:'DELETE'})
    if(f.aiSelected===el.dataset.aiDelete){f.aiSelected=null;f.aiMessages=[]}
    await aiRefresh();render()
  })))
  document.querySelector('[data-ai-costs-refresh]')?.addEventListener('click',()=>guard(async()=>{
    await loadSilent('aiCosts','/ai/costs?days=30'); render()
  }))
  document.querySelector('[data-ai-settings]')?.addEventListener('submit', event => {event.preventDefault();guard(async()=>{
    const form=new FormData(event.currentTarget)
    const body={model:String(form.get('model')),max_output_tokens:Number(form.get('max_output_tokens')),monthly_token_limit:Number(form.get('monthly_token_limit'))}
    for(const key of ['global_monthly_limit_cents','global_daily_limit_cents','user_monthly_limit_cents','user_daily_limit_cents']) body[key]=Number(form.get(key))
    if(form.get('price_override'))body.model_pricing={input:Number(form.get('price_input')),cached:Number(form.get('price_cached')),output:Number(form.get('price_output'))}
    if(form.get('api_key'))body.api_key=String(form.get('api_key'))
    await api('/ai/settings',{method:'PUT',body:JSON.stringify(body)})
    await aiRefresh();setNotice('ok','Настройки OpenAI сохранены.')
  })})
  document.querySelector('[data-ai-remove-key]')?.addEventListener('click',()=>guard(async()=>{
    if(!confirm('Удалить сохранённый OpenAI API-ключ?'))return
    await api('/ai/settings',{method:'PUT',body:'{"remove_key":true}'})
    await aiRefresh();setNotice('ok','Сохранённый ключ удалён.')
  }))
  document.querySelector('[data-ai-chat]')?.addEventListener('submit',event=>{event.preventDefault();guard(async()=>{
    if(f.aiBusy)return
    const textarea=event.currentTarget.querySelector('[name="message"]')
    const message=(textarea?.value||'').trim()
    if(!message)return
    f.aiBusy=true;f.aiMessages.push({id:'pending-'+Date.now(),role:'user',content:message});render()
    let answer='';let done=false
    try{
      const headers={'Accept':'text/event-stream','Content-Type':'application/json'}
      if(state.csrf)headers['X-CSRF-Token']=state.csrf
      const response=await fetch(API_BASE+'/ai/chat/stream',{method:'POST',credentials:'same-origin',cache:'no-store',headers,body:JSON.stringify({conversation_id:f.aiSelected,message,tools_enabled:f.aiToolsEnabled&&asArray(state.cache.aiTools).length>0,actions_enabled:f.aiToolsEnabled&&f.aiActionsEnabled&&integrationScope('content:write')})})
      if(!response.ok||!response.body){const err=await response.json().catch(()=>({}));throw new Error(err?.error?.message||'OpenAI HTTP '+response.status)}
      const reader=response.body.getReader(),decoder=new TextDecoder('utf-8');let pending=''
      const handle=frame=>{
        const lines=frame.split('\n'),event=lines.find(line=>line.startsWith('event:'))?.slice(6).trim()
        const raw=lines.filter(line=>line.startsWith('data:')).map(line=>line.slice(5).trimStart()).join('\n')
        if(!raw)return
        const data=JSON.parse(raw)
        if(event==='tool'){const node=document.querySelector('[data-ai-stream]');if(node){node.style.display='';node.querySelector('div').textContent='Инструмент: '+String(data.name||'')+' ('+String(data.status||'')+')'}}
        if(event==='delta'){
          answer+=String(data.text||'')
          const node=document.querySelector('[data-ai-stream]')
          if(node){node.style.display='';node.querySelector('div').textContent=answer}
        }
        if(event==='error')throw new Error(data.message||'AI-ответ завершился ошибкой.')
        if(event==='done'){done=true;f.aiSelected=data.conversation_id}
      }
      while(true){const packet=await reader.read();if(packet.done)break;pending+=decoder.decode(packet.value,{stream:true});pending=pending.replace(/\r\n/g,'\n');let at;while((at=pending.indexOf('\n\n'))>=0){handle(pending.slice(0,at));pending=pending.slice(at+2)}}
      if(!done)throw new Error('Поток завершился до сохранения ответа.')
      f.aiMessages=asArray(await api('/ai/conversations/'+encodeURIComponent(f.aiSelected)))
      await aiRefresh()
    } catch(e){f.aiMessages.pop();setNotice('error',e.message,e.payload);throw e}
    finally{f.aiBusy=false;render()}
  })})
}

function dashboardView() {
  const dashboard = dataOf(state.cache.dashboard, {})
  const cards = dashboard.cards || []
  const bootstrap = dataOf(state.cache.bootstrap, {})
  return `<div class="grid cards">${cards.map((card) => `<div class="card kpi"><div class="kpi-label">${escapeHtml(card.label)}</div><div class="kpi-value">${escapeHtml(card.value)}</div><span class="badge ${card.status || 'ok'}">${escapeHtml(card.status || 'ok')}</span></div>`).join('') || '<div class="card"><h2>Admin API</h2><p>Нажмите “Обновить”, чтобы получить dashboard.</p></div>'}</div><div class="grid two"><div class="card"><h2>Продукт</h2><p>Production static SPA с CRUD-экранами, авторизацией, route guards и обработкой 401/403.</p><div class="status-line"><span class="badge ok">static assets</span><span class="badge ok">Bearer auth</span><span class="badge ok">CMS CRUD</span><span class="badge ok">media upload</span></div></div><div class="card"><h2>Bootstrap</h2><pre class="json">${escapeHtml(JSON.stringify(bootstrap.app || bootstrap, null, 2))}</pre></div></div>`
}

function contentTypesView() {
  const items = asArray(state.cache.contentTypes)
  const rows = items.map((item) => `<tr><td><strong>${escapeHtml(item.handle)}</strong><br><span class="muted">${escapeHtml(item.name || '')}</span></td><td>${escapeHtml((item.fields || []).length)} fields</td><td>${escapeHtml(item.updated_at || item.created_at || '')}</td><td class="row-actions"><button class="btn small secondary" data-edit-content-type="${escapeHtml(item.handle)}">Редактировать</button><button class="btn small ghost" data-schema-content-type="${escapeHtml(item.handle)}">Schema</button><button class="btn small danger" data-delete-content-type="${escapeHtml(item.handle)}">Удалить</button></td></tr>`)
  return `<div class="grid two"><div class="card"><h2>Типы контента</h2>${table(['Handle', 'Поля', 'Дата', 'Действия'], rows)}</div><div class="card"><h2>Создать / изменить тип</h2><form class="form" data-form="content-type"><div class="form-row"><label>Handle</label><input class="input" name="handle" value="${escapeHtml(state.forms.contentType.handle)}" required></div><div class="form-row"><label>Название</label><input class="input" name="name" value="${escapeHtml(state.forms.contentType.name)}" required></div><div class="form-row"><label>Fields JSON</label><textarea class="input code" name="fields">${escapeHtml(state.forms.contentType.fields)}</textarea></div><div class="toolbar"><button class="btn" type="submit">Сохранить</button><button class="btn secondary" type="button" data-action="reset-content-type">Очистить</button></div></form></div></div>`
}

// Schema-driven editor. All values are transferred as JSON through the existing API.
function editorField(def, value, change) {
  const wrap=document.createElement('label');wrap.className='form-row';
  const label=document.createElement('span');label.textContent=(def.label||def.handle)+(def.required?' *':'');wrap.append(label)
  const multiline=['textarea','richtext','json'].includes(def.type), multi=['media','relation'].includes(def.type)&&(def.settings?.multiple||Array.isArray(value));
  const input=document.createElement(multiline||multi?'textarea':'input');input.className='input';
  if(def.type==='boolean'){input.type='checkbox';input.checked=value===true;input.addEventListener('change',()=>change(input.checked));}
  else {
    if(!multiline&&!multi)input.type=({number:'number',date:'date',datetime:'text'})[def.type]||'text';
    if(def.type==='number')input.step='any';
    input.value=def.type==='json'?(value==null?'':JSON.stringify(value,null,2)):multi?(Array.isArray(value)?value.join('\n'):value||''):value??'';
    input.addEventListener('input',()=>{try{const v=input.value;change(def.type==='number'?(v===''?null:Number(v)):def.type==='json'?(v===''?null:JSON.parse(v)):multi?v.split(/\s+/).filter(Boolean):v===''?null:v);input.setCustomValidity('')}catch{input.setCustomValidity('Некорректный JSON')}});
    if(def.type==='richtext'){
      const bar=document.createElement('div');bar.className='toolbar';for(const [name,tag] of [['Жирный','strong'],['Курсив','em'],['Абзац','p'],['H2','h2'],['Цитата','blockquote']]){const b=document.createElement('button');b.type='button';b.className='btn small secondary';b.textContent=name;b.onclick=()=>{const a=input.selectionStart,z=input.selectionEnd;input.setRangeText(`<${tag}>${input.value.slice(a,z)}</${tag}>`,a,z,'select');input.dispatchEvent(new Event('input',{bubbles:true}));input.focus()};bar.append(b)}wrap.append(bar);
    }
  }
  wrap.append(input);
  if(def.type==='media'){
    const box=document.createElement('div');box.className='toolbar';const q=document.createElement('input');q.className='input';q.placeholder='Поиск медиа';const select=document.createElement('select');select.className='input';const search=document.createElement('button');search.type='button';search.className='btn secondary small';search.textContent='Найти';const pick=document.createElement('button');pick.type='button';pick.className='btn secondary small';pick.textContent='Выбрать';const msg=document.createElement('span');search.onclick=async()=>{try{const list=asArray(await api('/media?limit=50&visibility=public&q='+encodeURIComponent(q.value)));select.replaceChildren(...list.map(m=>new Option(m.title||m.filename,m.id)));msg.textContent=list.length?'':'Нет результатов'}catch(e){msg.textContent=e.message}};pick.onclick=()=>{if(!select.value)return;input.value=multi?[...new Set([...input.value.split(/\s+/).filter(Boolean),select.value])].join('\n'):select.value;input.dispatchEvent(new Event('input',{bubbles:true}))};box.append(q,search,select,pick,msg);wrap.append(box)
  }
  return wrap;
}
function mountEntryEditor(form) {
  if(!form)return;
  const area=form.elements.fields, seo=form.elements.seo, type=asArray(state.cache.contentTypes).find(t=>t.handle===state.forms.entryType);if(!type)return;
  let data,seoData;try{data=jsonParse(area.value,{});seoData=jsonParse(seo.value,{})}catch{return;}
  if(!data||typeof data!=='object'||Array.isArray(data))return;
  const pane=document.createElement('div');pane.className='editor-fields';area.parentElement.before(pane);area.parentElement.hidden=true;seo.parentElement.hidden=true;form.querySelector('[data-media-picker]')?.setAttribute('hidden','');
  const draftKey=()=>`ce-editor-draft:${state.identity?.id||state.identity?.user_id||state.identity?.email||'session'}:${state.forms.entryType}:${state.forms.entry.id||'new'}`;
  const persistDraft=()=>{try{sessionStorage.setItem(draftKey(),JSON.stringify(state.forms.entry))}catch{}};
  const restore=document.createElement('button');restore.type='button';restore.className='btn small secondary';restore.textContent='Восстановить локальный черновик';restore.onclick=()=>{try{const value=JSON.parse(sessionStorage.getItem(draftKey())||'null');if(value&&confirm('Восстановить несохранённые изменения в редакторе?')){state.forms.entry=value;render()}}catch{}};if(sessionStorage.getItem(draftKey()))form.prepend(restore);
  form.dataset.draftKey=draftKey();
  const sync=(persist=true)=>{area.value=JSON.stringify(data,null,2);seo.value=JSON.stringify(seoData,null,2);state.forms.entry.fields=area.value;state.forms.entry.seo=seo.value;if(persist)persistDraft();};
  const reserved=new Set(['path','parent_id','seo_title','meta_description','canonical_url','seo_noindex']);
  for(const def of type.fields||[]){if(reserved.has(def.handle))continue;
    if(def.type!=='blocks'){pane.append(editorField(def,data[def.handle],v=>{data[def.handle]=v;sync()}));continue;}
    const blockPane=document.createElement('div');blockPane.className='editor-blocks';pane.append(blockPane);if(!Array.isArray(data[def.handle]))data[def.handle]=[];
    const list=data[def.handle], defs=type.blocks||[];
    const draw=()=>{blockPane.replaceChildren();const h=document.createElement('h3');h.textContent=def.label;blockPane.append(h);
      list.forEach((b,i)=>{const card=document.createElement('section');card.className='card editor-block';const name=document.createElement('strong');const bd=defs.find(d=>d.handle===b.handle);name.textContent=`${i+1}. ${bd?.name||b.handle}`;const bar=document.createElement('div');bar.className='toolbar';for(const [text,op] of [['↑',()=>{if(i>0)[list[i-1],list[i]]=[list[i],list[i-1]]}],['↓',()=>{if(i<list.length-1)[list[i+1],list[i]]=[list[i],list[i+1]]}],['Удалить',()=>list.splice(i,1)]]){const btn=document.createElement('button');btn.type='button';btn.className='btn small secondary';btn.textContent=text;btn.onclick=()=>{op();sync();draw()};bar.append(btn)}card.append(name,bar);b.data??={};for(const f of bd?.fields||[])card.append(editorField(f,b.data[f.handle],v=>{b.data[f.handle]=v;sync()}));blockPane.append(card)});
      const select=document.createElement('select');select.className='input';for(const d of defs)select.add(new Option(d.name,d.handle));const add=document.createElement('button');add.type='button';add.className='btn secondary';add.textContent='Добавить блок';add.disabled=!defs.length;add.onclick=()=>{if(!select.value)return;list.push({handle:select.value,data:{}});sync();draw()};const bar=document.createElement('div');bar.className='toolbar';bar.append(select,add);blockPane.append(bar);};draw();
  }
  const seoPane=document.createElement('div');seoPane.className='card';const heading=document.createElement('h3');heading.textContent='SEO';seoPane.append(heading);for(const [key,label,t,field] of [['title','Title','text','seo_title'],['description','Description','textarea','meta_description'],['canonical','Canonical URL','text','canonical_url'],['noindex','Noindex','boolean','seo_noindex']])if(type.fields.some(f=>f.handle===field))seoPane.append(editorField({handle:key,label,type:t},seoData[key],v=>{seoData[key]=v;sync()}));pane.append(seoPane);
  const advanced=document.createElement('button');advanced.type='button';advanced.className='btn small ghost';advanced.textContent='JSON (расширенный режим)';advanced.onclick=()=>{area.parentElement.hidden=!area.parentElement.hidden;seo.parentElement.hidden=area.parentElement.hidden;pane.hidden=!area.parentElement.hidden};form.append(advanced);
  // Keep an unsaved draft in memory, including on validation errors/re-render. Never silently publish it.
  form.addEventListener('input',()=>{const fd=new FormData(form);for(const k of ['title','slug','status','locale','path','fields','seo'])state.forms.entry[k]=String(fd.get(k)||'');state.forms.entry.parent=String(fd.get('parent_id')||'');persistDraft();});sync(false);
}
function mountFieldBuilder(form){
  if(!form)return;const area=form.elements.fields;let rows;try{rows=jsonParse(area.value,[])}catch{return;}if(!Array.isArray(rows))return;const box=document.createElement('div');box.className='editor-fields';area.parentElement.before(box);area.parentElement.hidden=true;
  const sync=()=>{area.value=JSON.stringify(rows,null,2);state.forms.contentType.fields=area.value};
  const draw=()=>{box.replaceChildren();rows.forEach((r,i)=>{const row=document.createElement('div');row.className='card';for(const [key,label] of [['handle','Handle'],['label','Название']])row.append(editorField({handle:key,label,type:'text'},r[key],v=>{r[key]=v||'';sync()}));const select=document.createElement('select');select.className='input';for(const t of ['text','textarea','richtext','number','boolean','date','datetime','media','relation','blocks','json'])select.add(new Option(t,t));select.value=r.type;select.onchange=()=>{r.type=select.value;sync()};row.append(select,editorField({handle:'required',label:'Обязательное',type:'boolean'},r.required,v=>{r.required=v;sync()}),editorField({handle:'localized',label:'Переводимое',type:'boolean'},r.localized,v=>{r.localized=v;sync()}));const del=document.createElement('button');del.type='button';del.className='btn danger small';del.textContent='Удалить поле';del.onclick=()=>{if(!confirm('Удалить поле из схемы? Данные могут стать недоступны.'))return;rows.splice(i,1);sync();draw()};row.append(del);box.append(row)});const add=document.createElement('button');add.type='button';add.className='btn secondary';add.textContent='Добавить поле';add.onclick=()=>{rows.push({handle:'',label:'',type:'text',required:false});sync();draw()};box.append(add)};draw();
  for(const [key,label] of [['localized','Локализация'],['revisionable','История ревизий']])box.before(editorField({handle:key,label,type:'boolean'},state.forms.contentType[key],v=>{state.forms.contentType[key]=v}));
  const blocks=document.createElement('details');const title=document.createElement('summary');title.textContent='Определения блоков (JSON)';const input=document.createElement('textarea');input.className='input code';input.value=state.forms.contentType.blocks||'[]';input.addEventListener('input',()=>{state.forms.contentType.blocks=input.value});blocks.append(title,input);form.append(blocks);
  const advanced=document.createElement('button');advanced.type='button';advanced.className='btn small ghost';advanced.textContent='JSON';advanced.onclick=()=>{area.parentElement.hidden=!area.parentElement.hidden;box.hidden=!area.parentElement.hidden};form.append(advanced);
  form.addEventListener('input',()=>{state.forms.contentType.handle=form.elements.handle.value;state.forms.contentType.name=form.elements.name.value;state.forms.contentType.fields=area.value});
}

function bindContentTypes() {
  const form = document.querySelector('[data-form="content-type"]')
  mountFieldBuilder(form)
  if (form) form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const fd = new FormData(form)
    const handle = String(fd.get('handle') || '').trim()
    try {
      const payload = { handle, name: String(fd.get('name') || '').trim(), fields: jsonParse(String(fd.get('fields') || '[]'), []),blocks:jsonParse(state.forms.contentType.blocks||'[]',[]),localized:!!state.forms.contentType.localized,revisionable:state.forms.contentType.revisionable!==false }
      const exists = asArray(state.cache.contentTypes).some((item) => item.handle === handle)
      await api(`/content-types${exists ? '/' + encodeURIComponent(handle) : ''}`, { method: exists ? 'PUT' : 'POST', body: JSON.stringify(payload) })
      await routeLoad('content-types');setNotice('ok', 'Тип контента сохранён.')
    } catch (error) { setNotice('error', error.message, error.payload) }
  })
  document.querySelectorAll('[data-edit-content-type]').forEach((button) => button.addEventListener('click', () => {
    const item = asArray(state.cache.contentTypes).find((x) => x.handle === button.dataset.editContentType)
    if (item) { state.forms.contentType = { handle: item.handle, name: item.name || item.handle, fields: JSON.stringify(item.fields || [], null, 2),blocks:JSON.stringify(item.blocks||[],null,2),localized:!!item.localized,revisionable:item.revisionable!==false }; render() }
  }))
  document.querySelectorAll('[data-schema-content-type]').forEach((button) => button.addEventListener('click', async () => {
    try { const payload = await api(`/content-types/${encodeURIComponent(button.dataset.schemaContentType)}/schema`); setNotice('ok', 'JSON Schema получена.', payload) } catch (error) { setNotice('error', error.message, error.payload) }
  }))
  document.querySelectorAll('[data-delete-content-type]').forEach((button) => button.addEventListener('click', async () => {
    if (!confirm('Удалить тип контента?')) return
    try { await api(`/content-types/${encodeURIComponent(button.dataset.deleteContentType)}`, { method: 'DELETE' }); setNotice('ok', 'Тип удалён.'); await routeLoad('content-types') } catch (error) { setNotice('error', error.message, error.payload) }
  }))
  document.querySelector('[data-action="reset-content-type"]')?.addEventListener('click', () => { state.forms.contentType = defaultContentType(); render() })
}

function contentView() {
  const types = asArray(state.cache.contentTypes)
  if (types.length && !types.find((t) => t.handle === state.forms.entryType)) state.forms.entryType = types[0].handle
  const entries = asArray(state.cache.entries)
  const parents = asArray(state.cache.pageParents)
  const byId = new Map(parents.map(p => [p.id,p]))
  const depth = e => { let d=0, parent=e.data?.parent_id; const seen=new Set([e.id]); while(parent && byId.has(parent) && !seen.has(parent) && d<128){seen.add(parent);d++;parent=byId.get(parent).data?.parent_id} return d }
  const sorted = state.forms.entryType === 'pages' ? [...entries].sort((a,b) => { const path = e => { const parts=[e.slug]; const seen=new Set([e.id]); let id=e.data?.parent_id; while(id&&byId.has(id)&&!seen.has(id)){seen.add(id);const p=byId.get(id);parts.unshift(p.slug);id=p.data?.parent_id}return e.locale+'/'+parts.join('/') };return path(a).localeCompare(path(b))}) : entries
  const parentOptions = `<option value="">Корень</option>${state.forms.entry.parent&&!parents.some(p=>p.id===state.forms.entry.parent)?`<option selected value="${escapeHtml(state.forms.entry.parent)}">${escapeHtml(state.forms.entry.parent)} (текущий родитель)</option>`:''}${parents.filter(p=>p.id!==state.forms.entry.id).map(p=>`<option value="${escapeHtml(p.id)}" ${p.id===state.forms.entry.parent?'selected':''}>${escapeHtml('— '.repeat(depth(p))+p.title+' ['+p.locale+']')}</option>`).join('')}`
  const rows = sorted.map((entry) => `<tr><td><strong>${escapeHtml((state.forms.entryType==='pages'?'— '.repeat(depth(entry)):'')+(entry.title || entry.slug || entry.id))}</strong><br><span class="muted">${escapeHtml(entry.id)}</span></td><td><span class="badge ${entry.status === 'published' ? 'ok' : entry.status === 'archived' ? 'warn' : ''}">${escapeHtml(entry.status || 'draft')}</span></td><td>${escapeHtml(entry.locale || '')}</td><td>${escapeHtml(entry.public_path || (entry.status==='published'?'Нет публичного URL':entry.data?.path || entry.slug || ''))}</td><td class="row-actions"><button class="btn small secondary" data-edit-entry="${escapeHtml(entry.id)}">Редактировать</button><button class="btn small ghost" data-entry-action="publish" data-id="${escapeHtml(entry.id)}">Publish</button><button class="btn small ghost" data-entry-action="unpublish" data-id="${escapeHtml(entry.id)}">Unpublish</button><button class="btn small ghost" data-entry-action="archive" data-id="${escapeHtml(entry.id)}">Archive</button><button class="btn small ghost" data-entry-action="revisions" data-id="${escapeHtml(entry.id)}">Revisions</button><button class="btn small danger" data-entry-action="delete" data-id="${escapeHtml(entry.id)}">Удалить</button></td></tr>`)
  return `<div class="card"><div class="toolbar"><select class="input" style="max-width:220px" data-entry-type>${types.map((t) => `<option value="${escapeHtml(t.handle)}" ${t.handle === state.forms.entryType ? 'selected' : ''}>${escapeHtml(t.name || t.handle)}</option>`).join('')}</select><select class="input" style="max-width:170px" data-entry-status>${['all','draft','published','archived'].map(v=>`<option value="${v}" ${state.forms.entryStatus===v?'selected':''}>${v==='all'?'Все':v}</option>`).join('')}</select><input class="input" style="max-width:260px" data-entry-q placeholder="Поиск" value="${escapeHtml(state.forms.entrySearch)}"><button class="btn secondary" data-action="filter-entries">Фильтр</button></div></div><div class="grid two"><div class="card"><h2>Записи</h2>${table(['Запись', 'Статус', 'Locale', 'Slug/path', 'Действия'], rows)}<div class="toolbar"><button class="btn secondary" data-entry-page="prev" ${state.forms.entryOffset===0?'disabled':''}>Назад</button><span>${state.forms.entryOffset+1}–${state.forms.entryOffset+entries.length} / ${state.cache.entries?.meta?.total??entries.length}</span><button class="btn secondary" data-entry-page="next" ${state.forms.entryOffset+entries.length>=(state.cache.entries?.meta?.total??entries.length)?'disabled':''}>Далее</button></div></div><div class="card"><h2>Редактор записи</h2><form class="form" data-form="entry"><div class="split"><div class="form-row"><label>ID для update</label><input class="input" name="id" value="${escapeHtml(state.forms.entry.id)}" placeholder="оставить пустым для create"></div><div class="form-row"><label>Status</label><select class="input" name="status"><option value="draft" ${state.forms.entry.status==='draft'?'selected':''}>draft</option><option value="published" ${state.forms.entry.status==='published'?'selected':''}>published</option><option value="archived" ${state.forms.entry.status==='archived'?'selected':''}>archived</option></select></div></div><div class="split"><div class="form-row"><label>Title</label><input class="input" name="title" value="${escapeHtml(state.forms.entry.title)}"></div><div class="form-row"><label>Slug</label><input class="input" name="slug" value="${escapeHtml(state.forms.entry.slug)}"></div></div><div class="split"><div class="form-row"><label>Locale</label><input class="input" name="locale" value="${escapeHtml(state.forms.entry.locale)}"></div><div class="form-row"><label>Path (корневая страница)</label><input class="input" name="path" value="${escapeHtml(state.forms.entry.path)}"></div></div>${state.forms.entryType==='pages'?`<div class="form-row"><label>Родительская страница (тот же язык)</label><div class="toolbar"><input class="input" data-parent-q placeholder="Поиск родителя" value="${escapeHtml(state.forms.parentSearch)}"><button type="button" class="btn secondary" data-parent-search>Найти</button></div><select class="input" name="parent_id">${parentOptions}</select><small>URL дочерней страницы определяется путём родителя и slug.</small></div>`:''}<div class="form-row"><label>Fields JSON</label><textarea class="input code" name="fields">${escapeHtml(state.forms.entry.fields)}</textarea></div><div class="form-row" data-media-picker><label>Выбор медиа для поля контента</label><select class="input" data-picker-field>${(types.find(t=>t.handle===state.forms.entryType)?.fields||[]).filter(f=>f.type==='media').map(f=>`<option value="${escapeHtml(f.handle)}">${escapeHtml(f.label||f.handle)}</option>`).join('')}</select><div class="toolbar"><input class="input" data-picker-q placeholder="Поиск медиа"><button class="btn secondary" type="button" data-picker-search>Найти</button></div><select class="input" data-picker-result></select><button class="btn secondary" type="button" data-picker-apply>Вставить UUID в выбранное поле</button></div><div class="form-row"><label>SEO JSON</label><textarea class="input code" name="seo">${escapeHtml(state.forms.entry.seo)}</textarea></div><div class="toolbar"><button class="btn" type="submit">Сохранить</button><button class="btn secondary" type="button" data-action="reset-entry">Очистить</button><button class="btn ghost" type="button" data-action="preview-entry">Preview token</button></div></form></div></div>${(()=>{
    const editor=state.forms.aiEditor;
    const currentType=types.find(t=>t.handle===state.forms.entryType);
    const selectedEntry=!!state.forms.entry.id;
    const targetFields=[{handle:'title',label:'Заголовок'},...(currentType?.fields||[]).filter(f=>['text','textarea','richtext'].includes(f.type))];
    let currentData={};try{currentData=jsonParse(state.forms.entry.fields,{})}catch{}
    if(selectedEntry)for(const field of currentType?.fields||[]){
      if(field.type!=='blocks'||!Array.isArray(currentData[field.handle]))continue;
      currentData[field.handle].forEach((block,index)=>{
        const blockDef=(currentType?.blocks||[]).find(b=>b.handle===block?.handle);
        for(const sub of blockDef?.fields||[])if(['text','textarea','richtext'].includes(sub.type))
          targetFields.push({handle:`${field.handle}|${index}|${sub.handle}`,label:`Блок ${index+1} / ${sub.label||sub.handle}`});
      });
    }
    const proposal=editor.proposal;
    const original=proposal?`<div><strong>До</strong><pre class="ai-editor-text">${escapeHtml(proposal.original||'(пусто)')}</pre></div>`:'';
    const proposed=proposal?`<div><strong>После</strong><pre class="ai-editor-text">${escapeHtml(proposal.proposed)}</pre></div>`:'';
    return `<section class="card" style="margin-top:18px"><h2>AI Content Editor</h2><p class="muted">Предложение не изменяет CMS до подтверждения. Изменения сохранённой записи защищены номером ревизии.</p><form data-ai-editor-form class="form"><div class="split"><div class="form-row"><label>Поле</label><select class="input" name="field">${targetFields.map(f=>`<option value="${escapeHtml(f.handle)}" ${editor.field===f.handle?'selected':''}>${escapeHtml(f.label||f.handle)}</option>`).join('')}</select></div><div class="form-row"><label>Операция</label><select class="input" name="mode" ${selectedEntry?'':'disabled'}>${[['generate','Генерация'],['rewrite','Переписать'],['shorten','Сократить'],['expand','Расширить'],['proofread','Исправить'],['tone','Изменить тон']].map(([id,label])=>`<option value="${id}" ${editor.mode===id?'selected':''}>${label}</option>`).join('')}</select></div></div><div class="form-row"><label>Задание / тема</label><textarea class="input" name="instructions" rows="3" maxlength="3000" placeholder="Что написать или изменить">${escapeHtml(editor.instructions)}</textarea></div>${selectedEntry?`<div class="form-row"><label>Выделенный фрагмент (необязательно; точная строка из сохранённого поля)</label><textarea class="input" name="selection" rows="2" maxlength="12000">${escapeHtml(editor.selection)}</textarea></div>`:''}<button class="btn secondary" type="submit" ${editor.busy?'disabled':''}>${editor.busy?'Генерация…':'Создать предложение'}</button></form>${proposal?`<div class="ai-editor-compare">${original}${proposed}</div><p class="muted">Токены: ${Number(proposal.usage?.input_tokens||0)} / ${Number(proposal.usage?.output_tokens||0)}. Сначала проверьте текст.</p><div class="toolbar"><button class="btn" data-ai-editor-accept ${editor.busy?'disabled':''}>${selectedEntry?'Применить как новую ревизию':'Перенести в черновик'}</button><button class="btn secondary" data-ai-editor-reject>Отклонить</button></div>`:''}</section>`;
  })()}`
}


function diagnosticsView(){
  if(!integrationScope('system:read'))return '<div class="notice error">Недостаточно прав для диагностики.</div>'
  const f=state.forms.diagnostics,cached=state.cache.aiDiagnostics
  const r=dataOf(cached)
  const notice=cached?.error?`<p class="notice error">${escapeHtml(cached.error.message)}</p>`:''
  const windows=[[15,'15 минут'],[60,'1 час'],[360,'6 часов'],[1440,'24 часа']]
  const choices=windows.map(([n,t])=>`<option value="${n}" ${n===f.window?'selected':''}>${t}</option>`).join('')
  const head=`<section class="card"><h2>AI CMS Diagnostics</h2><p>Проверка БД, storage, очередей, scheduler, кеша, поиска, backup и worker. Без изменения конфигурации. OpenAI получает только очищенные показатели без секретов и строк логов.</p><div class="toolbar"><label>Период runtime-событий <select class="input" data-diagnostics-window>${choices}</select></label><button class="btn" data-diagnostics-refresh ${f.busy?'disabled':''}>Проверить CMS</button><button class="btn secondary" data-diagnostics-export ${r?'':'disabled'}>Экспорт JSON</button></div>${notice}</section>`
  if(!r)return head+'<div class="card">Отчёт загружается…</div>'
  const summary=`<div class="card"><h2>Состояние: ${escapeHtml(r.status)}</h2><p>Проверено: ${escapeHtml(r.generated_at)} · замечаний ${Number(r.summary?.total||0)} · критичных ${Number(r.summary?.critical||0)} · высоких ${Number(r.summary?.high||0)}</p><p>Накопленные HTTP-метрики: ${Number(r.http?.requests||0)} запросов, ${Number(r.http?.errors_5xx||0)} ошибок 5xx, среднее ${escapeHtml(r.http?.average_ms??'—')} мс.</p></div>`
  const rows=Object.entries(r.checks||{}).map(([key,v])=>`<tr><td>${escapeHtml(key)}</td><td>${v.ok?'OK':'Проблема'}</td><td>${escapeHtml(v.driver||'—')}</td><td>${escapeHtml(v.latency_ms??'—')} мс</td><td>${v.counts?`pending ${Number(v.counts.pending)} / failed ${Number(v.counts.failed)}`:v.documents!==undefined?`Документов: ${Number(v.documents)}`:v.age_seconds!==undefined?`Backup: ${escapeHtml(v.age_seconds??'—')} с`:'—'}</td></tr>`)
  const checks=`<div class="card"><h2>Проверки сервисов</h2>${table(['Сервис','Статус','Драйвер','Задержка','Данные'],rows)}</div>`
  const issues=(r.issues||[]).map(i=>`<tr><td>${escapeHtml(i.severity)}</td><td><strong>${escapeHtml(i.title)}</strong><br>${escapeHtml(i.reason)}<br><small>${escapeHtml(i.code)}</small></td><td>${escapeHtml(i.action)}${i.command?`<pre>${escapeHtml(i.command)}</pre>`:''}</td></tr>`)
  const problems=`<div class="card"><h2>Рекомендации</h2>${issues.length?table(['Приоритет','Обнаружено','Проверка и устранение'],issues):'<p>Текущие проверки не выявили отклонений.</p>'}</div>`
  const focus=[`<option value="">Все проблемы</option>`,...(r.issues||[]).map(i=>`<option value="${escapeHtml(i.code)}" ${f.focus===i.code?'selected':''}>${escapeHtml(i.title)}</option>`)].join('')
  const ai=`<section class="card"><h2>Объяснение ChatGPT</h2><p>OpenAI получает только обезличенные коды, статусы и агрегаты. Никаких автоматических действий.</p><div class="toolbar"><label>Фокус <select class="input" data-diagnostics-focus>${focus}</select></label><button class="btn" data-diagnostics-explain ${f.busy?'disabled':''}>${f.busy?'Анализ…':'Объяснить через ChatGPT'}</button></div>${f.explanation?`<pre style="white-space:pre-wrap;overflow-wrap:anywhere">${escapeHtml(f.explanation)}</pre><p>Токены: ${Number(f.usage?.input_tokens||0)} / ${Number(f.usage?.output_tokens||0)}</p>`:''}<p class="muted">Предлагаемые команды требуют отдельной проверки оператором и здесь не выполняются.</p></section>`
  return head+summary+checks+problems+ai
}
function bindDiagnostics(){
  if(state.route!=='diagnostics')return
  const f=state.forms.diagnostics
  document.querySelector('[data-diagnostics-window]')?.addEventListener('change',e=>{f.window=Number(e.target.value);f.explanation=null;routeLoad('diagnostics').then(render)})
  document.querySelector('[data-diagnostics-focus]')?.addEventListener('change',e=>{f.focus=e.target.value;f.explanation=null})
  document.querySelector('[data-diagnostics-refresh]')?.addEventListener('click',()=>{f.explanation=null;f.usage=null;routeLoad('diagnostics').then(render)})
  document.querySelector('[data-diagnostics-explain]')?.addEventListener('click',async()=>{
    if(f.busy)return;f.busy=true;render()
    try{
      const value=dataOf(await api('/ai/diagnostics/explain',{method:'POST',body:JSON.stringify({window_minutes:f.window,focus:f.focus})}))
      state.cache.aiDiagnostics={data:value.diagnostics};f.explanation=value.explanation;f.usage=value.usage
      setNotice('ok','AI объяснение получено. Система не изменялась.')
    }catch(e){setNotice('error',e.message)}finally{f.busy=false;render()}
  })
  document.querySelector('[data-diagnostics-export]')?.addEventListener('click',()=>{
    const value=dataOf(state.cache.aiDiagnostics);if(!value)return
    const blob=new Blob([JSON.stringify({diagnostics:value,explanation:f.explanation,usage:f.usage},null,2)],{type:'application/json'})
    const url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download='cajeer-ai-diagnostics.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000)
  })
}

function seoView() {
  const f=state.forms.seo,types=asArray(state.cache.seoTypes),entries=asArray(state.cache.seoEntries),r=f.audit;
  const heading=(label,options)=>`<label>${label}<select class="input" name="${options.name}">${options.values.map(([value,title])=>`<option value="${escapeHtml(value)}" ${options.current===value?'selected':''}>${escapeHtml(title)}</option>`).join('')}</select></label>`;
  const typesHtml=heading('Тип контента',{name:'type',values:types.map(t=>[t.handle,(t.name||t.handle)+' / '+t.handle]),current:f.type});
  const entriesHtml=heading('Материал',{name:'entry',values:[['','Выберите страницу'],...entries.map(e=>[e.id,(e.title||e.id)+' · '+e.status+' · '+e.locale])],current:f.entry});
  const reports=f.batch?.reports||[];
  const batch=reports.length?`<section class="card"><h2>Проверено опубликованных страниц: ${reports.length} из ${Number(f.batch?.meta?.available||reports.length)}</h2>${table(['Материал','Балл','Замечания',''],reports.map(x=>`<tr><td>${escapeHtml(x.title)}</td><td>${Number(x.score)}/100</td><td>${x.issues.length}</td><td><button class="btn small secondary" data-seo-open="${escapeHtml(x.entry_id)}">Открыть</button></td></tr>`))}</section>`:'';
  const issues=r?.issues||[];
  const report=r?`<section class="card"><h2>SEO-аудит: ${escapeHtml(r.title)} · ${Number(r.score)}/100</h2><p>Ревизия ${Number(r.revision)} · ${escapeHtml(r.status)} · URL ${escapeHtml(r.path||'(нет)')}</p><p><strong>SEO Title:</strong> ${escapeHtml(r.seo.title||'(нет)')}<br><strong>Description:</strong> ${escapeHtml(r.seo.description||'(нет)')}<br><strong>Canonical:</strong> ${escapeHtml(r.seo.canonical_url||'(автоматический)')}<br><strong>Noindex:</strong> ${r.seo.noindex?'Да':'Нет'}</p><p>Слов: ${Number(r.stats.words)} · H1: ${Number(r.stats.headings.h1)} · H2: ${Number(r.stats.headings.h2)} · Проверено внутренних ссылок: ${Number(r.stats.internal_links_checked)}</p>${issues.length?table(['Приоритет','Замечание','Действие'],issues.map(i=>`<tr><td>${escapeHtml(i.severity)}</td><td>${escapeHtml(i.message)}</td><td>${escapeHtml(i.action)}</td></tr>`)):'<p>Выбранные проверки не выявили замечаний.</p>'}<p class="muted">${escapeHtml(r.notes)}</p><div class="card"><h3>AI-рекомендация</h3><form data-seo-propose class="form">${heading('Поле',{name:'field',values:[['seo_title','SEO Title'],['meta_description','Meta Description']],current:f.field})}<label>Ключевая фраза / рекомендации<textarea class="input" name="instructions" rows="3" maxlength="1500">${escapeHtml(f.instructions)}</textarea></label><button class="btn secondary" ${f.busy?'disabled':''} type="submit">${f.busy?'Выполняется…':'Сгенерировать предложение'}</button></form>${f.proposal?`<h3>Сравнение: ревизия ${Number(f.proposal.expected_revision)}</h3><div class="ai-editor-compare"><div><strong>Текущее значение</strong><pre class="ai-editor-text">${escapeHtml(f.proposal.original||'(пусто)')}</pre></div><div><strong>Предложение</strong><pre class="ai-editor-text">${escapeHtml(f.proposal.proposed)}</pre></div></div><p class="muted">Токены: ${Number(f.proposal.usage?.input_tokens||0)} / ${Number(f.proposal.usage?.output_tokens||0)}</p><div class="toolbar"><button class="btn" data-seo-accept>Применить как новую ревизию</button><button class="btn secondary" data-seo-reject>Отклонить</button></div>`:''}</div></section>`:'';
  const sources=integrationScope('analytics:read')?dataOf(state.cache.analyticsHub,{items:[]}).items||[]:[];
  const usable=sources.filter(x=>['search_console','webmaster','ga4','metrika','matomo','plausible','cf_web'].includes(x.provider));
  const selected=f.insightSource||usable[0]?.id||'', insight=f.insights;
  const insightRows=insight?.items||[];
  const insightPanel=`<section class="card"><h2>AI SEO & Content Insights</h2><p>Сопоставление сохранённой статистики и SEO-проверок по публичному URL. Изменение метрик не доказывает SEO-причину.</p>
    <form class="form" data-seo-insights><label>Источник<select class="input" name="connection_id">${usable.map(x=>`<option value="${escapeHtml(x.id)}" ${selected===x.id?'selected':''}>${escapeHtml(x.name)} · ${escapeHtml(x.provider)}</option>`).join('')}</select></label>
    <label>Начало периода (необязательно)<input class="input" type="date" name="from" value="${escapeHtml(f.insightFrom)}"></label>
    <label>Конец периода<input class="input" type="date" name="to" value="${escapeHtml(f.insightTo)}"></label>
    <button class="btn" ${!usable.length||f.busy?'disabled':''}>Сопоставить страницы</button></form>
    ${!usable.length?'<p>Нет доступных аналитических источников или права analytics:read.</p>':''}
    ${insight?`<p>${insight.available?`Проверено строк: ${Number(insight.examined||0)} · Рекомендаций: ${Number(insight.matched||0)} · Сравнение: ${escapeHtml(insight.comparison_status||'—')}`:escapeHtml(insight.message||'Нет данных')}</p>${(insight.warnings||[]).map(w=>`<p class="notice">${escapeHtml(w)}</p>`).join('')}
      ${insightRows.length?table(['Страница','SEO','Статистика','Рекомендации'],insightRows.map(row=>`<tr><td>${escapeHtml(row.title)}<p class="muted">${escapeHtml(row.path)}</p></td><td>${Number(row.score)}/100</td><td>${escapeHtml(row.metric)}: ${Number(row.current)}<p>${row.change_percent===null?'Сравнение недоступно':escapeHtml(row.change_percent)+'%'}</p></td><td><p>Приоритет ${Number(row.priority)}</p>${row.recommendations.map(rec=>`<p>${escapeHtml(rec.action)}</p>${rec.field?`<button class="btn secondary small" data-seo-insight-propose="${escapeHtml(row.entry_id)}" data-field="${escapeHtml(rec.field)}" ${f.busy?'disabled':''}>Подготовить ${escapeHtml(rec.field)}</button>`:''}`).join('')}</td></tr>`)):'<p>Нет совпавших опубликованных страниц с рекомендациями.</p>'}
      <p class="muted">${escapeHtml(insight.notes||'')}</p>`:''}
    ${f.insightProposal?`<div class="card"><h3>Предпросмотр: ${escapeHtml(f.insightItem?.path||'')} · ревизия ${Number(f.insightProposal.expected_revision)}</h3><div class="ai-editor-compare"><div><strong>Было</strong><pre>${escapeHtml(f.insightProposal.original||'(пусто)')}</pre></div><div><strong>Предложено</strong><pre>${escapeHtml(f.insightProposal.proposed)}</pre></div></div><div class="toolbar"><button class="btn" data-seo-insight-approve>Подтвердить и сохранить</button><button class="btn secondary" data-seo-insight-reject>Отклонить</button></div></div>`:''}
  </section>`;
  return `<section class="card"><h2>Анализ страниц и SEO-оптимизация</h2><p>Проверка читает сохранённые данные и маршруты CMS, без запросов к внешним сайтам. AI-рекомендации не применяются автоматически.</p><div class="toolbar"><div style="min-width:200px">${typesHtml}</div><div style="min-width:250px">${entriesHtml}</div><button class="btn" data-seo-audit ${f.busy?'disabled':''}>${f.busy?'Проверка…':'Анализировать'}</button><button class="btn secondary" data-seo-batch ${f.busy?'disabled':''}>Проверить опубликованные (до 30)</button></div></section>${batch}${report}${insightPanel}`;
}
function bindSeo() {
  if(state.route!=='seo')return;
  const f=state.forms.seo;
  document.querySelector('[name="type"]')?.addEventListener('change',async e=>{
    f.type=e.target.value;f.entry='';f.audit=null;f.proposal=null;f.batch=null;
    await routeLoad('seo');render();
  });
  document.querySelector('[name="entry"]')?.addEventListener('change',e=>{f.entry=e.target.value;f.audit=null;f.proposal=null;render()});
  const audit=async id=>{
    if(!id||f.busy)return;f.busy=true;f.proposal=null;
    try{f.audit=dataOf(await api('/ai/seo/audit?'+new URLSearchParams({type:f.type,entry_id:id})));f.entry=id;setNotice('ok','SEO-аудит завершён.')}
    catch(e){setNotice('error',e.message,e.payload)}finally{f.busy=false;render()}
  };
  document.querySelector('[data-seo-audit]')?.addEventListener('click',()=>audit(f.entry));
  document.querySelectorAll('[data-seo-open]').forEach(b=>b.addEventListener('click',()=>audit(b.dataset.seoOpen)));
  document.querySelector('[data-seo-batch]')?.addEventListener('click',async()=>{
    if(f.busy)return;f.busy=true;
    try{f.batch=dataOf(await api('/ai/seo/batch?'+new URLSearchParams({type:f.type,limit:'30'})));setNotice('ok','Пакетная проверка завершена.')}
    catch(e){setNotice('error',e.message,e.payload)}finally{f.busy=false;render()}
  });
  document.querySelector('[data-seo-insights]')?.addEventListener('submit',async e=>{
    e.preventDefault();if(f.busy)return
    const fields=new FormData(e.currentTarget),id=String(fields.get('connection_id')||''),from=String(fields.get('from')||''),to=String(fields.get('to')||'')
    if(Boolean(from)!==Boolean(to))return setNotice('error','Выберите обе даты или оставьте пустыми.')
    f.busy=true;f.insightSource=id;f.insightFrom=from;f.insightTo=to;f.insightProposal=null;f.insightItem=null
    try{f.insights=dataOf(await api('/ai/seo/insights?'+new URLSearchParams({connection_id:id,limit:'40',...(from?{from,to}:{})})));setNotice('ok','Сопоставление сохранённых отчётов и SEO-аудита завершено.')}
    catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  });
  document.querySelectorAll('[data-seo-insight-propose]').forEach(button=>button.addEventListener('click',async()=>{
    if(f.busy)return
    const item=f.insights?.items?.find(x=>x.entry_id===button.dataset.seoInsightPropose)
    if(!item)return
    f.busy=true;f.insightItem=item;f.insightProposal=null
    try{
      const response=dataOf(await api('/ai/seo/insights/proposals',{method:'POST',body:JSON.stringify({
        connection_id:f.insightSource||dataOf(state.cache.analyticsHub,{items:[]}).items?.[0]?.id,
        type:item.type,entry_id:item.entry_id,field:button.dataset.field,
        ...(f.insightFrom?{from:f.insightFrom,to:f.insightTo}:{})
      })}));f.insightProposal=response.proposal;setNotice('ok','Предложение готово. Проверьте перед применением.')
    }catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  }));
  document.querySelector('[data-seo-insight-reject]')?.addEventListener('click',()=>{f.insightProposal=null;f.insightItem=null;render()});
  document.querySelector('[data-seo-insight-approve]')?.addEventListener('click',async()=>{
    if(f.busy||!f.insightProposal||!f.insightItem)return
    if(!confirm('SEO-метаданные опубликованной страницы изменятся. Подтвердить новую ревизию?'))return
    f.busy=true
    try{
      await api('/ai/editor/proposals/'+encodeURIComponent(f.insightProposal.id)+'/apply',{method:'POST',body:JSON.stringify({confirm_published:true})})
      f.insightProposal=null;f.insightItem=null
      f.insights=dataOf(await api('/ai/seo/insights?'+new URLSearchParams({connection_id:f.insightSource,limit:'40',...(f.insightFrom?{from:f.insightFrom,to:f.insightTo}:{})})))
      setNotice('ok','SEO-правка сохранена как новая ревизия.')
    }catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  });
  const form=document.querySelector('[data-seo-propose]');
  form?.addEventListener('input',()=>{f.proposal=null;f.field=form.elements.field.value;f.instructions=form.elements.instructions.value});
  form?.addEventListener('submit',async e=>{
    e.preventDefault();if(f.busy||!f.audit)return;f.busy=true;f.proposal=null;
    try{f.proposal=dataOf(await api('/ai/seo/proposals',{method:'POST',body:JSON.stringify({type:f.type,entry_id:f.audit.entry_id,field:f.field,instructions:f.instructions})}));setNotice('ok','SEO-предложение готово. Проверьте перед сохранением.')}
    catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  });
  document.querySelector('[data-seo-reject]')?.addEventListener('click',()=>{f.proposal=null;render()});
  document.querySelector('[data-seo-accept]')?.addEventListener('click',async()=>{
    if(!f.proposal||!f.audit||f.busy)return;
    const published=f.audit.status==='published';
    if(published&&!confirm('SEO-данные опубликованной страницы будут изменены на сайте. Продолжить?'))return;
    f.busy=true;
    try{
      await api('/ai/editor/proposals/'+encodeURIComponent(f.proposal.id)+'/apply',{method:'POST',body:JSON.stringify({confirm_published:published})});
      f.proposal=null;f.audit=dataOf(await api('/ai/seo/audit?'+new URLSearchParams({type:f.type,entry_id:f.entry})));
      await loadSilent('seoEntries','/content/'+encodeURIComponent(f.type)+'?status=all&limit=200&offset=0');
      setNotice('ok','SEO-правка применена как новая ревизия.');
    }catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  });
}

function bindContent() {
  const aiEditor=state.forms.aiEditor;
  const aiForm=document.querySelector('[data-ai-editor-form]');
  aiForm?.addEventListener('input',()=>{
    const data=new FormData(aiForm);
    aiEditor.field=String(data.get('field')||'title');aiEditor.mode=String(data.get('mode')||'generate');
    aiEditor.instructions=String(data.get('instructions')||'');aiEditor.selection=String(data.get('selection')||'');
    aiEditor.proposal=null;
  });
  aiForm?.addEventListener('submit',event=>{event.preventDefault();if(aiEditor.busy)return;
    const fd=new FormData(aiForm);aiEditor.field=String(fd.get('field')||'title');
    aiEditor.mode=String(fd.get('mode')||'generate');aiEditor.instructions=String(fd.get('instructions')||'');
    aiEditor.selection=String(fd.get('selection')||'');aiEditor.busy=true;aiEditor.proposal=null;
    const [field,blockIndex,blockField]=aiEditor.field.split('|');
    const body={type:state.forms.entryType,entry_id:state.forms.entry.id||null,field,
      ...(blockIndex!==undefined?{block_index:Number(blockIndex),block_field:blockField}:{}),
      mode:state.forms.entry.id?aiEditor.mode:'generate',instructions:aiEditor.instructions,
      selection:state.forms.entry.id?aiEditor.selection:'',locale:state.forms.entry.locale||'ru'};
    (async()=>{try{
      const proposal=dataOf(await api('/ai/editor/proposals',{method:'POST',body:JSON.stringify(body)}));
      aiEditor.proposal=proposal;setNotice('ok','AI-предложение готово к проверке.')
    }catch(e){setNotice('error',e.message,e.payload)}finally{aiEditor.busy=false;render()}})();
  });
  document.querySelector('[data-ai-editor-reject]')?.addEventListener('click',()=>{aiEditor.proposal=null;render()});
  document.querySelector('[data-ai-editor-accept]')?.addEventListener('click',()=>(async()=>{
    const proposal=aiEditor.proposal;if(!proposal||aiEditor.busy)return;
    if(!state.forms.entry.id){
      try{
        if(proposal.field==='title')state.forms.entry.title=proposal.proposed;
        else{const data=jsonParse(state.forms.entry.fields,{});data[proposal.field]=proposal.proposed;state.forms.entry.fields=JSON.stringify(data,null,2)}
        aiEditor.proposal=null;render();setNotice('ok','AI-текст перенесён в черновик. Для сохранения нажмите «Сохранить».')
      }catch(e){setNotice('error',e.message)}return;
    }
    const published=state.forms.entry.status==='published';
    if(published&&!confirm('Изменение опубликованного материала станет доступно посетителям. Продолжить?'))return;
    aiEditor.busy=true;
    try{
      const result=dataOf(await api('/ai/editor/proposals/'+encodeURIComponent(proposal.id)+'/apply',{
        method:'POST',body:JSON.stringify({confirm_published:published})}));
      const entry=result.entry;
      state.forms.entry={...defaultEntry(),id:entry.id,revision:entry.revision,title:entry.title,slug:entry.slug,status:entry.status,locale:entry.locale,
        path:entry.data?.path||'',parent:entry.data?.parent_id||'',fields:JSON.stringify(entry.data||{},null,2),seo:JSON.stringify({title:entry.data?.seo_title||'',description:entry.data?.meta_description||'',canonical:entry.data?.canonical_url||'',noindex:entry.data?.seo_noindex||false},null,2)};
      aiEditor.proposal=null;await routeLoad('content');setNotice('ok','AI-правка сохранена как новая ревизия.')
    }catch(e){setNotice('error',e.message,e.payload)}finally{aiEditor.busy=false;render()}
  })());

  const entryForm=document.querySelector('[data-form="entry"]');mountEntryEditor(entryForm)
  if(entryForm && state.forms.entry.id){const button=document.createElement('button');button.type='button';button.className='btn secondary';button.textContent='Переводы';button.onclick=async()=>{try{const base=`/content/${encodeURIComponent(state.forms.entryType)}/${encodeURIComponent(state.forms.entry.id)}/localizations`;const list=asArray(await api(base));const panel=document.createElement('div');panel.className='card';for(const item of list){const link=document.createElement('button');link.type='button';link.className='btn small secondary';link.textContent=item.locale+' · '+item.title;link.onclick=()=>{state.forms.entry={...defaultEntry(),id:item.id,revision:item.revision,title:item.title,slug:item.slug,status:item.status,locale:item.locale,path:item.data?.path||'',parent:item.data?.parent_id||'',fields:JSON.stringify(item.data),seo:JSON.stringify({title:item.data?.seo_title,description:item.data?.meta_description,canonical:item.data?.canonical_url,noindex:item.data?.seo_noindex})};render()};panel.append(link)}const locale=document.createElement('input');locale.className='input';locale.placeholder='en';const add=document.createElement('button');add.type='button';add.className='btn';add.textContent='Создать черновик перевода';add.onclick=async()=>{try{const original=dataOf(await api(`/content/${encodeURIComponent(state.forms.entryType)}/${encodeURIComponent(state.forms.entry.id)}`));const d={...original.data};if('parent_id'in d)d.parent_id=null;const item=dataOf(await api(base,{method:'POST',body:JSON.stringify({title:original.title,slug:original.slug,status:'draft',locale:locale.value,data:d})}));state.forms.entry={...defaultEntry(),id:item.id,revision:item.revision,title:item.title,slug:item.slug,status:item.status,locale:item.locale,path:item.data?.path||'',fields:JSON.stringify(item.data)};await routeLoad('content');render()}catch(e){setNotice('error',e.message,e.payload)}};panel.append(locale,add);entryForm.before(panel);button.disabled=true}catch(e){setNotice('error',e.message,e.payload)}};entryForm.append(button)}
  document.querySelector('[data-picker-search]')?.addEventListener('click',async()=>{try{const q=document.querySelector('[data-picker-q]').value;const r=await api('/media?limit=50&visibility=public&q='+encodeURIComponent(q));const select=document.querySelector('[data-picker-result]');select.replaceChildren();for(const item of asArray(r)){const option=document.createElement('option');option.value=item.id;option.textContent=item.title||item.filename;select.append(option)}}catch(e){setNotice('error',e.message,e.payload)}})
  document.querySelector('[data-picker-apply]')?.addEventListener('click',()=>{const field=document.querySelector('[data-picker-field]').value,id=document.querySelector('[data-picker-result]').value,area=document.querySelector('[data-form="entry"] [name=fields]');if(!field||!id)return;try{const value=jsonParse(area.value,{});value[field]=id;area.value=JSON.stringify(value,null,2)}catch(e){setNotice('error',e.message)}})

  document.querySelector('[data-entry-type]')?.addEventListener('change', (e) => { state.forms.entryType = e.target.value; state.forms.entryOffset=0; state.forms.entry=defaultEntry(); routeLoad('content').then(render) })
  document.querySelector('[data-entry-status]')?.addEventListener('change', (e) => { state.forms.entryStatus = e.target.value })
  document.querySelector('[data-action="filter-entries"]')?.addEventListener('click', () => { state.forms.entrySearch = document.querySelector('[data-entry-q]')?.value || ''; state.forms.entryOffset=0; routeLoad('content').then(render) })
  document.querySelectorAll('[data-entry-page]').forEach(b=>b.addEventListener('click',()=>{state.forms.entryOffset=Math.max(0,state.forms.entryOffset+(b.dataset.entryPage==='next'?50:-50));routeLoad('content').then(render)}))
  document.querySelector('[data-parent-search]')?.addEventListener('click',async()=>{
    state.forms.parentSearch=document.querySelector('[data-parent-q]').value;
    const select=document.querySelector('[name=parent_id]');const current=select.value;
    try{const result=await api('/content/pages?status=all&limit=50&q='+encodeURIComponent(state.forms.parentSearch));
      select.replaceChildren(new Option('Корень',''));for(const p of asArray(result))if(p.id!==state.forms.entry.id)select.add(new Option(p.title+' ['+p.locale+']',p.id));
      if(current&&!Array.from(select.options).some(o=>o.value===current))select.add(new Option(current+' (текущий родитель)',current));select.value=current;
    }catch(e){setNotice('error',e.message,e.payload)}
  })
  document.querySelector('[data-form="entry"]')?.addEventListener('submit', async (event) => {
    event.preventDefault()
    const savingForm=event.currentTarget;const fd = new FormData(savingForm);const submit=savingForm.querySelector('[type=submit]');if(submit.disabled)return;submit.disabled=true;
    try {
      const id = String(fd.get('id') || '').trim()
      const data = jsonParse(String(fd.get('fields') || '{}'), {})
      const seo = jsonParse(String(fd.get('seo') || '{}'), {})
      const fieldDefs = (asArray(state.cache.contentTypes).find(t=>t.handle===state.forms.entryType)?.fields || [])
      const hasField = key => fieldDefs.some(f=>f.handle===key)
      if (hasField('path')) data.path=String(fd.get('path') || '') || null
      if (hasField('parent_id') && fd.has('parent_id')) data.parent_id=String(fd.get('parent_id') || '') || null
      for (const [from,to] of Object.entries({title:'seo_title',description:'meta_description',canonical:'canonical_url',noindex:'seo_noindex'})) if(hasField(to) && Object.hasOwn(seo,from)) data[to]=seo[from]
      const payload = { title: String(fd.get('title') || '').trim(), slug: String(fd.get('slug') || '').trim(), status: String(fd.get('status') || 'draft'), locale: String(fd.get('locale') || 'ru'), data }
      if(id && state.forms.entry.revision)payload.expected_revision=state.forms.entry.revision
      const saved=dataOf(await api(`/content/${encodeURIComponent(state.forms.entryType)}${id ? '/' + encodeURIComponent(id) : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) }))
      try{sessionStorage.removeItem(savingForm.dataset.draftKey||'')}catch{}
      Object.assign(state.forms.entry,{id:saved.id,revision:saved.revision,title:saved.title,slug:saved.slug,status:saved.status,locale:saved.locale,fields:JSON.stringify(saved.data,null,2)})
      await routeLoad('content');setNotice('ok', 'Запись сохранена.')
    } catch (error) { setNotice('error', error.message, error.payload) } finally {submit.disabled=false}
  })
  document.querySelectorAll('[data-edit-entry]').forEach((button) => button.addEventListener('click', () => {
    const entry = asArray(state.cache.entries).find((x) => String(x.id) === button.dataset.editEntry)
    if (!entry) return
    state.forms.aiEditor.proposal=null;state.forms.aiEditor.mode='rewrite';state.forms.aiEditor.selection='';
    state.forms.entry = { revision:entry.revision, id: entry.id || '', title: entry.title || '', slug: entry.slug || '', status: entry.status || 'draft', locale: entry.locale || 'ru', path: entry.data?.path || '', parent: entry.data?.parent_id || '', fields: JSON.stringify(entry.data || {}, null, 2), seo: JSON.stringify({title:entry.data?.seo_title||'',description:entry.data?.meta_description||'',canonical:entry.data?.canonical_url||'',noindex:entry.data?.seo_noindex||false}, null, 2) }
    render()
  }))
  document.querySelectorAll('[data-entry-action]').forEach((button) => button.addEventListener('click', async () => {
    const action = button.dataset.entryAction
    const id = button.dataset.id
    try {
      if (action === 'delete') { if (!confirm('Удалить запись?')) return; await api(`/content/${encodeURIComponent(state.forms.entryType)}/${encodeURIComponent(id)}`, { method: 'DELETE' }) }
      else if (action === 'revisions') { const payload = await api(`/content/${encodeURIComponent(state.forms.entryType)}/${encodeURIComponent(id)}/revisions`); showRevisionPanel(id,asArray(payload)); return }
      else await api(`/content/${encodeURIComponent(state.forms.entryType)}/${encodeURIComponent(id)}/${action}`, { method: 'POST', body: '{}' })
      await routeLoad('content');setNotice('ok', 'Действие выполнено.')
    } catch (error) { setNotice('error', error.message, error.payload) }
  }))
  document.querySelector('[data-action="reset-entry"]')?.addEventListener('click', () => { state.forms.entry = defaultEntry();state.forms.aiEditor.proposal=null;state.forms.aiEditor.mode='generate';render() })
  document.querySelector('[data-action="preview-entry"]')?.addEventListener('click', async () => {
    if (!state.forms.entry.id) return setNotice('error', 'Для preview нужен сохранённый ID записи.')
    try { const payload = await api('/cms/preview-token', { method: 'POST', body: JSON.stringify({ type: state.forms.entryType, id: state.forms.entry.id }) }); const link=dataOf(payload)?.preview_url; if(link && String(link).startsWith('/preview/')){const a=document.createElement('a');a.href=link;a.target='_blank';a.rel='noopener noreferrer';a.className='btn secondary';a.textContent='Открыть предпросмотр';document.querySelector('[data-form="entry"]')?.append(a);a.focus()}else setNotice('ok','Preview token создан.',payload) } catch (error) { setNotice('error', error.message, error.payload) }
  })
}

function showRevisionPanel(id, revisions){
  document.querySelector('[data-revision-panel]')?.remove();const panel=document.createElement('section');panel.dataset.revisionPanel='1';panel.className='card';const title=document.createElement('h2');title.textContent='История изменений';panel.append(title);
  for(const r of revisions){const row=document.createElement('div');row.className='toolbar';const text=document.createElement('span');text.textContent=`#${r.revision} · ${r.created_at||''} · ${r.title||''}`;const b=document.createElement('button');b.className='btn secondary small';b.textContent='Восстановить';b.onclick=async()=>{if(!confirm('Восстановить эту ревизию как черновик? Опубликованная страница будет снята с публикации.'))return;try{await api(`/content/${encodeURIComponent(state.forms.entryType)}/${encodeURIComponent(id)}/revisions/${r.revision}/restore`,{method:'POST',body:'{}'});state.forms.entry=defaultEntry();await routeLoad('content');setNotice('ok','Ревизия восстановлена.')}catch(e){setNotice('error',e.message,e.payload)}};row.append(text,b);panel.append(row)}
  document.querySelector('[data-form="entry"]')?.before(panel);panel.scrollIntoView({block:'center'});
}
function mediaQuery() {
  const f = state.forms, q = new URLSearchParams({ q: f.mediaQ || '', mime: f.mediaMime || '', visibility: f.mediaVisibility || '', limit: '30', offset: String(f.mediaOffset || 0) })
  if (f.mediaFolder !== undefined && f.mediaFolder !== '*') q.set('folder', f.mediaFolder)
  return '/media?' + q
}
function mediaMetaFields(item = {}) {
  return `<div class="form-row"><label>Название</label><input class="input" name="title" value="${escapeHtml(item.title || '')}"></div><div class="form-row"><label>Alt</label><input class="input" name="alt" value="${escapeHtml(item.alt || '')}"></div><div class="form-row"><label>Подпись</label><textarea class="input" name="caption">${escapeHtml(item.caption || '')}</textarea></div><div class="form-row"><label>Папка (например Фото/2026)</label><input class="input" name="folder" value="${escapeHtml(item.folder || '')}"></div><div class="form-row"><label>Теги (через запятую)</label><input class="input" name="tags" value="${escapeHtml((item.tags || []).join(', '))}"></div><div class="form-row"><label>Коллекции (через запятую)</label><input class="input" name="collections" value="${escapeHtml((item.collections || []).join(', '))}"></div>`
}
function mediaView() {
  const items=asArray(state.cache.media), meta=state.cache.media?.meta || {}, f=state.forms, offset=f.mediaOffset || 0
  const folders=[['*','Все папки'],['','Корневая папка'],...(meta.folders||[]).filter(Boolean).map(x=>[x,x])]
  const selected=items.find(x=>x.id===f.mediaSelected)
  const img = item => String(item.mime_type).startsWith('image/') ? `<img loading="lazy" src="${escapeHtml(item.variants?.thumbnail?.url || item.url)}" alt="${escapeHtml(item.alt || '')}">` : `<span>${escapeHtml(item.mime_type || 'Файл')}</span>`
  const records=items.map(item=>`<div class="media-item"><button type="button" class="media-preview" data-select-media="${escapeHtml(item.id)}">${img(item)}</button><div class="media-body"><strong>${escapeHtml(item.title || item.filename)}</strong><p class="muted">${escapeHtml(item.folder || '/')} · ${escapeHtml(item.visibility||'public')} · ${Math.round((item.size || 0)/1024)} KB ${item.width ? `· ${item.width} × ${item.height}` : ''}</p><div class="toolbar"><button class="btn small" data-select-media="${escapeHtml(item.id)}">Открыть</button><button class="btn small secondary" data-copy-media="${escapeHtml(item.url)}">URL</button></div></div></div>`).join('')
  return `<div class="grid two"><section class="card"><h2>Медиабиблиотека</h2><form class="form" data-form="media-filter"><input class="input" name="q" placeholder="Поиск по названию, тегу, коллекции" value="${escapeHtml(f.mediaQ || '')}"><div class="split"><select class="input" name="folder">${folders.map(([v,t])=>`<option value="${escapeHtml(v)}" ${v===(f.mediaFolder??'*')?'selected':''}>${escapeHtml(t)}</option>`).join('')}</select><select class="input" name="mime">${['','image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain','application/zip'].map(v=>`<option ${v===(f.mediaMime||'')?'selected':''} value="${escapeHtml(v)}">${escapeHtml(v||'Все форматы')}</option>`).join('')}</select></div><select class="input" name="visibility">${['','public','private'].map(v=>`<option value="${v}" ${v===(f.mediaVisibility||'')?'selected':''}>${v||'Все уровни доступа'}</option>`).join('')}</select><div class="toolbar"><button class="btn" type="submit">Найти</button><button class="btn secondary" type="button" data-media-layout>${f.mediaList?'Плитки':'Список'}</button></div></form><div class="media-grid ${f.mediaList?'media-list':''}">${records||'<div class="empty">Файлов нет.</div>'}</div><div class="toolbar"><button class="btn secondary" data-media-page="-1" ${offset===0?'disabled':''}>Назад</button><span>${meta.total?offset+1:0}–${Math.min(offset+30,meta.total||0)} / ${meta.total||0}</span><button class="btn secondary" data-media-page="1" ${offset+30>=(meta.total||0)?'disabled':''}>Далее</button></div><button class="btn secondary" data-media-diagnostics>Проверить файлы</button></section><section class="card"><h2>Загрузка</h2><form class="form" data-form="media"><div class="media-drop" data-media-drop>Перетащите файлы сюда или выберите их ниже</div><input class="input" type="file" name="file" multiple required><progress data-media-progress value="0" max="1"></progress>${mediaMetaFields({folder:f.mediaFolder==='*'?'':f.mediaFolder})}<label>Доступ<select class="input" name="visibility"><option value="public">Публичный</option><option value="private">Приватный (только авторизованные)</option></select></label><label><input type="checkbox" name="deduplicate" value="true"> Использовать существующий файл с таким же SHA256</label><button class="btn" type="submit">Загрузить</button></form>${selected?`<hr><h2>${escapeHtml(selected.filename)}</h2><div class="media-preview">${img(selected)}</div><p class="muted">UUID: ${escapeHtml(selected.id)}<br>SHA256: ${escapeHtml(selected.checksum)}</p><form class="form" data-form="media-edit">${mediaMetaFields(selected)}<button class="btn" type="submit">Сохранить</button></form><form class="form" data-form="media-replace"><label>Заменить оригинал (тот же формат и расширение)</label><input class="input" type="file" name="file" required><button class="btn secondary" type="submit">Заменить</button></form><div class="toolbar"><a class="btn secondary" href="${escapeHtml(selected.url)}" target="_blank" rel="noopener">Скачать</a><button class="btn secondary" data-media-sign="local">Ссылка на 5 мин</button>${selected.s3_replica?'<button class="btn secondary" data-media-sign="s3">S3-ссылка</button>':''}<button class="btn secondary" data-media-usage>Где используется</button><button class="btn secondary" data-media-variants>Создать варианты</button><button class="btn danger" data-delete-media="${escapeHtml(selected.id)}">Удалить</button></div><h3>Варианты</h3>${Object.entries(selected.variants||{}).map(([name,v])=>`<p><a href="${escapeHtml(v.url)}" target="_blank" rel="noopener">${escapeHtml(name)} · ${v.width} × ${v.height}</a></p>`).join('')||'<p class="muted">Нет вариантов</p>'}`:''}</section></div>`
}
function bindMedia() {
  const refresh=async()=>{await routeLoad('media');render()}
  const action=async(fn,msg)=>{try{await fn();await routeLoad('media');setNotice('ok',msg)}catch(e){setNotice('error',e.message,e.payload)}}
  document.querySelector('[data-form="media-filter"]')?.addEventListener('submit',e=>{e.preventDefault();const fd=new FormData(e.currentTarget);Object.assign(state.forms,{mediaQ:fd.get('q'),mediaFolder:fd.get('folder'),mediaMime:fd.get('mime'),mediaVisibility:fd.get('visibility'),mediaOffset:0,mediaSelected:''});refresh()})
  document.querySelector('[data-media-layout]')?.addEventListener('click',()=>{state.forms.mediaList=!state.forms.mediaList;render()})
  document.querySelectorAll('[data-media-page]').forEach(b=>b.addEventListener('click',()=>{state.forms.mediaOffset=Math.max(0,(state.forms.mediaOffset||0)+Number(b.dataset.mediaPage)*30);state.forms.mediaSelected='';refresh()}))
  document.querySelectorAll('[data-select-media]').forEach(b=>b.addEventListener('click',()=>{state.forms.mediaSelected=b.dataset.selectMedia;render()}))
  document.querySelectorAll('[data-copy-media]').forEach(b=>b.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(b.dataset.copyMedia);setNotice('ok','URL скопирован')}catch(e){setNotice('error',e.message)}}))
  const form=document.querySelector('[data-form="media"]')
  const upload=async(files)=>{if(!files.length)return;const fd=new FormData(form), progress=form.querySelector('progress');progress.max=files.length;progress.value=0;let done=0;const submit=form.querySelector('button[type=submit]');submit.disabled=true;
    try{for(const file of files){const body=new FormData();for(const [k,v] of fd)if(k!=='file')body.append(k,v);body.append('file',file);await api('/media',{method:'POST',body});progress.value=++done;}state.forms.mediaOffset=0;await routeLoad('media');setNotice('ok',`Загружено файлов: ${done}`)}catch(e){await routeLoad('media');setNotice('error',`Загружено ${done} из ${files.length}. ${e.message}`,e.payload)}finally{submit.disabled=false}}
  form?.addEventListener('submit',e=>{e.preventDefault();upload([...form.querySelector('[name=file]').files])})
  const drop=document.querySelector('[data-media-drop]');drop?.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('active')});drop?.addEventListener('dragleave',()=>drop.classList.remove('active'));drop?.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('active');upload([...e.dataTransfer.files])})
  const path=()=>'/media/'+encodeURIComponent(state.forms.mediaSelected)
  document.querySelector('[data-form="media-edit"]')?.addEventListener('submit',e=>{e.preventDefault();const p=Object.fromEntries(new FormData(e.currentTarget));for(const k of ['tags','collections'])p[k]=p[k].split(',').map(v=>v.trim()).filter(Boolean);action(()=>api(path(),{method:'PATCH',body:JSON.stringify(p)}),'Метаданные сохранены')})
  document.querySelector('[data-form="media-replace"]')?.addEventListener('submit',e=>{e.preventDefault();const body=new FormData(e.currentTarget);if(confirm('Заменить оригинал, сохранив URL?'))action(()=>api(path()+'/replace',{method:'POST',body}),'Оригинал заменён')})
  document.querySelectorAll('[data-media-sign]').forEach(b=>b.addEventListener('click',async()=>{try{const r=dataOf(await api(path()+'/sign?ttl=300&s3='+(b.dataset.mediaSign==='s3'?'1':'0')));const link=new URL(r.url,location.origin).href;await navigator.clipboard.writeText(link);setNotice('ok','Временная ссылка скопирована')}catch(e){setNotice('error',e.message,e.payload)}}))
  document.querySelector('[data-media-usage]')?.addEventListener('click',async()=>{try{const result=await api(path()+'/usage');setNotice('ok','Использование файла',dataOf(result))}catch(e){setNotice('error',e.message,e.payload)}})
  document.querySelector('[data-media-variants]')?.addEventListener('click',()=>action(()=>api(path()+'/variants',{method:'POST'}),'Варианты созданы'))
  document.querySelector('[data-media-diagnostics]')?.addEventListener('click',async()=>{try{const r=await api('/media/diagnostics?verify=1');setNotice('ok','Диагностика',dataOf(r))}catch(e){setNotice('error',e.message,e.payload)}})
  document.querySelectorAll('[data-delete-media]').forEach(b=>b.addEventListener('click',()=>{if(confirm('Удалить файл? Используемый файл удалить нельзя.'))action(async()=>{await api('/media/'+encodeURIComponent(b.dataset.deleteMedia),{method:'DELETE'});state.forms.mediaSelected=''},'Файл удалён')}))
}

function cmsView() {
  const redirects = asArray(state.cache.redirects)
  const navs = asArray(state.cache.navigation)
  const theme = dataOf(state.cache.theme,{}) || {}
  const routes=asArray(state.cache.publicRoutes), total=state.cache.publicRoutes?.meta?.total || 0
  const offset=state.forms.routeOffset || 0
  return `<div class="grid two"><div><div class="card"><h2>Theme config и маршрутизация</h2><form class="form" data-form="theme"><div class="form-row"><label>Язык по умолчанию</label><input class="input" name="locale" value="${escapeHtml(theme.locale||'ru')}"></div><div class="form-row"><label>Стратегия локализации</label><select class="input" name="locale_strategy"><option value="none" ${theme.locale_strategy!=='prefix'?'selected':''}>Без префиксов</option><option value="prefix" ${theme.locale_strategy==='prefix'?'selected':''}>Префикс для дополнительных языков</option></select></div><div class="form-row"><label>Доступные языки (через запятую)</label><input class="input" name="enabled_locales" value="${escapeHtml((theme.enabled_locales||['ru','en']).join(','))}"></div><div class="form-row"><label>Slug главной страницы</label><input class="input" name="home_slug" value="${escapeHtml(theme.home_slug||'home')}"></div><div class="split"><div class="form-row"><label>Страница 404</label><input class="input" name="error_404_path" value="${escapeHtml(theme.error_404_path||'')}"></div><div class="form-row"><label>Страница 410</label><input class="input" name="error_410_path" value="${escapeHtml(theme.error_410_path||'')}"></div></div><textarea class="input code" name="theme">${escapeHtml(JSON.stringify(dataOf(state.cache.theme, jsonParse(state.forms.themeJson)), null, 2))}</textarea><button class="btn" type="submit">Сохранить тему</button></form></div><div class="card"><h2>Navigation</h2>${table(['Handle', 'Title', 'Items'], navs.map((item) => `<tr><td>${escapeHtml(item.handle)}</td><td>${escapeHtml(item.name || '')}</td><td>${escapeHtml((item.items || []).length)} <button class="btn small secondary" data-edit-menu="${escapeHtml(item.handle)}">Редактировать</button> <button class="btn small danger" data-delete-menu="${escapeHtml(item.handle)}">Удалить</button></td></tr>`))}<form class="form" data-form="navigation"><div class="form-row"><label>Handle</label><input class="input" name="handle" value="${escapeHtml(state.forms.navigationHandle)}"></div><textarea class="input code" name="navigation">${escapeHtml(state.forms.navigationJson)}</textarea><button class="btn" type="submit">Сохранить меню</button></form></div></div><div><div class="card"><h2>Redirects</h2>${table(['From', 'To', 'Status', 'Действия'], redirects.map((r) => `<tr><td>${escapeHtml(r.source_path || '')}</td><td>${escapeHtml(r.target_url || '—')}</td><td>${escapeHtml(r.status || r.status_code || '')}</td><td><button class="btn small secondary" data-edit-redirect="${escapeHtml(r.id)}">Редактировать</button><button class="btn small danger" data-delete-redirect="${escapeHtml(r.id)}">Удалить</button></td></tr>`))}<form class="form" data-form="redirect"><div class="split"><input class="input" name="from" placeholder="/old"><input class="input" name="to" placeholder="/new"></div><select class="input" name="status"><option>301</option><option>302</option><option>307</option><option>308</option><option>410</option></select><button class="btn" type="submit">Добавить redirect</button></form></div><div class="card"><h2>Индекс публичных маршрутов</h2>${table(['Path','Kind','Target'],routes.map(r=>`<tr><td>${escapeHtml(r.path)}</td><td>${escapeHtml(r.kind)}</td><td>${escapeHtml(r.target||r.title||'')}</td></tr>`))}<div class="toolbar"><button class="btn secondary" data-action="route-prev" ${offset===0?'disabled':''}>Назад</button><span>${offset+1}–${Math.min(offset+100,total)} / ${total}</span><button class="btn secondary" data-action="route-next" ${offset+100>=total?'disabled':''}>Далее</button></div></div><div class="card"><h2>CMS tools</h2><div class="toolbar"><button class="btn secondary" data-action="cms-bootstrap">Bootstrap demo-home</button><button class="btn ghost" data-action="cms-overview">Overview</button></div><pre class="json">${escapeHtml(JSON.stringify(dataOf(state.cache.cmsOverview, {}), null, 2))}</pre></div></div></div>`
}

function mountMenuEditor(form){
  if(!form)return;const area=form.elements.navigation;let menu;try{menu=jsonParse(area.value,{})}catch{return;}menu.items??=[];const pane=document.createElement('div');pane.className='editor-fields';area.before(pane);area.hidden=true;
  const sync=()=>{area.value=JSON.stringify(menu,null,2);state.forms.navigationJson=area.value;state.forms.navigationHandle=form.elements.handle.value};
  pane.append(editorField({handle:'name',label:'Название меню',type:'text'},menu.name||menu.title,v=>{menu.name=v||'';sync()}));
  const rows=document.createElement('div');pane.append(rows);const draw=()=>{rows.replaceChildren();menu.items.forEach((item,i)=>{const card=document.createElement('div');card.className='card';for(const [key,label] of [['label','Название ссылки'],['url','URL'],['entry_id','UUID страницы (необязательно)']])card.append(editorField({handle:key,label,type:'text'},item[key],v=>{item[key]=v||'';sync()}));const bar=document.createElement('div');bar.className='toolbar';for(const [text,fn] of [['↑',()=>{if(i>0)[menu.items[i-1],menu.items[i]]=[menu.items[i],menu.items[i-1]]}],['↓',()=>{if(i<menu.items.length-1)[menu.items[i+1],menu.items[i]]=[menu.items[i],menu.items[i+1]]}],['Удалить',()=>menu.items.splice(i,1)]]){const b=document.createElement('button');b.type='button';b.className='btn small secondary';b.textContent=text;b.onclick=()=>{fn();sync();draw()};bar.append(b)}card.append(bar);rows.append(card)});const add=document.createElement('button');add.type='button';add.className='btn secondary';add.textContent='Добавить пункт';add.onclick=()=>{menu.items.push({label:'',url:'/',target:'_self'});sync();draw()};rows.append(add)};draw();
  form.elements.handle.addEventListener('input',sync);const advanced=document.createElement('button');advanced.type='button';advanced.className='btn ghost small';advanced.textContent='JSON';advanced.onclick=()=>{area.hidden=!area.hidden;pane.hidden=!area.hidden};form.append(advanced);
}
function bindCms() {
  mountMenuEditor(document.querySelector('[data-form="navigation"]'));
  document.querySelectorAll('[data-edit-menu]').forEach(b=>b.onclick=()=>{const m=asArray(state.cache.navigation).find(x=>x.handle===b.dataset.editMenu);if(m){state.forms.navigationHandle=m.handle;state.forms.navigationJson=JSON.stringify(m,null,2);render()}});
  document.querySelectorAll('[data-delete-menu]').forEach(b=>b.onclick=async()=>{if(!confirm('Удалить меню?'))return;try{await api('/cms/navigation/'+encodeURIComponent(b.dataset.deleteMenu),{method:'DELETE'});await routeLoad('cms');setNotice('ok','Меню удалено.')}catch(e){setNotice('error',e.message,e.payload)}});
  document.querySelectorAll('[data-edit-redirect]').forEach(b=>b.onclick=()=>{const r=asArray(state.cache.redirects).find(x=>x.id===b.dataset.editRedirect);const form=document.querySelector('[data-form="redirect"]');if(r&&form){form.elements.from.value=r.source_path;form.elements.to.value=r.target_url;form.elements.status.value=r.status_code;form.dataset.id=r.id;form.querySelector('[type=submit]').textContent='Сохранить redirect'}});

  const themeForm=document.querySelector('[data-form="theme"]');if(themeForm){const t=dataOf(state.cache.theme,{});const label=document.createElement('label');label.className='form-row';label.textContent='Активная тема';const select=document.createElement('select');select.name='active_theme';select.className='input';for(const item of state.cache.theme?.meta?.themes||[{id:'auto',name:'Автоматически'},{id:'default',name:'Стандартная'},{id:'editorial',name:'Editorial'}])select.add(new Option(item.name,item.id));if(t.active_theme&&!([...select.options].some(o=>o.value===t.active_theme)))select.add(new Option(t.active_theme,t.active_theme));select.value=t.active_theme||'auto';label.append(select);themeForm.prepend(label);const area=themeForm.elements.theme;let model=jsonParse(area.value,{});const pane=document.createElement('div');pane.className='editor-fields';area.before(pane);area.hidden=true;const sync=()=>{area.value=JSON.stringify(model,null,2)};for(const [key,title,type] of [['site_name','Название сайта','text'],['site_description','Описание','textarea'],['accent_color','Основной цвет (#rrggbb)','text'],['favicon_url','Favicon URL','text'],['primary_menu','Основное меню','text'],['robots_index','Индексация','boolean'],['robots_follow','Переход по ссылкам','boolean'],['sitemap_enabled','Sitemap','boolean']])pane.append(editorField({handle:key,label:title,type},model[key],v=>{model[key]=v??'';sync()}));const advanced=document.createElement('button');advanced.type='button';advanced.className='btn ghost small';advanced.textContent='Расширенные настройки JSON';advanced.onclick=()=>{area.hidden=!area.hidden;pane.hidden=!area.hidden};themeForm.append(advanced);}

  document.querySelector('[data-form="theme"]')?.addEventListener('submit', async (event) => { event.preventDefault(); try { const fd=new FormData(event.currentTarget); const data=jsonParse(fd.get('theme'),{}); for(const key of ['locale','locale_strategy','home_slug','error_404_path','error_410_path'])data[key]=String(fd.get(key)||'');data.active_theme=String(fd.get('active_theme')||'auto');data.enabled_locales=String(fd.get('enabled_locales')||'').split(',').map(v=>v.trim()).filter(Boolean);await api('/cms/theme', { method: 'PATCH', body: JSON.stringify(data) }); await routeLoad('cms');setNotice('ok', 'Theme config сохранён.') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelector('[data-form="navigation"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); try { await api(`/cms/navigation/${encodeURIComponent(String(fd.get('handle') || 'main'))}`, { method: 'PUT', body: JSON.stringify(jsonParse(String(fd.get('navigation') || '{}'), {})) }); await routeLoad('cms');setNotice('ok', 'Меню сохранено.') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelector('[data-form="redirect"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); try { const id=event.currentTarget.dataset.id;await api('/cms/redirects'+(id?'/'+encodeURIComponent(id):''), { method: id?'PATCH':'POST', body: JSON.stringify({ source_path: fd.get('from'), target_url: fd.get('to'), status_code: Number(fd.get('status') || 301), enabled: true }) }); await routeLoad('cms');setNotice('ok', 'Redirect сохранён.') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelectorAll('[data-delete-redirect]').forEach((button) => button.addEventListener('click', async () => { if (!confirm('Удалить redirect?')) return; try { await api(`/cms/redirects/${encodeURIComponent(button.dataset.deleteRedirect)}`, { method: 'DELETE' }); setNotice('ok', 'Redirect удалён.'); await routeLoad('cms') } catch (error) { setNotice('error', error.message, error.payload) } }))
  document.querySelector('[data-action="cms-bootstrap"]')?.addEventListener('click', async () => { try { const payload = await api('/cms/bootstrap', { method: 'POST', body: JSON.stringify({ demo_home: true }) }); setNotice('ok', 'CMS bootstrap выполнен.', payload); await routeLoad('cms') } catch (error) { setNotice('error', error.message, error.payload) } })
  for(const [action,step] of [['route-prev',-100],['route-next',100]]) document.querySelector(`[data-action="${action}"]`)?.addEventListener('click',()=>{state.forms.routeOffset=Math.max(0,(state.forms.routeOffset||0)+step);routeLoad('cms').then(render)})
  document.querySelector('[data-action="cms-overview"]')?.addEventListener('click', () => routeLoad('cms'))
}

function usersView() {
  const users = asArray(state.cache.users), roles = asArray(state.cache.roles), tokens = asArray(state.cache.tokens)
  return `<div class="tabs"><button class="active">Users/RBAC</button></div><div class="grid two"><div><div class="card"><h2>Пользователи</h2>${table(['Name', 'Email', 'Roles', 'Действия'], users.map((u) => `<tr><td>${escapeHtml(u.name || u.id)}</td><td>${escapeHtml(u.email || '')}</td><td>${escapeHtml((u.roles || []).join(', '))}</td><td><button class="btn small danger" data-disable-user="${escapeHtml(u.id)}">Disable</button></td></tr>`))}<form class="form" data-form="user"><h3>Создать пользователя</h3><input class="input" name="name" placeholder="Name"><input class="input" name="email" placeholder="Email"><input class="input" name="password" type="password" placeholder="Password"><input class="input" name="roles" placeholder="admin,editor"><button class="btn" type="submit">Создать</button></form></div><div class="card"><h2>API tokens</h2>${table(['Name', 'Scopes', 'Действия'], tokens.map((t) => `<tr><td>${escapeHtml(t.name || t.id)}</td><td>${escapeHtml((t.scopes || []).join(', '))}</td><td><button class="btn small danger" data-revoke-token="${escapeHtml(t.id)}">Revoke</button></td></tr>`))}<form class="form" data-form="token"><input class="input" name="name" placeholder="Token name"><input class="input" name="scopes" placeholder="content:read,content:write"><button class="btn" type="submit">Создать token</button></form></div></div><div><div class="card"><h2>Роли</h2>${table(['Handle', 'Name', 'Permissions'], roles.map((r) => `<tr><td>${escapeHtml(r.handle || r.id)}</td><td>${escapeHtml(r.name || '')}</td><td>${escapeHtml((r.permissions || []).join(', '))}</td></tr>`))}<form class="form" data-form="role"><h3>Создать/обновить роль</h3><input class="input" name="handle" placeholder="editor"><input class="input" name="name" placeholder="Editor"><textarea class="input" name="permissions" placeholder="content:read,content:write"></textarea><button class="btn" type="submit">Сохранить роль</button></form></div></div></div>`
}

function bindUsers() {
  document.querySelector('[data-form="user"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); try { await api('/users', { method: 'POST', body: JSON.stringify({ name: fd.get('name'), email: fd.get('email'), password: fd.get('password'), roles: String(fd.get('roles') || '').split(',').map((x) => x.trim()).filter(Boolean) }) }); setNotice('ok', 'Пользователь создан.'); await routeLoad('users') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelector('[data-form="role"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); try { await api('/roles', { method: 'POST', body: JSON.stringify({ handle: fd.get('handle'), name: fd.get('name'), permissions: String(fd.get('permissions') || '').split(',').map((x) => x.trim()).filter(Boolean) }) }); setNotice('ok', 'Роль сохранена.'); await routeLoad('users') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelector('[data-form="token"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); try { const payload = await api('/api-tokens', { method: 'POST', body: JSON.stringify({ name: fd.get('name'), scopes: String(fd.get('scopes') || '').split(',').map((x) => x.trim()).filter(Boolean) }) }); setNotice('ok', 'Token создан. Скопируйте plain token сейчас.', payload); await routeLoad('users') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelectorAll('[data-disable-user]').forEach((button) => button.addEventListener('click', async () => { if (!confirm('Disable user?')) return; try { await api(`/users/${encodeURIComponent(button.dataset.disableUser)}`, { method: 'DELETE' }); setNotice('ok', 'Пользователь отключён.'); await routeLoad('users') } catch (error) { setNotice('error', error.message, error.payload) } }))
  document.querySelectorAll('[data-revoke-token]').forEach((button) => button.addEventListener('click', async () => { if (!confirm('Revoke token?')) return; try { await api(`/api-tokens/${encodeURIComponent(button.dataset.revokeToken)}`, { method: 'DELETE' }); setNotice('ok', 'Token отозван.'); await routeLoad('users') } catch (error) { setNotice('error', error.message, error.payload) } }))
}

function extensionsView() {
  const items = asArray(state.cache.extensions)
  const runtime = dataOf(state.cache.extensionsRuntime, {}) || {}
  const pages = Array.isArray(runtime.pages) ? runtime.pages : []
  return `<div class="grid two"><div class="card"><h2>Расширения</h2><p>Установка, обновление и удаление подписанных пакетов выполняются оператором CLI. Активация выполняется командой extension:package:enable с проверкой запуска и backup. Отключение доступно здесь.</p>${table(['Имя / версия','Тип','Статус','Действия'],items.map(e=>`<tr><td>${escapeHtml(e.name||'')}<br>${escapeHtml(e.version||'')}</td><td>${escapeHtml(e.type||'')}</td><td>${escapeHtml(e.valid?(e.enabled?'enabled':e.installed?'disabled':'available'):'invalid')}</td><td>${e.installed?(e.enabled?`<button class="btn small secondary" data-ext-action="disable" data-slug="${escapeHtml(e.name)}">Отключить</button>`:`<code>php bin/cajeer extension:package:enable ${escapeHtml(e.name)}</code>`):''}</td></tr>`))}</div><div class="card"><h2>Страницы расширений</h2>${pages.map(p=>`<button class="btn secondary" data-ext-page="${escapeHtml(p.slug)}" data-action="${escapeHtml(p.action)}">${escapeHtml(p.title)}</button>`).join('')||'<p>Нет активных страниц.</p>'}<div id="extension-page-result"></div></div><div class="card"><h2>Runtime</h2><pre class="json">${escapeHtml(JSON.stringify(runtime,null,2))}</pre></div></div>`
}
function bindExtensions() {
  document.querySelectorAll('[data-ext-action]').forEach(button=>button.addEventListener('click',async()=>{try{await api('/extensions/'+button.dataset.extAction,{method:'POST',body:JSON.stringify({name:button.dataset.slug})});await routeLoad('extensions')}catch(error){setNotice('error',error.message,error.payload)}}))
  document.querySelectorAll('[data-ext-page]').forEach(button=>button.addEventListener('click',async()=>{const host=document.getElementById('extension-page-result');try{const prefix='/extensions/actions/'+encodeURIComponent(button.dataset.extPage)+'/';const payload=await api(prefix+encodeURIComponent(button.dataset.action));const data=dataOf(payload,{});host.replaceChildren();const title=document.createElement('h3');title.textContent=data.title||button.textContent;host.append(title);const text=document.createElement('pre');text.className='json';text.textContent=JSON.stringify(data.data??data,null,2);host.append(text);if(data.form&&typeof data.form.action==='string'&&Array.isArray(data.form.fields)){const form=document.createElement('form');form.className='form';for(const field of data.form.fields){if(typeof field.name!=='string')continue;const label=document.createElement('label');label.textContent=field.label||field.name;const input=document.createElement('input');input.className='input';input.name=field.name;input.type=field.type==='email'?'email':'text';input.required=!!field.required;label.append(input);form.append(label)}const submit=document.createElement('button');submit.type='submit';submit.className='btn';submit.textContent=data.form.submit||'Сохранить';form.append(submit);form.addEventListener('submit',async e=>{e.preventDefault();submit.disabled=true;try{const result=await api(prefix+encodeURIComponent(data.form.action),{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(form)))});text.textContent=JSON.stringify(dataOf(result,result),null,2);form.reset()}catch(error){setNotice('error',error.message,error.payload)}finally{submit.disabled=false}});host.append(form)}}catch(error){host.textContent=error.message}}))
}

function updatesView() {
  return `<div class="grid two"><div class="card"><h2>Production updater</h2><p>Подписанный dist, обязательный backup и восстановление. Подготовка и запуск выполняются отдельным CLI-процессом.</p><a class="btn" href="/upgrade/">Открыть мастер обновления</a><pre class="json">${escapeHtml(JSON.stringify(dataOf(state.cache.updates, state.cache.updates), null, 2))}</pre></div><div class="card"><h2>Maintenance</h2><pre class="json">${escapeHtml(JSON.stringify(dataOf(state.cache.maintenance, state.cache.maintenance), null, 2))}</pre><div class="toolbar"><button class="btn secondary" data-update-action="maintenance/on">Включить</button><button class="btn ghost" data-update-action="maintenance/off">Выключить</button></div></div></div>`
}

function bindUpdates() {
  document.querySelectorAll('[data-update-action]').forEach((button) => button.addEventListener('click', async () => { try { const payload = await api(`/updates/${button.dataset.updateAction}`, { method: 'POST', body: '{}' }); setNotice('ok', 'Update action выполнен.', payload); await routeLoad('updates') } catch (error) { setNotice('error', error.message, error.payload) } }))
}

function searchView() {
  const result=state.cache.searchResult, m=result?.meta||{}, f=state.forms;
  const rows=(result?.data||[]).map(r=>`<tr><td><a href="${escapeHtml(r.url)}" target="_blank" rel="noopener">${escapeHtml(r.title)}</a><small>${escapeHtml(r.excerpt)}</small></td><td>${escapeHtml(r.type)}</td><td>${escapeHtml(r.locale)}</td><td>${escapeHtml(Number(r.score).toFixed(2))}</td></tr>`).join('');
  const facets=Object.entries(m.facets||{}).map(([key,values])=>`<div><strong>${escapeHtml(key)}</strong> ${Object.entries(values).map(([v,n])=>`${escapeHtml(v)} (${escapeHtml(n)})`).join(' · ')}</div>`).join('');
  return `<div class="grid two"><div class="card"><h2>Поиск по опубликованному сайту</h2><form class="form" data-form="search"><label>Запрос<input class="input" name="q" maxlength="256" value="${escapeHtml(f.searchQuery)}"></label><div class="split"><label>Тип<input class="input" name="type" value="${escapeHtml(f.searchType||'')}" placeholder="pages"></label><label>Язык<input class="input" name="locale" value="${escapeHtml(f.searchLocale||'')}" placeholder="ru / en"></label></div><div class="toolbar"><button class="btn" type="submit">Искать</button><a class="btn secondary" href="/search" target="_blank" rel="noopener">Публичный поиск</a><button class="btn secondary" type="button" data-action="search-reindex">Перестроить индекс</button></div></form>${result?`<p>Найдено: ${escapeHtml(m.total)}</p>${facets}${rows?`<table class="table"><thead><tr><th>Страница</th><th>Тип</th><th>Язык</th><th>Рейтинг</th></tr></thead><tbody>${rows}</tbody></table>`:'<p>Нет результатов на этой странице.</p>'}<div class="toolbar"><button class="btn secondary" data-search-offset="${Math.max(0,m.offset-m.limit)}" ${m.offset<=0?'disabled':''}>Назад</button><button class="btn secondary" data-search-offset="${m.offset+m.limit}" ${m.offset+m.limit>=m.total?'disabled':''}>Далее</button></div>`:''}</div><div class="card"><h2>Состояние индекса</h2><pre class="json">${escapeHtml(JSON.stringify(dataOf(state.cache.searchDiagnostics,state.cache.searchDiagnostics),null,2))}</pre></div></div>`;
}

function bindSearch() {
  const query=async offset=>{try{const f=state.forms;state.cache.searchResult=await api('/search?'+new URLSearchParams({q:f.searchQuery||'',type:f.searchType||'',locale:f.searchLocale||'',limit:'20',offset:String(offset)}));render()}catch(e){setNotice('error',e.message,e.payload);render()}};
  document.querySelector('[data-form="search"]')?.addEventListener('submit',async event=>{event.preventDefault();const data=new FormData(event.currentTarget);state.forms.searchQuery=String(data.get('q')||'');state.forms.searchType=String(data.get('type')||'');state.forms.searchLocale=String(data.get('locale')||'');await query(0)});
  document.querySelectorAll('[data-search-offset]').forEach(b=>b.addEventListener('click',()=>query(Number(b.dataset.searchOffset))));
  document.querySelector('[data-action="search-reindex"]')?.addEventListener('click',async()=>{try{const payload=await api('/search/reindex',{method:'POST',body:'{}'});setNotice('ok','Индекс перестроен.',payload);await routeLoad('search');await query(0)}catch(e){setNotice('error',e.message,e.payload);render()}});
}

function importExportView() {
  const exports = asArray(state.cache.exports)
  const requests = asArray(state.cache.importRequests)
  const plan = state.cache.importPlan
  const selected = state.cache.importSelection || {}
  const options = ['content','media','cms','settings'].map(s=>`<label><input type="checkbox" name="section" value="${s}" ${!selected.sections || selected.sections.includes(s)?'checked':''}> ${s}</label>`).join(' ')
  return `<div class="grid two"><div class="card"><h2>Экспорт сайта</h2><form class="form" data-form="export-package">${['content','media','cms','settings'].map(s=>`<label><input type="checkbox" name="section" value="${s}" checked> ${s}</label>`).join(' ')}<button class="btn" type="submit">Создать ZIP</button></form>${table(['Файл','Байт','Создан'],exports.map(x=>`<tr><td><a href="${API_BASE}/import-export/download/${encodeURIComponent(x.file)}" download>${escapeHtml(x.file)}</a></td><td>${escapeHtml(x.size)}</td><td>${escapeHtml(x.created_at)}</td></tr>`))}</div><div><div class="card"><h2>Импорт сайта</h2><p>Загрузите ZIP или JSON v2. Сначала проверьте план, затем подтвердите его. Применение выполняется оператором CLI с резервной копией.</p><form class="form" data-form="upload-package"><input class="input" type="file" name="file" accept=".zip,.json" required><button class="btn" type="submit">Загрузить и проверить</button></form><form class="form" data-form="import-plan"><label>Имя пакета<input class="input" name="file" value="${escapeHtml(selected.file||'')}" required placeholder="export.zip"></label><label>Конфликты<select class="input" name="strategy">${['fail','skip','update'].map(x=>`<option value="${x}" ${selected.strategy===x?'selected':''}>${x}</option>`).join('')}</select></label>${options}<button class="btn" type="submit">Построить план</button></form>${plan?`<h3>План ${plan.ok?'готов':'с конфликтами'}</h3><pre class="json">${escapeHtml(JSON.stringify(plan.summary,null,2))}</pre>${plan.ok?'<button class="btn" data-action="confirm-import">Подтвердить план</button>':''}<details><summary>Записи и соответствия ID</summary><pre class="json">${escapeHtml(JSON.stringify({sections:plan.sections,mapping:plan.mapping,skipped:plan.skipped},null,2))}</pre></details>`:''}</div><div class="card"><h2>Запросы на импорт</h2><button class="btn" data-action="refresh-import">Обновить статус</button>${requests.map(r=>`<div class="card"><strong>${escapeHtml(r.file)}</strong> — ${escapeHtml(r.state)}<pre class="json">${escapeHtml(r.state==='pending'?'php bin/cajeer import:execute --id='+r.id:JSON.stringify(r.result||{},null,2))}</pre></div>`).join('')||'<p>Нет запросов.</p>'}</div></div></div>`
}

function bindImportExport() {
  const run = async (fn) => { try { await fn() } catch (error) { setNotice('error',error.message,error.payload); render() } }
  document.querySelector('[data-form="export-package"]')?.addEventListener('submit',event=>{event.preventDefault();const sections=new FormData(event.currentTarget).getAll('section');run(async()=>{if(!sections.length)throw new Error('Выберите секции');await api('/import-export/export',{method:'POST',body:JSON.stringify({sections})});await routeLoad('import-export');setNotice('ok','ZIP создан.');render()})})
  document.querySelector('[data-form="upload-package"]')?.addEventListener('submit',event=>{event.preventDefault();const body=new FormData(event.currentTarget);run(async()=>{const result=dataOf(await api('/import-export/upload',{method:'POST',body}));state.cache.importSelection={file:result.file,strategy:'fail',sections:result.manifest.sections.filter(x=>['content','media','cms','settings'].includes(x))};state.cache.importPlan=null;setNotice('ok','Пакет проверен. Постройте план.');render()})})
  document.querySelector('[data-form="import-plan"]')?.addEventListener('submit',event=>{event.preventDefault();const fd=new FormData(event.currentTarget);const selection={file:fd.get('file'),strategy:fd.get('strategy'),sections:fd.getAll('section')};run(async()=>{state.cache.importSelection=selection;state.cache.importPlan=null;if(!selection.sections.length)throw new Error('Выберите секции');state.cache.importPlan=dataOf(await api('/import-export/diff',{method:'POST',body:JSON.stringify(selection)}));setNotice('ok','План рассчитан.');render()})})
  document.querySelector('[data-form="import-plan"]')?.addEventListener('change',()=>{state.cache.importPlan=null;const b=document.querySelector('[data-action="confirm-import"]');if(b)b.disabled=true})
  document.querySelector('[data-action="confirm-import"]')?.addEventListener('click',()=>run(async()=>{const plan=state.cache.importPlan;if(!plan?.ok||!confirm('Подтвердить этот план для выполнения оператором?'))return;const result=dataOf(await api('/import-export/import',{method:'POST',body:JSON.stringify({...state.cache.importSelection,plan:plan.plan})}));state.cache.importPlan=null;await routeLoad('import-export');setNotice('ok','Запрос принят. '+result.command);render()}))
  document.querySelector('[data-action="refresh-import"]')?.addEventListener('click',()=>run(async()=>{await routeLoad('import-export');render()}))
}

function systemView() {
  const r=state.cache.operations
  if(!r || r.error)return `<section class="card"><h2>Эксплуатация</h2><p>${escapeHtml(r?.error?.message||'Загрузка диагностики...')}</p><button class="btn secondary" data-ops-refresh>Обновить</button></section>`
  const rows=Object.entries(r.checks||{}).map(([name,c])=>`<tr><td>${escapeHtml(name)}</td><td><span class="badge ${c.ok?'success':'danger'}">${c.ok?'OK':'Проблема'}</span></td><td>${escapeHtml(String(c.latency_ms??'—'))} мс</td><td>${escapeHtml(JSON.stringify(Object.fromEntries(Object.entries(c).filter(([k])=>!['ok','latency_ms'].includes(k)))))}</td></tr>`).join('')
  return `<section class="card"><h2>Эксплуатация · ${escapeHtml(r.status)}</h2><p>Проверено: ${escapeHtml(r.time||'')}</p><button class="btn secondary" data-ops-refresh>Обновить</button><div class="grid two"><div><h3>Диск</h3><p>${r.disk?.free_bytes==null?'Недоступно':(r.disk.free_bytes/1073741824).toFixed(2)+' ГиБ свободно'}</p></div><div><h3>Предупреждения</h3>${(r.alerts||[]).map(a=>`<p>${escapeHtml(a)}</p>`).join('')||'<p>Нет предупреждений</p>'}</div></div><h3>HTTP · накопленные метрики</h3><p>Запросы: ${escapeHtml(String(r.http?.requests??0))} · 5xx: ${escapeHtml(String(r.http?.errors_5xx??0))} · среднее: ${escapeHtml(String(r.http?.average_ms??"—"))} мс · p95 bucket: ${escapeHtml(String(r.http?.p95_bucket_ms??"—"))} мс</p><h3>Readiness</h3>${Object.entries(r.readiness?.checks||{}).map(([k,v])=>`<p>${escapeHtml(k)}: ${v?'OK':'FAIL'}</p>`).join('')}<div class="table-wrap"><table><thead><tr><th>Компонент</th><th>Статус</th><th>Задержка</th><th>Детали</th></tr></thead><tbody>${rows}</tbody></table></div></section>`
}
document.addEventListener('click',async e=>{if(e.target.closest('[data-ops-refresh]')){await routeLoad('system');render()}})

bootstrap()


function identityAdminView() {
  if (!integrationScope('users:read')) return ''
  const result = state.cache.identityAdmin
  if (result?.error) return `<div class="card"><h2>Пользователи Identity Center</h2><p class="notice error">${escapeHtml(result.error.message)}</p><button class="btn secondary" data-identity-admin-refresh>Повторить</button></div>`
  const page = dataOf(result, { items: [], total: 0, offset: 0, limit: 30 })
  const users = Array.isArray(page?.items) ? page.items : []
  const f = state.forms
  const selected = users.find(u => u.id === f.identitySelected)
  const write = integrationScope('*')
  const own = selected && selected.id === (state.identity?.user_id || state.identity?.user?.id)
  const sessionsResult = state.cache.identityAdminSessions
  const sessions = Array.isArray(dataOf(sessionsResult)) ? dataOf(sessionsResult) : []
  const rows = users.map(u => `<tr><td>${escapeHtml(u.name)}<br><small>${escapeHtml(u.email)}</small></td><td>${escapeHtml(u.status)}<br><small>2FA: ${u.two_factor_enabled ? 'включена' : 'нет'}</small></td><td>${u.linked ? `<strong>Привязан</strong>${u.provisioned ? ' (OIDC-only)' : ''}<br><small class="code-inline">${escapeHtml(u.subject)}</small>` : 'Не привязан'}</td><td><button type="button" class="btn secondary" data-identity-select="${escapeHtml(u.id)}">${selected?.id===u.id?'Выбран':'Управлять'}</button></td></tr>`).join('')
  const targetSessions = selected ? `<div class="card"><h3>SSO-сессии — ${escapeHtml(selected.email)}</h3>${sessionsResult?.error ? `<p class="notice error">${escapeHtml(sessionsResult.error.message)}</p>` : ''}<div style="overflow-x:auto"><table class="table"><thead><tr><th>Устройство</th><th>Последняя активность</th><th></th></tr></thead><tbody>${sessions.map(v => `<tr><td>${escapeHtml(v.device)}<br><small>${escapeHtml(v.network)}</small></td><td>${escapeHtml(v.last_seen_at)}</td><td>${write && !own ? `<button class="btn danger" data-identity-session-revoke="${escapeHtml(v.id)}">Завершить</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="3">Активных SSO-сессий нет</td></tr>'}</tbody></table></div>${write && !own && sessions.length ? `<button type="button" class="btn danger" data-identity-revoke-all>Завершить все SSO-сессии</button>` : ''}</div>` : ''
  const mapping = selected && write ? `<div class="card"><h3>Привязка — ${escapeHtml(selected.email)}</h3><p><strong>UUID:</strong> <code>${escapeHtml(selected.id)}</code></p><p>Оператор должен заранее сверить точный Keycloak <code>sub</code> с проверенной личностью. Email и имя не подтверждают идентичность. Операции требуют повторной аутентификации.</p>${selected.id === (state.identity?.user_id || state.identity?.user?.id) ? '<p>Для собственной учётной записи используйте форму привязки выше.</p>' : selected.linked ? `<p>Issuer: <code>https://id.skif4er.ru/realms/skif4er</code><br>Subject: <code>${escapeHtml(selected.subject)}</code><br>Создан: ${escapeHtml(selected.linked_at || '—')}</p>${selected.provisioned ? '<p class="notice error">Отвязка запрещена: сначала настройте проверенный локальный способ входа.</p>' : `<button class="btn danger" data-identity-admin-unlink>Отвязать Identity и завершить SSO-сессии</button>`}` : selected.status !== 'active' ? '<p>Привязка отключённого пользователя запрещена.</p>' : `<form class="form" data-identity-admin-link><label>Проверенный Keycloak sub<input class="input" name="subject" maxlength="255" required autocomplete="off" spellcheck="false"></label><label>Повторите sub для подтверждения<input class="input" name="confirm_subject" maxlength="255" required autocomplete="off" spellcheck="false"></label><button class="btn" type="submit">Привязать Identity</button></form>`}</div>` : ''
  return `<section class="card"><h2>Identity Center · управление</h2><p>Здесь управляются привязки и браузерные сессии CajeerEngine. Пользователи и MFA в самом Keycloak управляются отдельно. Все операции ограничены правами администраторов.</p><form class="toolbar" data-identity-admin-filter><input class="input" name="q" aria-label="Поиск пользователя" placeholder="Email, имя или UUID" value="${escapeHtml(f.identitySearch)}" maxlength="120"><select name="linked" aria-label="Фильтр привязок"><option value="all" ${f.identityLinked==='all'?'selected':''}>Все</option><option value="yes" ${f.identityLinked==='yes'?'selected':''}>Привязаны</option><option value="no" ${f.identityLinked==='no'?'selected':''}>Без привязки</option></select><button class="btn" type="submit">Найти</button><button class="btn secondary" type="button" data-identity-admin-refresh>Обновить</button></form><p class="muted">Найдено: ${Number(page.total || 0)}</p><div style="overflow-x:auto"><table class="table"><thead><tr><th>Пользователь</th><th>Статус</th><th>SkiF4er ID</th><th>Действия</th></tr></thead><tbody>${rows || '<tr><td colspan="4">Пользователи не найдены</td></tr>'}</tbody></table></div><div class="toolbar"><button class="btn secondary" data-identity-page="previous" ${f.identityOffset<=0?'disabled':''}>Назад</button><button class="btn secondary" data-identity-page="next" ${f.identityOffset+30>=Number(page.total||0)?'disabled':''}>Далее</button><span>${Number(page.total||0)===0 ? 0 : f.identityOffset+1}–${Math.min(f.identityOffset+30,Number(page.total||0))}</span></div></section>${mapping}${targetSessions}`
}

function identityView() {
  const cached = state.cache.identity
  const data = dataOf(cached)
  const entries = dataOf(state.cache.sessions, [])
  const sessions = Array.isArray(entries) ? entries : []
  const identityPanel = !data ? '<div class="card">Загрузка статуса SkiF4er ID…</div>' : !data.enabled
    ? '<div class="card"><h2>SkiF4er ID</h2><p>Провайдер отключён. Локальный вход продолжает работать.</p></div>'
    : `<div class="card"><h2>SkiF4er ID</h2><p>${data.linked ? 'Внешняя учётная запись привязана.' : 'Внешняя учётная запись не привязана.'}</p>${data.provisioned ? '<p>Аккаунт создан через SkiF4er ID. Для отвязки сначала настройте локальный вход.</p>' : ''}${data.created_at ? `<p class="muted">Дата привязки: ${escapeHtml(data.created_at)}</p>` : ''}<form class="form" data-identity-form><div class="form-row"><label>Текущий пароль CajeerEngine</label><input class="input" name="password" type="password" autocomplete="current-password" required maxlength="1024"></div><div class="form-row"><label>Код 2FA (если включён)</label><input class="input" name="two_factor_code" autocomplete="one-time-code" maxlength="128"></div><div class="toolbar">${data.linked ? `<button class="btn danger" type="submit" ${data.provisioned ? 'disabled' : ''}>Отвязать SkiF4er ID</button>` : '<button class="btn" type="submit">Привязать через SkiF4er ID</button>'}</div></form><p class="muted">Привязка требует локальный пароль и подтверждение SkiF4er ID. Email не используется для объединения аккаунтов.</p></div>`
  const sessionsPanel = `<div class="card"><h2>Активные сессии</h2><p>Отзыв немедленно блокирует доступ на выбранном устройстве. Обычные API-токены не затрагиваются.</p>${state.cache.sessions?.error ? `<p class="notice error">${escapeHtml(state.cache.sessions.error.message)}</p>` : ''}<div style="overflow-x:auto"><table class="table"><thead><tr><th>Устройство</th><th>Источник</th><th>Последняя активность</th><th>Срок действия</th><th></th></tr></thead><tbody>${sessions.map(session => `<tr><td>${escapeHtml(session.device || 'Браузер')} ${session.current ? '<strong>(текущая)</strong>' : ''}<br><span class="muted">${escapeHtml(session.network || '')}</span></td><td>${escapeHtml(session.provider)}</td><td>${escapeHtml(session.last_seen_at)}</td><td>${escapeHtml(session.expires_at || '—')}</td><td><button class="btn secondary" type="button" data-session-revoke="${escapeHtml(session.id)}">Завершить</button></td></tr>`).join('') || '<tr><td colspan="5">Активных сессий нет</td></tr>'}</tbody></table></div><div class="toolbar"><button class="btn secondary" data-session-rotate>Обновить текущую сессию</button><button class="btn secondary" data-session-others>Завершить остальные</button><button class="btn danger" data-session-all>Завершить все</button>${sessions.some(s => s.current && s.provider === "skif4er") ? '<button class="btn secondary" data-session-global>Выйти из SkiF4er ID (SSO)</button>' : ''}</div></div>`
  const assurancePanel = `<div class="card"><h2>Повторное подтверждение критических операций</h2><p>Изменение пользователей, ролей, API-токенов и интеграций требует свежего подтверждения. Для SkiF4er ID необходим MFA.</p><form class="form" data-assurance-form><div class="form-row"><label>Локальный пароль</label><input class="input" type="password" name="password" autocomplete="current-password" maxlength="1024" required></div><div class="form-row"><label>Текущий TOTP (если включён)</label><input class="input" name="two_factor_code" autocomplete="one-time-code" maxlength="128"></div><button class="btn" type="submit">Подтвердить локально</button></form>${sessions.some(s=>s.current&&s.provider==='skif4er') ? '<button class="btn secondary" data-assurance-oidc type="button">Подтвердить через SkiF4er ID + MFA</button>' : ''}<p class="muted">Подтверждение действует до 5 минут и аннулируется при ротации/отзыве сессии.</p></div>`
  return identityPanel + assurancePanel + sessionsPanel + identityAdminView()
}
function bindSessionActions() {
  const refresh = async () => { await loadSilent('sessions','/auth/sessions'); render() }
  const exit = () => { state.token='';state.csrf='';state.identity=null;state.cache={};go('login') }
  document.querySelectorAll('[data-session-revoke]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Завершить выбранную сессию?')) return
    b.disabled=true
    try { const current = dataOf(state.cache.sessions,[]).find(s=>s.id===b.dataset.sessionRevoke)?.current
      await api('/auth/sessions/'+encodeURIComponent(b.dataset.sessionRevoke),{method:'DELETE'})
      if (current) exit(); else await refresh()
    } catch(e) { setNotice('error',e.message);b.disabled=false;render() }
  }))
  document.querySelector('[data-session-others]')?.addEventListener('click',async () => {
    if (!confirm('Завершить все другие браузерные сессии?')) return
    try { await api('/auth/sessions/revoke-others',{method:'POST',body:'{}'});await refresh() } catch(e){setNotice('error',e.message);render()}
  })
  document.querySelector('[data-session-all]')?.addEventListener('click',async () => {
    if (!confirm('Завершить ВСЕ браузерные сессии, включая текущую?')) return
    try { await api('/auth/sessions/revoke-all',{method:'POST',body:'{}'});exit() } catch(e){setNotice('error',e.message);render()}
  })
  document.querySelector('[data-session-global]')?.addEventListener('click', async () => {
    if (!confirm('Завершить текущую сессию и выполнить глобальный выход SkiF4er ID?')) return
    try {
      const result = dataOf(await api('/auth/oidc/logout',{method:'POST',body:'{}'}))
      const u = new URL(result.authorization_url)
      if (u.origin !== 'https://id.skif4er.ru' || u.pathname !== '/realms/skif4er/protocol/openid-connect/logout') throw new Error('Недопустимый адрес выхода')
      exit(); window.location.assign(u.toString())
    } catch(e) { setNotice('error',e.message);render() }
  })
  document.querySelector('[data-session-rotate]')?.addEventListener('click',async () => {
    try { const result=dataOf(await api('/auth/sessions/rotate',{method:'POST',body:'{}'}));
      state.csrf=result.csrf_token;await refresh()
    } catch(e){setNotice('error',e.message);render()}
  })
}
function bindIdentityAdmin() {
  if (!integrationScope('users:read')) return
  const refresh = async () => { await loadIdentityUsers(); if (state.forms.identitySelected) await loadSilent('identityAdminSessions', '/identity/admin/users/' + encodeURIComponent(state.forms.identitySelected) + '/sessions'); render() }
  const act = async (fn) => { try { await fn(); await refresh() } catch (e) { setNotice('error', e.message, e.payload); render() } }
  document.querySelector('[data-identity-admin-filter]')?.addEventListener('submit', e => {
    e.preventDefault(); const d = new FormData(e.currentTarget)
    state.forms.identitySearch = String(d.get('q') || '').trim()
    state.forms.identityLinked = String(d.get('linked') || 'all')
    state.forms.identityOffset = 0; state.forms.identitySelected = ''
    act(async () => { await loadIdentityUsers() })
  })
  document.querySelector('[data-identity-admin-refresh]')?.addEventListener('click', () => act(async () => {}))
  document.querySelectorAll('[data-identity-page]').forEach(b => b.addEventListener('click', () => {
    state.forms.identityOffset = Math.max(0, state.forms.identityOffset + (b.dataset.identityPage === 'next' ? 30 : -30))
    state.forms.identitySelected = ''
    act(async () => {})
  }))
  document.querySelectorAll('[data-identity-select]').forEach(b => b.addEventListener('click', () => {
    state.forms.identitySelected = b.dataset.identitySelect
    state.cache.identityAdminSessions = null
    act(async () => {})
  }))
  const current = (dataOf(state.cache.identityAdmin, {}).items || []).find(u => u.id === state.forms.identitySelected)
  if (!current || !integrationScope('*')) return
  const base = '/identity/admin/users/' + encodeURIComponent(current.id)
  document.querySelector('[data-identity-admin-link]')?.addEventListener('submit', e => {
    e.preventDefault(); const f = new FormData(e.currentTarget)
    const subject = String(f.get('subject') || ''), confirmation = String(f.get('confirm_subject') || '')
    if (!subject || subject !== confirmation) { setNotice('error','Поля sub должны совпадать.');return }
    if (!confirm('Подтвердите: внешняя личность проверена, и sub принадлежит пользователю '+ current.email +'?')) return
    act(async () => { await api(base + '/link',{method:'POST',body:JSON.stringify({subject,confirm_subject:confirmation})});setNotice('ok','SkiF4er ID привязан.') })
  })
  document.querySelector('[data-identity-admin-unlink]')?.addEventListener('click', () => {
    if (!confirm('Отвязать SkiF4er ID пользователя '+current.email+' и завершить его SSO-сессии?')) return
    act(async () => { await api(base + '/link',{method:'DELETE',body:JSON.stringify({confirm_user_id:current.id})});setNotice('ok','SkiF4er ID отвязан, SSO-сессии отозваны.') })
  })
  document.querySelectorAll('[data-identity-session-revoke]').forEach(b => b.addEventListener('click', () => {
    if (!confirm('Завершить выбранную SSO-сессию пользователя '+current.email+'?')) return
    act(async () => { await api(base + '/sessions/' + encodeURIComponent(b.dataset.identitySessionRevoke), {method:'DELETE'}); setNotice('ok','SSO-сессия завершена.') })
  }))
  document.querySelector('[data-identity-revoke-all]')?.addEventListener('click', () => {
    if (!confirm('Завершить ВСЕ SSO-сессии пользователя '+current.email+'?')) return
    act(async () => { await api(base + '/sessions/revoke-all', {method:'POST',body:JSON.stringify({confirm_user_id:current.id})});setNotice('ok','SSO-сессии завершены.') })
  })
}

function bindIdentity() {
  bindSessionActions()
  bindIdentityAdmin()
  document.querySelector('[data-assurance-form]')?.addEventListener('submit', async e => {
    e.preventDefault(); const form=e.currentTarget;const data=new FormData(form)
    try { const result=dataOf(await api('/auth/assurance/confirm',{method:'POST',body:JSON.stringify({password:String(data.get('password')||''),two_factor_code:String(data.get('two_factor_code')||'')})}));form.reset();setNotice('ok','Повторная авторизация подтверждена до '+(result.expires_at||'истечения срока')) }
    catch (error) {setNotice('error',error.message)}
  })
  document.querySelector('[data-assurance-oidc]')?.addEventListener('click',async () => {
    try { const result=dataOf(await api('/identity/step-up/start',{method:'POST',body:'{}'}));const url=new URL(result.authorization_url)
      if(url.origin!=='https://id.skif4er.ru'||url.pathname!=='/realms/skif4er/protocol/openid-connect/auth'||url.searchParams.get('max_age')!=='0')throw new Error('Недопустимый адрес авторизации')
      window.location.assign(url.toString())
    } catch(error) {setNotice('error',error.message)}
  })
  const form = document.querySelector('[data-identity-form]')
  if (!form) return
  form.addEventListener('submit', async e => {
    e.preventDefault()
    const linked = dataOf(state.cache.identity, {}).linked === true
    if (linked && !confirm('Отвязать SkiF4er ID от текущей учётной записи?')) return
    const f = new FormData(form)
    const payload = {password: String(f.get('password') || ''), two_factor_code: String(f.get('two_factor_code') || '')}
    const btn = form.querySelector('[type=submit]'); btn.disabled = true
    try {
      const result = await api(linked ? '/identity/unlink' : '/identity/link/start', {method: 'POST', body: JSON.stringify(payload)})
      form.reset()
      if (!linked) {
        const url = dataOf(result, {}).authorization_url
        if (!url || !url.startsWith('https://id.skif4er.ru/realms/skif4er/')) throw new Error('Получен недопустимый адрес авторизации.')
        window.location.assign(url)
      } else {
        if (dataOf(result, {}).session_revoked === true) {
          state.csrf = null
          window.location.assign('/admin/#/login')
          return
        }
        await loadSilent('identity', '/identity/account')
        setNotice('ok', 'Внешний аккаунт отвязан; все SSO-сессии завершены.')
      }
    } catch (error) { setNotice('error', error.message, error.payload) }
    finally { btn.disabled = false }
  })
}

function translationsView() {
  const f=state.forms.translation;
  const types=asArray(state.cache.translationTypes), entries=asArray(state.cache.translationEntries);
  const source=f.overview?.source, peers=f.overview?.localizations||[];
  const found=peers.find(x=>x.locale===f.locale);
  const options=(list,selected)=>list.map(([value,title])=>`<option value="${escapeHtml(value)}" ${selected===value?'selected':''}>${escapeHtml(title)}</option>`).join('');
  const label=(name,html)=>`<label>${name}${html}</label>`;
  const toolbar=`<form class="form" data-translations-select><div class="split">${label('Тип контента',`<select class="input" name="type">${options(types.map(x=>[x.handle,x.name||x.handle]),f.type)}</select>`)}${label('Источник',`<select class="input" name="entry">${options(entries.map(x=>[x.id,`${x.title} · ${x.locale} · ${x.status}`]),f.entry)}</select>`)}</div><div class="split">${label('Язык источника',`<input class="input" value="${escapeHtml(source?.locale||'')}" disabled>`)}${label('Язык перевода',`<select class="input" name="locale">${options(['ru','en','de','fr','es','it','pl','pt','tr','uk','zh','ja','ko'].filter(x=>x!==source?.locale).map(x=>[x,x]),f.locale)}</select>`)}</div>${found?`<p>Найден перевод: ${escapeHtml(found.title)} · ${escapeHtml(found.status)} · rev ${Number(found.revision)} ${found.stale?'· Требует обновления':''}</p><label><input type="checkbox" name="update_existing" ${f.updateExisting?'checked':''}> Обновить существующий перевод</label>`:''}${label('Пожелания к переводу',`<textarea class="input" name="instructions" rows="3" maxlength="1500">${escapeHtml(f.instructions)}</textarea>`)}<button class="btn" type="submit" ${f.busy||!source||source.locale===f.locale||(!!found!==!!f.updateExisting)?'disabled':''}>${f.busy?'Перевод…':'Сформировать перевод'}</button></form>`;
  const peerTable=source?`<section class="card"><h2>Группа локализаций</h2>${table(['Язык','Название','Статус / ревизия','Актуальность'],peers.map(x=>`<tr><td>${escapeHtml(x.locale)}</td><td>${escapeHtml(x.title)}</td><td>${escapeHtml(x.status)} · ${Number(x.revision)}</td><td>${x.stale===true?'Исходник изменён':x.stale===false?'Актуально':'Без AI-метаданных'}</td></tr>`))}</section>`:'';
  const glossary=source?`<section class="card"><h2>Глоссарий ${escapeHtml(source.locale)} → ${escapeHtml(f.locale)}</h2><form class="form" data-translation-term><div class="split">${label('Термин',`<input class="input" name="term" maxlength="160" required>`)}${label('Перевод',`<input class="input" name="translation" maxlength="250" required>`)}</div><button class="btn secondary" ${f.busy?'disabled':''}>Сохранить термин</button></form>${f.glossary.length?table(['Термин','Перевод',''],f.glossary.map(t=>`<tr><td>${escapeHtml(t.source_term)}</td><td>${escapeHtml(t.translated_term)}</td><td><button class="btn small secondary" data-translation-remove="${escapeHtml(t.id)}">Удалить</button></td></tr>`)):'<p>Глоссарий для этой пары языков пуст.</p>'}</section>`:'';
  const selected=f.batchSelection||[];
  const batch=`<section class="card"><h2>Пакетный перевод через очередь</h2><p>Выберите до 20 материалов; предложения подготавливаются в worker и не публикуются автоматически.</p><div class="form" style="max-height:240px;overflow:auto">${entries.map(x=>`<label style="display:block"><input type="checkbox" data-translation-check="${escapeHtml(x.id)}" ${selected.includes(x.id)?'checked':''} ${(!selected.includes(x.id)&&selected.length>=20)||x.locale===f.locale?'disabled':''}> ${escapeHtml(x.title||x.id)} · ${escapeHtml(x.locale)}</label>`).join('')}</div><label><input type="checkbox" data-translation-batch-update ${f.batchUpdateExisting?'checked':''}> Обновить существующие локализации вместо создания новых</label><button class="btn secondary" data-translation-batch ${!selected.length||selected.length>20||f.busy?'disabled':''}>Поставить в очередь (${selected.length})</button>${f.batch?`<p>Пакет ${escapeHtml(f.batch.id)} · готово ${Number(f.batch.ready)}/${Number(f.batch.total)} · ошибок ${Number(f.batch.failed)} <button class="btn small secondary" data-translation-refresh>Обновить статус</button></p>${table(['Материал','Статус',''],f.batch.items.map(x=>`<tr><td>${escapeHtml(entries.find(e=>e.id===x.source_entry_id)?.title||x.source_entry_id)}</td><td>${escapeHtml(x.status)} ${escapeHtml(x.error_message||'')}</td><td>${x.proposal_id&&x.status==='ready'?`<button class="btn small secondary" data-translation-open="${escapeHtml(x.proposal_id)}" data-entry="${escapeHtml(x.source_entry_id)}">Проверить перевод</button>`:''}</td></tr>`))}`:''}</section>`;
  const p=f.proposal;
  const preview=p?`<section class="card"><h2>Предварительный просмотр · ${escapeHtml(p.source_locale)} → ${escapeHtml(p.target_locale)}</h2><p>Ревизия источника: ${Number(p.source_revision)}${p.segment_count===null?'':' · Сегменты: '+Number(p.segment_count)}${p.usage?.input_tokens==null?'':' · Токены: '+Number(p.usage.input_tokens)+'/'+Number(p.usage.output_tokens)}</p><div class="ai-editor-compare"><div><strong>Оригинал</strong><pre class="ai-editor-text">${escapeHtml(p.original_title)}</pre></div><div><strong>Перевод</strong><pre class="ai-editor-text">${escapeHtml(p.translated_title)}</pre></div></div><details open><summary>Переведённые поля и блоки</summary><pre class="json" style="max-height:440px;overflow:auto">${escapeHtml(JSON.stringify(p.translated_data,null,2))}</pre></details><p>AI не изменяет опубликованный материал до подтверждения.</p><div class="toolbar"><button class="btn" data-translation-accept>${p.target_entry_id?'Применить как ревизию':'Создать черновик локализации'}</button><button class="btn secondary" data-translation-reject>Отклонить</button></div></section>`:'';
  return `<section class="card"><h2>AI Translation Studio</h2><p>OpenAI переводит редактируемый контент; HTML-теги, ссылки и плейсхолдеры остаются неизменными. Результат сохраняется только после проверки.</p>${toolbar}</section>${peerTable}${glossary}${batch}${preview}`;
}

function bindTranslations() {
  if(state.route!=='translations')return;
  const f=state.forms.translation;
  async function overview(){
    f.proposal=null;f.overview=null;f.glossary=[];
    if(f.type&&f.entry){
      f.overview=dataOf(await api('/ai/translations/overview?'+new URLSearchParams({type:f.type,entry_id:f.entry})));
      await refreshGlossary();
    }
  }
  async function refreshGlossary(){
    if(!f.overview)return;
    f.glossary=asArray(await api('/ai/translations/glossary?'+new URLSearchParams({type:f.type,source_locale:f.overview.source.locale,target_locale:f.locale})));
  }
  const form=document.querySelector('[data-translations-select]');
  form?.elements.type.addEventListener('change',async e=>{
    f.type=e.target.value;f.entry='';f.overview=null;f.proposal=null;f.glossary=[];
    try{await loadSilent('translationEntries','/content/'+encodeURIComponent(f.type)+'?status=all&limit=200&offset=0');f.entry=asArray(state.cache.translationEntries)[0]?.id||'';await overview()}
    catch(err){setNotice('error',err.message,err.payload)}finally{render()}
  });
  form?.elements.entry.addEventListener('change',async e=>{f.entry=e.target.value;try{await overview()}catch(err){setNotice('error',err.message,err.payload)}finally{render()}});
  form?.elements.locale.addEventListener('change',async e=>{f.locale=e.target.value;f.updateExisting=false;f.proposal=null;try{await refreshGlossary()}catch(err){setNotice('error',err.message,err.payload)}finally{render()}});
  form?.elements.instructions.addEventListener('input',()=>{f.instructions=form.elements.instructions.value;f.proposal=null});
  form?.elements.update_existing?.addEventListener('change',e=>{f.updateExisting=e.target.checked;f.proposal=null;render()});
  form?.addEventListener('submit',async e=>{
    e.preventDefault();if(f.busy)return;
    f.busy=true;f.proposal=null;render();
    try{f.proposal=dataOf(await api('/ai/translations/proposals',{method:'POST',body:JSON.stringify({type:f.type,entry_id:f.entry,target_locale:f.locale,update_existing:f.updateExisting,instructions:f.instructions})}));setNotice('ok','Перевод готов для проверки.')}
    catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  });
  document.querySelector('[data-translation-term]')?.addEventListener('submit',async e=>{
    e.preventDefault();if(f.busy||!f.overview)return;const d=new FormData(e.currentTarget);f.busy=true;
    try{f.glossary=asArray(await api('/ai/translations/glossary',{method:'POST',body:JSON.stringify({type:f.type,source_locale:f.overview.source.locale,target_locale:f.locale,term:d.get('term'),translation:d.get('translation')})}));f.proposal=null;setNotice('ok','Термин сохранён.')}
    catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  });
  document.querySelectorAll('[data-translation-remove]').forEach(b=>b.addEventListener('click',async()=>{
    if(f.busy)return;f.busy=true;
    try{await api('/ai/translations/glossary/'+encodeURIComponent(b.dataset.translationRemove),{method:'DELETE'});await refreshGlossary();f.proposal=null;setNotice('ok','Термин удалён.')}
    catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  }));
  document.querySelectorAll('[data-translation-check]').forEach(b=>b.addEventListener('change',e=>{
    f.batchSelection=e.target.checked?[...new Set([...f.batchSelection,b.dataset.translationCheck])]:f.batchSelection.filter(id=>id!==b.dataset.translationCheck);
    render();
  }));
  document.querySelector('[data-translation-batch-update]')?.addEventListener('change',e=>{f.batchUpdateExisting=e.target.checked;});
  document.querySelector('[data-translation-batch]')?.addEventListener('click',async()=>{
    if(f.busy||!f.batchSelection.length)return;f.busy=true;
    try{
      f.batch=dataOf(await api('/ai/translations/batches',{method:'POST',body:JSON.stringify({type:f.type,entry_ids:f.batchSelection,target_locale:f.locale,update_existing:f.batchUpdateExisting,instructions:f.instructions})}));
      setNotice('ok','Переводы поставлены в очередь. Обновите статус после обработки worker.');
    }catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  });
  document.querySelector('[data-translation-refresh]')?.addEventListener('click',async()=>{
    if(f.busy||!f.batch)return;f.busy=true;
    try{f.batch=dataOf(await api('/ai/translations/batches/'+encodeURIComponent(f.batch.id)));setNotice('ok','Статус очереди обновлён.')}
    catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  });
  document.querySelectorAll('[data-translation-open]').forEach(b=>b.addEventListener('click',async()=>{
    try{
      const proposal=dataOf(await api('/ai/translations/proposals/'+encodeURIComponent(b.dataset.translationOpen)));
      f.entry=b.dataset.entry;f.locale=proposal.target_locale;f.updateExisting=!!proposal.target_entry_id;
      f.overview=dataOf(await api('/ai/translations/overview?'+new URLSearchParams({type:f.type,entry_id:f.entry})));
      await refreshGlossary();f.proposal=proposal;render();
    }catch(err){setNotice('error',err.message,err.payload)}
  }));
  document.querySelector('[data-translation-reject]')?.addEventListener('click',()=>{f.proposal=null;render()});
  document.querySelector('[data-translation-accept]')?.addEventListener('click',async()=>{
    if(f.busy||!f.proposal)return;
    const target=f.overview?.localizations?.find(e=>e.locale===f.locale);
    const published=target?.status==='published';
    if(published&&!confirm('Опубликованный перевод будет изменён. Подтвердить обновление публичной страницы?'))return;
    if(!confirm('Применить проверенный перевод?'))return;
    f.busy=true;
    try{
      const result=dataOf(await api('/ai/translations/proposals/'+encodeURIComponent(f.proposal.id)+'/apply',{method:'POST',body:JSON.stringify({confirm_published:!!published})}));
      await loadSilent('translationEntries','/content/'+encodeURIComponent(f.type)+'?status=all&limit=200&offset=0');
      await overview();setNotice('ok',result.created?'Черновик локализации создан.':'Перевод обновлён новой ревизией.');
    }catch(err){setNotice('error',err.message,err.payload)}finally{f.busy=false;render()}
  });
}

function knowledgeView() {
  const k = state.forms.knowledge, categories = asArray(state.cache.kbCategories), documents = dataOf(state.cache.kbDocuments,{}), items = asArray(documents.items), selected = k.selected
  const e = escapeHtml
  const options = (values, current) => values.map(([value,label])=>`<option value="${e(value)}" ${String(value)===String(current)?'selected':''}>${e(label)}</option>`).join('')
  const categoryOptions = [['','Без категории'], ...categories.map(c=>[c.id,c.name])]
  const typeOptions = asArray(state.cache.kbTypes).map(t=>[t.handle,t.name||t.handle])
  const docRows = items.map(d=>`<tr><td><button class="btn small secondary" data-kb-open="${e(d.id)}">${e(d.title)}</button></td><td>${e(d.locale)}</td><td>${e(d.visibility)}</td><td>${e(d.status)}</td><td>${Number(d.revision)}</td></tr>`)
  const editor = selected || {title:'',slug:'',body:'',locale:'ru',visibility:'internal',status:'draft',category_id:'',revision:0}
  return `<section class="card"><h2>AI Knowledge Base · Управляемая база знаний</h2><p>Индекс хранится отдельно от публичного поиска CMS. Внутренние документы доступны только в административном интерфейсе. OpenAI не получает документы автоматически.</p></section>
  <div class="two-col"><section class="card"><h2>Категории</h2>${categories.map(c=>`<div class="toolbar"><strong>${e(c.name)}</strong><span class="muted">${e(c.slug)}</span><button class="btn small secondary" data-kb-edit-cat="${e(c.id)}">Править</button><button class="btn small danger" data-kb-del-cat="${e(c.id)}">Удалить</button></div>`).join('')||'<p>Категорий нет.</p>'}
   <form class="form" data-kb-category><input type="hidden" name="id"><label>Название<input class="input" name="name" maxlength="160" required></label><label>Slug (латиница)<input class="input" name="slug" maxlength="80" required></label><label>Описание<textarea class="input" name="description" maxlength="1500"></textarea></label><button class="btn" type="submit">Сохранить категорию</button><button type="reset" class="btn secondary">Очистить</button></form></section>
  <section class="card"><h2>Документы · ${Number(documents.total||0)}</h2><form class="toolbar" data-kb-filter><input class="input" name="query" maxlength="120" placeholder="Название" value="${e(k.query)}"><button class="btn">Найти</button><button type="button" class="btn secondary" data-kb-new>Новый документ</button></form>
   ${table(['Документ','Язык','Доступ','Состояние','Ревизия'],docRows)}<div class="toolbar"><button class="btn small secondary" data-kb-prev ${k.offset===0?'disabled':''}>Назад</button><span>${k.offset+1}–${Math.min(Number(documents.total||0),k.offset+30)}</span><button class="btn small secondary" data-kb-next ${k.offset+30>=Number(documents.total||0)?'disabled':''}>Далее</button></div></section></div>
  <section class="card"><h2>${selected?'Редактировать':'Создать'} документ</h2>${selected?`<p>Источник: ${e(selected.source_kind)} · Ревизия: ${Number(selected.revision)} · Индекс: ${Number(selected.indexed_revision)}</p>`:''}
  <form class="form" data-kb-document><div class="two-col"><label>Название<input name="title" class="input" maxlength="240" value="${e(editor.title)}" required></label><label>Slug<input name="slug" class="input" maxlength="80" value="${e(editor.slug)}" required></label></div><div class="two-col"><label>Язык<input name="locale" class="input" maxlength="5" value="${e(editor.locale)}" required></label><label>Категория<select class="input" name="category_id">${options(categoryOptions,editor.category_id||'')}</select></label><label>Доступ<select class="input" name="visibility">${options([['internal','Внутренняя'],['public','Публичная']],editor.visibility)}</select></label><label>Состояние<select name="status" class="input">${options([['draft','Черновик'],['published','Опубликован'],['archived','Архив']],editor.status)}</select></label></div><label>Содержимое<textarea name="body" class="input" rows="11" maxlength="131072" required>${e(editor.body)}</textarea></label><div class="toolbar"><button class="btn" type="submit" ${k.busy?'disabled':''}>Сохранить и индексировать</button><button class="btn secondary" type="button" data-kb-new>Новый</button>${selected?`<button type="button" class="btn danger" data-kb-delete>Удалить документ</button>`:''}</div></form>${selected?.source_kind==='cms'?'<p class="muted">Синхронизированный текст нельзя изменить вручную. Категорию, статус и видимость можно изменить после проверки.</p>':''}</section>
  <div class="two-col"><section class="card"><h2>Импорт документов</h2><p>TXT / Markdown / поисковый PDF до 2 МиБ. Не сохраняет исходные бинарные файлы. Импорт создаёт черновик.</p><form data-kb-import class="form"><input class="input" type="file" name="file" accept=".txt,.md,.markdown,.pdf" required><button type="submit" class="btn" ${k.busy?'disabled':''}>Импортировать</button></form></section>
  <section class="card"><h2>Опубликованная CMS-страница</h2><p>Повторная синхронизация обновляет индекс того же документа.</p><form data-kb-cms class="form"><label>Тип контента<select class="input" name="type">${options(typeOptions,k.kind||typeOptions[0]?.[0]||'')}</select></label><label>UUID опубликованной записи<input class="input" name="entry_id" value="${e(k.entry)}" required></label><button type="submit" class="btn" ${k.busy?'disabled':''}>Синхронизировать</button></form></section></div>
  <section class="card"><h2>Поиск по индексу</h2><form data-kb-search class="toolbar"><input class="input" name="q" maxlength="200" placeholder="Поиск по содержимому" value="${e(k.search)}" required><select class="input" name="visibility">${options([['all','Все'],['public','Публичные'],['internal','Внутренние']],k.visibility)}</select><button class="btn" type="submit">Искать</button><button class="btn secondary" type="button" data-kb-reindex>Перестроить индекс (20 документов)</button></form>${k.results.map(r=>`<article class="card"><button class="btn small secondary" data-kb-open="${e(r.id)}">${e(r.title)}</button><small> · ${e(r.locale)} · ${e(r.visibility)} · ${Number(r.score)}</small><p style="white-space:pre-wrap">${e(r.excerpt)}</p></article>`).join('')}</section>`
}

function bindKnowledge() {
  if (state.route !== 'knowledge') return
  const k=state.forms.knowledge
  const refresh=async()=>{
    await Promise.all([loadSilent('kbCategories','/knowledge/categories'),loadSilent('kbDocuments','/knowledge/documents?'+new URLSearchParams({limit:'30',offset:String(k.offset),q:k.query}))]);render()
  }
  const run=async fn=>{
    if(k.busy)return;k.busy=true
    try{await fn()}catch(err){setNotice('error',err.message,err.payload)}finally{k.busy=false;render()}
  }
  document.querySelectorAll('[data-kb-new]').forEach(b=>b.addEventListener('click',()=>{k.selected=null;render()}))
  document.querySelectorAll('[data-kb-open]').forEach(b=>b.addEventListener('click',()=>run(async()=>{
    k.selected=dataOf(await api('/knowledge/documents/'+encodeURIComponent(b.dataset.kbOpen)));render()
  })))
  document.querySelector('[data-kb-filter]')?.addEventListener('submit',event=>{event.preventDefault();k.query=String(new FormData(event.currentTarget).get('query')||'');k.offset=0;run(refresh)})
  for(const [key,delta] of [['prev',-30],['next',30]]) document.querySelector('[data-kb-'+key+']')?.addEventListener('click',()=>{k.offset=Math.max(0,k.offset+delta);run(refresh)})
  document.querySelector('[data-kb-category]')?.addEventListener('submit',event=>{event.preventDefault();const f=new FormData(event.currentTarget);run(async()=>{
    const id=String(f.get('id')||''),data={name:f.get('name'),slug:f.get('slug'),description:f.get('description')}
    await api('/knowledge/categories'+(id?'/'+encodeURIComponent(id):''),{method:id?'PUT':'POST',body:JSON.stringify(data)})
    setNotice('ok','Категория сохранена.');await refresh()
  })})
  document.querySelectorAll('[data-kb-edit-cat]').forEach(b=>b.addEventListener('click',()=>{
    const cat=asArray(state.cache.kbCategories).find(x=>x.id===b.dataset.kbEditCat)
    if(!cat)return
    const form=document.querySelector('[data-kb-category]');for(const field of ['id','name','slug','description'])form.elements.namedItem(field).value=cat[field]||''
  }))
  document.querySelectorAll('[data-kb-del-cat]').forEach(b=>b.addEventListener('click',()=>{if(!confirm('Удалить пустую категорию?'))return;run(async()=>{await api('/knowledge/categories/'+encodeURIComponent(b.dataset.kbDelCat),{method:'DELETE'});await refresh()})}))
  document.querySelector('[data-kb-document]')?.addEventListener('submit',event=>{event.preventDefault();const f=new FormData(event.currentTarget);run(async()=>{
    const selected=k.selected,body={title:f.get('title'),slug:f.get('slug'),body:f.get('body'),locale:f.get('locale'),visibility:f.get('visibility'),status:f.get('status'),category_id:f.get('category_id')||null}
    if(selected)body.expected_revision=Number(selected.revision)
    k.selected=dataOf(await api('/knowledge/documents'+(selected?'/'+encodeURIComponent(selected.id):''),{method:selected?'PUT':'POST',body:JSON.stringify(body)}))
    setNotice('ok','Документ сохранён и индекс обновлён.');await refresh()
  })})
  document.querySelector('[data-kb-delete]')?.addEventListener('click',()=>{if(!k.selected||!confirm('Удалить документ и индекс?'))return;run(async()=>{
    await api('/knowledge/documents/'+encodeURIComponent(k.selected.id),{method:'DELETE',body:JSON.stringify({expected_revision:Number(k.selected.revision)})})
    k.selected=null;await refresh();setNotice('ok','Документ удалён.')
  })})
  document.querySelector('[data-kb-import]')?.addEventListener('submit',event=>{event.preventDefault();const file=event.currentTarget.querySelector('input[type=file]').files?.[0];if(!file)return;run(async()=>{
    if(file.size>2097152||!(/\.(txt|md|markdown|pdf)$/i.test(file.name)))throw new Error('Допустимы TXT, Markdown и PDF до 2 МиБ.')
    const bytes=new Uint8Array(await file.arrayBuffer());let raw='';for(let i=0;i<bytes.length;i+=16384)raw+=String.fromCharCode(...bytes.subarray(i,i+16384))
    k.selected=dataOf(await api('/knowledge/import',{method:'POST',body:JSON.stringify({filename:file.name,base64:btoa(raw),locale:'ru',visibility:'internal',status:'draft'})}));
    await refresh();setNotice('ok','Импортирован черновик.')
  })})
  document.querySelector('[data-kb-cms]')?.addEventListener('submit',event=>{event.preventDefault();const f=new FormData(event.currentTarget);k.kind=String(f.get('type'));k.entry=String(f.get('entry_id'));run(async()=>{
    k.selected=dataOf(await api('/knowledge/sync-cms',{method:'POST',body:JSON.stringify({type:k.kind,entry_id:k.entry})}));await refresh();setNotice('ok','CMS-содержимое синхронизировано. Новые страницы по умолчанию внутренние; проверьте перед публикацией.')
  })})
  document.querySelector('[data-kb-search]')?.addEventListener('submit',event=>{event.preventDefault();const f=new FormData(event.currentTarget);k.search=String(f.get('q'));k.visibility=String(f.get('visibility'));run(async()=>{
    k.results=asArray(await api('/knowledge/search?'+new URLSearchParams({q:k.search,visibility:k.visibility})));render()
  })})
  document.querySelector('[data-kb-reindex]')?.addEventListener('click',()=>run(async()=>{
    const next=prompt('С какого смещения перестроить индекс? (0 — первая партия)', '0');if(next===null)return;const offset=Number(next);if(!Number.isInteger(offset)||offset<0||offset>100000)throw new Error('Некорректное смещение.')
    const result=dataOf(await api('/knowledge/reindex',{method:'POST',body:JSON.stringify({limit:20,offset})}))
    setNotice('ok','Переиндексировано: '+Number(result.processed)+'. Следующее смещение: '+(result.next_offset??'завершено'));await refresh()
  }))
}


/** Production static SPA, same API and authorization policy as the Nuxt Knowledge Chat. */
function knowledgeChatView(){
  const e=escapeHtml,k=state.forms.knowledgeChat,conversations=asArray(state.cache.kbChatConversations)
  const status=dataOf(state.cache.aiStatus,{})
  const messages=k.messages.map(m=>`<article class="card" style="overflow-wrap:anywhere;background:${m.role==='user'?'#eff6ff':'#f8fafc'}">
    <h3>${m.role==='user'?'Вопрос':'Ответ'}</h3><p style="white-space:pre-wrap">${e(m.content)}</p>
    ${m.revoked?'<p class="notice error">Источник изменён или недоступен; ответ скрыт.</p>':''}
    ${(m.sources||[]).length?`<h4>Подтверждённые источники</h4><ul>${m.sources.map(src=>`<li>[${Number(src.number)}] <button class="btn small secondary" data-kc-source="${e(src.id)}">${e(src.title)}</button> · ${e(src.locale)} · ревизия ${Number(src.revision)}, фрагмент ${Number(src.chunk_no)+1}</li>`).join('')}</ul>`:''}
    ${m.role==='assistant'&&!m.revoked?`<div class="toolbar"><button class="btn small secondary" data-kc-rate="${e(m.id)}" data-rating="1" ${Number(m.feedback)===1?'aria-pressed="true"':''}>Полезно</button><button class="btn small secondary" data-kc-rate="${e(m.id)}" data-rating="-1" ${Number(m.feedback)===-1?'aria-pressed="true"':''}>Не помогло</button></div>`:''}
  </article>`).join('')
  const options=(values,value)=>values.map(([v,l])=>`<option value="${e(v)}" ${v===value?'selected':''}>${e(l)}</option>`).join('')
  return `<section class="card"><h2>AI Knowledge Chat / RAG</h2><p>Рабочие ответы по опубликованной базе знаний и импортированным CMS-страницам. Документы отправляются в OpenAI только при вопросе. История приватна и ссылки сверяются с актуальной ревизией.</p>${!status?.configured?'<p class="notice">Для генерации нужен API-ключ OpenAI в настройках AI Assistant.</p>':''}</section>
   <div class="two-col"><section class="card"><h3>Диалоги</h3><button class="btn secondary" data-kc-new ${k.busy?'disabled':''}>Новый диалог</button>
   ${conversations.map(c=>`<div class="toolbar" style="margin:8px 0"><button class="btn small secondary" data-kc-open="${e(c.id)}" ${k.busy?'disabled':''}>${e(c.title)}</button><small>${e(c.visibility)}</small><button class="btn small danger" data-kc-delete="${e(c.id)}" ${k.busy?'disabled':''}>Удалить</button></div>`).join('')||'<p>История пуста.</p>'}</section>
   <section class="card"><h3>Ответы с цитированием</h3><div class="toolbar"><label>Источники <select id="kc-visibility" class="input" ${k.active||k.busy?'disabled':''}>${options([['all','Все доступные'],['public','Только публичные']],k.visibility)}</select></label>
   <label>Язык <select id="kc-locale" class="input" ${k.active||k.busy?'disabled':''}>${options([['','Все'],['ru','Русский'],['en','English']],k.locale)}</select></label></div>
   <div style="max-height:60vh;overflow:auto">${messages||'<p>Задайте вопрос по базе знаний. Если подтверждённых фрагментов нет, ответ будет ограничен.</p>'}</div>
   <form class="form" data-kc-ask><label>Вопрос <textarea name="question" class="input" maxlength="1200" rows="4" required>${e(k.question)}</textarea></label><button class="btn" type="submit" ${k.busy?'disabled':''}>${k.busy?'Формирование ответа…':'Спросить базу знаний'}</button></form></section></div>`
}
function bindKnowledgeChat(){
  if(state.route!=='knowledge-chat')return
  const k=state.forms.knowledgeChat
  const refresh=async()=>{await loadSilent('kbChatConversations','/knowledge/chat/conversations');render()}
  const run=async fn=>{
    if(k.busy)return;k.busy=true;render()
    try{await fn()}catch(err){setNotice('error',err.message,err.payload)}finally{k.busy=false;render()}
  }
  document.querySelector('[data-kc-new]')?.addEventListener('click',()=>{k.active=null;k.messages=[];k.question='';render()})
  document.querySelectorAll('[data-kc-open]').forEach(b=>b.addEventListener('click',()=>run(async()=>{
    const c=asArray(state.cache.kbChatConversations).find(c=>c.id===b.dataset.kcOpen)
    if(!c)return
    k.active=c.id;k.visibility=c.visibility;k.locale=c.locale;k.messages=asArray(await api('/knowledge/chat/conversations/'+encodeURIComponent(c.id)))
  })))
  document.querySelectorAll('[data-kc-delete]').forEach(b=>b.addEventListener('click',()=>{if(!confirm('Удалить диалог и все ответы?'))return;run(async()=>{
    await api('/knowledge/chat/conversations/'+encodeURIComponent(b.dataset.kcDelete),{method:'DELETE'})
    if(k.active===b.dataset.kcDelete){k.active=null;k.messages=[]}
    await refresh()
  })}))
  document.querySelectorAll('[data-kc-rate]').forEach(b=>b.addEventListener('click',()=>run(async()=>{
    const rating=Number(b.dataset.rating),id=b.dataset.kcRate
    await api('/knowledge/chat/messages/'+encodeURIComponent(id)+'/feedback',{method:'POST',body:JSON.stringify({rating})})
    const m=k.messages.find(m=>m.id===id);if(m)m.feedback=rating
  })))
  document.querySelectorAll('[data-kc-source]').forEach(b=>b.addEventListener('click',()=>run(async()=>{
    const doc=dataOf(await api('/knowledge/documents/'+encodeURIComponent(b.dataset.kcSource)))
    state.forms.knowledge.selected=doc;go('knowledge')
  })))
  document.querySelector('[data-kc-ask]')?.addEventListener('submit',event=>{
    event.preventDefault()
    const f=new FormData(event.currentTarget);k.question=String(f.get('question')||'').trim()
    const visibility=document.querySelector('#kc-visibility');const locale=document.querySelector('#kc-locale')
    if(!k.active){k.visibility=visibility?.value||'all';k.locale=locale?.value||''}
    if(!k.question)return
    run(async()=>{
      const answer=dataOf(await api('/knowledge/chat/ask',{method:'POST',body:JSON.stringify({conversation_id:k.active,question:k.question,visibility:k.visibility,locale:k.locale})}))
      k.active=answer.conversation_id;k.question=''
      k.messages=asArray(await api('/knowledge/chat/conversations/'+encodeURIComponent(k.active)))
      await refresh()
    })
  })
}

/** Full operator ticket workflow in the production static SPA (matches Nuxt UI). */
function supportView() {
  const e = escapeHtml, f = state.forms.support, data = dataOf(state.cache.supportTickets, {}), items = data.items || []
  const t = f.selected
  const opts = (options, selected) => options.map(([v, label]) => `<option value="${e(v)}" ${v === selected ? 'selected' : ''}>${e(label)}</option>`).join('')
  return `<div class="two-col"><section class="card"><h2>Очередь обращений · ${Number(data.total || 0)}</h2>
      <form data-sp-filter class="toolbar"><label>Статус <select name="status" class="input">${opts([['','Все'],['open','Открытые'],['waiting','Ожидание'],['resolved','Решённые'],['closed','Закрытые']],f.status)}</select></label><button class="btn secondary">Фильтр</button></form>
      ${(items.map(item=>`<article class="card" style="margin:8px 0;overflow-wrap:anywhere"><strong>${e(item.subject)}</strong><p>${e(item.name)} · ${e(item.email)}</p><small>${e(item.status)} · ${e(item.priority)} ${item.needs_human ? ' · требуется оператор':''}</small><div><button class="btn small secondary" data-sp-open="${e(item.id)}">Открыть</button></div></article>`).join('')) || '<p>Обращений нет.</p>'}
      <div class="toolbar"><button class="btn secondary" data-sp-prev ${f.offset===0?'disabled':''}>Назад</button><button class="btn secondary" data-sp-next ${f.offset+30>=Number(data.total||0)?'disabled':''}>Далее</button></div>
    </section><section class="card">${t ? `<h2>${e(t.subject)}</h2><p><strong>${e(t.name)}</strong> · ${e(t.email)} · ${e(t.locale)}</p>
      <form class="form" data-sp-update><label>Статус <select class="input" name="status">${opts([['open','Открыто'],['waiting','Ожидание посетителя'],['resolved','Решено'],['closed','Закрыто']],f.ticketStatus)}</select></label>
      <label>Приоритет <select name="priority" class="input">${opts([['low','Низкий'],['normal','Обычный'],['high','Высокий'],['urgent','Срочный']],f.priority)}</select></label>
      <label>Ответственный (UUID пользователя)<input class="input" name="assigned_to" value="${e(f.assignee)}" maxlength="36" placeholder="Не назначен"></label><button class="btn secondary" data-sp-self type="button">Назначить себе</button><button class="btn" ${f.busy?'disabled':''}>Сохранить статус и назначение</button></form>
      <h3>Переписка</h3><div style="max-height:46vh;overflow:auto;display:grid;gap:8px" aria-live="polite">${(t.messages||[]).map(msg=>`<article class="card" style="background:${msg.role==='visitor'?'#eff6ff':msg.role==='ai'?'#ecfdf5':'#f8fafc'};overflow-wrap:anywhere"><strong>${msg.role==='visitor'?'Посетитель':msg.role==='ai'?'AI':'Оператор'}</strong><p style="white-space:pre-wrap">${e(msg.content)}</p>${(msg.sources||[]).map(src=>`<small>[${Number(src.number)}] ${e(src.title)}</small>`).join('')}</article>`).join('')}</div>
      <form class="form" data-sp-reply><label>Ответ<textarea name="message" class="input" maxlength="6000" rows="5" required ${t.status==='closed'?'disabled':''}>${e(f.reply)}</textarea></label><div class="toolbar"><button class="btn secondary" type="button" data-sp-draft ${f.busy||t.status==='closed'?'disabled':''}>AI-подсказка</button><button class="btn" ${f.busy||t.status==='closed'?'disabled':''}>Ответить</button></div></form>
      ${f.draft?`<p class="notice">AI-черновик требует проверки оператором перед отправкой.</p><ul>${f.draftSources.map(src=>`<li>[${Number(src.number)}] ${e(src.title)}</li>`).join('')}</ul>`:''}` : '<p>Выберите обращение для работы.</p>'}</section></div>`
}
function bindSupport() {
  if (state.route !== 'support') return
  const f = state.forms.support
  const reload = async () => {
    await routeLoad('support')
    if (f.selected) f.selected = dataOf(await api('/support/tickets/' + encodeURIComponent(f.selected.id)))
  }
  const run = async fn => {
    if (f.busy) return
    f.busy = true; render()
    try { await fn() } catch (err) { setNotice('error', err.message, err.payload) }
    finally { f.busy = false; render() }
  }
  document.querySelector('[data-sp-filter]')?.addEventListener('submit', event => {
    event.preventDefault(); f.status = String(new FormData(event.currentTarget).get('status')); f.offset = 0
    run(async () => { await reload() })
  })
  document.querySelector('[data-sp-prev]')?.addEventListener('click', () => run(async () => { f.offset = Math.max(0, f.offset - 30); await reload() }))
  document.querySelector('[data-sp-next]')?.addEventListener('click', () => run(async () => { f.offset += 30; await reload() }))
  document.querySelectorAll('[data-sp-open]').forEach(button => button.addEventListener('click', () => run(async () => {
    f.selected = dataOf(await api('/support/tickets/' + encodeURIComponent(button.dataset.spOpen)))
    f.assignee = f.selected.assigned_to || ''; f.priority = f.selected.priority; f.ticketStatus = f.selected.status
    f.reply = ''; f.draft = ''; f.draftSources = []
  })))
  document.querySelector('[data-sp-self]')?.addEventListener('click', () => {
    f.assignee = state.identity?.user_id || '';
    const input = document.querySelector('[data-sp-update] input[name=assigned_to]'); if(input)input.value=f.assignee
  })
  document.querySelector('[data-sp-update]')?.addEventListener('submit', event => {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    const body = {expected_revision:Number(f.selected.revision),status:String(data.get('status')),priority:String(data.get('priority')),assigned_to:String(data.get('assigned_to')).trim()||null}
    run(async () => { f.selected = dataOf(await api('/support/tickets/'+encodeURIComponent(f.selected.id),{method:'PATCH',body:JSON.stringify(body)})); await reload();setNotice('ok','Обращение обновлено.') })
  })
  document.querySelector('[data-sp-reply]')?.addEventListener('submit', event => {
    event.preventDefault()
    const message = String(new FormData(event.currentTarget).get('message')||'').trim()
    if (!message) return
    run(async () => { f.selected = dataOf(await api('/support/tickets/'+encodeURIComponent(f.selected.id)+'/messages',{method:'POST',body:JSON.stringify({message})})); f.reply='';f.draft='';f.draftSources=[];await reload();setNotice('ok','Ответ отправлен.') })
  })
  document.querySelector('[data-sp-draft]')?.addEventListener('click', () => run(async () => {
    const result = dataOf(await api('/support/tickets/'+encodeURIComponent(f.selected.id)+'/ai-draft',{method:'POST',body:'{}'}))
    f.draft = result.text || '';f.reply = f.draft;f.draftSources = result.sources || []
    setNotice('ok',f.draft?'Черновик готов. Проверьте текст перед отправкой.':'Нет подтверждённых публичных источников; ответьте вручную.')
  }))
}
