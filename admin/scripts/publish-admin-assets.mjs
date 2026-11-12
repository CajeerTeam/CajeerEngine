import { readFile, writeFile, mkdir, copyFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
const root = fileURLToPath(new URL('../..', import.meta.url))
const source = join(root, 'admin/static')
const target = join(root, 'public/admin')
const version = (await readFile(join(root, 'VERSION'), 'utf8')).trim()
const check = process.argv.includes('--check')
const paths = ['index.html', 'assets/admin-app.js', 'assets/admin-app.css', 'assets/admin-preview.js', 'assets/admin-preview.css']
const digest = b => createHash('sha256').update(b).digest('hex')
const files = {}
try {
  for (const path of paths) {
    const body = await readFile(join(source, path))
    if (!body.length) throw new Error(`Empty source: ${path}`)
    if (!check) {
      await mkdir(dirname(join(target, path)), { recursive: true })
      await copyFile(join(source, path), join(target, path))
    }
    const published = await readFile(join(target, path))
    if (!body.equals(published)) throw new Error(`Outdated asset: ${path}`)
    files[path] = { path: `/admin/${path}`, exists: true, size: body.length, sha256: digest(body) }
  }
  const manifest = { name: 'CajeerEngine Admin UI', version, mode: 'production-static-spa', base_path: '/admin/', api_base: '/api/v1', files, quality: { production_assets: true }, ready: true }
  const path = join(target, 'assets/admin-manifest.json')
  if (check) {
    const stored = JSON.parse(await readFile(path, 'utf8'))
    if (stored.version !== version || stored.mode !== manifest.mode || stored.ready !== true || JSON.stringify(stored.files) !== JSON.stringify(files)) throw new Error('Outdated admin manifest')
  } else await writeFile(path, JSON.stringify(manifest, null, 2) + '\n')
  console.log(JSON.stringify(manifest, null, 2))
} catch (e) { console.error(e.message); process.exitCode = 1 }
