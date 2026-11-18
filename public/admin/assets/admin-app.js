const ADMIN_VERSION = '1.5.1'
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
  ['tracking', '📈', 'Счётчики', 'Яндекс Метрика, GA4, GTM, Matomo и Plausible'],
  ['analytics-hub', '📊', 'Analytics Hub', 'Отчёты трафика и поисковой статистики'],
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
    entryType: 'pages',
    entryStatus: 'all',
    entrySearch: '',
    entryOffset: 0, parentSearch: '',
    entry: defaultEntry(),
    mediaTitle: '',
    mediaAlt: '',
    user: { name: '', email: '', password: '', roles: 'admin' },
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
    if (route === 'tracking') await loadSilent('tracking', '/tracking/counters')
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
  return `<div class="shell"><aside class="sidebar"><div class="brand"><div class="brand-mark">CE</div><div><div class="brand-title">CajeerEngine</div><div class="brand-sub">Admin UI ${ADMIN_VERSION}</div></div></div><nav class="nav">${nav.filter(([id]) => (id !== 'migration' || integrationScope('migration:read')) && (id !== 'integrations' || integrationScope('integrations:read')) && (id !== 'privacy' || integrationScope('privacy:read')) && (id !== 'analytics-hub' || integrationScope('analytics:read')) && (!['tracking','analytics-events'].includes(id) || integrationScope('tracking:read'))).map(([id, icon, label]) => `<button class="${state.route === id ? 'active' : ''}" data-route="${id}"><span class="emoji">${icon}</span><span>${label}</span></button>`).join('')}</nav><div class="footer-note">API: <span class="code-inline">${API_BASE}</span><br>${state.identity ? `User: ${escapeHtml(state.identity.email || state.identity.name || state.identity.user_id || 'auth')}` : 'Без активной сессии'}</div></aside><main class="main"><div class="topbar"><div><h1>${escapeHtml(title)}</h1><p>${escapeHtml(subtitle || '')}</p></div><div class="toolbar">${toolbar}<button class="btn secondary" data-action="refresh">Обновить</button>${state.token ? '<button class="btn ghost" data-action="logout">Выйти</button>' : '<button class="btn" data-route="login">Войти</button>'}</div></div>${noticeHtml()}${state.loading ? '<div class="notice">Загрузка...</div>' : ''}${body}</main></div>`
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
  else if (state.route === 'tracking') body = trackingView()
  else if (state.route === 'analytics-hub') body = analyticsHubView()
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
  return `<div class="grid two"><div class="card"><h2>1. Проекты миграции</h2><p>Анализ источника без изменения сайта назначения.</p>${projects.map(p=>`<div class="notice"><strong>${escapeHtml(p.name)}</strong> · ${escapeHtml(kinds[p.source])} · ${escapeHtml(p.status)} <button class="btn secondary" data-migration-open="${p.id}">Открыть</button></div>`).join('')||'<p>Нет проектов.</p>'}${manage?`<button class="btn secondary" data-migration-new>Новый проект</button><h3>${editing?'Изменить подключение':'Создать проект'}</h3><form class="form" data-migration-form data-id="${editing?selected.id:''}">${field('name','Название',editing?selected.name:'')}<label>Источник<select name="source">${Object.entries(kinds).map(([k,v])=>`<option value="${k}" ${k===source?'selected':''}>${escapeHtml(v)}</option>`).join('')}</select></label>${sourceFields}<p>Изменение подключения сбрасывает предыдущий отчёт; credentials нужно ввести заново.</p><button class="btn" type="submit">Сохранить проект</button></form>`:''}</div><div class="card"><h2>2. Анализ источника</h2>${selected?`<h3>${escapeHtml(selected.name)}</h3><p>${escapeHtml(kinds[selected.source])} · ревизия ${selected.revision}</p>${manage&&!completed?'<button class="btn secondary" data-migration-edit>Изменить подключение</button>':''}${write&&!completed?'<button class="btn ghost" data-migration-delete>Удалить</button>':''}${run&&!completed?`<button class="btn" data-migration-scan>Запустить / повторить анализ</button>${selected.source==='wordpress-wxr'&&write?'<form class="form" data-migration-upload><label>WXR XML (до 32 MiB)<input type="file" name="file" accept=".xml,text/xml,application/xml" required></label><button class="btn" type="submit">Загрузить и анализировать</button></form>':''}`:''}${selected.error?`<div class="notice error">${escapeHtml(selected.error)}${report?' (ниже предыдущий успешный отчёт)':''}</div>`:''}${report?`<h2>3. Отчёт совместимости</h2><p>Последний успешный анализ: ${escapeHtml(report.scanned_at)}</p><table><thead><tr><th>Сущность</th><th>Количество</th><th>Совместимость</th></tr></thead><tbody>${Object.entries(report.counts||{}).map(([k,v])=>`<tr><td>${escapeHtml(k)}</td><td>${v===null?'Недоступно':escapeHtml((report.details?.lower_bound_counts||[]).includes(k)?'≥ '+v:v)}</td><td>${escapeHtml(report.compatibility?.[k])}</td></tr>`).join('')}</tbody></table><h3>Типы, статусы, поля и структура</h3><pre>${escapeHtml(JSON.stringify({types:report.types,statuses:report.statuses,fields:report.fields,details:report.details},null,2))}</pre><h3>Ограничения</h3><ul>${(report.warnings||[]).map(w=>`<li>${escapeHtml(w)}</li>`).join('')}</ul><button class="btn secondary" data-migration-export>Скачать JSON отчёт</button>`:'<p>Отчёт появится после успешного анализа.</p>'}`:'<p>Создайте или откройте проект.</p>'}</div></div>${selected&&report&&!selected.error?migrationPlanningView(selected,write&&!completed,run&&!completed)+migrationExecutionView(selected):''}`
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
  document.querySelectorAll('[data-migration-open]').forEach(b=>b.addEventListener('click',()=>{state.cache.migrationSelected=b.dataset.migrationOpen;state.cache.migrationEditing=false;render()}))
  document.querySelector('[data-migration-new]')?.addEventListener('click',()=>{state.cache.migrationSelected=null;state.cache.migrationEditing=false;render()})
  document.querySelector('[data-migration-edit]')?.addEventListener('click',()=>{state.cache.migrationEditing=true;render()})
  const f=document.querySelector('[data-migration-form]')
  if(f){const sync=()=>{const v=f.elements.source.value;for(const [sel,show] of [['[data-migration-db]',v.endsWith('-db')],['[data-migration-rest]',v==='wordpress-rest'],['[data-migration-credentials]',v!=='wordpress-wxr']]){const el=f.querySelector(sel);el.hidden=!show;el.querySelectorAll('input,select').forEach(i=>i.disabled=!show)}};sync();f.elements.source.addEventListener('change',sync);f.addEventListener('submit',e=>{e.preventDefault();execute(f,async()=>{const d=new FormData(f),source=d.get('source');const body={name:d.get('name'),source,config:source.endsWith('-db')?{host:d.get('host'),port:Number(d.get('port')),database:d.get('database'),prefix:d.get('prefix'),charset:d.get('charset')}:source==='wordpress-rest'?{url:d.get('url')}: {},credential:source==='wordpress-wxr'?{}:{username:d.get('username')||'',password:d.get('password')||''}};const id=f.dataset.id;if(id)body.revision=selected.revision;const p=dataOf(await api('/migration/projects'+(id?'/'+id:''),{method:id?'PUT':'POST',body:JSON.stringify(body)}));f.elements.password.value='';state.cache.migrationSelected=p.id;state.cache.migrationEditing=false;await refresh()})})}
  if(!selected)return
  const path='/migration/projects/'+selected.id
  bindMigrationPlanning(selected,path,execute,refresh)
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
  bindMedia()
  bindCms()
  bindUsers()
  bindPrivacy()
  bindTracking()
  bindAnalyticsEvents()
  bindAnalyticsHub()
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

