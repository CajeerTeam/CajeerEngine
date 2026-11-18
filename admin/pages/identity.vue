<script setup lang="ts">
const api = useAdminApi()
const status = ref<{enabled:boolean;linked:boolean;provisioned?:boolean;created_at?:string|null}|null>(null)
const password = ref('')
const twoFactorCode = ref('')
const error = ref('')
const busy = ref(false)
const assurancePassword = ref('')
const assuranceTotp = ref('')
const assuranceMessage = ref('')
const confirmAssurance = async () => {
  assuranceMessage.value = ''
  try {
    const r = await api.request<{data:{expires_at:string}}>('/auth/assurance/confirm',{method:'POST',body:JSON.stringify({password:assurancePassword.value,two_factor_code:assuranceTotp.value})})
    assurancePassword.value='';assuranceTotp.value=''
    assuranceMessage.value='Подтверждено до '+r.data.expires_at
  } catch(e) {assuranceMessage.value=e instanceof Error?e.message:'Ошибка подтверждения'}
}
const oidcAssurance = async () => {
  assuranceMessage.value = ''
  try {
    const r=await api.request<{data:{authorization_url:string}}>('/identity/step-up/start',{method:'POST',body:'{}'})
    const u=new URL(r.data.authorization_url)
    if(u.origin!=='https://id.skif4er.ru'||u.pathname!=='/realms/skif4er/protocol/openid-connect/auth'||u.searchParams.get('max_age')!=='0')throw new Error('Недопустимый адрес повторного входа')
    window.location.assign(u.toString())
  } catch(e) {assuranceMessage.value=e instanceof Error?e.message:'Ошибка OIDC'}
}
type Session = {id:string;provider:string;device:string;network:string;current:boolean;created_at:string;last_seen_at:string;expires_at:string|null}
const sessions = ref<Session[]>([])
const sessionError = ref('')
const loadSessions = async () => {
  try { sessions.value = (await api.request<{data:Session[]}>('/auth/sessions')).data;sessionError.value='' }
  catch(e) { sessionError.value = e instanceof Error ? e.message : 'Не удалось получить сессии.' }
}
const revokeSession = async (s:Session) => {
  if (!confirm('Завершить сессию?')) return
  try { await api.request('/auth/sessions/'+encodeURIComponent(s.id),{method:'DELETE'});
    if(s.current) {await navigateTo('/login');return} await loadSessions() }
  catch(e) { sessionError.value=e instanceof Error ? e.message : 'Ошибка отзыва.' }
}
const revokeOthers = async () => {
  if (!confirm('Завершить все остальные сессии?')) return
  try { await api.request('/auth/sessions/revoke-others',{method:'POST',body:'{}'});await loadSessions() }
  catch(e) { sessionError.value=e instanceof Error ? e.message : 'Ошибка отзыва.' }
}
const revokeAll = async () => {
  if (!confirm('Завершить все сессии, включая текущую?')) return
  try { await api.request('/auth/sessions/revoke-all',{method:'POST',body:'{}'});await navigateTo('/login') }
  catch(e) { sessionError.value=e instanceof Error ? e.message : 'Ошибка отзыва.' }
}
const globalLogout = async () => {
  if (!confirm('Завершить текущую сессию SkiF4er ID и выйти из приложений SSO?')) return
  sessionError.value = ''
  try {
    const result = await api.request<{data:{authorization_url:string;local_session_revoked:boolean}}>('/auth/oidc/logout',{method:'POST',body:'{}'})
    const uri = new URL(result.data.authorization_url)
    if (uri.origin !== 'https://id.skif4er.ru' || uri.pathname !== '/realms/skif4er/protocol/openid-connect/logout') throw new Error('Некорректный OIDC logout URL')
    api.setCsrf(null)
    window.location.assign(uri.toString())
  } catch(e) { sessionError.value = e instanceof Error ? e.message : 'Глобальный выход не выполнен.' }
}
const rotate = async () => {
  try { const result=await api.request<{data:{csrf_token:string}}>('/auth/sessions/rotate',{method:'POST',body:'{}'});
    api.setCsrf(result.data.csrf_token);await loadSessions() }
  catch(e) { sessionError.value=e instanceof Error ? e.message : 'Ошибка ротации.' }
}

