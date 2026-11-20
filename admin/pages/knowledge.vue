<script setup lang="ts">
const api = useAdminApi()
const route = useRoute()
type Category = {id:string;slug:string;name:string;description:string}
type Document = {id:string;category_id:string|null;slug:string;title:string;body:string;locale:string;visibility:'public'|'internal';status:'draft'|'published'|'archived';revision:number;indexed_revision:number;source_kind:string;source_type:string|null;source_id:string|null;source_revision:number|null}
type Hit = {id:string;title:string;excerpt:string;locale:string;visibility:string;score:number}
const categories = ref<Category[]>([])
const documents = ref<Omit<Document,'body'>[]>([])
const selected = ref<Document|null>(null)
const searchHits = ref<Hit[]>([])
const total = ref(0), offset = ref(0)
const query = ref(''), searchQuery = ref(''), scope = ref('all'), lang = ref('')
const loading = ref(false), busy = ref(false), error = ref(''), info = ref('')
const form = reactive({title:'',slug:'',body:'',locale:'ru',visibility:'internal',status:'draft',category_id:''})
const categoryForm = reactive({id:'',slug:'',name:'',description:''})
const cms = reactive({type:'',entry_id:''})
const types = ref<{handle:string;name:string}[]>([])
const entries = ref<{id:string;title:string;status:string}[]>([])
const upload = ref<File|null>(null)
const pageSize=30
const reindexOffset=ref(0)
async function refresh(){
  loading.value=true;error.value=''
  try{
    const [a,b,c] = await Promise.all([
      api.request<{data:Category[]}>('/knowledge/categories'),
      api.request<{data:{items:Omit<Document,'body'>[];total:number}}>(`/knowledge/documents?${new URLSearchParams({limit:String(pageSize),offset:String(offset.value),q:query.value})}`),
      api.request<{data:{handle:string;name:string}[]}>('/content-types')
    ])
    categories.value=a.data;documents.value=b.data.items;total.value=b.data.total;types.value=c.data
    const firstType = types.value[0]
    if(firstType && !types.value.some(x=>x.handle===cms.type))cms.type=firstType.handle
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось получить базу знаний.'}
  finally{loading.value=false}
}
function reset(){selected.value=null;Object.assign(form,{title:'',slug:'',body:'',locale:'ru',visibility:'internal',status:'draft',category_id:''})}
async function openDocument(id:string){
  busy.value=true;error.value=''
  try{
    selected.value=(await api.request<{data:Document}>(`/knowledge/documents/${encodeURIComponent(id)}`)).data
    const d=selected.value
    Object.assign(form,{title:d.title,slug:d.slug,body:d.body,locale:d.locale,visibility:d.visibility,status:d.status,category_id:d.category_id||''})
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось открыть документ.'}
  finally{busy.value=false}
}
async function saveDocument(){
  busy.value=true;error.value='';info.value=''
  try{
    const data={...form,category_id:form.category_id||null,...(selected.value?{expected_revision:Number(selected.value.revision)}:{})}
    const result=await api.request<{data:Document}>(selected.value?`/knowledge/documents/${selected.value.id}`:'/knowledge/documents',{
      method:selected.value?'PUT':'POST',body:JSON.stringify(data)
    })
    selected.value=result.data
    info.value=`Документ сохранён, ревизия ${result.data.revision}. Индекс обновлён.`
    await refresh()
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось сохранить документ.'}
  finally{busy.value=false}
}
async function deleteDocument(){
  if(!selected.value||!confirm(`Удалить «${selected.value.title}» и все его индексированные фрагменты?`))return
  busy.value=true;error.value=''
  try{
    await api.request(`/knowledge/documents/${selected.value.id}`,{method:'DELETE',body:JSON.stringify({expected_revision:Number(selected.value.revision)})})
    reset();info.value='Документ и индекс удалены.';await refresh()
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось удалить документ.'}
  finally{busy.value=false}
}
async function saveCategory(){
  busy.value=true;error.value=''
  try{
    const payload={name:categoryForm.name,slug:categoryForm.slug,description:categoryForm.description}
    await api.request(categoryForm.id?`/knowledge/categories/${categoryForm.id}`:'/knowledge/categories',{
      method:categoryForm.id?'PUT':'POST',body:JSON.stringify(payload)
    })
    Object.assign(categoryForm,{id:'',slug:'',name:'',description:''});await refresh()
    info.value='Категория сохранена.'
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось сохранить категорию.'}
  finally{busy.value=false}
}
async function removeCategory(id:string){
  if(!confirm('Удалить пустую категорию?'))return
  busy.value=true;error.value=''
  try{await api.request(`/knowledge/categories/${id}`,{method:'DELETE'});await refresh();info.value='Категория удалена.'}
  catch(e){error.value=e instanceof Error?e.message:'Не удалось удалить категорию.'}
  finally{busy.value=false}
}
async function find(){
  error.value='';busy.value=true
  try{searchHits.value=(await api.request<{data:Hit[]}>(`/knowledge/search?${new URLSearchParams({q:searchQuery.value,visibility:scope.value,locale:lang.value})}`)).data}
  catch(e){error.value=e instanceof Error?e.message:'Ошибка поиска.'}
  finally{busy.value=false}
}
async function onFile(event:Event){upload.value=(event.target as HTMLInputElement).files?.[0]||null}
async function importFile(){
  if(!upload.value)return
  const file=upload.value
  if(file.size>2097152||!(/\.(txt|md|markdown|pdf)$/i.test(file.name))){error.value='Допустимы TXT, Markdown или PDF до 2 МиБ.';return}
  busy.value=true;error.value=''
  try{
    const bytes=new Uint8Array(await file.arrayBuffer());let encoded=''
    for(let i=0;i<bytes.length;i+=16384)encoded+=String.fromCharCode(...bytes.subarray(i,i+16384))
    const document=(await api.request<{data:Document}>('/knowledge/import',{
      method:'POST',body:JSON.stringify({filename:file.name,base64:btoa(encoded),status:'draft',visibility:'internal',locale:'ru',category_id:form.category_id||null})
    })).data
    upload.value=null;await refresh();await openDocument(document.id)
    info.value='Файл импортирован в черновик. Проверьте текст перед публикацией.'
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось импортировать файл.'}
  finally{busy.value=false}
}
async function loadEntries(){
  entries.value=[];if(!cms.type)return
  try{const result=await api.request<{data:{id:string;title:string;status:string}[]}>(`/content/${encodeURIComponent(cms.type)}?status=published&limit=100`)
    entries.value=result.data||[]
  }catch(e){error.value=e instanceof Error?e.message:'Не удалось получить опубликованные страницы.'}
}
async function sync(){
  if(!cms.entry_id)return
  busy.value=true;error.value=''
  try{
    const d=(await api.request<{data:Document}>('/knowledge/sync-cms',{method:'POST',body:JSON.stringify({type:cms.type,entry_id:cms.entry_id})})).data
    await refresh();await openDocument(d.id);info.value='CMS-страница синхронизирована как внутренняя. Проверьте содержимое перед открытием публичного доступа.'
  }catch(e){error.value=e instanceof Error?e.message:'Синхронизация не выполнена.'}
  finally{busy.value=false}
}
async function rebuild(){
  busy.value=true;error.value=''
  try{
    const offset=reindexOffset.value
    const result=(await api.request<{data:{processed:number;next_offset:number|null}}>('/knowledge/reindex',{
      method:'POST',body:JSON.stringify({offset,limit:20})
    })).data
    reindexOffset.value=result.next_offset??0
    info.value=`Индекс: обработано ${result.processed} записей начиная с ${offset}. ${result.next_offset===null?'Переиндексация завершена.':'Следующий пакет с '+result.next_offset+'.'}`
    await refresh()
  }catch(e){error.value=e instanceof Error?e.message:'Переиндексация прервана; можно продолжить с текущего смещения.'}
  finally{busy.value=false}
}
onMounted(async()=>{await refresh();await loadEntries();const id=route.query.document;if(typeof id==='string' && /^[a-f0-9-]{36}$/i.test(id)) await openDocument(id)})
watch(()=>cms.type,()=>{cms.entry_id='';loadEntries()})
</script>

<template>
  <section class="kb-page">
    <h1>AI Knowledge Base</h1>
    <p>Категории, документы и отдельный поисковый индекс. В публичный поиск CMS внутренние материалы не передаются. Здесь нет автоматической отправки содержимого в OpenAI.</p>
    <p v-if="error" class="ce-error" role="alert">{{error}}</p>
    <p v-if="info" role="status">{{info}}</p>
    <div class="kb-columns">
      <section class="ce-panel">
        <h2>Категории</h2>
        <ul><li v-for="category in categories" :key="category.id">
          <button @click="Object.assign(categoryForm,category)">{{category.name}}</button>
          <small> · {{category.slug}}</small>
          <button :disabled="busy" @click="removeCategory(category.id)">Удалить</button>
        </li></ul>
        <form class="kb-form" @submit.prevent="saveCategory">
          <input v-model="categoryForm.name" maxlength="160" required placeholder="Название">
          <input v-model="categoryForm.slug" maxlength="80" required placeholder="slug-latin">
          <textarea v-model="categoryForm.description" maxlength="1500" placeholder="Описание"/>
          <button :disabled="busy">{{categoryForm.id?'Обновить':'Создать категорию'}}</button>
          <button type="button" @click="Object.assign(categoryForm,{id:'',slug:'',name:'',description:''})">Очистить</button>
        </form>
      </section>
      <section class="ce-panel">
        <h2>Документы ({{total}})</h2>
        <form class="kb-inline" @submit.prevent="offset=0;refresh()">
          <input v-model="query" maxlength="120" placeholder="Поиск по названию">
          <button :disabled="loading">Найти</button>
          <button type="button" @click="reset">Новый документ</button>
        </form>
        <ul class="kb-list"><li v-for="doc in documents" :key="doc.id">
          <button @click="openDocument(doc.id)">{{doc.title}}</button>
          <small> · {{doc.locale}} / {{doc.status}} / {{doc.visibility}} · рев. {{doc.revision}}</small>
        </li></ul>
        <p v-if="!documents.length">Документов пока нет.</p>
        <div class="kb-inline"><button :disabled="offset===0||loading" @click="offset=Math.max(0,offset-pageSize);refresh()">Назад</button><span>{{offset+1}}–{{Math.min(total,offset+pageSize)}}</span><button :disabled="offset+pageSize>=total||loading" @click="offset+=pageSize;refresh()">Далее</button></div>
      </section>
    </div>
    <section class="ce-panel">
      <h2>{{selected?'Редактировать документ':'Новый документ'}}</h2>
      <p v-if="selected">Источник: {{selected.source_kind}} · Ревизия: {{selected.revision}} · Индекс: {{selected.indexed_revision}}</p>
      <p v-if="selected?.source_kind==='cms'">Содержимое поступает из CMS и не редактируется напрямую. Можно изменить категорию, статус и видимость после проверки.</p>
      <form class="kb-form" @submit.prevent="saveDocument">
        <div class="kb-inline"><label>Название<input v-model="form.title" maxlength="240" required></label><label>Slug<input v-model="form.slug" maxlength="80" required></label></div>
        <div class="kb-inline">
          <label>Категория<select v-model="form.category_id"><option value="">Без категории</option><option v-for="category in categories" :key="category.id" :value="category.id">{{category.name}}</option></select></label>
          <label>Язык<input v-model="form.locale" maxlength="5" required></label>
          <label>Доступ<select v-model="form.visibility"><option value="internal">Внутренняя</option><option value="public">Публичная</option></select></label>
          <label>Состояние<select v-model="form.status"><option value="draft">Черновик</option><option value="published">Опубликован</option><option value="archived">Архив</option></select></label>
        </div>
        <label>Содержимое<textarea v-model="form.body" rows="12" maxlength="131072" required></textarea></label>
        <div class="kb-inline"><button :disabled="busy">Сохранить и обновить индекс</button><button v-if="selected" type="button" :disabled="busy" @click="deleteDocument">Удалить</button><button type="button" @click="reset">Сбросить</button></div>
      </form>
    </section>
    <div class="kb-columns">
      <section class="ce-panel">
        <h2>Импорт файла</h2>
        <p>TXT, Markdown или текстовый PDF до 2 МиБ. PDF требует Poppler на сервере. Файл превращается в черновик; бинарный оригинал не хранится.</p>
        <input type="file" accept=".txt,.md,.markdown,.pdf" @change="onFile">
        <button :disabled="busy||!upload" @click="importFile">Импортировать</button>
      </section>
      <section class="ce-panel">
        <h2>Синхронизация страницы CMS</h2>
        <p>Только опубликованные страницы. Повторный запуск обновляет тот же документ и индекс.</p>
        <div class="kb-form"><select v-model="cms.type"><option v-for="type in types" :key="type.handle" :value="type.handle">{{type.name||type.handle}}</option></select>
          <select v-model="cms.entry_id"><option value="">Выберите страницу</option><option v-for="e in entries" :key="e.id" :value="e.id">{{e.title}}</option></select>
          <button :disabled="busy||!cms.entry_id" @click="sync">Синхронизировать</button></div>
      </section>
    </div>
    <section class="ce-panel">
      <h2>Поиск и обслуживание индекса</h2>
      <form class="kb-inline" @submit.prevent="find">
        <input v-model="searchQuery" maxlength="200" required placeholder="Запрос по содержимому">
        <select v-model="scope"><option value="all">Все</option><option value="public">Публичные</option><option value="internal">Внутренние</option></select>
        <input v-model="lang" maxlength="5" placeholder="Язык (ru)"><button :disabled="busy">Искать</button>
      </form>
      <ul class="kb-list"><li v-for="hit in searchHits" :key="hit.id"><button @click="openDocument(hit.id)">{{hit.title}}</button> · {{hit.visibility}} · {{hit.locale}} · {{hit.score}}<p>{{hit.excerpt}}</p></li></ul>
      <button :disabled="busy" @click="rebuild">Перестроить индекс (20 документов, смещение {{reindexOffset}})</button>
    </section>
  </section>
</template>

<style scoped>
.kb-page{display:grid;gap:16px}.kb-columns{display:grid;grid-template-columns:1fr 2fr;gap:16px}.kb-form{display:grid;gap:10px;margin-top:10px}.kb-form label{display:grid;gap:5px}.kb-inline{display:flex;align-items:end;flex-wrap:wrap;gap:10px}.kb-inline label{flex:1;min-width:120px}.kb-page input,.kb-page select,.kb-page textarea{padding:9px;border:1px solid #cbd5e1;border-radius:7px;max-width:100%;box-sizing:border-box}.kb-page textarea{width:100%;font-family:inherit}.kb-page button{padding:7px 12px;border:1px solid #94a3b8;border-radius:7px;background:#f8fafc;cursor:pointer}.kb-page button:disabled{opacity:.5;cursor:not-allowed}.kb-list{max-height:350px;overflow:auto}.kb-list li{margin:8px 0;overflow-wrap:anywhere}.kb-list p{white-space:pre-wrap}@media(max-width:900px){.kb-columns{grid-template-columns:1fr}}
</style>
