import { readFileSync } from 'node:fs'

const engineVersion = readFileSync(new URL('../VERSION', import.meta.url), 'utf8').trim()

export default defineNuxtConfig({
  ssr: false,
  compatibilityDate: '2026-06-14',
  devtools: { enabled: true },
  app: {
    baseURL: '/admin/',
    buildAssetsDir: 'assets/',
    head: {
      title: `CajeerEngine Admin ${engineVersion}`,
      htmlAttrs: { lang: 'ru' }
    }
  },
  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || '/api/v1',
      adminVersion: engineVersion
    }
  },
  typescript: {
    strict: true,
    typeCheck: true
  }
})
