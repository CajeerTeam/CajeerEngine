<script setup lang="ts">
const api = useAdminApi()
const users = ref<any[]>([])
const roles = ref<any[]>([])
const error = ref<string | null>(null)
const form = reactive({ email: '', name: '', password: '', roles: 'viewer' })

const load = async () => {
  error.value = null
  try {
    const [usersResponse, rolesResponse] = await Promise.all([
      api.request<{ data: any[] }>('/users'),
      api.request<{ data: any[] }>('/roles'),
    ])
    users.value = usersResponse.data || []
    roles.value = rolesResponse.data || []
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить пользователей.'
  }
}

const create = async () => {
  error.value = null
  try {
    await api.request('/users', { method: 'POST', body: JSON.stringify({ email: form.email, name: form.name, password: form.password, roles: form.roles.split(',').map((r) => r.trim()).filter(Boolean) }) })
    form.email = ''; form.name = ''; form.password = ''; form.roles = 'viewer'
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать пользователя.'
  }
}

const disableUser = async (id: string) => {
  if (!confirm('Отключить пользователя?')) return
  await api.request(`/users/${id}`, { method: 'DELETE' })
  await load()
}

onMounted(load)
</script>

<template>
  <section>
    <div class="ce-actions"><h1>Пользователи</h1><button class="ce-button secondary" @click="load">Обновить</button></div>
    <p v-if="error" class="ce-error">{{ error }}</p>
    <div class="ce-grid" style="margin-bottom: 18px">
      <AdminCard label="Пользователи" :value="users.length" />
      <AdminCard label="Роли" :value="roles.length" />
    </div>
    <div class="ce-panel" style="margin-bottom: 18px">
      <h2>Создать пользователя</h2>
      <form class="ce-form" @submit.prevent="create">
        <AdminFormRow><template #label>Email</template><input v-model="form.email" type="email" required /></AdminFormRow>
        <AdminFormRow><template #label>Имя</template><input v-model="form.name" required /></AdminFormRow>
        <AdminFormRow><template #label>Пароль</template><input v-model="form.password" type="password" required /></AdminFormRow>
        <AdminFormRow><template #label>Роли через запятую</template><input v-model="form.roles" /></AdminFormRow>
        <button class="ce-button" type="submit">Создать</button>
      </form>
    </div>
    <table class="ce-table">
      <thead><tr><th>Email</th><th>Имя</th><th>Роли</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <tr v-for="user in users" :key="user.id">
          <td>{{ user.email }}</td><td>{{ user.name }}</td><td>{{ (user.roles || []).join(', ') }}</td><td>{{ user.status || 'active' }}</td>
          <td><button class="ce-button danger" @click="disableUser(user.id)">Отключить</button></td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
