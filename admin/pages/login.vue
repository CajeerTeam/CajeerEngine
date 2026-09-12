<script setup lang="ts">
const auth = useAdminAuth()
const router = useRouter()
const email = ref('')
const password = ref('')
const twoFactorCode = ref('')

const submit = async () => {
  const ok = await auth.login(email.value, password.value, twoFactorCode.value)
  if (ok) await router.push('/')
}
</script>

<template>
  <section class="ce-panel ce-login">
    <h1>Вход в CajeerEngine</h1>
    <p class="ce-muted">Используется реальный endpoint <code>POST /api/v1/auth/login</code>. Token сохраняется в localStorage Admin UI.</p>
    <form class="ce-form" @submit.prevent="submit">
      <AdminFormRow><template #label>Email</template><input v-model="email" type="email" autocomplete="username" required /></AdminFormRow>
      <AdminFormRow><template #label>Пароль</template><input v-model="password" type="password" autocomplete="current-password" required /></AdminFormRow>
      <AdminFormRow><template #label>2FA-код, если включён</template><input v-model="twoFactorCode" inputmode="numeric" /></AdminFormRow>
      <p v-if="auth.error.value" class="ce-error">{{ auth.error.value }}</p>
      <button class="ce-button" type="submit" :disabled="auth.loading.value">Войти</button>
    </form>
  </section>
</template>

<style scoped>
.ce-login { max-width: 520px; }
</style>
