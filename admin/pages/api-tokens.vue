<script setup lang="ts">
const api = useAdminApi()
const tokens = ref<any[]>([])
const createdToken = ref<string | null>(null)
const error = ref<string | null>(null)
const form = reactive({ name: 'Admin token', scopes: 'system:read,content:read,content:write,users:read,users:write' })

const load = async () => {
  error.value = null
  try {
    const response = await api.request<{ data: any[] }>('/api-tokens')
    tokens.value = response.data || []
  } catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось загрузить токены.' }
}

const create = async () => {
  error.value = null
  createdToken.value = null
  try {
    const response = await api.request<{ data: any }>('/api-tokens', { method: 'POST', body: JSON.stringify({ name: form.name, scopes: form.scopes.split(',').map((s) => s.trim()).filter(Boolean) }) })
    createdToken.value = response.data?.plain_token || response.data?.token || null
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось создать token.' }
}

const revoke = async (id: string) => { await api.request(`/api-tokens/${id}`, { method: 'DELETE' }); await load() }
onMounted(load)
</script>

<template>
  <section>
    <div class="ce-actions"><h1>API tokens</h1><button class="ce-button secondary" @click="load">Обновить</button></div>
    <p v-if="error" class="ce-error">{{ error }}</p>
    <p v-if="createdToken" class="ce-panel"><strong>Plain token показывается один раз:</strong><br><code>{{ createdToken }}</code></p>
    <div class="ce-panel" style="margin-bottom: 18px">
      <h2>Создать token</h2>
      <form class="ce-form" @submit.prevent="create">
        <AdminFormRow><template #label>Название</template><input v-model="form.name" required /></AdminFormRow>
        <AdminFormRow><template #label>Scopes</template><input v-model="form.scopes" /></AdminFormRow>
        <button class="ce-button" type="submit">Создать</button>
      </form>
    </div>
    <table class="ce-table">
      <thead><tr><th>Название</th><th>Scopes</th><th>Prefix</th><th>Последнее использование</th><th></th></tr></thead>
      <tbody>
        <tr v-for="token in tokens" :key="token.id">
          <td>{{ token.name }}</td><td>{{ (token.scopes || []).join(', ') }}</td><td>{{ token.prefix }}</td><td>{{ token.last_used_at || '—' }}</td>
          <td><button class="ce-button danger" @click="revoke(token.id)">Отозвать</button></td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
