export default defineNuxtConfig({
  ssr: false,
  compatibilityDate: '2026-06-14',
  devtools: { enabled: true },
  app: {
    baseURL: '/admin/',
    buildAssetsDir: 'assets/',
    head: {
      title: 'CajeerEngine Admin 1.1.1',
      htmlAttrs: { lang: 'ru' }
    }
  },
  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || '/api/v1',
      adminVersion: '1.1.1'
    }
  },
  typescript: {
    strict: true,
    typeCheck: true
  }
})