const load = async () => {
  try { status.value = (await api.request<{data: typeof status.value}>('/identity/account')).data }
  catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось получить статус.' }
}
onMounted(() => { load(); loadSessions() })
const submit = async () => {
  if (!status.value || busy.value) return
  const linked = status.value.linked
  if (linked && !confirm('Отвязать SkiF4er ID?')) return
  busy.value = true; error.value = ''
  try {
    const r = await api.request<{data: {authorization_url?:string}}> (linked ? '/identity/unlink' : '/identity/link/start',
      {method:'POST',body:JSON.stringify({password:password.value,two_factor_code:twoFactorCode.value})})
    password.value = ''; twoFactorCode.value = ''
    if (linked) await load()
    else {
      const url = r.data.authorization_url
      if (!url || !url.startsWith('https://id.skif4er.ru/realms/skif4er/')) throw new Error('Недопустимый адрес авторизации')
      window.location.assign(url)
    }
  } catch (e) { error.value = e instanceof Error ? e.message : 'Ошибка привязки.' }
  finally { busy.value = false }
}
</script>
<template>
  <section class="ce-panel">
    <h1>Identity — SkiF4er ID</h1>
    <p v-if="error" class="ce-error">{{ error }}</p>
    <template v-if="status?.enabled">
      <p>{{ status.linked ? 'Внешний аккаунт привязан' : 'Внешний аккаунт не привязан' }}</p>
      <p v-if="status.provisioned">Аккаунт создан через SkiF4er ID. Отвязка требует предварительной настройки локального входа.</p>
      <p v-if="status.created_at">Дата привязки: {{ status.created_at }}</p>
      <form class="ce-form" @submit.prevent="submit">
        <AdminFormRow><template #label>Текущий локальный пароль</template><input v-model="password" type="password" autocomplete="current-password" required /></AdminFormRow>
        <AdminFormRow><template #label>Код 2FA (если включён)</template><input v-model="twoFactorCode" autocomplete="one-time-code" /></AdminFormRow>
        <button class="ce-button" type="submit" :disabled="busy || (status.linked && status.provisioned)">{{ status.linked ? 'Отвязать SkiF4er ID' : 'Привязать SkiF4er ID' }}</button>
      </form>
    </template>
    <p v-else-if="status">SkiF4er ID отключён.</p>
    <p v-else>Загрузка…</p>
    <section class="ce-panel">
      <h2>Подтверждение критических операций</h2>
      <p>Подтверждение привязано к текущей сессии и действует не более пяти минут.</p>
      <p v-if="assuranceMessage">{{ assuranceMessage }}</p>
      <form class="ce-form" @submit.prevent="confirmAssurance">
        <AdminFormRow><template #label>Локальный пароль</template><input v-model="assurancePassword" type="password" autocomplete="current-password" required /></AdminFormRow>
        <AdminFormRow><template #label>Код TOTP при включённой 2FA</template><input v-model="assuranceTotp" autocomplete="one-time-code" /></AdminFormRow>
        <button type="submit">Подтвердить локально</button>
      </form>
      <button v-if="sessions.some(s=>s.current && s.provider==='skif4er')" type="button" @click="oidcAssurance">Подтвердить через SkiF4er ID + MFA</button>
    </section>
    <section class="ce-panel">
      <h2>Активные сессии</h2>
      <p>Завершение сессии блокирует доступ на указанном устройстве. API-токены сохраняются.</p>
      <p v-if="sessionError" class="ce-error">{{ sessionError }}</p>
      <table><thead><tr><th>Устройство</th><th>Источник</th><th>Последняя активность</th><th></th></tr></thead>
        <tbody><tr v-for="s in sessions" :key="s.id"><td>{{ s.device }} <strong v-if="s.current">(текущая)</strong><small>{{ s.network }}</small></td><td>{{ s.provider }}</td><td>{{ s.last_seen_at }}</td><td><button type="button" @click="revokeSession(s)">Завершить</button></td></tr></tbody>
      </table>
      <button type="button" @click="rotate">Обновить текущую сессию</button>
      <button type="button" @click="revokeOthers">Завершить остальные</button>
      <button type="button" @click="revokeAll">Завершить все</button>
      <button v-if="sessions.some(s => s.current && s.provider === 'skif4er')" type="button" @click="globalLogout">Выйти из SkiF4er ID (SSO)</button>
    </section>
  </section>
</template>
