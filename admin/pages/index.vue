<script setup lang="ts">
import type { DashboardPayload } from '../types/admin'

const api = useAdminApi()
const dashboard = ref<DashboardPayload | null>(null)
const bootstrap = ref<unknown>(null)
const error = ref<string | null>(null)

const load = async () => {
  error.value = null
  try {
    const [boot, dash] = await Promise.all([
      api.request<{ data: unknown }>('/admin/bootstrap'),
      api.request<{ data: DashboardPayload }>('/admin/dashboard'),
    ])
    bootstrap.value = boot.data
    dashboard.value = dash.data
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить dashboard.'
  }
}

onMounted(load)
</script>

<template>
  <section>
    <div class="ce-actions">
      <h1>Обзор</h1>
      <button class="ce-button secondary" @click="load">Обновить</button>
    </div>
    <p v-if="error" class="ce-error">{{ error }}</p>
    <div class="ce-grid">
      <AdminCard v-for="card in dashboard?.cards || []" :key="card.label" :label="card.label" :value="card.value" :status="card.status" />
    </div>
    <div class="ce-panel" style="margin-top: 18px">
      <h2>Bootstrap Admin UI</h2>
      <AdminJsonBlock :value="bootstrap" />
    </div>
  </section>
</template>
