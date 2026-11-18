<script setup lang="ts">
import type { ContentEntryRecord, ContentTypeRecord } from '../types/admin'

const api = useAdminApi()
type EditorialProposal = { id: string; type: string; entry_id: string | null; field: string; field_type: string; mode: string; original: string; proposed: string; expected_revision: number | null; usage: { input_tokens: number; output_tokens: number } }
const types = ref<ContentTypeRecord[]>([])
const entries = ref<ContentEntryRecord[]>([])
const selectedType = ref('')
const error = ref('')
const info = ref('')
const busy = ref(false)
const form = reactive({ id: '', revision: 0, status: 'draft', title: '', slug: '', locale: 'ru', dataJson: '{}' })
const ai = reactive({ field: 'title', mode: 'generate', instructions: '', selection: '', generating: false, applying: false })
const proposal = ref<EditorialProposal | null>(null)
const fields = computed(() => {
  const type=types.value.find(t => t.handle === selectedType.value)
  const fields=[{handle:'title',label:'Заголовок'},...(type?.fields||[])
    .filter(f=>['text','textarea','richtext'].includes(f.type)).map(f=>({handle:f.handle,label:f.label||f.handle}))]
  if(form.id && type) {
    let data:Record<string,unknown>
    try { data=JSON.parse(form.dataJson) } catch { return fields }
    if (!data || typeof data!=='object' || Array.isArray(data)) return fields
    for(const field of type.fields.filter(f=>f.type==='blocks')) {
      const blocks=data[field.handle]
      if(!Array.isArray(blocks)) continue
      blocks.forEach((block,index)=>{
        const definition=type.blocks?.find(b=>b.handle===block?.handle)
        const subfields=Array.isArray(definition?.fields)?definition.fields:[]
        subfields.filter((f:Record<string,unknown>)=>['text','textarea','richtext'].includes(String(f.type)))
          .forEach((f:Record<string,unknown>)=>fields.push({handle:`${field.handle}|${index}|${f.handle}`,label:`Блок ${index+1} / ${f.label||f.handle}`}))
      })
    }
  }
  return fields
})
const reset = () => { Object.assign(form, {id:'',revision:0,status:'draft',title:'',slug:'',locale:'ru',dataJson:'{}'}); ai.mode='generate'; ai.selection=''; proposal.value=null; info.value='' }
const loadTypes = async () => {
  const response = await api.request<{data:ContentTypeRecord[]}>('/content-types')
  types.value = response.data || []
  if (!types.value.some(t => t.handle === selectedType.value)) selectedType.value = types.value[0]?.handle || ''
}
const loadEntries = async () => {
  if (!selectedType.value) { entries.value=[]; return }
  const response = await api.request<{data:ContentEntryRecord[]}>(`/content/${encodeURIComponent(selectedType.value)}?status=all&limit=50`)
  entries.value = response.data || []
}
const load = async () => {
  error.value=''
  try { await loadTypes(); await loadEntries() }
  catch (e) { error.value=e instanceof Error ? e.message : 'Не удалось загрузить контент.' }
}
watch(selectedType, () => { reset(); loadEntries().catch(e => { error.value=e.message }) })
// A preview belongs to its exact operation, target field, instructions and selection.
watch(() => [ai.field, ai.mode, ai.instructions, ai.selection], () => { proposal.value = null })
const selectEntry = async (entry:ContentEntryRecord) => {
  error.value=''; info.value=''; proposal.value=null
  try {
    const response = await api.request<{data:ContentEntryRecord}>(`/content/${encodeURIComponent(selectedType.value)}/${encodeURIComponent(entry.id)}`)
    const item = response.data
    Object.assign(form, {id:item.id, revision:Number(item.revision ?? item.revision_number ?? 0), status:item.status || 'draft',
      title:item.title || '', slug:item.slug || '', locale:item.locale || 'ru', dataJson:JSON.stringify(item.data || {},null,2)})
    ai.mode = 'rewrite'; ai.selection=''
  } catch(e) { error.value=e instanceof Error ? e.message : 'Ошибка загрузки записи.' }
}
const save = async () => {
  if (busy.value || !selectedType.value) return
  busy.value=true; error.value=''; info.value=''
  try {
    const data = JSON.parse(form.dataJson)
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('Data JSON должен быть объектом.')
    const body:Record<string,unknown>={title:form.title,slug:form.slug,locale:form.locale,data,status:form.status}
    if (form.id) body.expected_revision=form.revision
    const url = `/content/${encodeURIComponent(selectedType.value)}${form.id ? '/' + encodeURIComponent(form.id) : ''}`
    const item = (await api.request<{data:ContentEntryRecord}>(url,{method:form.id?'PUT':'POST', body:JSON.stringify(body)})).data
    await loadEntries(); await selectEntry(item); info.value='Запись сохранена с новой ревизией.'
  } catch(e) { error.value=e instanceof Error ? e.message : 'Ошибка сохранения.' }
  finally { busy.value=false }
}
const action = async (entry:ContentEntryRecord,name:'publish'|'unpublish'|'archive') => {
  error.value=''; info.value=''
  try { await api.request(`/content/${encodeURIComponent(selectedType.value)}/${encodeURIComponent(entry.id)}/${name}`,{method:'POST'})
    await loadEntries(); if(form.id===entry.id) await selectEntry(entry) }
  catch(e) { error.value=e instanceof Error ? e.message : 'Ошибка изменения статуса.' }
}
const generate = async () => {
  if(ai.generating || !selectedType.value) return
  ai.generating=true; error.value=''; info.value=''; proposal.value=null
  try {
    const [field,blockIndex,blockField]=ai.field.split('|')
    const response = await api.request<{data:EditorialProposal}>('/ai/editor/proposals', {method:'POST', body:JSON.stringify({
      type:selectedType.value, entry_id:form.id || null, field, ...(blockIndex!==undefined?{block_index:Number(blockIndex),block_field:blockField}:{}), mode:form.id ? ai.mode : 'generate',
      instructions:ai.instructions, selection:form.id ? ai.selection : '', locale:form.locale,
    })})
    proposal.value=response.data
  } catch(e) { error.value=e instanceof Error ? e.message : 'Ошибка AI Editor.' }
  finally { ai.generating=false }
}
const accept = async () => {
  if(!proposal.value || ai.applying) return
  error.value=''; info.value=''
  if(!form.id) {
    if(proposal.value.field === 'title') form.title=proposal.value.proposed
    else {
      try { const data=JSON.parse(form.dataJson); data[proposal.value.field]=proposal.value.proposed; form.dataJson=JSON.stringify(data,null,2) }
      catch(e) { error.value='Исправьте Data JSON перед применением предложения.'; return }
    }
    proposal.value=null; info.value='Предложение перенесено в черновик. Нажмите «Сохранить» для создания записи.'
    return
  }
  const published = form.status === 'published'
  if(published && !window.confirm('Запись опубликована. Применение изменит её на публичном сайте. Подтвердить обновление?')) return
  ai.applying=true
  try {
    const response = await api.request<{data:{entry:ContentEntryRecord}}>(`/ai/editor/proposals/${encodeURIComponent(proposal.value.id)}/apply`,{
      method:'POST',body:JSON.stringify({confirm_published:published}),
    })
    await loadEntries(); await selectEntry(response.data.entry); info.value='AI-правка применена как новая ревизия.'
  } catch(e) { error.value=e instanceof Error ? e.message : 'Не удалось применить AI-правку. Проверьте ревизию.' }
  finally { ai.applying=false }
}
onMounted(load)
</script>

