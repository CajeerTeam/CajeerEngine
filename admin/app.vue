<template>
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>

<style>
:root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
body { margin: 0; background: #f5f6f8; color: #111827; }
a { color: inherit; text-decoration: none; }
button, input, textarea, select { font: inherit; }
button { cursor: pointer; }
.ce-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 20px; box-shadow: 0 8px 24px rgba(15, 23, 42, .05); }
.ce-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.ce-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 16px; display: grid; gap: 8px; }
.ce-card span { color: #6b7280; font-size: 13px; }
.ce-card strong { font-size: 28px; }
.ce-card small { color: #6b7280; }
.ce-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 14px; overflow: hidden; }
.ce-table th, .ce-table td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
.ce-table th { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
.ce-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ce-button { border: 1px solid #111827; background: #111827; color: #fff; border-radius: 10px; padding: 10px 14px; }
.ce-button.secondary { background: #fff; color: #111827; border-color: #d1d5db; }
.ce-button.danger { background: #991b1b; border-color: #991b1b; }
.ce-form { display: grid; gap: 14px; }
.ce-form-row { display: grid; gap: 6px; }
.ce-form-row span { font-size: 13px; color: #4b5563; }
.ce-form-row input, .ce-form-row textarea, .ce-form-row select { border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px; background: #fff; }
.ce-json { background: #111827; color: #f9fafb; border-radius: 12px; padding: 16px; overflow: auto; font-size: 12px; }
.ce-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 12px; border-radius: 12px; }
.ce-muted { color: #6b7280; }
</style>
