<script setup lang="ts">
/** Administration of local SkiF4er ID mappings, not Keycloak management. */
const api = useAdminApi()
const auth = useAdminAuth()
type User = {id:string;email:string;name:string;status:string;two_factor_enabled:boolean;linked:boolean;subject:string|null;provisioned:boolean;linked_at:string|null;last_login_at:string|null}
type Session = {id:string;provider:string;device:string;network:string;created_at:string;last_seen_at:string;expires_at:string|null}
const currentUserId = computed(() => auth.identity.value?.user_id || auth.identity.value?.user?.id || '')
const hasRead = computed(() => (auth.identity.value?.scopes || []).some(s => s === '*' || s === 'users:read'))
const hasWrite = computed(() => (auth.identity.value?.scopes || []).includes('*'))
const query = ref('')
const linked = ref('all')
const offset = ref(0)
const page = ref<{items:User[];total:number;limit:number;offset:number}>({items:[],total:0,limit:30,offset:0})
const selectedId = ref('')
const selected = computed(() => page.value.items.find(u => u.id === selectedId.value) || null)
const sessions = ref<Session[]>([])
const subject = ref('')
const confirmSubject = ref('')
const error = ref('')
const success = ref('')
const busy = ref(false)
const loading = ref(false)
const load = async () => {
  if (!hasRead.value) return
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({q:query.value.trim(),linked:linked.value,offset:String(offset.value),limit:'30'})
    page.value = (await api.request<{data:typeof page.value}>('/identity/admin/users?' + params)).data
    if (!selected.value) { selectedId.value = ''; sessions.value = [] }
    else await loadSessions()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось загрузить Identity Center.' }
  finally { loading.value = false }
}
const loadSessions = async () => {
  if (!selectedId.value) return
  try { sessions.value = (await api.request<{data:Session[]}>('/identity/admin/users/' + encodeURIComponent(selectedId.value) + '/sessions')).data }
  catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось загрузить сессии.' }
}
const search = async () => { offset.value = 0; selectedId.value = ''; sessions.value = []; await load() }
const pageBy = async (delta:number) => { offset.value = Math.max(0, offset.value + delta); selectedId.value = ''; sessions.value = []; await load() }
const select = async (id:string) => { selectedId.value = id; subject.value = ''; confirmSubject.value = ''; sessions.value = []; error.value = ''; await loadSessions() }
const execute = async (fn:() => Promise<void>) => {
  if (busy.value) return
  busy.value = true; error.value = ''; success.value = ''
  try { await fn(); await load() }
  catch (e) { error.value = e instanceof Error ? e.message : 'Операция завершилась ошибкой.' }
  finally { busy.value = false }
}
const link = async () => {
  if (!selected.value || !subject.value || subject.value !== confirmSubject.value) { error.value = 'Проверьте точное совпадение обоих значений sub.'; return }
  if (!window.confirm(`Внешняя личность проверена, и Keycloak sub принадлежит ${selected.value.email}?`)) return
  const id = selected.value.id
  await execute(async () => {
    await api.request(`/identity/admin/users/${encodeURIComponent(id)}/link`, {method:'POST',body:JSON.stringify({subject:subject.value,confirm_subject:confirmSubject.value})})
    subject.value = ''; confirmSubject.value = ''; success.value = 'Привязка сохранена.'
  })
}
const unlink = async () => {
  if (!selected.value || !window.confirm(`Отвязать SkiF4er ID пользователя ${selected.value.email} и отозвать его SSO-сессии?`)) return
  const id = selected.value.id
  await execute(async () => {
    await api.request(`/identity/admin/users/${encodeURIComponent(id)}/link`, {method:'DELETE',body:JSON.stringify({confirm_user_id:id})})
    success.value = 'Привязка удалена, связанные SSO-сессии отозваны.'
  })
}
const revoke = async (s:Session) => {
  if (!selected.value || !window.confirm('Завершить выбранную SSO-сессию?')) return
  const id = selected.value.id
  await execute(async () => {
    await api.request(`/identity/admin/users/${encodeURIComponent(id)}/sessions/${encodeURIComponent(s.id)}`, {method:'DELETE'})
    success.value = 'SSO-сессия отозвана.'
  })
}
const revokeAll = async () => {
  if (!selected.value || !window.confirm(`Завершить все SSO-сессии ${selected.value.email}?`)) return
  const id = selected.value.id
  await execute(async () => {
    await api.request(`/identity/admin/users/${encodeURIComponent(id)}/sessions/revoke-all`, {method:'POST',body:JSON.stringify({confirm_user_id:id})})
    success.value = 'SSO-сессии отозваны.'
  })
}
watch(hasRead, allowed => { if (allowed) void load() }, {immediate:true})
</script>
<template>
  <section v-if="hasRead" class="ce-panel">
    <h2>Identity Center · управление пользователями</h2>
    <p>Управление локальными привязками и браузерными SSO-сессиями CajeerEngine. Пользователи и MFA в Keycloak администрируются отдельно.</p>
    <p v-if="error" class="ce-error" role="alert">{{ error }}</p>
    <p v-if="success" role="status">{{ success }}</p>
    <form class="ce-actions" @submit.prevent="search">
      <input v-model="query" aria-label="Поиск пользователя" maxlength="120" placeholder="Email, имя или UUID" />
      <select v-model="linked" aria-label="Фильтр привязок">
        <option value="all">Все</option><option value="yes">Привязаны</option><option value="no">Без привязки</option>
      </select>
      <button type="submit" :disabled="loading">Найти</button>
      <button type="button" :disabled="loading" @click="load">Обновить</button>
    </form>
    <p>Найдено: {{ page.total }}</p>
    <div style="overflow-x:auto">
      <table><thead><tr><th>Пользователь</th><th>Статус</th><th>SkiF4er ID</th><th></th></tr></thead>
        <tbody><tr v-for="user in page.items" :key="user.id">
          <td>{{ user.name }} <small>{{ user.email }}</small></td>
          <td>{{ user.status }} <small>2FA: {{ user.two_factor_enabled ? 'включена' : 'нет' }}</small></td>
          <td>{{ user.linked ? 'Привязан' : 'Не привязан' }} {{ user.provisioned ? '(OIDC-only)' : '' }}<small v-if="user.subject">{{ user.subject }}</small></td>
          <td><button type="button" @click="select(user.id)">{{ selectedId===user.id?'Выбран':'Управлять' }}</button></td>
        </tr><tr v-if="!page.items.length"><td colspan="4">Пользователи не найдены.</td></tr></tbody>
      </table>
    </div>
    <div class="ce-actions">
      <button type="button" :disabled="offset===0 || loading" @click="pageBy(-30)">Назад</button>
      <button type="button" :disabled="offset+30>=page.total || loading" @click="pageBy(30)">Далее</button>
    </div>
    <section v-if="selected" class="ce-panel">
      <h3>{{ selected.email }}</h3>
      <p>UUID: <code>{{ selected.id }}</code></p>
      <template v-if="hasWrite && selected.id!==currentUserId">
        <p v-if="selected.linked">Issuer: <code>https://id.skif4er.ru/realms/skif4er</code> · sub: <code>{{ selected.subject }}</code> · привязан: {{ selected.linked_at }}</p>
        <p v-if="selected.provisioned">Отвязка запрещена: сначала необходим проверенный локальный способ входа.</p>
        <button v-else-if="selected.linked" type="button" :disabled="busy" @click="unlink">Отвязать Identity и завершить SSO-сессии</button>
        <form v-else-if="selected.status==='active'" class="ce-form" @submit.prevent="link">
          <p>Укажите точный Keycloak sub, заранее сверенный с личностью владельца. Совпадение email не подтверждает владение.</p>
          <AdminFormRow><template #label>Проверенный Keycloak sub</template><input v-model="subject" autocomplete="off" maxlength="255" required /></AdminFormRow>
          <AdminFormRow><template #label>Повторите sub</template><input v-model="confirmSubject" autocomplete="off" maxlength="255" required /></AdminFormRow>
          <button type="submit" :disabled="busy">Привязать SkiF4er ID</button>
        </form>
      </template>
      <p v-else-if="!hasWrite">Изменения доступны только администратору.</p>
      <h3>Браузерные SSO-сессии</h3>
      <table><thead><tr><th>Устройство</th><th>Последняя активность</th><th></th></tr></thead>
        <tbody><tr v-for="s in sessions" :key="s.id"><td>{{ s.device }} <small>{{ s.network }}</small></td><td>{{ s.last_seen_at }}</td><td><button v-if="hasWrite && selected.id!==currentUserId" type="button" :disabled="busy" @click="revoke(s)">Завершить</button></td></tr>
          <tr v-if="sessions.length===0"><td colspan="3">Активных SSO-сессий нет.</td></tr></tbody>
      </table>
      <button v-if="hasWrite && sessions.length>0 && selected.id!==currentUserId" type="button" :disabled="busy" @click="revokeAll">Завершить все SSO-сессии</button>
    </section>
  </section>
</template>
