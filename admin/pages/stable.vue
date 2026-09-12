<template>
  <section class="ce-page">
    <h1>Stable 1.1.1</h1>
    <p>Production readiness, release lock, backup and support bundle.</p>
    <div class="ce-actions">
      <button @click="load">Обновить статус</button>
      <button @click="smoke">Smoke-test</button>
      <button @click="lock">Release lock</button>
      <button @click="backup">Backup</button>
      <button @click="support">Support bundle</button>
    </div>
    <AdminJsonBlock :value="state" />
  </section>
</template>

<script setup lang="ts">
const api = useAdminApi()
const state = ref<unknown>({ loading: true })
async function load() { state.value = await api.get('/stable') }
async function smoke() { state.value = await api.post('/stable/smoke-test', {}) }
async function lock() { state.value = await api.post('/stable/release-lock', {}) }
async function backup() { state.value = await api.post('/stable/backup', { include_uploads: true }) }
async function support() { state.value = await api.post('/stable/support-bundle', { include_logs: true }) }
onMounted(load)
</script>
