<script setup lang="ts">
const { request } = useAdminApi()
const media = ref<any[]>([])
const filename = ref('note.txt')
const content = ref('CajeerEngine media upload')
const error = ref('')

async function load() {
  const response = await request('/media')
  media.value = response.data || []
}

async function uploadText() {
  error.value = ''
  try {
    await request('/media', {
      method: 'POST',
      body: JSON.stringify({
        filename: filename.value,
        mime_type: 'text/plain',
        content_base64: btoa(unescape(encodeURIComponent(content.value))),
      }),
    })
    await load()
  } catch (e: any) {
    error.value = e.message || 'Upload failed'
  }
}

onMounted(load)
</script>

<template>
  <section>
    <h1>Media Library</h1>
    <p>Рабочий media upload через API CajeerEngine 0.7.0.</p>

    <div class="card">
      <h2>Загрузить текстовый файл</h2>
      <input v-model="filename" placeholder="filename.txt">
      <textarea v-model="content" rows="5" />
      <button @click="uploadText">Загрузить</button>
      <p v-if="error" class="error">{{ error }}</p>
    </div>

    <div class="card">
      <h2>Файлы</h2>
      <div v-for="item in media" :key="item.id" class="media-row">
        <img v-if="String(item.mime_type).startsWith('image/')" :src="item.url" alt="">
        <div>
          <strong>{{ item.filename }}</strong><br>
          <a :href="item.url" target="_blank">{{ item.url }}</a><br>
          <span>{{ item.mime_type }} · {{ item.size }} bytes</span>
        </div>
      </div>
    </div>
  </section>
</template>
