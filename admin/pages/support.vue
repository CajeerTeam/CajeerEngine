<script setup lang="ts">
const api = useAdminApi()
const auth = useAdminAuth()
type Ticket = {id:string; name:string; email:string; subject:string; locale:string; status:string; priority:string; assigned_to:string|null; needs_human:number|boolean; revision:number; created_at:number; updated_at:number}
type Message = {id:string;role:'visitor'|'operator'|'ai';content:string;created_at:number;sources:Array<{id:string;title:string;number:number}>}
const rows = ref<Ticket[]>([])
const total = ref(0), offset = ref(0), status = ref(''), loading = ref(false), saving = ref(false)
const selected = ref<(Ticket & {messages:Message[]})|null>(null)
const reply = ref(''), draft = ref(''), draftSources = ref<Array<{id:string;title:string;number:number}>>([]), error = ref(''), notice = ref('')
const assignment = ref(''), priority = ref('normal'), ticketStatus = ref('open')
async function refresh(){
  loading.value=true;error.value=''
  try {
    const r=await api.request<{data:{items:Ticket[];total:number}}>('/support/tickets?'+new URLSearchParams({limit:'30',offset:String(offset.value),status:status.value}))
    rows.value=r.data.items;total.value=r.data.total
    if(selected.value) await open(selected.value.id)
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось загрузить обращения'}finally{loading.value=false}
}
async function open(id:string){
  try{
    const data=(await api.request<{data:Ticket & {messages:Message[]}}>('/support/tickets/'+encodeURIComponent(id))).data
    selected.value=data;assignment.value=data.assigned_to||'';priority.value=data.priority;ticketStatus.value=data.status
  }catch(e){error.value=e instanceof Error?e.message:'Ошибка открытия'}
}
async function execute(fn:()=>Promise<void>){
  if(saving.value)return
  saving.value=true;error.value='';notice.value=''
  try{await fn();await refresh()}catch(e){error.value=e instanceof Error?e.message:'Операция не выполнена'}finally{saving.value=false}
}
function save(){if(!selected.value)return
  execute(async()=>{
    await api.request('/support/tickets/'+encodeURIComponent(selected.value!.id),{method:'PATCH',body:JSON.stringify({
      expected_revision:Number(selected.value!.revision),assigned_to:assignment.value.trim()||null,status:ticketStatus.value,priority:priority.value
    })})
    notice.value='Обращение обновлено'
  })
}
function send(){if(!selected.value||!reply.value.trim())return
  execute(async()=>{
    await api.request('/support/tickets/'+encodeURIComponent(selected.value!.id)+'/messages',{method:'POST',body:JSON.stringify({message:reply.value.trim()})})
    reply.value='';draft.value='';draftSources.value=[];notice.value='Ответ отправлен посетителю'
  })
}
function suggest(){if(!selected.value)return
  execute(async()=>{
    const result=(await api.request<{data:{text:string;sources:Array<{title:string}>}}>('/support/tickets/'+encodeURIComponent(selected.value!.id)+'/ai-draft',{method:'POST',body:'{}'})).data
    draft.value=result.text
    draftSources.value=(result.sources||[]) as Array<{id:string;title:string;number:number}>
    if(result.text)reply.value=result.text
    notice.value=result.text?'Черновик подготовлен по публичной базе знаний. Проверьте текст перед отправкой.':'Подходящие публичные источники не найдены. Ответьте вручную.'
  })
}
function fmt(v:number){return new Date(Number(v)*1000).toLocaleString('ru-RU')}
onMounted(refresh)
</script>

<template>
  <section class="support-page">
    <header><h1>AI Support Center</h1><p>Обращения посетителей, переписка, передача оператору и AI-черновики только из опубликованной публичной базы знаний.</p></header>
    <p v-if="error" role="alert" class="ce-error">{{error}}</p><p v-if="notice" role="status">{{notice}}</p>
    <div class="support-grid">
      <aside class="support-panel">
        <h2>Очередь ({{total}})</h2>
        <div class="actions"><select v-model="status" aria-label="Фильтр статуса" @change="offset=0;refresh()">
          <option value="">Все</option><option value="open">Открытые</option><option value="waiting">Ожидают ответа</option><option value="resolved">Решённые</option><option value="closed">Закрытые</option>
        </select><button :disabled="loading" @click="refresh">Обновить</button></div>
        <p v-if="loading">Загрузка…</p>
        <ul class="tickets"><li v-for="t in rows" :key="t.id">
          <button :aria-current="selected?.id===t.id?'page':undefined" @click="open(t.id)"><strong>{{t.subject}}</strong><small>{{t.name}} · {{t.email}}</small><small>{{t.status}} · {{t.priority}} <span v-if="t.needs_human">· нужен оператор</span></small><small>{{fmt(t.updated_at)}}</small></button>
        </li></ul>
        <div class="actions"><button :disabled="offset===0||loading" @click="offset=Math.max(0,offset-30);refresh()">Назад</button><button :disabled="offset+30>=total||loading" @click="offset+=30;refresh()">Далее</button></div>
      </aside>
      <section v-if="selected" class="support-panel">
        <h2>{{selected.subject}}</h2><p><strong>{{selected.name}}</strong> · {{selected.email}} · {{selected.locale}}</p>
        <form class="ticket-editor" @submit.prevent="save">
          <label>Статус<select v-model="ticketStatus" :disabled="saving"><option value="open">Открыто</option><option value="waiting">Ожидание посетителя</option><option value="resolved">Решено</option><option value="closed">Закрыто</option></select></label>
          <label>Приоритет<select v-model="priority" :disabled="saving"><option value="low">Низкий</option><option value="normal">Обычный</option><option value="high">Высокий</option><option value="urgent">Срочный</option></select></label>
          <label>Ответственный (UUID CMS-пользователя)<input v-model="assignment" :disabled="saving" placeholder="Не назначен" maxlength="36"/></label>
          <button type="button" :disabled="saving" @click="assignment=auth.identity.value?.user_id||''">Назначить себе</button>
          <button type="submit" :disabled="saving">Сохранить состояние</button>
        </form>
        <h3>История</h3><div class="transcript" aria-live="polite"><article v-for="m in selected.messages" :key="m.id" :class="['message',m.role]"><strong>{{m.role==='visitor'?'Посетитель':m.role==='operator'?'Оператор':'AI'}}</strong><p>{{m.content}}</p><small v-for="source in m.sources" :key="source.id">[{{source.number}}] {{source.title}}</small></article></div>
        <form class="ticket-editor" @submit.prevent="send"><label>Ответ посетителю<textarea v-model="reply" :disabled="saving||selected.status==='closed'" maxlength="6000" rows="5" required/></label>
          <div class="actions"><button type="button" :disabled="saving||selected.status==='closed'" @click="suggest">AI-подсказка по публичной KB</button><button type="submit" :disabled="saving||!reply.trim()||selected.status==='closed'">Отправить ответ</button></div>
          <p v-if="draft">AI сформировал черновик. Перед отправкой проверьте источники и точность.</p><ul v-if="draftSources.length"><li v-for="source in draftSources" :key="source.id">[{{source.number}}] {{source.title}}</li></ul>
        </form>
      </section><section v-else class="support-panel">Выберите обращение для просмотра и ответа.</section>
    </div>
  </section>
</template>
<style scoped>
.support-page{display:grid;gap:16px}.support-grid{display:grid;grid-template-columns:minmax(260px,340px) minmax(0,1fr);gap:16px}.support-panel{padding:18px;border:1px solid #d7dce4;border-radius:12px;min-width:0;background:#fff}.tickets{padding:0;list-style:none;max-height:75vh;overflow:auto}.tickets li{margin:5px 0}.tickets button{display:grid;gap:4px;text-align:left;width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:7px;background:#f8fafc;overflow-wrap:anywhere}.tickets button[aria-current=page]{border-color:#2563eb;background:#eff6ff}.tickets small{color:#64748b}.actions{display:flex;gap:8px;flex-wrap:wrap}.ticket-editor{display:grid;gap:12px;margin:16px 0}.ticket-editor label{display:grid;gap:4px}.ticket-editor input,.ticket-editor select,.ticket-editor textarea{padding:9px;border:1px solid #cbd5e1;border-radius:6px;max-width:100%;font:inherit}.support-panel button{cursor:pointer;padding:8px;border:1px solid #bfcad9;border-radius:6px;background:#f8fafc}.support-panel button:disabled{cursor:wait;opacity:.55}.transcript{max-height:50vh;overflow:auto;display:grid;gap:8px}.message{border-radius:8px;padding:12px;background:#f1f5f9;overflow-wrap:anywhere}.message.visitor{background:#eff6ff}.message.ai{background:#f0fdf4}.message p{white-space:pre-wrap;margin:6px 0}.message small{display:block;color:#475569}@media(max-width:950px){.support-grid{grid-template-columns:1fr}}
</style>
