<script setup lang="ts">
type ExtensionItem = {
  name: string
  title?: string
  type: string
  version: string
  engine: string
  installed: boolean
  enabled: boolean
  valid: boolean
  permissions?: string[]
  events?: string[]
  errors?: string[]
  warnings?: string[]
}

const api = useAdminApi()
const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<ExtensionItem[]>([])
const meta = ref<Record<string, unknown>>({})
const target = ref('')

const load = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await api.request<{ data: ExtensionItem[]; meta: Record<string, unknown> }>('/extensions')
    items.value = response.data
    meta.value = response.meta || {}
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить расширения'
  } finally {
    loading.value = false
  }
}

const action = async (path: string, payload: Record<string, unknown>) => {
  loading.value = true
  error.value = null
  try {
    await api.request(path, { method: 'POST', body: JSON.stringify(payload) })
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Операция не выполнена'
  } finally {
    loading.value = false
  }
}

const installTarget = async () => {
  if (!target.value.trim()) return
  await action('/extensions/install', { target: target.value.trim(), enable: true })
  target.value = ''
}

onMounted(load)
</script>

<template>
  <section>
    <h1>Расширения</h1>
    <p>Modules, plugins и themes управляются через Extension Registry.</p>

    <div class="ce-card-grid">
      <AdminCard title="Обнаружено" :value="String(meta.discovered || 0)" />
      <AdminCard title="Установлено" :value="String(meta.installed || 0)" />
      <AdminCard title="Включено" :value="String(meta.enabled || 0)" />
      <AdminCard title="Невалидно" :value="String(meta.invalid || 0)" />
    </div>

    <form class="ce-panel" @submit.prevent="installTarget">
      <AdminFormRow label="Установить расширение" description="Укажите имя vendor/name или путь к директории/manifest.">
        <input v-model="target" placeholder="cajeer/graphql" />
      </AdminFormRow>
      <button :disabled="loading || !target.trim()">Установить и включить</button>
    </form>

    <p v-if="error" class="ce-error">{{ error }}</p>
    <p v-if="loading">Загрузка...</p>

    <div class="ce-table-wrap">
      <table>
        <thead>
          <tr>
            <th>Название</th>
            <th>Тип</th>
            <th>Версия</th>
            <th>Статус</th>
            <th>Permissions</th>
            <th>Events</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.name">
            <td>
              <strong>{{ item.title || item.name }}</strong><br>
              <small>{{ item.name }} · engine {{ item.engine }}</small>
              <div v-if="item.errors?.length" class="ce-error">{{ item.errors.join('; ') }}</div>
              <div v-if="item.warnings?.length" class="ce-muted">{{ item.warnings.join('; ') }}</div>
            </td>
            <td>{{ item.type }}</td>
            <td>{{ item.version }}</td>
            <td>
              <span>{{ item.installed ? 'installed' : 'available' }}</span><br>
              <strong>{{ item.enabled ? 'enabled' : 'disabled' }}</strong>
            </td>
            <td>{{ item.permissions?.length || 0 }}</td>
            <td>{{ item.events?.length || 0 }}</td>
            <td class="ce-actions">
              <button v-if="!item.installed" @click="action('/extensions/install', { name: item.name })">Install</button>
              <button v-if="item.installed && !item.enabled" @click="action('/extensions/enable', { name: item.name })">Enable</button>
              <button v-if="item.installed && item.enabled" @click="action('/extensions/disable', { name: item.name })">Disable</button>
              <button v-if="item.installed" @click="action('/extensions/uninstall', { name: item.name })">Uninstall</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
