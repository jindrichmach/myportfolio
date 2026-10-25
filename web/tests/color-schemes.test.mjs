import assert from 'node:assert/strict'
import { access, readFile } from 'node:fs/promises'
import test from 'node:test'

const web = new URL('../', import.meta.url)
const read = (path) => readFile(new URL(path, web), 'utf8')

async function schemeKeys() {
  const ts = await read('src/theme/colorSchemes.ts')
  return [...ts.matchAll(/^\s+(\w+):\s+\['#/gm)].map((m) => m[1])
}

test('every color scheme has light and dark tokens, a favicon and a name', async () => {
  const keys = await schemeKeys()
  assert.equal(keys.length, 10)
  const css = await read('src/styles/schemes.css')
  const cs = JSON.parse(await read('src/i18n/cs.json'))
  const en = JSON.parse(await read('src/i18n/en.json'))
  for (const key of keys) {
    assert.equal(typeof cs.appearance.names[key], 'string', key)
    assert.equal(typeof en.appearance.names[key], 'string', key)
    if (key === 'forest') continue
    assert.match(css, new RegExp(`:root\\[data-scheme="${key}"\\] \\{[^}]*--color-logo:`), key)
    assert.match(css, new RegExp(`:root\\.dark\\[data-scheme="${key}"\\] \\{[^}]*--color-neutral-50:`), key)
    await access(new URL(`public/pwa/favicon-${key}.svg`, web))
  }
})

test('backend knows the same color schemes in the same order', async () => {
  const php = await readFile(new URL('../api/src/Service/Appearance/ColorSchemes.php', web), 'utf8')
  const list = php.match(/public const KEYS = \[([^\]]+)\]/)[1].match(/'(\w+)'/g).map((k) => k.slice(1, -1))
  assert.deepEqual(list, await schemeKeys())
  for (const key of list) {
    await access(new URL(`../api/templates/email/logo-${key}.png`, web))
  }
})