<template>
  <section>
    <div class="ce-actions"><h1>Контент · AI Editor</h1>
      <select v-model="selectedType"><option v-for="type in types" :key="type.handle" :value="type.handle">{{ type.name }} / {{ type.handle }}</option></select>
      <button class="ce-button secondary" @click="load">Обновить</button>
      <button class="ce-button secondary" @click="reset">Новый черновик</button>
    </div>
    <p v-if="error" class="ce-error" role="alert">{{ error }}</p><p v-if="info" role="status">{{ info }}</p>
    <div class="ce-panel" style="margin-bottom:18px">
      <h2>{{ form.id ? `Редактирование · ревизия ${form.revision}` : 'Создать черновик' }}</h2>
      <form class="ce-form" @submit.prevent="save">
        <AdminFormRow><template #label>Заголовок</template><input v-model="form.title" required /></AdminFormRow>
        <AdminFormRow><template #label>Slug</template><input v-model="form.slug" /></AdminFormRow>
        <AdminFormRow><template #label>Locale</template><input v-model="form.locale" /></AdminFormRow>
        <AdminFormRow><template #label>Статус</template><select v-model="form.status"><option value="draft">draft</option><option value="published">published</option><option value="archived">archived</option></select></AdminFormRow>
        <AdminFormRow><template #label>Data JSON</template><textarea v-model="form.dataJson" rows="10" spellcheck="false" /></AdminFormRow>
        <button class="ce-button" type="submit" :disabled="busy || !selectedType">{{ busy ? 'Сохранение…' : 'Сохранить' }}</button>
      </form>
    </div>
    <div class="ce-panel ce-editor-ai" style="margin-bottom:18px">
      <h2>AI Content Editor</h2><p>Предложение не меняет контент до подтверждения. Для существующей записи используется сохранённая серверная ревизия.</p>
      <div class="ce-form">
        <label>Поле<select v-model="ai.field"><option v-for="field in fields" :key="field.handle" :value="field.handle">{{ field.label }} ({{ field.handle }})</option></select></label>
        <label>Операция<select v-model="ai.mode" :disabled="!form.id"><option value="generate">Генерация</option><option value="rewrite">Переписать</option><option value="shorten">Сократить</option><option value="expand">Расширить</option><option value="proofread">Исправить ошибки</option><option value="tone">Изменить тон</option></select></label>
        <label>Задание<textarea v-model="ai.instructions" rows="3" maxlength="3000" placeholder="Тема, цель, стиль, требования" /></label>
        <label v-if="form.id && ai.mode !== 'generate'">Выделенный фрагмент (необязательно, должен дословно совпадать с текстом сохранённого поля)<textarea v-model="ai.selection" rows="2" maxlength="12000" /></label>
        <button class="ce-button secondary" type="button" :disabled="ai.generating || ai.applying || !selectedType" @click="generate">{{ ai.generating ? 'Генерация…' : 'Создать предложение' }}</button>
      </div>
      <div v-if="proposal" class="ce-editor-preview">
        <h3>Предварительный просмотр</h3>
        <div class="ce-editor-diff"><div><strong>Исходный текст</strong><pre>{{ proposal.original || '(пусто)' }}</pre></div><div><strong>AI-предложение</strong><pre>{{ proposal.proposed }}</pre></div></div>
        <p>Токены: {{ proposal.usage.input_tokens }} вход / {{ proposal.usage.output_tokens }} выход. Никакие изменения ещё не сохранены.</p>
        <button class="ce-button" type="button" :disabled="ai.applying" @click="accept">{{ ai.applying ? 'Применение…' : form.id ? 'Применить как ревизию' : 'Перенести в черновик' }}</button>
        <button class="ce-button secondary" type="button" @click="proposal=null">Отклонить</button>
      </div>
    </div>
    <table class="ce-table"><thead><tr><th>Заголовок</th><th>Status</th><th>Locale</th><th>Revision</th><th>Actions</th></tr></thead>
      <tbody><tr v-for="entry in entries" :key="entry.id"><td>{{ entry.title || entry.slug || entry.id }}</td><td>{{ entry.status }}</td><td>{{ entry.locale }}</td><td>{{ entry.revision ?? entry.revision_number }}</td>
        <td class="ce-actions"><button class="ce-button secondary" @click="selectEntry(entry)">Редактировать</button><button class="ce-button secondary" @click="action(entry,'publish')">Publish</button><button class="ce-button secondary" @click="action(entry,'unpublish')">Unpublish</button><button class="ce-button danger" @click="action(entry,'archive')">Archive</button></td></tr></tbody>
    </table>
    <p v-if="types.length===0" class="ce-muted">Сначала создайте тип контента.</p>
  </section>
</template>

<style scoped>
.ce-editor-ai label{display:grid;gap:5px}.ce-editor-ai select,.ce-editor-ai textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px}.ce-editor-preview{margin-top:16px}.ce-editor-diff{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ce-editor-diff pre{white-space:pre-wrap;overflow-wrap:anywhere;max-height:320px;overflow:auto;background:#f1f5f9;padding:14px;border-radius:8px}.ce-editor-preview button{margin-right:8px}@media(max-width:800px){.ce-editor-diff{grid-template-columns:1fr}}
</style>
