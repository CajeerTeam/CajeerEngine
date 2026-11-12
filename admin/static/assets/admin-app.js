const ADMIN_VERSION = '1.2.4'
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
  ['extensions', '🔌', 'Extensions', 'Runtime, lifecycle, events'],
  ['updates', '⬆️', 'Updates', 'Maintenance и rollback'],
  ['search', '🔎', 'Search', 'Поиск и reindex'],
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
    if (route === 'extensions') await Promise.allSettled([loadSilent('extensions', '/extensions'), loadSilent('extensionsRuntime', '/extensions/runtime'), loadSilent('extensionsDiagnostics', '/extensions/diagnostics')])
    if (route === 'updates') await Promise.allSettled([loadSilent('updates', '/updates'), loadSilent('maintenance', '/updates/maintenance')])
    if (route === 'search') await loadSilent('searchDiagnostics', '/search/diagnostics')
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
  app.innerHTML = `<div class="login-shell"><form class="login-card" data-form="login"><div class="brand" style="color:var(--text)"><div class="brand-mark">CE</div><div><div class="brand-title">CajeerEngine Admin</div><div class="brand-sub" style="color:var(--muted)">Production static SPA ${ADMIN_VERSION}</div></div></div>${noticeHtml()}<div class="form"><div class="form-row"><label>Email</label><input class="input" name="email" type="email" autocomplete="username" required></div><div class="form-row"><label>Пароль</label><input class="input" name="password" type="password" autocomplete="current-password" required></div><div class="form-row"><label>2FA код, если включён</label><input class="input" name="two_factor_code" inputmode="numeric"></div><button class="btn" type="submit">Войти</button><p class="muted">Вход использует защищённую HttpOnly-сессию.</p></div></form></div>`
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
  document.querySelectorAll('[data-action="logout"]').forEach((button) => button.addEventListener('click', async () => { try { await api('/auth/logout', { method: 'POST', body: '{}' }) } catch (error) { setNotice('error', error.message); return } state.token = ''; state.csrf = ''; state.identity = null; state.forms.entry=defaultEntry();for(const key of Object.keys(sessionStorage))if(key.startsWith('ce-editor-draft:'))sessionStorage.removeItem(key);localStorage.removeItem(TOKEN_KEY); go('login') }))
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
  return `<div class="grid cards">${cards.map((card) => `<div class="card kpi"><div class="kpi-label">${escapeHtml(card.label)}</div><div class="kpi-value">${escapeHtml(card.value)}</div><span class="badge ${card.status || 'ok'}">${escapeHtml(card.status || 'ok')}</span></div>`).join('') || '<div class="card"><h2>Admin API</h2><p>Нажмите “Обновить”, чтобы получить dashboard.</p></div>'}</div><div class="grid two"><div class="card"><h2>Продукт</h2><p>Admin UI 1.2.4 — production static SPA с CRUD-экранами, авторизацией, route guards и обработкой 401/403.</p><div class="status-line"><span class="badge ok">static assets</span><span class="badge ok">Bearer auth</span><span class="badge ok">CMS CRUD</span><span class="badge ok">media upload</span></div></div><div class="card"><h2>Bootstrap</h2><pre class="json">${escapeHtml(JSON.stringify(bootstrap.app || bootstrap, null, 2))}</pre></div></div>`
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
