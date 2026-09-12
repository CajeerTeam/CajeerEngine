<script setup lang="ts">
const api = useAdminApi()
const payload = ref<unknown>(null)
const error = ref<string | null>(null)
const load = async () => { error.value = null; try { payload.value = await api.request('/updates') } catch (e) { error.value = e instanceof Error ? e.message : String(e) } }
const check = async () => { error.value = null; try { payload.value = await api.request('/updates/check', { method: 'POST', body: '{}' }) } catch (e) { error.value = e instanceof Error ? e.message : String(e) } }
onMounted(load)
</script>
<template><section><div class="ce-actions"><h1>Обновления</h1><button class="ce-button" @click="check">Проверить</button><button class="ce-button secondary" @click="load">Диагностика</button></div><p v-if="error" class="ce-error">{{ error }}</p><AdminJsonBlock :value="payload" /></section></template>
