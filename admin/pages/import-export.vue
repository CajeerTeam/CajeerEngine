<script setup lang="ts">
const api = useAdminApi()
const payload = ref<unknown>(null)
const error = ref<string | null>(null)
const load = async () => { error.value = null; try { payload.value = await api.request('/import-export/exports') } catch (e) { error.value = e instanceof Error ? e.message : String(e) } }
const createExport = async () => { error.value = null; try { payload.value = await api.request('/import-export/export', { method: 'POST', body: JSON.stringify({ sections: ['content', 'media', 'users', 'settings', 'themes', 'extensions'] }) }) } catch (e) { error.value = e instanceof Error ? e.message : String(e) } }
onMounted(load)
</script>
<template><section><div class="ce-actions"><h1>Import / Export</h1><button class="ce-button" @click="createExport">Создать экспорт</button><button class="ce-button secondary" @click="load">Обновить</button></div><p v-if="error" class="ce-error">{{ error }}</p><AdminJsonBlock :value="payload" /></section></template>
