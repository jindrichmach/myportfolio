import assert from 'node:assert/strict'
import { readFile, readdir } from 'node:fs/promises'
import test from 'node:test'

const repo = new URL('../../', import.meta.url)

async function phpFiles(dir) {
  const out = []
  for (const e of await readdir(new URL(dir, repo), { withFileTypes: true })) {
    const path = `${dir}${e.name}`
    if (e.isDirectory()) out.push(...await phpFiles(`${path}/`))
    else if (e.name.endsWith('.php')) out.push(path)
  }
  return out
}

// Kódy akcí zapisované do activity logu (->log('…'), ->audit($request, '…'), ternár share.revoked/left)
test('každý kód akce activity logu má popisek v cs i en', async () => {
  const codes = new Set()
  for (const file of await phpFiles('api/src/')) {
    const src = await readFile(new URL(file, repo), 'utf8')
    for (const m of src.matchAll(/->(?:log|audit)\(\s*(?:\$request,\s*)?'([a-z_]+\.[a-z_.]+)'/g)) codes.add(m[1])
    for (const m of src.matchAll(/\? '(share\.[a-z]+)' : '(share\.[a-z]+)'/g)) { codes.add(m[1]); codes.add(m[2]) }
  }
  assert.ok(codes.size > 40)
  for (const locale of ['cs', 'en']) {
    const actions = JSON.parse(await readFile(new URL(`web/src/i18n/${locale}.json`, repo), 'utf8')).activity_log.actions
    for (const code of codes) {
      const label = code.split('.').reduce((node, part) => node?.[part], actions)
      assert.equal(typeof label, 'string', `${locale}: activity_log.actions.${code}`)
    }
  }
})
