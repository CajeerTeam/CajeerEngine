import { access, copyFile, mkdir, readdir, readFile, stat, writeFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import { dirname, join, resolve } from 'node:path'

const root = resolve(new URL('../..', import.meta.url).pathname)
const publicAdmin = join(root, 'public/admin')
const publicAssets = join(publicAdmin, 'assets')
const staticAdmin = join(root, 'admin/static')
const generatedPublicDirs = [
  join(root, 'admin/.output/public'),
  join(root, 'admin/.output/public/admin'),
  join(root, 'admin/dist'),
]
const required = [
  'index.html',
  'assets/admin-app.js',
  'assets/admin-app.css',
  'assets/admin-preview.js',
  'assets/admin-preview.css',
]

const exists = async (path) => access(path).then(() => true).catch(() => false)
const sha256 = async (path) => createHash('sha256').update(await readFile(path)).digest('hex')

async function copyIfExists(from, to) {
  if (!(await exists(from))) return false
  await mkdir(dirname(to), { recursive: true })
  await copyFile(from, to)
  return true
}

async function copyRecursive(from, to) {
  if (!(await exists(from))) return false
  const info = await stat(from)
  if (info.isFile()) {
    await mkdir(dirname(to), { recursive: true })
    await copyFile(from, to)
    return true
  }
  await mkdir(to, { recursive: true })
  for (const item of await readdir(from, { withFileTypes: true })) {
    await copyRecursive(join(from, item.name), join(to, item.name))
  }
  return true
}

async function publishGeneratedAssets() {
  let copied = false
  for (const dir of generatedPublicDirs) {
    if (!(await exists(dir))) continue
    copied = (await copyIfExists(join(dir, 'index.html'), join(publicAdmin, 'index.html'))) || copied
    copied = (await copyRecursive(join(dir, 'assets'), publicAssets)) || copied
    copied = (await copyRecursive(join(dir, '_nuxt'), join(publicAdmin, '_nuxt'))) || copied
  }
  return copied
}

async function publishStaticAssets() {
  if (!(await exists(staticAdmin))) return false
  await copyIfExists(join(staticAdmin, 'index.html'), join(publicAdmin, 'index.html'))
  await copyRecursive(join(staticAdmin, 'assets'), publicAssets)
  return true
}

async function manifestFile(relative) {
  const path = join(publicAdmin, relative)
  const fileExists = await exists(path)
  const size = fileExists ? (await stat(path)).size : 0
  return {
    path: `/admin/${relative}`,
    exists: fileExists,
    size,
    sha256: fileExists ? await sha256(path) : null,
  }
}

function productionQuality(files) {
  const appJs = files['assets/admin-app.js']
  const appCss = files['assets/admin-app.css']
  const index = files['index.html']
  return Boolean(
    index?.exists && index.size >= 350 &&
    appJs?.exists && appJs.size >= 18000 &&
    appCss?.exists && appCss.size >= 3500
  )
}

await mkdir(publicAssets, { recursive: true })
const generated = await publishGeneratedAssets()
const staticPublished = generated ? false : await publishStaticAssets()

const files = {}
for (const file of required) files[file] = await manifestFile(file)
const quality = productionQuality(files)
const manifest = {
  name: 'CajeerEngine Admin UI',
  version: '1.1.1',
  mode: generated ? 'nuxt-generated-static-spa' : 'production-static-spa',
  base_path: '/admin/',
  api_base: '/api/v1',
  generated_at: new Date().toISOString(),
  source: {
    generated_output_used: generated,
    static_source_used: staticPublished,
    static_source: 'admin/static',
  },
  quality: {
    production_assets: quality,
    min_app_js_bytes: 18000,
    min_app_css_bytes: 3500,
  },
  files,
}
manifest.ready = Object.values(files).every((file) => file.exists && file.size > 0) && quality
await writeFile(join(publicAssets, 'admin-manifest.json'), JSON.stringify(manifest, null, 2) + '\n', 'utf8')
if (process.argv.includes('--check') && !manifest.ready) {
  console.error('Admin production assets are incomplete or too small.')
  console.error(JSON.stringify(manifest, null, 2))
  process.exit(1)
}
console.log(JSON.stringify(manifest, null, 2))
