<script setup lang="ts">
import type { ContentEntryRecord, ContentTypeRecord } from '../types/admin'

const api = useAdminApi()
type Issue = { code: string; severity: 'high'|'medium'|'low'|'info'; message: string; action: string }
type SeoAudit = {
  type: string; entry_id: string; revision: number; status: string; title: string; locale: string; path: string|null;
  score: number; seo: {title:string; description:string; canonical_url:string; noindex:boolean};
  stats:{words:number;headings:Record<string,number>;images_without_alt:number;internal_links_checked:number}; issues:Issue[]; notes:string
}
type Proposal = {id:string;original:string;proposed:string;field:string;expected_revision:number;usage:{input_tokens:number;output_tokens:number}}
const types = ref<ContentTypeRecord[]>([])
const selectedType = ref('')
const entries = ref<ContentEntryRecord[]>([])
const selectedId = ref('')
const audit = ref<SeoAudit | null>(null)
const batch = ref<SeoAudit[]>([])
const available = ref(0)
const proposal = ref<Proposal|null>(null)
const target = ref<'seo_title'|'meta_description'>('seo_title')
const instructions = ref('')
const error = ref('')
const info = ref('')
const busy = ref(false)
const generating = ref(false)
const applying = ref(false)
const typePath = computed(() => encodeURIComponent(selectedType.value))

