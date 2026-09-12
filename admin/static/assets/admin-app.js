const ADMIN_VERSION = '1.1.1'
const API_BASE = '/api/v1'
const TOKEN_KEY = 'cajeerengine.admin.token'
const app = document.getElementById('app')

const nav = [
  ['dashboard', '📊', 'Обзор', 'Состояние продукта'],
  ['content-types', '🧩', 'Типы контента', 'Field builder и schema'],
  ['content', '📝', 'Контент', 'Записи, публикации, ревизии'],
  ['media', '🖼️', 'Media', 'Загрузка и управление файлами'],
  ['cms', '🌐', 'CMS', 'Тема, меню, редиректы, preview'],
  ['users', '👥', 'Доступ', 'Пользователи, роли, tokens'],
  ['extensions', '🔌', 'Extensions', 'Runtime, lifecycle, events'],
  ['updates', '⬆️', 'Updates', 'Maintenance и rollback'],
  ['search', '🔎', 'Search', 'Поиск и reindex'],
  ['import-export', '📦', 'Import/export', 'Пакеты, diff, run'],
  ['system', '⚙️', 'System', 'Диагностика API/runtime'],
]

const state = {
  route: location.hash.replace(/^#\/?/, '') || 'dashboard',
  token: localStorage.getItem(TOKEN_KEY) || '',
  identity: null,
  loading: false,
  notice: null,
  cache: {},
  forms: {
    contentType: defaultContentType(),
    entryType: 'pages',
    entryStatus: 'all',
    entrySearch: '',
    entry: defaultEntry(),
    mediaTitle: '',
    mediaAlt: '',
    user: { name: '', email: '', password: '', roles: 'admin' },
    role: { handle: '', name: '', permissions: 'content:read,content:write' },
    token: { name: 'Admin token', scopes: 'content:read,content:write' },
    redirect: { from: '', to: '/', status: 301, enabled: true },
    navigationHandle: 'main',
    navigationJson: JSON.stringify({ handle: 'main', title: 'Main menu', items: [{ label: 'Главная', url: '/' }] }, null, 2),
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
  return {
    handle: 'pages',
    name: 'Pages',
    fields: JSON.stringify([
      { handle: 'title', label: 'Заголовок', type: 'text', required: true },
      { handle: 'body', label: 'Текст', type: 'richtext', required: false },
      { handle: 'seo_title', label: 'SEO title', type: 'text', required: false },
      { handle: 'seo_description', label: 'SEO description', type: 'textarea', required: false }
    ], null, 2)
  }
}

function defaultEntry() {
  return {
    id: '',
    title: '',
    slug: '',
    status: 'draft',
    locale: 'ru',
    path: '/',
    fields: JSON.stringify({ title: 'Новая страница', body: '<p>Текст страницы</p>' }, null, 2),
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
  if (state.token) headers.set('Authorization', `Bearer ${state.token}`)
  const response = await fetch(`${API_BASE}${path}`, { ...options, headers })
  const text = await response.text()
  let payload = null
  if (text) {
    try { payload = JSON.parse(text) } catch { payload = { raw: text } }
  }
  if (response.status === 401) {
    state.identity = null
    localStorage.removeItem(TOKEN_KEY)
    state.token = ''
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
  try {
    const payload = await api('/auth/me')
    state.identity = dataOf(payload)
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
    if (route === 'content') await Promise.allSettled([loadSilent('contentTypes', '/content-types'), loadSilent('entries', `/content/${encodeURIComponent(state.forms.entryType)}?status=${encodeURIComponent(state.forms.entryStatus)}&q=${encodeURIComponent(state.forms.entrySearch)}`)])
    if (route === 'media') await loadSilent('media', '/media')
    if (route === 'cms') await Promise.allSettled([loadSilent('cmsOverview', '/cms'), loadSilent('theme', '/cms/theme'), loadSilent('navigation', '/cms/navigation'), loadSilent('redirects', '/cms/redirects')])
    if (route === 'users') await Promise.allSettled([loadSilent('users', '/users'), loadSilent('roles', '/roles'), loadSilent('tokens', '/api-tokens')])
    if (route === 'extensions') await Promise.allSettled([loadSilent('extensions', '/extensions'), loadSilent('extensionsRuntime', '/extensions/runtime'), loadSilent('extensionsDiagnostics', '/extensions/diagnostics')])
    if (route === 'updates') await Promise.allSettled([loadSilent('updates', '/updates'), loadSilent('maintenance', '/updates/maintenance')])
    if (route === 'search') await loadSilent('searchDiagnostics', '/search/diagnostics')
    if (route === 'import-export') await loadSilent('exports', '/import-export/exports')
    if (route === 'system') await Promise.allSettled([loadSilent('system', '/system'), loadSilent('runtime', '/runtime'), loadSilent('database', '/database'), loadSilent('security', '/security'), loadSilent('rc', '/rc/readiness'), loadSilent('stable', '/stable'), loadSilent('queue', '/queue'), loadSilent('scheduler', '/scheduler/tasks')])
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
  return `<div class="shell"><aside class="sidebar"><div class="brand"><div class="brand-mark">CE</div><div><div class="brand-title">CajeerEngine</div><div class="brand-sub">Admin UI ${ADMIN_VERSION}</div></div></div><nav class="nav">${nav.map(([id, icon, label]) => `<button class="${state.route === id ? 'active' : ''}" data-route="${id}"><span class="emoji">${icon}</span><span>${label}</span></button>`).join('')}</nav><div class="footer-note">API: <span class="code-inline">${API_BASE}</span><br>${state.identity ? `User: ${escapeHtml(state.identity.email || state.identity.name || state.identity.user_id || 'auth')}` : 'Без активной сессии'}</div></aside><main class="main"><div class="topbar"><div><h1>${escapeHtml(title)}</h1><p>${escapeHtml(subtitle || '')}</p></div><div class="toolbar">${toolbar}<button class="btn secondary" data-action="refresh">Обновить</button>${state.token ? '<button class="btn ghost" data-action="logout">Выйти</button>' : '<button class="btn" data-route="login">Войти</button>'}</div></div>${noticeHtml()}${state.loading ? '<div class="notice">Загрузка...</div>' : ''}${body}</main></div>`
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
  else if (state.route === 'extensions') body = extensionsView()
  else if (state.route === 'updates') body = updatesView()
  else if (state.route === 'search') body = searchView()
  else if (state.route === 'import-export') body = importExportView()
  else if (state.route === 'system') body = systemView()
  else body = dashboardView()
  app.innerHTML = shell(title, subtitle, body)
  bindCommon()
  bindForms()
}

function renderLogin() {
  app.innerHTML = `<div class="login-shell"><form class="login-card" data-form="login"><div class="brand" style="color:var(--text)"><div class="brand-mark">CE</div><div><div class="brand-title">CajeerEngine Admin</div><div class="brand-sub" style="color:var(--muted)">Production static SPA ${ADMIN_VERSION}</div></div></div>${noticeHtml()}<div class="form"><div class="form-row"><label>Email</label><input class="input" name="email" type="email" autocomplete="username" required></div><div class="form-row"><label>Пароль</label><input class="input" name="password" type="password" autocomplete="current-password" required></div><div class="form-row"><label>2FA код, если включён</label><input class="input" name="two_factor_code" inputmode="numeric"></div><button class="btn" type="submit">Войти</button><p class="muted">После входа Bearer token хранится в localStorage текущего браузера.</p></div></form></div>`
  document.querySelector('[data-form="login"]').addEventListener('submit', async (event) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    try {
      const payload = await api('/auth/login', { method: 'POST', body: JSON.stringify(Object.fromEntries(form.entries())) })
      const session = dataOf(payload, {})
      const token = session.token || session.access_token || session.plain_text_token || session.api_token
      if (!token) throw new Error('API не вернул token.')
      state.token = token
      state.identity = session.user || session.identity || null
      localStorage.setItem(TOKEN_KEY, token)
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
  document.querySelectorAll('[data-action="logout"]').forEach((button) => button.addEventListener('click', async () => { try { await api('/auth/logout', { method: 'POST', body: '{}' }) } catch {} state.token = ''; state.identity = null; localStorage.removeItem(TOKEN_KEY); go('login') }))
  document.querySelectorAll('[data-json]').forEach((el) => el.addEventListener('click', () => navigator.clipboard?.writeText(el.dataset.json || '')))
}

function bindForms() {
  bindContentTypes()
  bindContent()
  bindMedia()
  bindCms()
  bindUsers()
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
  return `<div class="grid cards">${cards.map((card) => `<div class="card kpi"><div class="kpi-label">${escapeHtml(card.label)}</div><div class="kpi-value">${escapeHtml(card.value)}</div><span class="badge ${card.status || 'ok'}">${escapeHtml(card.status || 'ok')}</span></div>`).join('') || '<div class="card"><h2>Admin API</h2><p>Нажмите “Обновить”, чтобы получить dashboard.</p></div>'}</div><div class="grid two"><div class="card"><h2>Продукт</h2><p>Admin UI 1.1.1 — production static SPA с CRUD-экранами, авторизацией, route guards и обработкой 401/403.</p><div class="status-line"><span class="badge ok">static assets</span><span class="badge ok">Bearer auth</span><span class="badge ok">CMS CRUD</span><span class="badge ok">media upload</span></div></div><div class="card"><h2>Bootstrap</h2><pre class="json">${escapeHtml(JSON.stringify(bootstrap.app || bootstrap, null, 2))}</pre></div></div>`
}

function contentTypesView() {
  const items = asArray(state.cache.contentTypes)
  const rows = items.map((item) => `<tr><td><strong>${escapeHtml(item.handle)}</strong><br><span class="muted">${escapeHtml(item.name || '')}</span></td><td>${escapeHtml((item.fields || []).length)} fields</td><td>${escapeHtml(item.updated_at || item.created_at || '')}</td><td class="row-actions"><button class="btn small secondary" data-edit-content-type="${escapeHtml(item.handle)}">Редактировать</button><button class="btn small ghost" data-schema-content-type="${escapeHtml(item.handle)}">Schema</button><button class="btn small danger" data-delete-content-type="${escapeHtml(item.handle)}">Удалить</button></td></tr>`)
  return `<div class="grid two"><div class="card"><h2>Типы контента</h2>${table(['Handle', 'Поля', 'Дата', 'Действия'], rows)}</div><div class="card"><h2>Создать / изменить тип</h2><form class="form" data-form="content-type"><div class="form-row"><label>Handle</label><input class="input" name="handle" value="${escapeHtml(state.forms.contentType.handle)}" required></div><div class="form-row"><label>Название</label><input class="input" name="name" value="${escapeHtml(state.forms.contentType.name)}" required></div><div class="form-row"><label>Fields JSON</label><textarea class="input code" name="fields">${escapeHtml(state.forms.contentType.fields)}</textarea></div><div class="toolbar"><button class="btn" type="submit">Сохранить</button><button class="btn secondary" type="button" data-action="reset-content-type">Очистить</button></div></form></div></div>`
}

function bindContentTypes() {
  const form = document.querySelector('[data-form="content-type"]')
  if (form) form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const fd = new FormData(form)
    const handle = String(fd.get('handle') || '').trim()
    try {
      const payload = { handle, name: String(fd.get('name') || '').trim(), fields: jsonParse(String(fd.get('fields') || '[]'), []) }
      const exists = asArray(state.cache.contentTypes).some((item) => item.handle === handle)
      await api(`/content-types${exists ? '/' + encodeURIComponent(handle) : ''}`, { method: exists ? 'PUT' : 'POST', body: JSON.stringify(payload) })
      setNotice('ok', 'Тип контента сохранён.')
      await routeLoad('content-types')
    } catch (error) { setNotice('error', error.message, error.payload) }
  })
  document.querySelectorAll('[data-edit-content-type]').forEach((button) => button.addEventListener('click', () => {
    const item = asArray(state.cache.contentTypes).find((x) => x.handle === button.dataset.editContentType)
    if (item) { state.forms.contentType = { handle: item.handle, name: item.name || item.handle, fields: JSON.stringify(item.fields || [], null, 2) }; render() }
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
  const rows = entries.map((entry) => `<tr><td><strong>${escapeHtml(entry.title || entry.slug || entry.id)}</strong><br><span class="muted">${escapeHtml(entry.id)}</span></td><td><span class="badge ${entry.status === 'published' ? 'ok' : entry.status === 'archived' ? 'warn' : ''}">${escapeHtml(entry.status || 'draft')}</span></td><td>${escapeHtml(entry.locale || '')}</td><td>${escapeHtml(entry.slug || entry.path || '')}</td><td class="row-actions"><button class="btn small secondary" data-edit-entry="${escapeHtml(entry.id)}">Редактировать</button><button class="btn small ghost" data-entry-action="publish" data-id="${escapeHtml(entry.id)}">Publish</button><button class="btn small ghost" data-entry-action="unpublish" data-id="${escapeHtml(entry.id)}">Unpublish</button><button class="btn small ghost" data-entry-action="archive" data-id="${escapeHtml(entry.id)}">Archive</button><button class="btn small ghost" data-entry-action="revisions" data-id="${escapeHtml(entry.id)}">Revisions</button><button class="btn small danger" data-entry-action="delete" data-id="${escapeHtml(entry.id)}">Удалить</button></td></tr>`)
  return `<div class="card"><div class="toolbar"><select class="input" style="max-width:220px" data-entry-type>${types.map((t) => `<option value="${escapeHtml(t.handle)}" ${t.handle === state.forms.entryType ? 'selected' : ''}>${escapeHtml(t.name || t.handle)}</option>`).join('')}</select><select class="input" style="max-width:170px" data-entry-status><option value="all">Все</option><option value="draft">draft</option><option value="published">published</option><option value="archived">archived</option></select><input class="input" style="max-width:260px" data-entry-q placeholder="Поиск" value="${escapeHtml(state.forms.entrySearch)}"><button class="btn secondary" data-action="filter-entries">Фильтр</button></div></div><div class="grid two"><div class="card"><h2>Записи</h2>${table(['Запись', 'Статус', 'Locale', 'Slug/path', 'Действия'], rows)}</div><div class="card"><h2>Редактор записи</h2><form class="form" data-form="entry"><div class="split"><div class="form-row"><label>ID для update</label><input class="input" name="id" value="${escapeHtml(state.forms.entry.id)}" placeholder="оставить пустым для create"></div><div class="form-row"><label>Status</label><select class="input" name="status"><option value="draft">draft</option><option value="published">published</option><option value="archived">archived</option></select></div></div><div class="split"><div class="form-row"><label>Title</label><input class="input" name="title" value="${escapeHtml(state.forms.entry.title)}"></div><div class="form-row"><label>Slug</label><input class="input" name="slug" value="${escapeHtml(state.forms.entry.slug)}"></div></div><div class="split"><div class="form-row"><label>Locale</label><input class="input" name="locale" value="${escapeHtml(state.forms.entry.locale)}"></div><div class="form-row"><label>Path</label><input class="input" name="path" value="${escapeHtml(state.forms.entry.path)}"></div></div><div class="form-row"><label>Fields JSON</label><textarea class="input code" name="fields">${escapeHtml(state.forms.entry.fields)}</textarea></div><div class="form-row"><label>SEO JSON</label><textarea class="input code" name="seo">${escapeHtml(state.forms.entry.seo)}</textarea></div><div class="toolbar"><button class="btn" type="submit">Сохранить</button><button class="btn secondary" type="button" data-action="reset-entry">Очистить</button><button class="btn ghost" type="button" data-action="preview-entry">Preview token</button></div></form></div></div>`
}

function bindContent() {
  document.querySelector('[data-entry-type]')?.addEventListener('change', (e) => { state.forms.entryType = e.target.value; routeLoad('content').then(render) })
  document.querySelector('[data-entry-status]')?.addEventListener('change', (e) => { state.forms.entryStatus = e.target.value })
  document.querySelector('[data-action="filter-entries"]')?.addEventListener('click', () => { state.forms.entrySearch = document.querySelector('[data-entry-q]')?.value || ''; routeLoad('content').then(render) })
  document.querySelector('[data-form="entry"]')?.addEventListener('submit', async (event) => {
    event.preventDefault()
    const fd = new FormData(event.currentTarget)
    try {
      const id = String(fd.get('id') || '').trim()
      const payload = { title: String(fd.get('title') || '').trim(), slug: String(fd.get('slug') || '').trim(), status: String(fd.get('status') || 'draft'), locale: String(fd.get('locale') || 'ru'), path: String(fd.get('path') || ''), fields: jsonParse(String(fd.get('fields') || '{}'), {}), seo: jsonParse(String(fd.get('seo') || '{}'), {}) }
      await api(`/content/${encodeURIComponent(state.forms.entryType)}${id ? '/' + encodeURIComponent(id) : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) })
      setNotice('ok', 'Запись сохранена.')
      await routeLoad('content')
    } catch (error) { setNotice('error', error.message, error.payload) }
  })
  document.querySelectorAll('[data-edit-entry]').forEach((button) => button.addEventListener('click', () => {
    const entry = asArray(state.cache.entries).find((x) => String(x.id) === button.dataset.editEntry)
    if (!entry) return
    state.forms.entry = { id: entry.id || '', title: entry.title || '', slug: entry.slug || '', status: entry.status || 'draft', locale: entry.locale || 'ru', path: entry.path || '', fields: JSON.stringify(entry.fields || {}, null, 2), seo: JSON.stringify(entry.seo || {}, null, 2) }
    render()
  }))
  document.querySelectorAll('[data-entry-action]').forEach((button) => button.addEventListener('click', async () => {
    const action = button.dataset.entryAction
    const id = button.dataset.id
    try {
      if (action === 'delete') { if (!confirm('Удалить запись?')) return; await api(`/content/${encodeURIComponent(state.forms.entryType)}/${encodeURIComponent(id)}`, { method: 'DELETE' }) }
      else if (action === 'revisions') { const payload = await api(`/content/${encodeURIComponent(state.forms.entryType)}/${encodeURIComponent(id)}/revisions`); setNotice('ok', 'Ревизии получены.', payload); return }
      else await api(`/content/${encodeURIComponent(state.forms.entryType)}/${encodeURIComponent(id)}/${action}`, { method: 'POST', body: '{}' })
      setNotice('ok', 'Действие выполнено.')
      await routeLoad('content')
    } catch (error) { setNotice('error', error.message, error.payload) }
  }))
  document.querySelector('[data-action="reset-entry"]')?.addEventListener('click', () => { state.forms.entry = defaultEntry(); render() })
  document.querySelector('[data-action="preview-entry"]')?.addEventListener('click', async () => {
    if (!state.forms.entry.id) return setNotice('error', 'Для preview нужен сохранённый ID записи.')
    try { const payload = await api('/cms/preview-token', { method: 'POST', body: JSON.stringify({ type: state.forms.entryType, id: state.forms.entry.id }) }); setNotice('ok', 'Preview token создан.', payload) } catch (error) { setNotice('error', error.message, error.payload) }
  })
}

function mediaView() {
  const items = asArray(state.cache.media)
  return `<div class="grid two"><div class="card"><h2>Media library</h2><div class="media-grid">${items.map((item) => `<div class="media-item"><div class="media-preview">${String(item.mime_type || '').startsWith('image/') || item.url ? `<img src="${escapeHtml(item.url || item.public_url || '')}" alt="">` : 'file'}</div><div class="media-body"><strong>${escapeHtml(item.title || item.filename || item.id)}</strong><p class="muted">${escapeHtml(item.mime_type || '')}<br>${escapeHtml(item.size || item.size_bytes || '')} bytes</p><button class="btn small danger" data-delete-media="${escapeHtml(item.id)}">Удалить</button></div></div>`).join('') || '<div class="empty">Файлов нет.</div>'}</div></div><div class="card"><h2>Загрузить файл</h2><form class="form" data-form="media"><div class="form-row"><label>Файл</label><input class="input" type="file" name="file" required></div><div class="form-row"><label>Title</label><input class="input" name="title"></div><div class="form-row"><label>Alt</label><input class="input" name="alt"></div><button class="btn" type="submit">Загрузить</button></form><h3>Diagnostics</h3><pre class="json">${escapeHtml(JSON.stringify(state.cache.media?.meta?.diagnostics || {}, null, 2))}</pre></div></div>`
}

function bindMedia() {
  document.querySelector('[data-form="media"]')?.addEventListener('submit', async (event) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    try { await api('/media', { method: 'POST', body: form }); setNotice('ok', 'Файл загружен.'); await routeLoad('media') } catch (error) { setNotice('error', error.message, error.payload) }
  })
  document.querySelectorAll('[data-delete-media]').forEach((button) => button.addEventListener('click', async () => {
    if (!confirm('Удалить media-файл?')) return
    try { await api(`/media/${encodeURIComponent(button.dataset.deleteMedia)}`, { method: 'DELETE' }); setNotice('ok', 'Media удалён.'); await routeLoad('media') } catch (error) { setNotice('error', error.message, error.payload) }
  }))
}

function cmsView() {
  const redirects = asArray(state.cache.redirects)
  const navs = asArray(state.cache.navigation)
  return `<div class="grid two"><div><div class="card"><h2>Theme config</h2><form class="form" data-form="theme"><textarea class="input code" name="theme">${escapeHtml(JSON.stringify(dataOf(state.cache.theme, jsonParse(state.forms.themeJson)), null, 2))}</textarea><button class="btn" type="submit">Сохранить тему</button></form></div><div class="card"><h2>Navigation</h2>${table(['Handle', 'Title', 'Items'], navs.map((item) => `<tr><td>${escapeHtml(item.handle)}</td><td>${escapeHtml(item.title || '')}</td><td>${escapeHtml((item.items || []).length)}</td></tr>`))}<form class="form" data-form="navigation"><div class="form-row"><label>Handle</label><input class="input" name="handle" value="${escapeHtml(state.forms.navigationHandle)}"></div><textarea class="input code" name="navigation">${escapeHtml(state.forms.navigationJson)}</textarea><button class="btn" type="submit">Сохранить меню</button></form></div></div><div><div class="card"><h2>Redirects</h2>${table(['From', 'To', 'Status', 'Действия'], redirects.map((r) => `<tr><td>${escapeHtml(r.from || r.source || '')}</td><td>${escapeHtml(r.to || r.target || '')}</td><td>${escapeHtml(r.status || r.status_code || '')}</td><td><button class="btn small danger" data-delete-redirect="${escapeHtml(r.id)}">Удалить</button></td></tr>`))}<form class="form" data-form="redirect"><div class="split"><input class="input" name="from" placeholder="/old"><input class="input" name="to" placeholder="/new"></div><select class="input" name="status"><option>301</option><option>302</option><option>307</option><option>308</option><option>410</option></select><button class="btn" type="submit">Добавить redirect</button></form></div><div class="card"><h2>CMS tools</h2><div class="toolbar"><button class="btn secondary" data-action="cms-bootstrap">Bootstrap demo-home</button><button class="btn ghost" data-action="cms-overview">Overview</button></div><pre class="json">${escapeHtml(JSON.stringify(dataOf(state.cache.cmsOverview, {}), null, 2))}</pre></div></div></div>`
}

function bindCms() {
  document.querySelector('[data-form="theme"]')?.addEventListener('submit', async (event) => { event.preventDefault(); try { await api('/cms/theme', { method: 'PATCH', body: JSON.stringify(jsonParse(new FormData(event.currentTarget).get('theme'), {})) }); setNotice('ok', 'Theme config сохранён.'); await routeLoad('cms') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelector('[data-form="navigation"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); try { await api(`/cms/navigation/${encodeURIComponent(String(fd.get('handle') || 'main'))}`, { method: 'PUT', body: JSON.stringify(jsonParse(String(fd.get('navigation') || '{}'), {})) }); setNotice('ok', 'Меню сохранено.'); await routeLoad('cms') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelector('[data-form="redirect"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); try { await api('/cms/redirects', { method: 'POST', body: JSON.stringify({ from: fd.get('from'), to: fd.get('to'), status: Number(fd.get('status') || 301), enabled: true }) }); setNotice('ok', 'Redirect добавлен.'); await routeLoad('cms') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelectorAll('[data-delete-redirect]').forEach((button) => button.addEventListener('click', async () => { if (!confirm('Удалить redirect?')) return; try { await api(`/cms/redirects/${encodeURIComponent(button.dataset.deleteRedirect)}`, { method: 'DELETE' }); setNotice('ok', 'Redirect удалён.'); await routeLoad('cms') } catch (error) { setNotice('error', error.message, error.payload) } }))
  document.querySelector('[data-action="cms-bootstrap"]')?.addEventListener('click', async () => { try { const payload = await api('/cms/bootstrap', { method: 'POST', body: JSON.stringify({ demo_home: true }) }); setNotice('ok', 'CMS bootstrap выполнен.', payload); await routeLoad('cms') } catch (error) { setNotice('error', error.message, error.payload) } })
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
  return `<div class="grid two"><div class="card"><h2>Расширения</h2>${table(['Slug', 'Type', 'Enabled', 'Действия'], items.map((e) => `<tr><td>${escapeHtml(e.slug || e.name || '')}</td><td>${escapeHtml(e.type || '')}</td><td>${e.enabled ? '<span class="badge ok">enabled</span>' : '<span class="badge warn">disabled</span>'}</td><td class="row-actions"><button class="btn small secondary" data-ext-action="enable" data-slug="${escapeHtml(e.slug)}">Enable</button><button class="btn small ghost" data-ext-action="disable" data-slug="${escapeHtml(e.slug)}">Disable</button><button class="btn small ghost" data-ext-action="assets" data-slug="${escapeHtml(e.slug)}">Assets</button><button class="btn small ghost" data-ext-action="migrate" data-slug="${escapeHtml(e.slug)}">Migrate</button></td></tr>`))}</div><div><div class="card"><h2>Runtime</h2><pre class="json">${escapeHtml(JSON.stringify(dataOf(state.cache.extensionsRuntime, state.cache.extensionsRuntime), null, 2))}</pre></div><div class="card"><h2>Event dispatch</h2><form class="form" data-form="extension-event"><input class="input" name="event" value="${escapeHtml(state.forms.extensionEvent)}"><textarea class="input code" name="payload">${escapeHtml(state.forms.extensionPayload)}</textarea><button class="btn" type="submit">Dispatch</button></form></div></div></div>`
}

function bindExtensions() {
  document.querySelectorAll('[data-ext-action]').forEach((button) => button.addEventListener('click', async () => { const slug = button.dataset.slug; const action = button.dataset.extAction; const path = action === 'assets' ? '/extensions/assets/publish' : action === 'migrate' ? '/extensions/migrate' : `/extensions/${encodeURIComponent(slug)}/${action}`; const body = action === 'assets' || action === 'migrate' ? JSON.stringify({ slug }) : '{}'; try { const payload = await api(path, { method: 'POST', body }); setNotice('ok', `Extension ${action} выполнен.`, payload); await routeLoad('extensions') } catch (error) { setNotice('error', error.message, error.payload) } }))
  document.querySelector('[data-form="extension-event"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); try { const payload = await api('/extensions/events/dispatch', { method: 'POST', body: JSON.stringify({ event: fd.get('event'), payload: jsonParse(String(fd.get('payload') || '{}'), {}) }) }); setNotice('ok', 'Event dispatch выполнен.', payload) } catch (error) { setNotice('error', error.message, error.payload) } })
}

function updatesView() {
  return `<div class="grid two"><div class="card"><h2>Update diagnostics</h2><pre class="json">${escapeHtml(JSON.stringify(dataOf(state.cache.updates, state.cache.updates), null, 2))}</pre></div><div><div class="card"><h2>Maintenance</h2><pre class="json">${escapeHtml(JSON.stringify(dataOf(state.cache.maintenance, state.cache.maintenance), null, 2))}</pre><div class="toolbar"><button class="btn secondary" data-update-action="maintenance/on">Включить</button><button class="btn ghost" data-update-action="maintenance/off">Выключить</button></div></div><div class="card"><h2>Rollback</h2><form class="form" data-form="rollback"><input class="input" name="backup" placeholder="storage/app/updates/rollback/rollback-...zip"><button class="btn danger" type="submit">Rollback</button></form></div></div></div>`
}

function bindUpdates() {
  document.querySelectorAll('[data-update-action]').forEach((button) => button.addEventListener('click', async () => { try { const payload = await api(`/updates/${button.dataset.updateAction}`, { method: 'POST', body: '{}' }); setNotice('ok', 'Update action выполнен.', payload); await routeLoad('updates') } catch (error) { setNotice('error', error.message, error.payload) } }))
  document.querySelector('[data-form="rollback"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const backup = new FormData(event.currentTarget).get('backup'); if (!confirm('Запустить rollback?')) return; try { const payload = await api('/updates/rollback', { method: 'POST', body: JSON.stringify({ backup }) }); setNotice('ok', 'Rollback выполнен.', payload); await routeLoad('updates') } catch (error) { setNotice('error', error.message, error.payload) } })
}

function searchView() {
  const result = state.cache.searchResult
  return `<div class="grid two"><div class="card"><h2>Search</h2><form class="form" data-form="search"><input class="input" name="q" value="${escapeHtml(state.forms.searchQuery)}" placeholder="query"><div class="toolbar"><button class="btn" type="submit">Искать</button><button class="btn secondary" type="button" data-action="search-reindex">Reindex</button></div></form>${result ? `<pre class="json">${escapeHtml(JSON.stringify(result, null, 2))}</pre>` : ''}</div><div class="card"><h2>Diagnostics</h2><pre class="json">${escapeHtml(JSON.stringify(dataOf(state.cache.searchDiagnostics, state.cache.searchDiagnostics), null, 2))}</pre></div></div>`
}

function bindSearch() {
  document.querySelector('[data-form="search"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const q = new FormData(event.currentTarget).get('q'); try { state.cache.searchResult = await api(`/search?q=${encodeURIComponent(q)}`); setNotice('ok', 'Поиск выполнен.'); render() } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelector('[data-action="search-reindex"]')?.addEventListener('click', async () => { try { const payload = await api('/search/reindex', { method: 'POST', body: '{}' }); setNotice('ok', 'Reindex запущен.', payload); await routeLoad('search') } catch (error) { setNotice('error', error.message, error.payload) } })
}

function importExportView() {
  const exports = asArray(state.cache.exports)
  return `<div class="grid two"><div class="card"><h2>Exports</h2><div class="toolbar"><button class="btn" data-action="export-create">Создать export</button></div>${table(['Path', 'Size', 'Created'], exports.map((x) => `<tr><td>${escapeHtml(x.path || x.file || x.name)}</td><td>${escapeHtml(x.size || '')}</td><td>${escapeHtml(x.created_at || '')}</td></tr>`))}</div><div><div class="card"><h2>Import diff/run</h2><form class="form" data-form="import"><input class="input" name="path" placeholder="storage/app/exports/export.json"><select class="input" name="mode"><option value="diff">diff</option><option value="run">run</option></select><button class="btn" type="submit">Выполнить</button></form></div><div class="card"><h2>Последний результат</h2><pre class="json">${escapeHtml(JSON.stringify(state.cache.importResult || {}, null, 2))}</pre></div></div></div>`
}

function bindImportExport() {
  document.querySelector('[data-action="export-create"]')?.addEventListener('click', async () => { try { const payload = await api('/import-export/export', { method: 'POST', body: JSON.stringify({ sections: ['content', 'media', 'settings', 'cms', 'extensions'] }) }); setNotice('ok', 'Export создан.', payload); await routeLoad('import-export') } catch (error) { setNotice('error', error.message, error.payload) } })
  document.querySelector('[data-form="import"]')?.addEventListener('submit', async (event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); const mode = fd.get('mode'); try { state.cache.importResult = await api(mode === 'run' ? '/import-export/import' : '/import-export/diff', { method: 'POST', body: JSON.stringify({ path: fd.get('path'), mode: 'merge' }) }); setNotice('ok', `Import ${mode} выполнен.`); render() } catch (error) { setNotice('error', error.message, error.payload) } })
}

function systemView() {
  const blocks = [['System', state.cache.system], ['Runtime', state.cache.runtime], ['Database', state.cache.database], ['Security', state.cache.security], ['RC', state.cache.rc], ['Stable', state.cache.stable], ['Queue', state.cache.queue], ['Scheduler', state.cache.scheduler]]
  return `<div class="grid two">${blocks.map(([name, data]) => `<div class="card"><h2>${escapeHtml(name)}</h2><pre class="json">${escapeHtml(JSON.stringify(dataOf(data, data), null, 2))}</pre></div>`).join('')}</div>`
}

bootstrap()
