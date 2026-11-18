<script setup lang="ts">
import type { ContentEntryRecord, ContentTypeRecord } from '../types/admin'

const api = useAdminApi()
type Localization = {id:string;title:string;locale:string;status:string;revision:number;ai_generated:boolean;stale:boolean|null;source_revision:number|null}
type Overview = {source:{id:string;title:string;locale:string;revision:number};localizations:Localization[]}
type Term = {id:string;source_term:string;translated_term:string}
type Proposal = {id:string;source_locale:string;target_locale:string;original_title:string;translated_title:string;translated_data:Record<string,unknown>;source_revision:number;target_entry_id:string|null;target_revision:number|null;segment_count:number|null;usage:{input_tokens:number|null;output_tokens:number|null}}
const types = ref<ContentTypeRecord[]>([])
const selectedType = ref('')
const entries = ref<ContentEntryRecord[]>([])
const selectedId = ref('')
const targetLocale = ref('en')
const instructions = ref('')
const updateExisting = ref(false)
const overview = ref<Overview|null>(null)
const glossary = ref<Term[]>([])
const term = ref('')
const translation = ref('')
const proposal = ref<Proposal|null>(null)
type BatchItem = {id:string;source_entry_id:string;status:string;proposal_id:string|null;error_message:string|null}
type Batch = {id:string;type:string;target_locale:string;total:number;ready:number;failed:number;items:BatchItem[]}
const batch = ref<Batch|null>(null)
const batchSelection = ref<string[]>([])
const batchUpdateExisting = ref(false)
const batchBusy = ref(false)
const busy = ref(false)
const translating = ref(false)
const applying = ref(false)
const error = ref('')
const info = ref('')
const existing = computed(()=>overview.value?.localizations.find(e=>e.locale===targetLocale.value) || null)
const path = computed(()=>encodeURIComponent(selectedType.value))
const langs = ['ru','en','de','fr','es','it','pl','pt','tr','uk','zh','ja','ko']