function dashboardView() {
  const dashboard = dataOf(state.cache.dashboard, {})
  const cards = dashboard.cards || []
  const bootstrap = dataOf(state.cache.bootstrap, {})
  return `<div class="grid cards">${cards.map((card) => `<div class="card kpi"><div class="kpi-label">${escapeHtml(card.label)}</div><div class="kpi-value">${escapeHtml(card.value)}</div><span class="badge ${card.status || 'ok'}">${escapeHtml(card.status || 'ok')}</span></div>`).join('') || '<div class="card"><h2>Admin API</h2><p>Нажмите “Обновить”, чтобы получить dashboard.</p></div>'}</div><div class="grid two"><div class="card"><h2>Продукт</h2><p>Admin UI 1.4.5 — production static SPA с CRUD-экранами, авторизацией, route guards и обработкой 401/403.</p><div class="status-line"><span class="badge ok">static assets</span><span class="badge ok">Bearer auth</span><span class="badge ok">CMS CRUD</span><span class="badge ok">media upload</span></div></div><div class="card"><h2>Bootstrap</h2><pre class="json">${escapeHtml(JSON.stringify(bootstrap.app || bootstrap, null, 2))}</pre></div></div>`
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
  return `<div class="card"><div class="toolbar"><select class="input" style="max-width:220px" data-entry-type>${types.map((t) => `<option value="${escapeHtml(t.handle)}" ${t.handle === state.forms.entryType ? 'selected' : ''}>${escapeHtml(t.name || t.handle)}</option>`).join('')}</select><select class="input" style="max-width:170px" data-entry-status>${['all','draft','published','archived'].map(v=>`<option value="${v}" ${state.forms.entryStatus===v?'selected':''}>${v==='all'?'Все':v}</option>`).join('')}</select><input class="input" style="max-width:260px" data-entry-q placeholder="Поиск" value="${escapeHtml(state.forms.entrySearch)}"><button class="btn secondary" data-action="filter-entries">Фильтр</button></div></div><div class="grid two"><div class="card"><h2>Записи</h2>${table(['Запись', 'Статус', 'Locale', 'Slug/path', 'Действия'], rows)}<div class="toolbar"><button class="btn secondary" data-entry-page="prev" ${state.forms.entryOffset===0?'disabled':''}>Назад</button><span>${state.forms.entryOffset+1}–${state.forms.entryOffset+entries.length} / ${state.cache.entries?.meta?.total??entries.length}</span><button class="btn secondary" data-entry-page="next" ${state.forms.entryOffset+entries.length>=(state.cache.entries?.meta?.total??entries.length)?'disabled':''}>Далее</button></div></div><div class="card"><h2>Редактор записи</h2><form class="form" data-form="entry"><div class="split"><div class="form-row"><label>ID для update</label><input class="input" name="id" value="${escapeHtml(state.forms.entry.id)}" placeholder="оставить пустым для create"></div><div class="form-row"><label>Status</label><select class="input" name="status"><option value="draft" ${state.forms.entry.status==='draft'?'selected':''}>draft</option><option value="published" ${state.forms.entry.status==='published'?'selected':''}>published</option><option value="archived" ${state.forms.entry.status==='archived'?'selected':''}>archived</option></select></div></div><div class="split"><div class="form-row"><label>Title</label><input class="input" name="title" value="${escapeHtml(state.forms.entry.title)}"></div><div class="form-row"><label>Slug</label><input class="input" name="slug" value="${escapeHtml(state.forms.entry.slug)}"></div></div><div class="split"><div class="form-row"><label>Locale</label><input class="input" name="locale" value="${escapeHtml(state.forms.entry.locale)}"></div><div class="form-row"><label>Path (корневая страница)</label><input class="input" name="path" value="${escapeHtml(state.forms.entry.path)}"></div></div>${state.forms.entryType==='pages'?`<div class="form-row"><label>Родительская страница (тот же язык)</label><div class="toolbar"><input class="input" data-parent-q placeholder="Поиск родителя" value="${escapeHtml(state.forms.parentSearch)}"><button type="button" class="btn secondary" data-parent-search>Найти</button></div><select class="input" name="parent_id">${parentOptions}</select><small>URL дочерней страницы определяется путём родителя и slug.</small></div>`:''}<div class="form-row"><label>Fields JSON</label><textarea class="input code" name="fields">${escapeHtml(state.forms.entry.fields)}</textarea></div><div class="form-row" data-media-picker><label>Выбор медиа для поля контента</label><select class="input" data-picker-field>${(types.find(t=>t.handle===state.forms.entryType)?.fields||[]).filter(f=>f.type==='media').map(f=>`<option value="${escapeHtml(f.handle)}">${escapeHtml(f.label||f.handle)}</option>`).join('')}</select><div class="toolbar"><input class="input" data-picker-q placeholder="Поиск медиа"><button class="btn secondary" type="button" data-picker-search>Найти</button></div><select class="input" data-picker-result></select><button class="btn secondary" type="button" data-picker-apply>Вставить UUID в выбранное поле</button></div><div class="form-row"><label>SEO JSON</label><textarea class="input code" name="seo">${escapeHtml(state.forms.entry.seo)}</textarea></div><div class="toolbar"><button class="btn" type="submit">Сохранить</button><button class="btn secondary" type="button" data-action="reset-entry">Очистить</button><button class="btn ghost" type="button" data-action="preview-entry">Preview token</button></div></form></div></div>`
}

function bindContent() {
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
  document.querySelector('[data-action="reset-entry"]')?.addEventListener('click', () => { state.forms.entry = defaultEntry(); render() })
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
