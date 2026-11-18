/* CajeerEngine Support Center. Same-origin guest capability, no external dependencies. */
(() => {
  'use strict'
  const ENDPOINT = '/api/v1/public/support'
  const STORAGE = 'ce.support.ticket.v1'
  const create = (tag, text, className) => {
    const el = document.createElement(tag)
    if (text !== undefined) el.textContent = text
    if (className) el.className = className
    return el
  }
  async function request(path, method = 'GET', body, token = '') {
    const options = { method, credentials: 'omit', cache: 'no-store', headers: {Accept:'application/json'} }
    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json'
      options.body = JSON.stringify(body)
    }
    if (token) options.headers['X-Support-Token'] = token
    const response = await fetch(ENDPOINT + path, options)
    const payload = await response.json().catch(() => null)
    if (!response.ok) throw new Error(payload?.error?.message || `HTTP ${response.status}`)
    return payload.data
  }
  let current = null
  try {
    const stored = JSON.parse(localStorage.getItem(STORAGE) || 'null')
    if (stored && /^[a-f0-9-]{36}$/i.test(stored.id) && /^[A-Za-z0-9_-]{43}$/.test(stored.token)) current = stored
  } catch { /* Empty storage is normal. */ }
  const root = create('section', undefined, 'ce-support-widget')
  const opener = create('button', 'Поддержка', 'ce-support-toggle')
  opener.type = 'button'; opener.setAttribute('aria-expanded', 'false')
  const panel = create('section', undefined, 'ce-support-panel')
  panel.hidden = true; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Поддержка')
  const heading = create('h2', 'Поддержка')
  const close = create('button', '×', 'ce-support-close')
  close.type = 'button'; close.setAttribute('aria-label', 'Закрыть окно поддержки')
  const status = create('p', '', 'ce-support-status'); status.setAttribute('role','status')
  const content = create('div', undefined, 'ce-support-content')
  heading.append(close); panel.append(heading, status, content); root.append(opener, panel)
  document.body.append(root)
  const message = (text, error = false) => { status.textContent = text; status.classList.toggle('error', error) }
  const field = (labelText, name, type, max) => {
    const label = create('label', labelText)
    const input = create(type === 'textarea' ? 'textarea' : 'input')
    input.name = name; if (type !== 'textarea') input.type = type
    input.maxLength = max; input.required = true
    label.append(input)
    return label
  }
  async function showTicket() {
    if (!current) return showNew()
    let ticket
    try { ticket = await request('/tickets/' + encodeURIComponent(current.id), 'GET', undefined, current.token) }
    catch (e) {
      message('Не удалось открыть обращение: ' + e.message, true)
      showNew(); return
    }
    content.replaceChildren()
    const title = create('h3', ticket.subject)
    const subtitle = create('p', 'Статус: ' + ticket.status + (ticket.needs_human ? ' · ожидает оператора' : ''))
    const history = create('div', undefined, 'ce-support-history')
    for (const msg of ticket.messages) {
      const article = create('article', undefined, 'ce-support-message ' + msg.role)
      article.append(create('strong', msg.role === 'visitor' ? 'Вы' : msg.role === 'operator' ? 'Оператор' : 'AI'), create('p', msg.content))
      if (Array.isArray(msg.sources)) for (const src of msg.sources) article.append(create('small', '[' + src.number + '] ' + src.title))
      history.append(article)
    }
    const refresh = create('button', 'Обновить переписку', 'ce-support-secondary')
    refresh.type = 'button'
    refresh.addEventListener('click', async () => { refresh.disabled = true; try { await showTicket() } catch (e) { message(e.message, true) } finally { refresh.disabled = false } })
    content.append(title, subtitle, history, refresh)
    if (ticket.status !== 'closed') {
      const form = create('form')
      form.append(field('Сообщение', 'message', 'textarea', 4000))
      const send = create('button', 'Отправить'); send.type = 'submit'; form.append(send)
      form.addEventListener('submit', async e => {
        e.preventDefault(); send.disabled = true
        try {
          await request('/tickets/' + encodeURIComponent(current.id) + '/messages','POST',{message:form.elements.message.value},current.token)
          message('Сообщение отправлено.');await showTicket()
        } catch (err) { message(err.message,true) } finally { send.disabled = false }
      })
      const human = create('button', 'Позвать оператора', 'ce-support-secondary'); human.type = 'button'
      human.addEventListener('click', async () => {
        human.disabled = true
        try { await request('/tickets/'+encodeURIComponent(current.id)+'/handoff','POST',{},current.token); message('Обращение передано оператору.'); await showTicket() }
        catch(err) { message(err.message,true) } finally { human.disabled=false }
      })
      content.append(form, human)
    }
    const reset = create('button', 'Новое обращение', 'ce-support-secondary'); reset.type = 'button'
    reset.addEventListener('click', () => { current=null; localStorage.removeItem(STORAGE); showNew() })
    content.append(reset)
  }
  function showNew() {
    content.replaceChildren()
    const form = create('form')
    form.append(field('Имя','name','text',120), field('Email','email','email',254), field('Тема','subject','text',160), field('Опишите вопрос','message','textarea',4000))
    const submit = create('button','Создать обращение'); submit.type='submit'; form.append(submit)
    form.addEventListener('submit', async e => {
      e.preventDefault(); submit.disabled=true; message('Отправка…')
      const body=Object.fromEntries(new FormData(form).entries())
      body.locale=(document.documentElement.lang || 'ru').split('-')[0].toLowerCase()
      if(!/^[a-z]{2}$/.test(body.locale))body.locale='ru'
      try {
        const result=await request('/tickets','POST',body)
        current={id:result.ticket_id,token:result.access_token}
        try{localStorage.setItem(STORAGE,JSON.stringify(current))}catch{message('Сохранение истории в браузере недоступно: не закрывайте вкладку.')}
        message('Обращение создано.'); await showTicket()
      }catch(err){message(err.message,true)}finally{submit.disabled=false}
    })
    content.append(create('p','Опишите проблему. Ответ AI, если включён, использует только публичную базу знаний. Личные данные не отправляйте.'),form)
  }
  opener.addEventListener('click', async () => {
    panel.hidden=!panel.hidden; opener.setAttribute('aria-expanded',String(!panel.hidden))
    if (!panel.hidden) { message(''); await showTicket() }
  })
  close.addEventListener('click', () => { panel.hidden=true; opener.setAttribute('aria-expanded','false'); opener.focus() })
  document.addEventListener('keydown', e => { if(e.key==='Escape'&&!panel.hidden){panel.hidden=true;opener.setAttribute('aria-expanded','false');opener.focus()} })
  request('/config').then(data => { if (!data.enabled) root.remove() }).catch(() => root.remove())
})()
