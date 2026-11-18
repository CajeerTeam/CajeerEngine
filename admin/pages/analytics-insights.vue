<script setup lang="ts">
const api = useAdminApi()
const auth = useAdminAuth()
type Source = { id:string; name:string; provider:string; last_success:string|null; enabled:boolean }
type Quality = { complete:boolean; sampled:boolean; truncated:boolean; data_loss:boolean; thresholded:boolean; unavailable:boolean; limited_by_provider:boolean }
type Change = { metric:string; label:string; current:number; previous:number; delta:number; percent:number|null; sampled:boolean }
type Insight = {
  available:boolean; provider:string; connection_id:string; from?:string; to?:string; fetched_at?:string;
  message?:string; quality?:Quality; comparison_status?:string; changes?:Change[];
  observations?:{code:string;severity:string;message:string;metric:string;percent:number|null}[];
  daily_signals?:{metric:string;recent_average:number;previous_average:number;percent:number;direction:string}[];
  segments?:Record<string,{items:{label:string;values:Record<string,number|null>}[];quality:Quality}>;
  warnings?:string[]
}
const sources=ref<Source[]>([])
const sourceId=ref('')
const from=ref('')
const to=ref('')
const insights=ref<Insight|null>(null)
const explanation=ref('')
const usage=ref<{input_tokens:number;output_tokens:number}|null>(null)
const loading=ref(false)
const explaining=ref(false)
const error=ref('')
const format=(v:number|null|undefined) => v===null||v===undefined?'—':v.toLocaleString('ru-RU',{maximumFractionDigits:2})
const body=()=>({connection_id:sourceId.value,...(from.value&&to.value?{from:from.value,to:to.value}:{})})
const loadSources=async()=>{
  error.value=''
  try {
    const result=await api.request<{data:{items:Source[]}}>('/analytics/reports')
    sources.value=result.data.items
    if(!sources.value.some(s=>s.id===sourceId.value)) sourceId.value=sources.value[0]?.id||''
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось получить источники.'}
}
const analyze=async()=>{
  if(!sourceId.value) return
  error.value='';insights.value=null;explanation.value='';usage.value=null;loading.value=true
  try{
    if(Boolean(from.value)!==Boolean(to.value))throw new Error('Укажите обе границы периода или оставьте их пустыми.')
    const query=new URLSearchParams(body()).toString()
    insights.value=(await api.request<{data:Insight}>('/ai/analytics/insights?'+query)).data
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось проанализировать отчёт.'}
  finally{loading.value=false}
}
const explain=async()=>{
  if(!insights.value?.available||explaining.value) return
  error.value='';explaining.value=true
  try {
    const result=(await api.request<{data:{insights:Insight;explanation:string;usage:{input_tokens:number;output_tokens:number}}}>('/ai/analytics/explain',
      {method:'POST',body:JSON.stringify(body())})).data
    insights.value=result.insights;explanation.value=result.explanation;usage.value=result.usage
  }catch(e){error.value=e instanceof Error?e.message:'AI-анализ недоступен.'}
  finally{explaining.value=false}
}
onMounted(async()=>{await auth.load();await loadSources()})
</script>
<template>
  <section>
    <h1>AI External Analytics</h1>
    <p class="ce-muted">Анализ сохранённых данных Analytics Hub. Источники не суммируются и не запрашиваются напрямую из OpenAI.</p>
    <p v-if="error" class="ce-error" role="alert">{{error}}</p>
    <div class="ce-panel ce-form">
      <label class="ce-form-row"><span>Источник статистики</span><select v-model="sourceId" :disabled="loading||explaining">
        <option v-for="s in sources" :key="s.id" :value="s.id">{{s.name}} · {{s.provider}}</option>
      </select></label>
      <div class="ce-actions">
        <label class="ce-form-row"><span>Начало периода (необязательно)</span><input v-model="from" type="date" /></label>
        <label class="ce-form-row"><span>Конец периода (необязательно)</span><input v-model="to" type="date" /></label>
      </div>
      <div class="ce-actions">
        <button class="ce-button" :disabled="loading||explaining||!sourceId" @click="analyze">{{loading?'Проверка…':'Проанализировать сохранённый отчёт'}}</button>
        <button v-if="insights?.available" class="ce-button secondary" :disabled="loading||explaining" @click="explain">{{explaining?'Обработка…':'Объяснить через OpenAI'}}</button>
        <button class="ce-button secondary" :disabled="loading||explaining" @click="loadSources">Обновить источники</button>
      </div>
    </div>
    <div v-if="insights" class="ce-panel" style="margin-top:16px">
      <h2>{{insights.available?'Обнаруженные изменения':'Нет сохранённых данных'}}</h2>
      <p v-if="!insights.available">{{insights.message}}</p>
      <template v-else>
        <p>{{insights.provider}} · {{insights.from}} — {{insights.to}} · Получено {{insights.fetched_at}}</p>
        <p>Сопоставимость предыдущего периода: <strong>{{insights.comparison_status}}</strong></p>
        <div v-for="(w,i) in insights.warnings||[]" :key="i" class="ce-error" style="margin-bottom:8px">{{w}}</div>
        <h3>Изменения по одинаковым метрикам</h3>
        <table class="ce-table"><thead><tr><th>Метрика</th><th>Текущий</th><th>Предыдущий</th><th>Изменение</th></tr></thead>
          <tbody><tr v-for="c in insights.changes||[]" :key="c.metric"><td>{{c.label}}</td><td>{{format(c.current)}}</td><td>{{format(c.previous)}}</td><td>{{format(c.percent)}}%</td></tr></tbody></table>
        <p v-if="!insights.changes?.length" class="ce-muted">Сравнение недоступно. Синхронизируйте сопоставимый предыдущий период в Analytics Hub.</p>
        <h3>Сигналы</h3>
        <ul><li v-for="(o,i) in insights.observations||[]" :key="i">{{o.message}} {{format(o.percent)}}% ({{o.metric}})</li>
          <li v-for="(d,i) in insights.daily_signals||[]" :key="'d'+i">{{d.metric}}: среднее за последние 7 дней {{format(d.recent_average)}}, за предыдущие 7 — {{format(d.previous_average)}}; {{format(d.percent)}}%</li></ul>
        <h3>Срезы источника</h3>
        <div v-for="(group,name) in insights.segments||{}" :key="name"><h4>{{name}} <small v-if="!group.quality.complete">· данные ограничены</small></h4>
          <ul><li v-for="(item,i) in group.items" :key="i">{{item.label}} — <span v-for="(value,key) in item.values" :key="key">{{key}}: {{format(value)}}; </span></li></ul></div>
      </template>
    </div>
    <div v-if="explanation" class="ce-panel" style="margin-top:16px">
      <h2>AI-объяснение</h2><p style="white-space:pre-wrap">{{explanation}}</p>
      <p class="ce-muted" v-if="usage">Токены запроса: {{usage.input_tokens}}, ответа: {{usage.output_tokens}}. Не является подтверждением причин изменений.</p>
    </div>
  </section>
</template>
