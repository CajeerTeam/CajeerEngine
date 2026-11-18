<script setup lang="ts">
const api = useAdminApi()
type Issue = {code:string;component:string;severity:string;title:string;reason:string;action:string;command:string}
type Check = {ok:boolean;latency_ms?:number;driver?:string;counts?:Record<string,number>;oldest_ready_age_seconds?:number;tasks?:Record<string,{stale:boolean;status:string}>;age_seconds?:number|null;last_seen?:number;documents?:number}
type Diagnostic = {status:string;generated_at:string;window_minutes:number;readiness:Record<string,boolean>;checks:Record<string,Check>;http:{requests:number;errors_5xx:number;average_ms:number|null;p95_bucket_ms:number|null};runtime_events:Record<string,number>;issues:Issue[];summary:{total:number;critical:number;high:number};method:string}
const report=ref<Diagnostic|null>(null)
const explanation=ref('')
const usage=ref<{input_tokens:number;output_tokens:number}|null>(null)
const minutes=ref(60)
const focus=ref('')
const loading=ref(false)
const explaining=ref(false)
const error=ref('')
const info=ref('')
const windows=[{label:'15 минут',value:15},{label:'1 час',value:60},{label:'6 часов',value:360},{label:'24 часа',value:1440}]
async function refresh(){
  if(loading.value)return
  loading.value=true;error.value='';info.value='';explanation.value='';usage.value=null
  try{
    report.value=(await api.request<{data:Diagnostic}>(`/ai/diagnostics?window_minutes=${minutes.value}`)).data
    if(focus.value&&!report.value.issues.some(i=>i.code===focus.value))focus.value=''
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось выполнить диагностику.'}
  finally{loading.value=false}
}
async function explain(){
  if(explaining.value||!report.value)return
  explaining.value=true;error.value='';explanation.value='';usage.value=null
  try{
    const response=(await api.request<{data:{diagnostics:Diagnostic;explanation:string;usage:{input_tokens:number;output_tokens:number}}}>('/ai/diagnostics/explain',{
      method:'POST',body:JSON.stringify({window_minutes:minutes.value,focus:focus.value})
    })).data
    report.value=response.diagnostics;explanation.value=response.explanation;usage.value=response.usage
    info.value='AI объяснение сформировано по очищенным диагностическим данным. Изменения в CMS не выполнялись.'
  }catch(e){error.value=e instanceof Error?e.message:'OpenAI недоступен. Локальные рекомендации остаются доступными.'}
  finally{explaining.value=false}
}
function download(){
  if(!report.value||!import.meta.client)return
  const blob=new Blob([JSON.stringify({diagnostics:report.value,explanation:explanation.value||null,usage:usage.value},null,2)],{type:'application/json'})
  const link=document.createElement('a');const url=URL.createObjectURL(blob)
  link.href=url;link.download='cajeer-ai-diagnostics-'+new Date().toISOString().slice(0,10)+'.json';link.click()
  setTimeout(()=>URL.revokeObjectURL(url),1000)
}
onMounted(refresh)
</script>

<template>
  <section class="ce-diagnostics">
    <h1>AI CMS Diagnostics</h1>
    <p>Оперативная диагностика CMS с объяснением отклонений. Проверки не меняют конфигурацию или данные. OpenAI получает только обезличенные показатели и коды проблем.</p>
    <div class="ce-diagnostics-bar">
      <label>Период runtime-событий
        <select v-model.number="minutes" :disabled="loading||explaining" @change="refresh"><option v-for="w in windows" :key="w.value" :value="w.value">{{w.label}}</option></select>
      </label>
      <button class="ce-button" :disabled="loading||explaining" @click="refresh">{{loading?'Проверка…':'Проверить CMS'}}</button>
      <button class="ce-button secondary" :disabled="!report" @click="download">Экспорт JSON</button>
    </div>
    <p v-if="error" class="ce-error" role="alert">{{error}}</p>
    <p v-if="info" role="status">{{info}}</p>
    <template v-if="report">
      <div class="ce-panel">
        <h2>Состояние: {{report.status}}</h2>
        <p>Время: {{report.generated_at}} · Всего замечаний: {{report.summary.total}} · Критичных: {{report.summary.critical}} · Высоких: {{report.summary.high}}</p>
        <p>{{report.method}}</p>
        <p>HTTP (накопленные метрики): {{report.http.requests}} запросов, {{report.http.errors_5xx}} ошибок 5xx; среднее {{report.http.average_ms??'—'}} мс; p95: {{report.http.p95_bucket_ms??'—'}} мс.</p>
      </div>
      <div class="ce-panel">
        <h2>Проверки сервисов</h2>
        <div class="ce-diagnostics-grid">
          <article v-for="(value,key) in report.checks" :key="key" class="ce-diagnostics-check">
            <strong>{{key}} — {{value.ok?'OK':'Требует проверки'}}</strong>
            <p v-if="value.latency_ms!==undefined">{{value.latency_ms}} мс</p>
            <p v-if="value.driver">Драйвер: {{value.driver}}</p>
            <p v-if="value.counts">Очередь: {{value.counts.pending}} ожидают · {{value.counts.failed}} ошибок; возраст: {{value.oldest_ready_age_seconds}} с.</p>
            <p v-if="value.documents!==undefined">Документов: {{value.documents}}</p>
            <p v-if="value.age_seconds!==undefined">Возраст backup: {{value.age_seconds??'неизвестно'}} с.</p>
            <ul v-if="value.tasks"><li v-for="(task,name) in value.tasks" :key="name">{{name}}: {{task.status}}{{task.stale?' · устарело':''}}</li></ul>
          </article>
        </div>
      </div>
      <div class="ce-panel">
        <h2>Обнаруженные проблемы</h2>
        <p v-if="!report.issues.length">Текущие проверки не выявили отклонений. Это не заменяет полный аудит безопасности.</p>
        <article v-for="issue in report.issues" :key="issue.code" class="ce-diagnostics-issue">
          <h3>{{issue.title}} — {{issue.severity}}</h3>
          <p>{{issue.reason}}</p>
          <p><strong>Что проверить:</strong> {{issue.action}}</p>
          <code v-if="issue.command">{{issue.command}}</code>
          <p class="ce-diagnostics-code">{{issue.code}} · {{issue.component}}</p>
        </article>
      </div>
      <div class="ce-panel">
        <h2>Объяснение ChatGPT</h2>
        <p>Дополнительно использует OpenAI API-ключ из AI Assistant. Оправляет только очищенные данные — без содержимого журналов, IP-адресов, токенов, SQL и путей запросов.</p>
        <label>Фокус анализа
          <select v-model="focus"><option value="">Все проблемы</option><option v-for="issue in report.issues" :key="issue.code" :value="issue.code">{{issue.title}} ({{issue.code}})</option></select>
        </label>
        <button class="ce-button" :disabled="explaining||loading" @click="explain">{{explaining?'Анализ…':'Объяснить через ChatGPT'}}</button>
        <pre v-if="explanation" class="ce-diagnostics-response">{{explanation}}</pre>
        <p v-if="usage">Токены: {{usage.input_tokens}} входных / {{usage.output_tokens}} выходных.</p>
        <p>Предлагаемые команды — только для ознакомления. AI не запускает их автоматически.</p>
      </div>
    </template>
  </section>
</template>

<style scoped>
.ce-diagnostics{display:grid;gap:16px}.ce-diagnostics-bar{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.ce-diagnostics-bar label,.ce-diagnostics label{display:grid;gap:6px}.ce-diagnostics select{padding:9px;border:1px solid #cbd5e1;border-radius:8px}.ce-diagnostics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.ce-diagnostics-check{border:1px solid #d1d5db;border-radius:8px;padding:12px;overflow-wrap:anywhere}.ce-diagnostics-check p{margin:5px 0}.ce-diagnostics-issue{border-top:1px solid #e5e7eb;padding:10px 0}.ce-diagnostics-issue h3{font-size:1.1rem}.ce-diagnostics-issue code,.ce-diagnostics-response{display:block;white-space:pre-wrap;overflow-wrap:anywhere;padding:10px;background:#f1f5f9;border-radius:6px}.ce-diagnostics-code{color:#64748b;font-size:.85em}
</style>
