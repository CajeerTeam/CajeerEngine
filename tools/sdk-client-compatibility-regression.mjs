#!/usr/bin/env node
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve, join } from 'node:path'
import { createRequire } from 'node:module'
import { pathToFileURL } from 'node:url'
import { fileURLToPath } from 'node:url'

const root = resolve(fileURLToPath(new URL('..', import.meta.url)))
const tmp = mkdtempSync(join(tmpdir(), 'cajeer-sdk-regression-'))
const assert = (value, message) => { if (!value) throw new Error(message); process.stdout.write(`PASS ${message}\n`) }
try {
  const localTsc = resolve(root, 'packages/ts-sdk/node_modules/.bin/tsc')
  const tsc = process.platform === 'win32' ? `${localTsc}.cmd` : localTsc
  const compiler = (() => { try { readFileSync(tsc); return tsc } catch { return 'tsc' } })()
  const build = spawnSync(compiler, ['--module', 'commonjs', '--moduleResolution', 'node', '--target', 'es2022', '--outDir', tmp, '--skipLibCheck',
    'packages/ts-sdk/src/index.ts', 'packages/ts-sdk/src/operations.ts'], { cwd: root, encoding: 'utf8' })
  assert(build.status === 0, 'TypeScript SDK compiles: ' + (build.stderr || build.stdout).slice(0, 500))
  writeFileSync(join(tmp, 'package.json'), '{"type":"commonjs"}')
  const require = createRequire(join(tmp, 'sdk-test.cjs'))
  const { CajeerEngineClient, operations } = require(join(tmp, 'index.js'))
  const esmDir = join(tmp, 'esm')
  const esmBuild = spawnSync(compiler, ['--module', 'esnext', '--moduleResolution', 'bundler', '--target', 'es2022', '--outDir', esmDir, '--skipLibCheck',
    'packages/ts-sdk/src/index.ts', 'packages/ts-sdk/src/operations.ts'], { cwd: root, encoding: 'utf8' })
  assert(esmBuild.status === 0, 'production ESM SDK compiles')
  writeFileSync(join(esmDir, 'package.json'), '{"type":"module"}')
  const esm = await import(pathToFileURL(join(esmDir, 'index.js')).href)
  assert(Object.keys(esm.operations).length === Object.keys(operations).length, 'Node ESM can load every generated SDK operation')
  for (const url of ['http://api.example.test/api/v1', 'https://user:password@api.example.test/api/v1', 'https://api.example.test/api/v1?token=secret']) {
    let rejected = false
    try { new CajeerEngineClient({ baseUrl: url, token: 'sensitive' }) } catch { rejected = true }
    assert(rejected, 'rejects unsafe SDK base URL')
  }
  for (const url of ['https://api.example.test/api/v1', 'http://localhost:8080/api/v1', 'http://127.0.0.1/api/v1']) {
    assert(new CajeerEngineClient({ baseUrl: url }) instanceof CajeerEngineClient, 'accepts supported API endpoint')
  }
  const config = { baseUrl: 'https://api.example.test/api/v1/', token: 'test-token' }
  const client = new CajeerEngineClient(config)
  let rejected = false
  try { client.setToken('hello\r\nBad-Header: yes') } catch { rejected = true }
  assert(rejected, 'rejects header injection into bearer credentials')
  const calls = []
  globalThis.fetch = async (url, options) => {
    calls.push({ url, options })
    return new Response(JSON.stringify({ data: { ok: true } }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  }
  config.baseUrl = 'http://untrusted.example.test/stolen'
  await client.health()
  assert(calls[0].url === 'https://api.example.test/api/v1/health', 'base URL cannot change after construction')
  assert(calls[0].options.headers.get('Authorization') === 'Bearer test-token', 'bearer token attached to validated HTTPS origin')
  assert(calls[0].options.redirect === 'error', 'SDK blocks HTTP redirects')
  const entry = Object.entries(operations).find(([, operation]) => /\{[^}]+\}/.test(operation.path))
  assert(Boolean(entry), 'generated SDK contains parametrized API operations')
  const [operationId, operation] = entry
  const params = Object.fromEntries([...operation.path.matchAll(/\{([^}]+)\}/g)].map(([, key]) => [key, 'slash/in-path']))
  await client.call(operationId, { path: params, body: operation.bodyRequired ? {} : undefined })
  assert(calls[1].url.includes('slash%2Fin-path'), 'path parameters remain encoded in real generated SDK calls')
  console.log('TypeScript SDK compatibility regression complete')
} finally {
  rmSync(tmp, { recursive: true, force: true })
}
