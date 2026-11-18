<script setup lang="ts">
const api = useAdminApi()
type Conversation = { id:string; title:string; visibility:'all'|'public'; locale:string; updated_at:number }
type Source = {number:number;id:string;title:string;revision:number;locale:string;visibility:string;chunk_no:number}
type Message = {id:string;role:'user'|'assistant';content:string;sources:Source[];revoked:boolean;feedback:number|null}
type Answer = {conversation_id:string;message_id:string;text:string;sources:Source[];usage:{input_tokens:number;output_tokens:number}}
const conversations = ref<Conversation[]>([])
const active = ref<string|null>(null)
const messages = ref<Message[]>([])
const question = ref('')
const visibility = ref<'all'|'public'>('all')
const locale = ref('')
const loading = ref(false), sending = ref(false), error = ref(''), notice = ref('')
const configured = ref(false)
const canSwitchScope = computed(() => active.value === null && !sending.value)
async function refresh(){
  conversations.value = (await api.request<{data:Conversation[]}>('/knowledge/chat/conversations')).data
}
async function initialize(){
  loading.value = true; error.value = ''
  try {
    const [status] = await Promise.all([api.request<{data:{configured:boolean}}>('/ai/status'),refresh()])
    configured.value = status.data.configured
  } catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось загрузить Knowledge Chat.' }
  finally { loading.value = false }
}
async function open(conv:Conversation|null){
  if (sending.value) return
  error.value = ''; notice.value = ''; active.value = conv?.id || null
  if (!conv) { messages.value = []; return }
  visibility.value = conv.visibility; locale.value = conv.locale
  try { messages.value = (await api.request<{data:Message[]}>(`/knowledge/chat/conversations/${encodeURIComponent(conv.id)}`)).data }
  catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось открыть историю.' }
}
async function ask(){
  const text = question.value.trim()
  if (!text || sending.value) return
  sending.value = true; error.value = ''; notice.value = ''
  try {
    const response = (await api.request<{data:Answer}>('/knowledge/chat/ask', {
      method:'POST', body:JSON.stringify({conversation_id:active.value,question:text,visibility:visibility.value,locale:locale.value})
    })).data
    question.value = ''
    active.value = response.conversation_id
    await refresh()
    messages.value = (await api.request<{data:Message[]}>(`/knowledge/chat/conversations/${encodeURIComponent(active.value)}`)).data
    notice.value = response.sources.length ? `Ответ со ссылками: ${response.sources.length}.` : 'Подтверждённые источники не найдены.'
  } catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось получить ответ.' }
  finally { sending.value = false }
}
async function remove(conv:Conversation){
  if (!window.confirm(`Удалить диалог «${conv.title}»?`)) return
  try {
    await api.request(`/knowledge/chat/conversations/${encodeURIComponent(conv.id)}`,{method:'DELETE'})
    if (active.value===conv.id) await open(null)
    await refresh()
  } catch(e){error.value=e instanceof Error?e.message:'Не удалось удалить диалог.'}
}
async function rate(message:Message, rating:number){
  try{
    await api.request(`/knowledge/chat/messages/${encodeURIComponent(message.id)}/feedback`,{
      method:'POST',body:JSON.stringify({rating})
    })
    message.feedback=rating
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось сохранить оценку.'}
}
onMounted(initialize)
</script>

<template>
  <section class="rag-page">
    <header>
      <h1>AI Knowledge Chat / RAG</h1>
      <p>Ответы только на основе опубликованных документов базы знаний и синхронизированных страниц CMS. Фрагменты передаются в OpenAI только при запросе администратора. Ссылки проверяются по актуальной ревизии.</p>
    </header>
    <p v-if="error" class="ce-error" role="alert">{{error}}</p>
    <p v-if="notice" role="status">{{notice}}</p>
    <p v-if="!configured" role="note">Для вопросов с найденными источниками нужен OpenAI API-ключ в разделе AI Assistant. Поиск по базе знаний доступен без OpenAI.</p>
    <div class="rag-layout">
      <aside class="rag-panel">
        <h2>Диалоги</h2>
        <button :disabled="sending" @click="open(null)">Новый диалог</button>
        <p v-if="loading">Загрузка…</p>
        <ul>
          <li v-for="conv in conversations" :key="conv.id">
            <button class="rag-dialog" :aria-current="active===conv.id?'page':undefined" :disabled="sending" @click="open(conv)">{{conv.title}}</button>
            <small>{{conv.visibility==='public'?'Публичные':'Все доступные'}} · {{conv.locale||'все языки'}}</small>
            <button :disabled="sending" title="Удалить диалог" @click="remove(conv)">Удалить</button>
          </li>
        </ul>
      </aside>
      <section class="rag-panel rag-conversation">
        <h2>{{active?'История вопросов':'Новый вопрос'}}</h2>
        <div class="rag-scope">
          <label>Источники
            <select v-model="visibility" :disabled="!canSwitchScope">
              <option value="all">Все доступные, включая внутренние</option>
              <option value="public">Только публичные</option>
            </select>
          </label>
          <label>Язык
            <select v-model="locale" :disabled="!canSwitchScope">
              <option value="">Все языки</option>
              <option value="ru">Русский</option>
              <option value="en">English</option>
            </select>
          </label>
        </div>
        <div v-if="messages.length" class="rag-messages" aria-live="polite">
          <article v-for="m in messages" :key="m.id" :class="['rag-message',m.role]">
            <h3>{{m.role==='user'?'Вопрос':'Ответ'}}</h3>
            <p class="rag-text">{{m.content}}</p>
            <p v-if="m.revoked" class="rag-revoked">Ссылка на исходный документ больше недействительна.</p>
            <div v-if="m.sources?.length" class="rag-sources">
              <strong>Источники</strong>
              <ul><li v-for="source in m.sources" :key="source.number">
                [{{source.number}}] <NuxtLink :to="`/knowledge?document=${encodeURIComponent(source.id)}`">{{source.title}}</NuxtLink>
                <small> · {{source.locale}}, ревизия {{source.revision}}, фрагмент {{source.chunk_no+1}}</small>
              </li></ul>
            </div>
            <div v-if="m.role==='assistant'&&!m.revoked" class="rag-feedback" aria-label="Полезность ответа">
              <button :aria-pressed="m.feedback===1" @click="rate(m,1)">Полезно</button>
              <button :aria-pressed="m.feedback===-1" @click="rate(m,-1)">Не помогло</button>
            </div>
          </article>
        </div>
        <p v-else>Задайте вопрос по инструкциям, статьям и CMS-материалам. При отсутствии подтверждённого источника ассистент не будет придумывать ответ.</p>
        <form class="rag-form" @submit.prevent="ask">
          <label for="rag-question">Вопрос</label>
          <textarea id="rag-question" v-model="question" maxlength="1200" rows="3" required :disabled="sending" placeholder="Например: Как настроить публикацию страниц?"/>
          <button type="submit" :disabled="sending||!question.trim()">{{sending?'Поиск и генерация…':'Спросить базу знаний'}}</button>
        </form>
      </section>
    </div>
  </section>
</template>

<style scoped>
.rag-page{display:grid;gap:16px}.rag-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:18px}.rag-panel{padding:18px;border:1px solid #d6dce4;border-radius:12px;background:#fff;min-width:0}.rag-panel ul{padding-left:18px}.rag-panel li{margin:12px 0}.rag-dialog{max-width:100%;overflow-wrap:anywhere;text-align:left}.rag-panel button{cursor:pointer;border:1px solid #bbc6d3;background:#f8fafc;border-radius:6px;padding:8px}.rag-panel button[aria-pressed=true],.rag-panel button[aria-current=page]{background:#dbeafe}.rag-panel button:disabled{cursor:wait;opacity:.6}.rag-panel small{color:#64748b;display:block}.rag-conversation{display:grid;gap:15px;align-content:start}.rag-scope{display:flex;flex-wrap:wrap;gap:12px}.rag-scope label{display:grid;gap:4px}.rag-scope select{padding:8px}.rag-messages{display:grid;gap:13px;max-height:65vh;overflow:auto}.rag-message{padding:12px;background:#f8fafc;border-radius:8px;overflow-wrap:anywhere}.rag-message.user{background:#eff6ff}.rag-message h3{margin:0 0 8px;font-size:14px}.rag-text{white-space:pre-wrap;margin:0}.rag-revoked{font-weight:600;color:#a33}.rag-sources{border-top:1px solid #cbd5e1;margin-top:12px;padding-top:8px}.rag-form{display:grid;gap:8px}.rag-form textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:6px;font:inherit}.rag-feedback{display:flex;gap:8px;margin-top:8px}@media(max-width:900px){.rag-layout{grid-template-columns:1fr}}
</style>