async function loadTypes(){
  error.value=''
  try{
    types.value = (await api.request<{data:ContentTypeRecord[]}>('/content-types')).data || []
    if(!types.value.some(t=>t.handle===selectedType.value))selectedType.value=types.value[0]?.handle || ''
    await loadEntries()
  }catch(e){error.value=e instanceof Error?e.message:'Ошибка загрузки типов контента.'}
}
async function loadEntries(){
  overview.value=null;proposal.value=null;glossary.value=[];entries.value=[]
  if(!selectedType.value)return
  try{
    entries.value=(await api.request<{data:ContentEntryRecord[]}>(`/content/${path.value}?status=all&limit=200&offset=0`)).data || []
    if(!entries.value.some(e=>e.id===selectedId.value))selectedId.value=entries.value[0]?.id || ''
    await loadOverview()
  }catch(e){error.value=e instanceof Error?e.message:'Ошибка загрузки материалов.'}
}
async function loadOverview(){
  proposal.value=null;overview.value=null;glossary.value=[];info.value=''
  if(!selectedId.value)return
  try{
    overview.value=(await api.request<{data:Overview}>(`/ai/translations/overview?type=${path.value}&entry_id=${encodeURIComponent(selectedId.value)}`)).data
    await loadGlossary()
  }catch(e){error.value=e instanceof Error?e.message:'Ошибка загрузки локализаций.'}
}
async function loadGlossary(){
  if(!overview.value)return
  try{
    glossary.value=(await api.request<{data:Term[]}>(`/ai/translations/glossary?${new URLSearchParams({type:selectedType.value,source_locale:overview.value.source.locale,target_locale:targetLocale.value})}`)).data||[]
  }catch(e){error.value=e instanceof Error?e.message:'Ошибка загрузки глоссария.'}
}
async function saveTerm(){
  if(!overview.value || busy.value)return
  busy.value=true;error.value=''
  try{
    glossary.value=(await api.request<{data:Term[]}>('/ai/translations/glossary',{method:'POST',body:JSON.stringify({type:selectedType.value,source_locale:overview.value.source.locale,target_locale:targetLocale.value,term:term.value,translation:translation.value})})).data
    term.value='';translation.value='';proposal.value=null;info.value='Глоссарий сохранён.'
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось сохранить термин.'}
  finally{busy.value=false}
}
async function deleteTerm(id:string){
  if(busy.value)return
  busy.value=true;error.value=''
  try{
    await api.request(`/ai/translations/glossary/${encodeURIComponent(id)}`,{method:'DELETE'})
    proposal.value=null;await loadGlossary();info.value='Термин удалён.'
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось удалить термин.'}
  finally{busy.value=false}
}
async function propose(){
  if(!overview.value || translating.value)return
  translating.value=true;error.value='';info.value='';proposal.value=null
  try{
    proposal.value=(await api.request<{data:Proposal}>('/ai/translations/proposals',{method:'POST',body:JSON.stringify({
      type:selectedType.value,entry_id:selectedId.value,target_locale:targetLocale.value,
      update_existing:updateExisting.value,instructions:instructions.value,
    })})).data
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось перевести материал.'}
  finally{translating.value=false}
}
async function apply(){
  if(!proposal.value || applying.value)return
  const published=existing.value?.status==='published'
  if(published&&!window.confirm('Существующий перевод опубликован. Применение изменит публичную страницу. Подтвердить?'))return
  if(!window.confirm('Создать или обновить локализацию на основе показанного перевода?'))return
  applying.value=true;error.value=''
  try{
    const result=(await api.request<{data:{entry:ContentEntryRecord;created:boolean}}>(`/ai/translations/proposals/${encodeURIComponent(proposal.value.id)}/apply`,{method:'POST',body:JSON.stringify({confirm_published:published||false})})).data
    info.value=result.created?'Создан новый черновик локализации.':'Локализация обновлена с новой ревизией.'
    proposal.value=null
    overview.value=(await api.request<{data:Overview}>(`/ai/translations/overview?type=${path.value}&entry_id=${encodeURIComponent(selectedId.value)}`)).data
    entries.value=(await api.request<{data:ContentEntryRecord[]}>(`/content/${path.value}?status=all&limit=200&offset=0`)).data||[]
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось применить перевод; проверьте ревизию и язык.'}
  finally{applying.value=false}
}
async function queueBatch(){
  if(batchBusy.value || !batchSelection.value.length)return
  batchBusy.value=true;error.value=''
  try{
    batch.value=(await api.request<{data:Batch}>('/ai/translations/batches',{method:'POST',body:JSON.stringify({
      type:selectedType.value,entry_ids:batchSelection.value,target_locale:targetLocale.value,
      update_existing:batchUpdateExisting.value,instructions:instructions.value,
    })})).data
    info.value='Переводы отправлены в очередь. Обновляйте статус после выполнения worker.'
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось создать пакет переводов.'}
  finally{batchBusy.value=false}
}
async function refreshBatch(){
  if(!batch.value || batchBusy.value)return
  batchBusy.value=true;error.value=''
  try{batch.value=(await api.request<{data:Batch}>(`/ai/translations/batches/${encodeURIComponent(batch.value.id)}`)).data}
  catch(e){error.value=e instanceof Error?e.message:'Не удалось обновить пакет.'}
  finally{batchBusy.value=false}
}
async function openBatch(item:BatchItem){
  if(!item.proposal_id)return
  error.value=''
  try{
    const result=(await api.request<{data:Proposal}>(`/ai/translations/proposals/${encodeURIComponent(item.proposal_id)}`)).data
    selectedId.value=item.source_entry_id
    await nextTick()
    await loadOverview()
    targetLocale.value=result.target_locale
    await nextTick()
    updateExisting.value=!!result.target_entry_id
    proposal.value=result
  }catch(e){error.value=e instanceof Error?e.message:'Предложение недоступно.'}
}
watch(selectedType,()=>{batch.value=null;batchSelection.value=[]})
watch(selectedType,loadEntries)
watch(selectedId,loadOverview)
watch(targetLocale,()=>{proposal.value=null;updateExisting.value=false;loadGlossary()})
watch([instructions,updateExisting],()=>{proposal.value=null})
onMounted(loadTypes)
</script>

<template>
  <section>
    <div class="ce-actions"><h1>AI Translation Studio</h1><button class="ce-button secondary" @click="loadTypes">Обновить</button></div>
    <p>Переводы создаются как черновики в существующей группе локализаций. HTML, URL и плейсхолдеры сохраняются; изменения применяются только после проверки.</p>
    <p v-if="error" role="alert" class="ce-error">{{error}}</p><p v-if="info" role="status">{{info}}</p>
    <div class="ce-panel translation-panel">
      <h2>Источник и язык</h2>
      <label>Тип контента<select v-model="selectedType"><option v-for="t in types" :key="t.handle" :value="t.handle">{{t.name}} / {{t.handle}}</option></select></label>
      <label>Исходный материал<select v-model="selectedId"><option v-for="e in entries" :key="e.id" :value="e.id">{{e.title}} · {{e.locale}} · {{e.status}}</option></select></label>
      <div v-if="overview" class="translation-grid">
        <label>Язык оригинала<input :value="overview.source.locale" disabled /></label>
        <label>Язык перевода<select v-model="targetLocale"><option v-for="locale in langs.filter(l=>l!==overview?.source.locale)" :key="locale" :value="locale">{{locale}}</option></select></label>
      </div>
      <p v-if="existing"><strong>Существующий перевод:</strong> {{existing.title}} · {{existing.status}} · ревизия {{existing.revision}} <strong v-if="existing.stale">· Требует обновления</strong></p>
      <label v-if="existing"><input v-model="updateExisting" type="checkbox" /> Обновить существующую локализацию (не создавать новый черновик)</label>
      <label>Указания переводчику<textarea v-model="instructions" maxlength="1500" rows="3" placeholder="Термины, стиль, особенности аудитории" /></label>
      <button class="ce-button" :disabled="!overview || translating || applying || overview.source.locale===targetLocale || (!!existing && !updateExisting) || (!existing && updateExisting)" @click="propose">{{translating?'Перевод…':'Предложить перевод'}}</button>
    </div>
    <div v-if="overview" class="ce-panel translation-panel">
      <h2>Локализации в группе</h2>
      <table class="ce-table"><thead><tr><th>Язык</th><th>Материал</th><th>Статус</th><th>Актуальность</th></tr></thead><tbody>
        <tr v-for="loc in overview.localizations" :key="loc.id"><td>{{loc.locale}}</td><td>{{loc.title}}</td><td>{{loc.status}} · rev {{loc.revision}}</td><td>{{loc.stale===true?'Исходник изменён':loc.stale===false?'Актуально':'Без AI-метаданных'}}</td></tr>
      </tbody></table>
    </div>
    <div v-if="overview" class="ce-panel translation-panel">
      <h2>Глоссарий {{overview.source.locale}} → {{targetLocale}}</h2>
      <form class="translation-grid" @submit.prevent="saveTerm"><label>Исходный термин<input v-model="term" maxlength="160" required /></label><label>Перевод<input v-model="translation" maxlength="250" required /></label><button class="ce-button secondary" :disabled="busy || overview.source.locale===targetLocale">Сохранить термин</button></form>
      <table v-if="glossary.length" class="ce-table"><tbody><tr v-for="item in glossary" :key="item.id"><td>{{item.source_term}}</td><td>{{item.translated_term}}</td><td><button class="ce-button secondary" :disabled="busy" @click="deleteTerm(item.id)">Удалить</button></td></tr></tbody></table>
    </div>
    <div class="ce-panel translation-panel">
      <h2>Пакетный перевод через очередь</h2>
      <p>Выберите до 20 материалов. Фоновый worker подготовит отдельные предложения; публикация выполняется только после проверки каждого.</p>
      <div class="translation-batch-select"><label v-for="entry in entries" :key="entry.id"><input v-model="batchSelection" type="checkbox" :value="entry.id" :disabled="batchSelection.length>=20&&!batchSelection.includes(entry.id) || entry.locale===targetLocale" /> {{entry.title}} · {{entry.locale}}</label></div>
      <label><input v-model="batchUpdateExisting" type="checkbox" /> Обновить существующие переводы вместо создания новых (для всех выбранных записей)</label>
      <button class="ce-button secondary" :disabled="batchBusy||!batchSelection.length||batchSelection.length>20" @click="queueBatch">{{batchBusy?'Отправка…':`Поставить в очередь (${batchSelection.length})`}}</button>
      <div v-if="batch"><div class="ce-actions"><p><strong>Пакет:</strong> {{batch.id}} · готово {{batch.ready}} / {{batch.total}} · ошибок {{batch.failed}}</p><button class="ce-button secondary" :disabled="batchBusy" @click="refreshBatch">Обновить статус</button></div>
        <table class="ce-table"><thead><tr><th>Материал</th><th>Статус</th><th>Действие</th></tr></thead><tbody><tr v-for="item in batch.items" :key="item.id"><td>{{entries.find(e=>e.id===item.source_entry_id)?.title||item.source_entry_id}}</td><td>{{item.status}} {{item.error_message||''}}</td><td><button v-if="item.status==='ready'&&item.proposal_id" class="ce-button secondary" @click="openBatch(item)">Проверить перевод</button></td></tr></tbody></table>
      </div>
    </div>
    <div v-if="proposal" class="ce-panel translation-panel">
      <h2>Предварительный просмотр · {{proposal.source_locale}} → {{proposal.target_locale}}</h2>
      <p>Исходная ревизия {{proposal.source_revision}} <template v-if="proposal.segment_count!==null">· Сегментов {{proposal.segment_count}}</template> <template v-if="proposal.usage.input_tokens!==null">· Токены {{proposal.usage.input_tokens}} / {{proposal.usage.output_tokens}}</template></p>
      <div class="translation-grid"><div><strong>Заголовок оригинала</strong><pre>{{proposal.original_title}}</pre></div><div><strong>Переведённый заголовок</strong><pre>{{proposal.translated_title}}</pre></div></div>
      <details open><summary>Переведённые поля и блоки (JSON)</summary><pre class="translation-json">{{JSON.stringify(proposal.translated_data,null,2)}}</pre></details>
      <p>До подтверждения ни исходная запись, ни локализации не меняются.</p>
      <button class="ce-button" :disabled="applying" @click="apply">{{applying?'Применение…':proposal.target_entry_id?'Применить как новую ревизию':'Создать черновик локализации'}}</button>
      <button class="ce-button secondary" @click="proposal=null">Отклонить</button>
    </div>
  </section>
</template>

<style scoped>
.translation-panel{margin:0 0 18px;display:grid;gap:12px}.translation-panel label{display:grid;gap:6px}.translation-panel select,.translation-panel input:not([type=checkbox]),.translation-panel textarea{padding:10px;border:1px solid #cbd5e1;border-radius:8px;max-width:100%;width:100%}.translation-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.translation-batch-select{display:grid;grid-template-columns:1fr 1fr;gap:8px;max-height:240px;overflow:auto}.translation-batch-select label{display:block!important}.translation-panel pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f1f5f9;padding:12px;border-radius:8px;max-height:350px;overflow:auto}.translation-json{max-height:550px!important}@media(max-width:800px){.translation-grid{grid-template-columns:1fr}}
</style>
