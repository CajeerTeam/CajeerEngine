<script setup lang="ts">
const api = useAdminApi()
const status = ref<{enabled:boolean;linked:boolean;provisioned?:boolean;created_at?:string|null}|null>(null)
const password = ref('')
const twoFactorCode = ref('')
const error = ref('')
const busy = ref(false)
const load = async () => {
  try { status.value = (await api.request<{data: typeof status.value}>('/identity/account')).data }
  catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось получить статус.' }
}
onMounted(load)
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
  </section>
</template>
