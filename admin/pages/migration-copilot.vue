<script setup lang="ts">
const api=useAdminApi()
const auth=useAdminAuth()
type Project={id:string;name:string;source:string;status:string}
type Finding={code:string;source:string;severity:string;evidence:string;action:string}
type Report={project_id:string;name:string;status:string;import_complete:boolean;validation_available:boolean;validation_ready:boolean;validation_checked_at:string|null;cutover_confirmed:boolean;cutover_at:string|null;counts:Record<string,number>;finding_counts:Record<string,number>;finding_total:number;findings:Finding[];warnings:string[];checks:Record<string,number|boolean>;checked_at:string;next_step:string;method:string}
const projects=ref<Project[]>([])
const selected=ref('')
const report=ref<Report|null>(null)
const explanation=ref('')
const usage=ref<{input_tokens:number;output_tokens:number}|null>(null)
const busy=ref(false)
const explaining=ref(false)
const error=ref('')
const load=async()=>{
  error.value=''
  try{
    const response=await api.request<{data:{projects:Project[]}}>('/migration/projects')
    projects.value=response.data.projects
    if(!projects.value.some(x=>x.id===selected.value))selected.value=projects.value[0]?.id||''
  }catch(e){error.value=e instanceof Error?e.message:String(e)}
}
const analyze=async()=>{
  if(!selected.value||busy.value)return
  error.value='';explanation.value='';usage.value=null;report.value=null;busy.value=true
  try{
    report.value=(await api.request<{data:Report}>(`/ai/migration/projects/${encodeURIComponent(selected.value)}/report`)).data
  }catch(e){error.value=e instanceof Error?e.message:String(e)}
  finally{busy.value=false}
}
const explain=async()=>{
  if(!selected.value||!report.value?.import_complete||explaining.value)return
  error.value='';explaining.value=true
  try{
    const response=(await api.request<{data:{report:Report;explanation:string;usage:{input_tokens:number;output_tokens:number}}}>(`/ai/migration/projects/${encodeURIComponent(selected.value)}/explain`,{method:'POST',body:'{}'})).data
    report.value=response.report;explanation.value=response.explanation;usage.value=response.usage
  }catch(e){error.value=e instanceof Error?e.message:String(e)}
  finally{explaining.value=false}
}
const exportReport=()=>{
  if(!report.value)return
  const content=JSON.stringify(report.value,null,2)
  const url=URL.createObjectURL(new Blob([content],{type:'application/json'}))
  const a=document.createElement('a');a.href=url;a.download=`migration-copilot-${report.value.project_id}.json`;a.click()
  URL.revokeObjectURL(url)
}
watch(selected,()=>{report.value=null;explanation.value='';usage.value=null})
onMounted(async()=>{await auth.load();await load()})
</script>
<template>
  <section>
    <h1>AI Migration Copilot</h1>
    <p class="ce-muted">Диагностика после DLE/WordPress миграции. Только чтение: изменения, импорты, DNS и восстановление не выполняются.</p>
    <p v-if="error" class="ce-error" role="alert">{{error}}</p>
    <div class="ce-panel ce-form">
      <label class="ce-form-row"><span>Проект миграции</span><select v-model="selected" :disabled="busy||explaining">
        <option v-for="p in projects" :key="p.id" :value="p.id">{{p.name}} · {{p.source}} · {{p.status}}</option>
      </select></label>
      <div class="ce-actions">
        <button class="ce-button" :disabled="!selected||busy||explaining" @click="analyze">{{busy?'Проверка…':'Проверить результаты миграции'}}</button>
        <button v-if="report?.import_complete" class="ce-button secondary" :disabled="busy||explaining" @click="explain">{{explaining?'Генерация…':'Объяснить через OpenAI'}}</button>
        <button class="ce-button secondary" :disabled="busy||explaining" @click="load">Обновить проекты</button>
        <button v-if="report" class="ce-button secondary" @click="exportReport">Экспорт JSON</button>
      </div>
    </div>
    <section v-if="report" class="ce-panel" style="margin-top:16px">
      <h2>{{report.name}}</h2>
      <p>Импорт: {{report.import_complete?'завершён':'не завершён'}} · Операторская сверка: {{report.validation_available?(report.validation_ready?'готово':'требует действий'):'не выполнялась'}} · Cutover: {{report.cutover_confirmed?'подтверждён':'не подтверждён'}}</p>
      <p class="ce-muted">Обновлено {{report.checked_at}} · последняя полная сверка {{report.validation_checked_at||'отсутствует'}}</p>
      <p v-for="(warning,i) in report.warnings" :key="i" class="ce-error">{{warning}}</p>
      <h3>Показатели переноса</h3>
      <table class="ce-table"><tbody><tr v-for="(value,key) in report.counts" :key="key"><th>{{key}}</th><td>{{value}}</td></tr></tbody></table>
      <h3>Текущие проверки назначения</h3>
      <table class="ce-table"><tbody><tr v-for="(value,key) in report.checks" :key="key"><th>{{key}}</th><td>{{value}}</td></tr></tbody></table>
      <h3>Отклонения ({{report.finding_total}})</h3>
      <table class="ce-table"><thead><tr><th>Приоритет</th><th>Код</th><th>Объект</th><th>Что проверить</th></tr></thead>
        <tbody><tr v-for="(item,i) in report.findings" :key="i"><td>{{item.severity}}</td><td>{{item.code}}</td><td>{{item.source}}</td><td>{{item.action}}</td></tr></tbody></table>
      <p v-if="!report.findings.length" class="ce-muted">В проверенном объёме отклонений нет. Это не заменяет полную операторскую сверку.</p>
      <h3>Повторная полная проверка</h3><pre style="white-space:pre-wrap">{{report.next_step}}</pre>
    </section>
    <div v-if="explanation" class="ce-panel" style="margin-top:16px">
      <h2>AI-объяснение и последовательность проверок</h2>
      <p style="white-space:pre-wrap">{{explanation}}</p>
      <p class="ce-muted" v-if="usage">Использовано токенов: {{usage.input_tokens}} + {{usage.output_tokens}}. Данные и выводы требуют проверки оператором.</p>
    </div>
  </section>
</template>
