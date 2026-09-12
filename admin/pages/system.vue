<script setup lang="ts">
const api = useAdminApi()
const tabs = ['system', 'runtime', 'database', 'security'] as const
const active = ref<typeof tabs[number]>('system')
const payloads = reactive<Record<string, unknown>>({})
const error = ref<string | null>(null)

const load = async () => {
  error.value = null
  try {
    const [system, runtime, database, security] = await Promise.all([
      api.request('/system'),
      api.request('/runtime'),
      api.request('/database'),
      api.request('/security'),
    ])
    payloads.system = system
    payloads.runtime = runtime
    payloads.database = database
    payloads.security = security
  } catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось загрузить diagnostics.' }
}

onMounted(load)
</script>

<template>
  <section>
    <div class="ce-actions"><h1>Система</h1><button class="ce-button secondary" @click="load">Обновить</button></div>
    <p v-if="error" class="ce-error">{{ error }}</p>
    <div class="ce-actions" style="margin: 14px 0">
      <button v-for="tab in tabs" :key="tab" class="ce-button secondary" @click="active = tab">{{ tab }}</button>
    </div>
    <AdminJsonBlock :value="payloads[active]" />
  </section>
</template>
