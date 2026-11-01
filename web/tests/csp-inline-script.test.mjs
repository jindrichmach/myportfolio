import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const repo = new URL('../../', import.meta.url)
const read = path => readFile(new URL(path, repo), 'utf8')

// CSP script-src nemá 'unsafe-inline' — jediný inline skript (anti-FOUC v index.html) povoluje jeho sha256.
// Po úpravě skriptu se hash musí přepsat v .htaccess i docker/nginx.conf.
test('CSP povoluje inline skripty z index.html jen přes jejich sha256', async () => {
  const html = await read('web/index.html')
  const inline = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1])
  assert.ok(inline.length > 0)
  const hashes = inline.map(code => `'sha256-${createHash('sha256').update(code).digest('base64')}'`)

  for (const file of ['.htaccess', 'docker/nginx.conf']) {
    const scriptSrc = (await read(file)).match(/script-src ([^;]*)/)?.[1] ?? ''
    assert.doesNotMatch(scriptSrc, /'unsafe-inline'/, file)
    for (const hash of hashes) assert.ok(scriptSrc.includes(hash), `${file}: chybí ${hash}`)
  }
})
