<script setup lang="ts">
const api = useAdminApi()
const auth = useAdminAuth()
type Conversation = { id: string; title: string; updated_at: number; total_input_tokens: number; total_output_tokens: number }
type ToolRun = { name: string; status: string; duration_ms: number }
type Message = { id: string; role: 'user' | 'assistant'; content: string; created_at: number; tools?: ToolRun[] }
type AiStatus = { configured: boolean; credential_source: string; model: string; max_output_tokens: number; monthly_token_limit: number }
const settings = ref<AiStatus | null>(null)
const conversations = ref<Conversation[]>([])
const active = ref<string | null>(null)
const messages = ref<Message[]>([])
const prompt = ref('')
const toolsEnabled = ref(false)
const availableTools = ref<{ name: string; description: string; scope: string }[]>([])
const workingTools = ref<ToolRun[]>([])
const partial = ref('')
const loading = ref(false)
const sending = ref(false)
const error = ref('')
const info = ref('')
const key = ref('')
const editingModel = ref('gpt-4.1-mini')
const editingOutput = ref(1024)
const editingBudget = ref(250000)
const configuring = ref(false)
const canManage = computed(() => {
  const scopes = (auth.identity.value as { scopes?: string[] } | null)?.scopes || []
  return scopes.includes('*') || (scopes.includes('system:write') && scopes.includes('integrations:secrets'))
})
const load = async () => {
  loading.value = true; error.value = ''
  try {
    const [s, list, tools] = await Promise.all([
      api.request<{ data: AiStatus }>('/ai/status'),
      api.request<{ data: Conversation[] }>('/ai/conversations'),
      api.request<{ data: { name:string;description:string;scope:string }[] }>('/ai/tools'),
    ])
    settings.value = s.data
    conversations.value = list.data
    availableTools.value = tools.data
    editingModel.value = s.data.model
    editingOutput.value = s.data.max_output_tokens
    editingBudget.value = s.data.monthly_token_limit
  } catch (e) { error.value = e instanceof Error ? e.message : 'Ошибка AI.' }
  finally { loading.value = false }
}
onMounted(async () => { await auth.load(); await load() })
const open = async (id: string | null) => {
  if (sending.value) return
  active.value = id; messages.value = []; partial.value = ''; error.value = ''; info.value = ''
  if (id) {
    try { messages.value = (await api.request<{ data: Message[] }>('/ai/conversations/' + encodeURIComponent(id))).data }
    catch (e) { error.value = e instanceof Error ? e.message : 'Ошибка загрузки диалога.' }
  }
}
const refreshList = async () => {
  conversations.value = (await api.request<{ data: Conversation[] }>('/ai/conversations')).data
}
const rename = async (conversation: Conversation) => {
  const title = window.prompt('Название диалога', conversation.title)
  if (!title) return
  try { await api.request('/ai/conversations/' + encodeURIComponent(conversation.id), { method: 'PATCH', body: JSON.stringify({ title }) }); await refreshList() }
  catch (e) { error.value = e instanceof Error ? e.message : 'Ошибка переименования.' }
}
const remove = async (conversation: Conversation) => {
  if (!window.confirm('Удалить диалог и всю его историю?')) return
  try {
    await api.request('/ai/conversations/' + encodeURIComponent(conversation.id), { method: 'DELETE' })
    if (active.value === conversation.id) await open(null)
    await refreshList()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Ошибка удаления.' }
}
const saveSettings = async () => {
  configuring.value = true; error.value = ''; info.value = ''
  try {
    const body: Record<string, unknown> = { model: editingModel.value, max_output_tokens: editingOutput.value, monthly_token_limit: editingBudget.value }
    if (key.value) body.api_key = key.value
    settings.value = (await api.request<{ data: AiStatus }>('/ai/settings', { method: 'PUT', body: JSON.stringify(body) })).data
    key.value = ''; info.value = 'Настройки сохранены.'
  } catch (e) { error.value = e instanceof Error ? e.message : 'Ошибка сохранения.' }
  finally { configuring.value = false }
}
const removeKey = async () => {
  if (!window.confirm('Удалить сохранённый API-ключ?')) return
  try { settings.value = (await api.request<{data: AiStatus}>('/ai/settings', {method:'PUT', body:JSON.stringify({remove_key:true})})).data; key.value=''; info.value='Сохранённый ключ удалён.' }
  catch (e) { error.value = e instanceof Error ? e.message : 'Ошибка удаления ключа.' }
}
const submit = async () => {
  const text = prompt.value.trim()
  if (!text || sending.value || !settings.value?.configured) return
  sending.value = true; error.value = ''; info.value = ''; partial.value = ''; workingTools.value = []
  prompt.value = ''
  messages.value.push({id: 'pending-' + Date.now(), role: 'user', content: text, created_at: Date.now()})
  let done = false
  try {
    const headers = new Headers({ Accept: 'text/event-stream', 'Content-Type': 'application/json' })
    if (api.csrf.value) headers.set('X-CSRF-Token', api.csrf.value)
    const response = await fetch(api.baseUrl + '/ai/chat/stream', { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers,
      body: JSON.stringify({ conversation_id: active.value, message: text, tools_enabled: toolsEnabled.value && availableTools.value.length > 0 }) })
    if (!response.ok || !response.body) {
      const payload = await response.json().catch(() => null)
      throw new Error(payload?.error?.message || 'Ошибка AI HTTP ' + response.status)
    }
    const reader = response.body.getReader(); const decoder = new TextDecoder('utf-8')
    let buffer = ''
    const processFrame = (frame: string) => {
      const lines = frame.split('\n')
      const eventName = lines.find(line => line.startsWith('event:'))?.slice(6).trim()
      const data = lines.filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n')
      if (!data) return
      const value = JSON.parse(data)
      if (eventName === 'delta') partial.value += value.text || ''
      if (eventName === 'tool' && value.name) workingTools.value.push({ name: value.name, status: value.status || 'ok', duration_ms: Number(value.duration_ms || 0) })
      if (eventName === 'error') throw new Error(value.message || 'Ошибка генерации.')
      if (eventName === 'done') { done = true; active.value = value.conversation_id; partial.value = ''; workingTools.value = [] }
    }
    while (true) {
      const { value, done: eof } = await reader.read()
      if (eof) break
      buffer += decoder.decode(value, { stream: true })
      buffer = buffer.replace(/\r\n/g, '\n')
      let index: number
      while ((index = buffer.indexOf('\n\n')) !== -1) {
        processFrame(buffer.slice(0, index))
        buffer = buffer.slice(index + 2)
      }
    }
    if (!done) throw new Error('Поток AI завершился до сохранения ответа.')
    await openSaved()
  } catch (e) { error.value = e instanceof Error ? e.message : 'AI временно недоступен.'; if (!done) { messages.value = messages.value.filter(m => !m.id.startsWith('pending-')); prompt.value = text } }
  finally { sending.value = false }
}
const openSaved = async () => {
  if (active.value) {
    messages.value = (await api.request<{ data: Message[] }>('/ai/conversations/' + encodeURIComponent(active.value))).data
  }
  await refreshList()
}
</script>

<template>
  <section class="ce-ai">
    <header><h1>AI Assistant · ChatGPT</h1><p>История чатов сохраняется в CajeerEngine. Сообщения отправляются в OpenAI API.</p></header>
    <p v-if="error" class="ce-ai-error" role="alert">{{ error }}</p>
    <p v-if="info" role="status">{{ info }}</p>
    <p v-if="loading">Загрузка…</p>
    <div v-else class="ce-ai-layout">
      <aside class="ce-ai-sidebar">
        <button type="button" @click="open(null)" :disabled="sending">Новый диалог</button>
        <div v-for="conversation in conversations" :key="conversation.id" class="ce-ai-item">
          <button type="button" class="ce-ai-title" :aria-current="active === conversation.id ? 'page' : undefined" :disabled="sending" @click="open(conversation.id)">{{ conversation.title }}</button>
          <button type="button" :disabled="sending" title="Переименовать" @click="rename(conversation)">✎</button>
          <button type="button" :disabled="sending" title="Удалить" @click="remove(conversation)">×</button>
        </div>
      </aside>
      <div class="ce-ai-main">
        <div v-if="!settings?.configured" class="ce-ai-notice">OpenAI API-ключ не настроен. {{ canManage ? 'Укажите его ниже.' : 'Обратитесь к администратору.' }}</div>
        <div class="ce-ai-messages" aria-live="polite">
          <article v-for="m in messages" :key="m.id" :class="['ce-ai-message', m.role]"><strong>{{ m.role === 'user' ? 'Вы' : 'ChatGPT' }}</strong><p>{{ m.content }}</p><p v-if="m.tools?.length" class="ce-ai-tool-trace">Инструменты: {{ m.tools.map(t => t.name + ' (' + t.status + ')').join(', ') }}</p></article>
          <article v-if="sending" class="ce-ai-message assistant"><strong>ChatGPT</strong><p v-if="workingTools.length" class="ce-ai-tool-trace">{{ workingTools.map(t => t.name + ' (' + t.status + ')').join(', ') }}</p><p>{{ partial || 'Генерация…' }}</p></article>
          <p v-if="!messages.length && !sending">Создайте диалог и отправьте первое сообщение.</p>
        </div>
        <form class="ce-ai-compose" @submit.prevent="submit">
          <label v-if="availableTools.length" class="ce-ai-tools-switch"><input v-model="toolsEnabled" type="checkbox" :disabled="sending"> Разрешить AI-инструментам читать данные CMS и передавать результаты в OpenAI</label>
          <details v-if="availableTools.length"><summary>Доступные AI Tools ({{ availableTools.length }})</summary><ul><li v-for="tool in availableTools" :key="tool.name"><code>{{ tool.name }}</code> — {{ tool.description }}</li></ul></details>
          <textarea v-model="prompt" rows="4" maxlength="12000" placeholder="Введите сообщение для ChatGPT" :disabled="sending || !settings?.configured" />
          <button type="submit" :disabled="sending || !prompt.trim() || !settings?.configured">{{ sending ? 'Отправка…' : 'Отправить' }}</button>
          <small>Модель: {{ settings?.model || '—' }}. Ответы могут содержать ошибки.</small>
        </form>
      </div>
    </div>
    <details v-if="canManage && settings" class="ce-ai-settings">
      <summary>Настройки OpenAI</summary>
      <form @submit.prevent="saveSettings">
        <label>API-ключ <input v-model="key" type="password" autocomplete="off" placeholder="Оставьте пустым, чтобы сохранить текущий" /></label>
        <p>Источник ключа: {{ settings.credential_source }}. Ключ не передаётся в браузер после сохранения.</p>
        <label>Модель <input v-model="editingModel" required maxlength="80" /></label>
        <label>Лимит токенов ответа <input v-model.number="editingOutput" type="number" min="128" max="4096" required /></label>
        <label>Месячный бюджет токенов на пользователя <input v-model.number="editingBudget" type="number" min="1000" max="10000000" required /></label>
        <button type="submit" :disabled="configuring">Сохранить настройки</button>
        <button type="button" :disabled="configuring" @click="removeKey">Удалить сохранённый ключ</button>
        <p>Для изменений необходимы права и свежее подтверждение в Identity Center.</p>
      </form>
    </details>
  </section>
</template>

<style scoped>
.ce-ai{max-width:1300px}.ce-ai-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:18px;margin-top:20px}.ce-ai-sidebar,.ce-ai-main,.ce-ai-settings{border:1px solid #e2e8f0;border-radius:12px;padding:15px;background:white}.ce-ai-sidebar{max-height:65vh;overflow:auto}.ce-ai-item{display:flex;align-items:center;gap:4px;padding:7px 0;border-bottom:1px solid #e2e8f0}.ce-ai-title{flex:1;text-align:left;overflow-wrap:anywhere}.ce-ai-title[aria-current=page]{font-weight:bold}.ce-ai-item button{border:0;border-radius:5px;background:#f1f5f9;padding:7px;cursor:pointer}.ce-ai-messages{height:min(54vh,650px);min-height:250px;overflow-y:auto;display:flex;flex-direction:column;gap:13px}.ce-ai-message{padding:12px;border-radius:10px;background:#f1f5f9}.ce-ai-message.user{background:#eaf2ff}.ce-ai-message p{white-space:pre-wrap;overflow-wrap:anywhere;margin:6px 0 0}.ce-ai-compose{display:grid;gap:10px;margin-top:14px}.ce-ai-compose textarea,.ce-ai-settings input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px}.ce-ai-compose button,.ce-ai-settings button,.ce-ai-sidebar>button{padding:10px;border:0;border-radius:8px;background:#155eef;color:white;cursor:pointer}.ce-ai-compose button:disabled{opacity:.5}.ce-ai-settings{margin-top:20px}.ce-ai-settings form{display:grid;gap:12px;padding-top:14px}.ce-ai-settings label{display:grid;gap:5px}.ce-ai-settings button[type=button]{background:#64748b}.ce-ai-error{color:#b42318}.ce-ai-notice{padding:12px;background:#fff4dc;margin-bottom:12px}@media(max-width:850px){.ce-ai-layout{grid-template-columns:1fr}.ce-ai-sidebar{max-height:190px}}
</style>
