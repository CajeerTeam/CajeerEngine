<script setup lang="ts">
import type { ContentEntryRecord, ContentTypeRecord } from '../types/admin'

const api = useAdminApi()
const types = ref<ContentTypeRecord[]>([])
const entries = ref<ContentEntryRecord[]>([])
const selectedType = ref('')
const error = ref<string | null>(null)
const form = reactive({ title: '', slug: '', locale: 'ru', dataJson: '{}' })

const loadTypes = async () => {
  const response = await api.request<{ data: ContentTypeRecord[] }>('/content-types')
  types.value = response.data || []
  if (!selectedType.value && types.value.length > 0) selectedType.value = types.value[0].handle
}

const loadEntries = async () => {
  if (!selectedType.value) { entries.value = []; return }
  const response = await api.request<{ data: ContentEntryRecord[] }>(`/content/${selectedType.value}`)
  entries.value = response.data || []
}

const load = async () => {
  error.value = null
  try { await loadTypes(); await loadEntries() } catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось загрузить контент.' }
}

watch(selectedType, () => loadEntries())

const create = async () => {
  error.value = null
  try {
    await api.request(`/content/${selectedType.value}`, { method: 'POST', body: JSON.stringify({ title: form.title, slug: form.slug, locale: form.locale, data: JSON.parse(form.dataJson) }) })
    form.title = ''; form.slug = ''; form.dataJson = '{}'
    await loadEntries()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось создать запись.' }
}

const action = async (entry: ContentEntryRecord, name: 'publish' | 'unpublish' | 'archive') => {
  await api.request(`/content/${selectedType.value}/${entry.id}/${name}`, { method: 'POST' })
  await loadEntries()
}

onMounted(load)
</script>

<template>
  <section>
    <div class="ce-actions">
      <h1>Контент</h1>
      <select v-model="selectedType"><option v-for="type in types" :key="type.handle" :value="type.handle">{{ type.name }} / {{ type.handle }}</option></select>
      <button class="ce-button secondary" @click="load">Обновить</button>
    </div>
    <p v-if="error" class="ce-error">{{ error }}</p>

    <div class="ce-panel" style="margin-bottom: 18px">
      <h2>Создать черновик</h2>
      <form class="ce-form" @submit.prevent="create">
        <AdminFormRow><template #label>Заголовок</template><input v-model="form.title" required /></AdminFormRow>
        <AdminFormRow><template #label>Slug</template><input v-model="form.slug" /></AdminFormRow>
        <AdminFormRow><template #label>Locale</template><input v-model="form.locale" /></AdminFormRow>
        <AdminFormRow><template #label>Data JSON</template><textarea v-model="form.dataJson" rows="8" /></AdminFormRow>
        <button class="ce-button" type="submit" :disabled="!selectedType">Создать</button>
      </form>
    </div>

    <table class="ce-table">
      <thead><tr><th>Заголовок</th><th>Status</th><th>Locale</th><th>Revision</th><th>Actions</th></tr></thead>
      <tbody>
        <tr v-for="entry in entries" :key="entry.id">
          <td>{{ entry.title || entry.slug || entry.id }}</td>
          <td>{{ entry.status }}</td>
          <td>{{ entry.locale }}</td>
          <td>{{ entry.revision_number }}</td>
          <td class="ce-actions">
            <button class="ce-button secondary" @click="action(entry, 'publish')">Publish</button>
            <button class="ce-button secondary" @click="action(entry, 'unpublish')">Unpublish</button>
            <button class="ce-button danger" @click="action(entry, 'archive')">Archive</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="types.length === 0" class="ce-muted">Сначала создайте тип контента.</p>
  </section>
</template>
