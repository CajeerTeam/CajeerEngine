<script setup lang="ts">
import type { ContentTypeRecord } from '../types/admin'

const api = useAdminApi()
const items = ref<ContentTypeRecord[]>([])
const error = ref<string | null>(null)
const loading = ref(false)
const form = reactive({
  handle: '',
  name: '',
  fieldsJson: '[{"handle":"title","type":"text","label":"Заголовок","required":true}]',
  localized: false,
  revisionable: true,
})

const load = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await api.request<{ data: ContentTypeRecord[] }>('/content-types')
    items.value = response.data || []
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить типы контента.'
  } finally {
    loading.value = false
  }
}

const create = async () => {
  error.value = null
  try {
    const fields = JSON.parse(form.fieldsJson)
    await api.request('/content-types', {
      method: 'POST',
      body: JSON.stringify({ handle: form.handle, name: form.name, fields, localized: form.localized, revisionable: form.revisionable }),
    })
    form.handle = ''
    form.name = ''
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать тип контента.'
  }
}

const remove = async (handle: string) => {
  if (!confirm(`Удалить тип контента ${handle}?`)) return
  await api.request(`/content-types/${handle}`, { method: 'DELETE' })
  await load()
}

onMounted(load)
</script>

<template>
  <section>
    <div class="ce-actions">
      <h1>Типы контента</h1>
      <button class="ce-button secondary" @click="load">Обновить</button>
    </div>
    <p v-if="error" class="ce-error">{{ error }}</p>

    <div class="ce-panel" style="margin-bottom: 18px">
      <h2>Создать тип контента</h2>
      <form class="ce-form" @submit.prevent="create">
        <AdminFormRow><template #label>Handle</template><input v-model="form.handle" placeholder="articles" required /></AdminFormRow>
        <AdminFormRow><template #label>Название</template><input v-model="form.name" placeholder="Статьи" required /></AdminFormRow>
        <AdminFormRow><template #label>Fields JSON</template><textarea v-model="form.fieldsJson" rows="6" required /></AdminFormRow>
        <label><input v-model="form.localized" type="checkbox" /> Локализуемый</label>
        <label><input v-model="form.revisionable" type="checkbox" /> Ревизии</label>
        <button class="ce-button" type="submit">Создать</button>
      </form>
    </div>

    <table class="ce-table">
      <thead><tr><th>Handle</th><th>Название</th><th>Fields</th><th>Storage</th><th></th></tr></thead>
      <tbody>
        <tr v-for="item in items" :key="item.handle">
          <td><code>{{ item.handle }}</code></td>
          <td>{{ item.name }}</td>
          <td>{{ item.fields?.length || 0 }}</td>
          <td>{{ item.storage || 'auto' }}</td>
          <td><button class="ce-button danger" @click="remove(item.handle)">Удалить</button></td>
        </tr>
      </tbody>
    </table>
    <p v-if="!loading && items.length === 0" class="ce-muted">Типов контента пока нет.</p>
  </section>
</template>
