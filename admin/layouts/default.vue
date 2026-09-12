<script setup lang="ts">
const auth = useAdminAuth()
onMounted(() => auth.load())
</script>

<template>
  <main class="ce-admin-shell">
    <aside class="ce-admin-sidebar">
      <strong>CajeerEngine</strong>
      <small>Admin UI 1.1.1</small>
      <nav>
        <NuxtLink to="/">Обзор</NuxtLink>
        <NuxtLink to="/content-types">Типы контента</NuxtLink>
        <NuxtLink to="/content">Контент</NuxtLink>
        <NuxtLink to="/media">Media</NuxtLink>
        <NuxtLink to="/users">Пользователи</NuxtLink>
        <NuxtLink to="/api-tokens">API tokens</NuxtLink>
        <NuxtLink to="/extensions">Расширения</NuxtLink>
        <NuxtLink to="/import-export">Import / Export</NuxtLink>
        <NuxtLink to="/updates">Обновления</NuxtLink>
        <NuxtLink to="/system">Система</NuxtLink>
        <NuxtLink to="/stable">Stable</NuxtLink>
      </nav>
      <footer>
        <NuxtLink v-if="!auth.token.value" class="ce-login-link" to="/login">Войти</NuxtLink>
        <button v-else class="ce-logout" @click="auth.logout()">Выйти</button>
      </footer>
    </aside>
    <section class="ce-admin-content">
      <slot />
    </section>
  </main>
</template>

<style scoped>
.ce-admin-shell { display: grid; grid-template-columns: 270px 1fr; min-height: 100vh; }
.ce-admin-sidebar { position: sticky; top: 0; height: 100vh; border-right: 1px solid #e5e7eb; background: #fff; padding: 24px; display: flex; flex-direction: column; gap: 16px; box-sizing: border-box; }
.ce-admin-sidebar strong { font-size: 20px; }
.ce-admin-sidebar small { color: #6b7280; }
.ce-admin-sidebar nav { display: grid; gap: 8px; margin-top: 10px; }
.ce-admin-sidebar a, .ce-logout { border-radius: 10px; padding: 10px 12px; color: #374151; border: 0; background: transparent; text-align: left; }
.ce-admin-sidebar a.router-link-active, .ce-admin-sidebar a:hover, .ce-logout:hover { background: #f3f4f6; color: #111827; }
.ce-admin-sidebar footer { margin-top: auto; display: grid; gap: 10px; }
.ce-admin-content { padding: 32px; min-width: 0; }
@media (max-width: 900px) { .ce-admin-shell { grid-template-columns: 1fr; } .ce-admin-sidebar { position: static; height: auto; } }
</style>