async function load() {
  error.value = ''; info.value=''
  try {
    types.value = (await api.request<{data:ContentTypeRecord[]}>('/content-types')).data || []
    if (!types.value.some(t=>t.handle===selectedType.value)) selectedType.value = types.value[0]?.handle || ''
    await loadEntries()
  } catch(e) {error.value=e instanceof Error?e.message:'Ошибка загрузки типов контента.'}
}
async function loadEntries() {
  audit.value=null;proposal.value=null;batch.value=[];selectedId.value='';entries.value=[]
  if (!selectedType.value) return
  try {
    entries.value=(await api.request<{data:ContentEntryRecord[]}>(`/content/${typePath.value}?status=all&limit=200&offset=0`)).data || []
  } catch(e) {error.value=e instanceof Error?e.message:'Ошибка списка записей.'}
}
async function scan(id=selectedId.value) {
  if(!id || busy.value) return
  busy.value=true;error.value='';info.value='';proposal.value=null
  try {
    audit.value=(await api.request<{data:SeoAudit}>(`/ai/seo/audit?type=${typePath.value}&entry_id=${encodeURIComponent(id)}`)).data
    selectedId.value=id
  }catch(e){error.value=e instanceof Error?e.message:'Ошибка SEO-аудита.'}
  finally{busy.value=false}
}
async function scanBatch(){
  if(!selectedType.value||busy.value)return
  busy.value=true;error.value=''
  try{
    const res=(await api.request<{data:{reports:SeoAudit[];meta:{available:number}}}>(`/ai/seo/batch?type=${typePath.value}&limit=30`)).data
    batch.value=res.reports;available.value=res.meta.available
  }catch(e){error.value=e instanceof Error?e.message:'Ошибка пакетной проверки.'}
  finally{busy.value=false}
}
async function generate(){
  if(!audit.value||generating.value)return
  generating.value=true;error.value='';info.value='';proposal.value=null
  try{
    proposal.value=(await api.request<{data:Proposal}>('/ai/seo/proposals', {method:'POST',body:JSON.stringify({
      type:audit.value.type,entry_id:audit.value.entry_id,field:target.value,instructions:instructions.value,
    })})).data
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось сформировать SEO-предложение.'}
  finally{generating.value=false}
}
async function apply(){
  if(!proposal.value||!audit.value||applying.value)return
  const published=audit.value.status==='published'
  if(published&&!window.confirm('Страница опубликована. SEO-метаданные будут изменены на сайте. Применить новую ревизию?'))return
  applying.value=true;error.value=''
  try{
    const accepted=proposal.value
    await api.request(`/ai/editor/proposals/${encodeURIComponent(accepted.id)}/apply`,{method:'POST',body:JSON.stringify({confirm_published:published})})
    proposal.value=null
    await scan(selectedId.value)
    info.value='SEO-метаданные сохранены как новая ревизия. Изменения можно откатить в истории контента.'
    await loadEntriesAfterApply()
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось применить предложение.'}
  finally{applying.value=false}
}
async function loadEntriesAfterApply(){
  try{entries.value=(await api.request<{data:ContentEntryRecord[]}>(`/content/${typePath.value}?status=all&limit=200&offset=0`)).data||[]}
  catch{/* Audit already applied; allow a later list refresh. */}
}
watch(selectedType,loadEntries)
watch([target,instructions],()=>{proposal.value=null})
type SeoInsightItem={type:string;entry_id:string;revision:number;path:string;title:string;score:number;priority:number;metric:string;current:number;previous:number|null;change_percent:number|null;trend:string;recommendations:{code:string;field:string|null;action:string;severity:string}[]}
type SeoInsights={available:boolean;message?:string;connection_id?:string;provider?:string;from?:string;to?:string;matched?:number;examined?:number;comparison_status?:string;warnings?:string[];items:SeoInsightItem[];notes?:string}
const analyticsSources=ref<{id:string;name:string;provider:string}[]>([])
const insightConnection=ref('')
const insightFrom=ref('')
const insightTo=ref('')
const insightReport=ref<SeoInsights|null>(null)
const insightBusy=ref(false)
const insightField=ref<'seo_title'|'meta_description'>('seo_title')
const insightItem=ref<SeoInsightItem|null>(null)
const insightProposal=ref<Proposal|null>(null)
const insightNote=ref('')
const loadSources=async()=>{
  try{
    const result=(await api.request<{data:{items:{id:string;name:string;provider:string}[]}}>('/analytics/reports')).data
    analyticsSources.value=(result.items||[]).filter(s=>['search_console','webmaster','ga4','metrika','matomo','plausible','cf_web'].includes(s.provider))
    if(!analyticsSources.value.some(s=>s.id===insightConnection.value))insightConnection.value=analyticsSources.value[0]?.id||''
  }catch{analyticsSources.value=[]}
}
const analyzeInsights=async()=>{
  if(!insightConnection.value||insightBusy.value)return
  insightBusy.value=true;error.value='';insightProposal.value=null;insightItem.value=null;insightReport.value=null
  try{
    if(Boolean(insightFrom.value)!==Boolean(insightTo.value))throw new Error('Укажите обе даты или оставьте обе пустыми.')
    const query=new URLSearchParams({connection_id:insightConnection.value,limit:'40',...(insightFrom.value?{from:insightFrom.value,to:insightTo.value}:{})})
    insightReport.value=(await api.request<{data:SeoInsights}>('/ai/seo/insights?'+query)).data
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось сопоставить SEO со статистикой.'}
  finally{insightBusy.value=false}
}
const proposeInsight=async(item:SeoInsightItem,field:'seo_title'|'meta_description')=>{
  if(insightBusy.value)return
  insightBusy.value=true;insightItem.value=item;insightField.value=field;insightProposal.value=null;error.value='';insightNote.value=''
  try{
    const result=(await api.request<{data:{proposal:Proposal}}>('/ai/seo/insights/proposals',{method:'POST',body:JSON.stringify({
      connection_id:insightConnection.value,type:item.type,entry_id:item.entry_id,field,
      ...(insightFrom.value?{from:insightFrom.value,to:insightTo.value}:{})
    })})).data
    insightProposal.value=result.proposal
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось подготовить предложение.'}
  finally{insightBusy.value=false}
}
const applyInsight=async()=>{
  if(!insightProposal.value||!insightItem.value||insightBusy.value)return
  if(!window.confirm('Изменить SEO-метаданные опубликованной страницы? Будет создана новая ревизия.'))return
  insightBusy.value=true;error.value=''
  try{
    await api.request('/ai/editor/proposals/'+encodeURIComponent(insightProposal.value.id)+'/apply',
      {method:'POST',body:JSON.stringify({confirm_published:true})})
    insightProposal.value=null;insightItem.value=null;insightNote.value='Правка сохранена как новая ревизия CMS.'
    await analyzeInsightsAfterApply()
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось применить рекомендацию.'}
  finally{insightBusy.value=false}
}
const analyzeInsightsAfterApply=async()=>{
  const query=new URLSearchParams({connection_id:insightConnection.value,limit:'40',...(insightFrom.value?{from:insightFrom.value,to:insightTo.value}:{})})
  insightReport.value=(await api.request<{data:SeoInsights}>('/ai/seo/insights?'+query)).data
}
onMounted(async()=>{await load();await loadSources()})
</script>

<template>
  <section>
    <div class="ce-actions"><h1>AI SEO Assistant</h1>
      <select v-model="selectedType" aria-label="Тип контента"><option v-for="type in types" :key="type.handle" :value="type.handle">{{type.name}} / {{type.handle}}</option></select>
      <button class="ce-button secondary" @click="load">Обновить</button>
      <button class="ce-button secondary" :disabled="busy||!selectedType" @click="scanBatch">Проверить опубликованные (до 30)</button>
    </div>
    <p v-if="error" class="ce-error" role="alert">{{error}}</p><p v-if="info" role="status">{{info}}</p>
    <p>SEO-аудит читает текущие записи и маршруты CMS без внешнего сканирования. Для предложения OpenAI требуется настроенный API-ключ. Сохранение только после подтверждения.</p>
    <div class="ce-panel" style="margin-bottom:18px">
      <h2>Материал</h2>
      <div class="ce-actions"><select v-model="selectedId" aria-label="Материал"><option value="">Выберите запись</option><option v-for="entry in entries" :key="entry.id" :value="entry.id">{{entry.title}} · {{entry.status}} · {{entry.locale}}</option></select>
        <button class="ce-button" :disabled="busy||!selectedId" @click="scan()">{{busy?'Проверка…':'Анализировать'}}</button>
      </div>
    </div>
    <div v-if="batch.length" class="ce-panel" style="margin-bottom:18px">
      <h2>Пакетная проверка — {{batch.length}} из {{available}}</h2>
      <table class="ce-table"><thead><tr><th>Страница</th><th>Балл</th><th>Замечания</th><th></th></tr></thead><tbody>
        <tr v-for="report in batch" :key="report.entry_id"><td>{{report.title}}</td><td>{{report.score}}/100</td><td>{{report.issues.length}}</td><td><button class="ce-button secondary" @click="scan(report.entry_id)">Открыть</button></td></tr>
      </tbody></table>
    </div>
    <div v-if="audit" class="ce-panel" style="margin-bottom:18px">
      <h2>SEO-аудит: {{audit.title}} · {{audit.score}}/100</h2>
      <p><strong>Ревизия:</strong> {{audit.revision}} · <strong>Статус:</strong> {{audit.status}} · <strong>URL:</strong> {{audit.path || 'Нет маршрута'}}</p>
      <p><strong>SEO Title:</strong> {{audit.seo.title || '(нет)'}}<br><strong>Description:</strong> {{audit.seo.description || '(нет)'}}<br><strong>Canonical:</strong> {{audit.seo.canonical_url || '(автоматический)'}} · <strong>Noindex:</strong> {{audit.seo.noindex?'Да':'Нет'}}</p>
      <p>Слов: {{audit.stats.words}} · H1: {{audit.stats.headings.h1}} · H2: {{audit.stats.headings.h2}} · Проверено внутренних ссылок: {{audit.stats.internal_links_checked}}</p>
      <table v-if="audit.issues.length" class="ce-table"><thead><tr><th>Приоритет</th><th>Проблема</th><th>Рекомендация</th></tr></thead><tbody><tr v-for="issue in audit.issues" :key="issue.code"><td>{{issue.severity}}</td><td>{{issue.message}}</td><td>{{issue.action}}</td></tr></tbody></table>
      <p v-else>В выбранных проверках замечаний не найдено.</p>
      <p class="ce-muted">{{audit.notes}}</p>
      <div class="ce-seo-generator">
        <h3>AI-рекомендация</h3>
        <label>Поле<select v-model="target"><option value="seo_title">SEO Title</option><option value="meta_description">Meta Description</option></select></label>
        <label>Ключевая фраза / рекомендации<textarea v-model="instructions" maxlength="1500" rows="3" placeholder="Необязательно"/></label>
        <button class="ce-button secondary" :disabled="generating||applying" @click="generate">{{generating?'Генерация…':'Сгенерировать предложение'}}</button>
        <div v-if="proposal" class="ce-seo-compare"><h3>Проверка перед применением</h3><div class="ce-seo-diff"><div><strong>Текущее значение</strong><pre>{{proposal.original||'(пусто)'}}</pre></div><div><strong>Предложение</strong><pre>{{proposal.proposed}}</pre></div></div>
          <p>Исходная ревизия {{proposal.expected_revision}} · токены {{proposal.usage.input_tokens}} / {{proposal.usage.output_tokens}}</p>
          <button class="ce-button" :disabled="applying" @click="apply">{{applying?'Применение…':'Применить как новую ревизию'}}</button>
          <button class="ce-button secondary" @click="proposal=null">Отклонить</button>
        </div>
      </div>
    </div>
    <div class="ce-panel" style="margin-top:20px">
      <h2>AI SEO & Content Insights — статистика + SEO</h2>
      <p class="ce-muted">Только ранее синхронизированные показатели Analytics Hub. Сопоставление по точному публичному URL и одному источнику. Изменение показателей не доказывает SEO-причину.</p>
      <div class="ce-actions">
        <label>Источник
          <select v-model="insightConnection"><option v-for="s in analyticsSources" :key="s.id" :value="s.id">{{s.name}} · {{s.provider}}</option></select>
        </label>
        <label>От <input v-model="insightFrom" type="date" /></label>
        <label>До <input v-model="insightTo" type="date" /></label>
        <button class="ce-button" :disabled="!insightConnection||insightBusy" @click="analyzeInsights">{{insightBusy?'Анализ…':'Сопоставить страницы'}}</button>
      </div>
      <p v-if="!analyticsSources.length" class="ce-muted">Нет доступных источников статистики или недостаточно прав analytics:read.</p>
      <p v-if="insightNote" role="status">{{insightNote}}</p>
      <div v-if="insightReport">
        <p v-if="!insightReport.available">{{insightReport.message}}</p>
        <template v-else>
          <p>Проверено строк: {{insightReport.examined}} · Рекомендаций: {{insightReport.matched}} · Сравнение: {{insightReport.comparison_status}}</p>
          <p v-for="(warning,index) in insightReport.warnings||[]" :key="index" class="ce-error">{{warning}}</p>
          <table class="ce-table"><thead><tr><th>Страница</th><th>SEO</th><th>Метрика</th><th>Приоритет и рекомендации</th></tr></thead>
            <tbody><tr v-for="item in insightReport.items" :key="item.entry_id">
              <td>{{item.title}}<p class="ce-muted">{{item.path}}</p></td>
              <td>{{item.score}}/100</td>
              <td>{{item.metric}}: {{item.current}}<p v-if="item.change_percent!==null">{{item.change_percent}}% к предыдущему периоду</p><p v-else class="ce-muted">Сравнение недоступно</p></td>
              <td><p>Приоритет: {{item.priority}}</p><div v-for="r in item.recommendations" :key="r.code"><p>{{r.action}}</p>
                <button v-if="r.field==='seo_title'||r.field==='meta_description'" class="ce-button secondary" :disabled="insightBusy" @click="proposeInsight(item,r.field)">Подготовить {{r.field==='seo_title'?'SEO Title':'Description'}}</button>
              </div></td>
            </tr></tbody></table>
          <p v-if="!insightReport.items.length" class="ce-muted">По доступной детализации опубликованные страницы с рекомендациями не найдены.</p>
          <p class="ce-muted">{{insightReport.notes}}</p>
        </template>
      </div>
      <div v-if="insightProposal" class="ce-seo-compare">
        <h3>Предварительный просмотр · {{insightItem?.path}} · ревизия {{insightProposal.expected_revision}}</h3>
        <div class="ce-seo-diff"><div><strong>Было</strong><pre>{{insightProposal.original||'(пусто)'}}</pre></div><div><strong>Предложено</strong><pre>{{insightProposal.proposed}}</pre></div></div>
        <div class="ce-actions"><button class="ce-button" :disabled="insightBusy" @click="applyInsight">Подтвердить и сохранить ревизию</button>
          <button class="ce-button secondary" @click="insightProposal=null">Отклонить</button></div>
      </div>
    </div>
  </section>
</template>
<style scoped>
.ce-seo-generator{margin-top:20px;border-top:1px solid #d1d5db;padding-top:16px;display:grid;gap:12px}.ce-seo-generator label{display:grid;gap:5px;max-width:740px}.ce-seo-generator select,.ce-seo-generator textarea{padding:10px;border:1px solid #cbd5e1;border-radius:8px;width:100%}.ce-seo-diff{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ce-seo-diff pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f1f5f9;padding:12px;min-height:55px}.ce-seo-compare button{margin-right:8px}@media(max-width:800px){.ce-seo-diff{grid-template-columns:1fr}}
</style>
