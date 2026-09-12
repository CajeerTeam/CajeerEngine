<script setup lang="ts">
defineProps<{ label: string; value: string | number | boolean; hint?: string; status?: string }>()
</script>

<template>
  <article class="ce-card" :data-status="status || 'ok'">
    <span>{{ label }}</span>
    <strong>{{ value }}</strong>
    <small v-if="hint">{{ hint }}</small>
  </article>
</template>
