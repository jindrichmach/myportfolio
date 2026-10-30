import assert from 'node:assert/strict'
import { readFile, readdir } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)

async function vueFiles(dir = root) {
  const out = []
  for (const e of await readdir(dir, { withFileTypes: true })) {
    const url = new URL(e.name + (e.isDirectory() ? '/' : ''), dir)
    if (e.isDirectory()) out.push(...await vueFiles(url))
    else if (e.name.endsWith('.vue')) out.push(url)
  }
  return out
}

const template = (src) => src.match(/<template>([\s\S]*)<\/template>/)?.[1] ?? ''
const rel = (url) => url.pathname.replace(root.pathname, '')
// Ovládací prvek uvnitř <label> = implicitní propojení (input, select, textarea, SearchableSelect…).
const CONTROL = /<(input|select|textarea|SearchableSelect|GridFilterField)\b/

test('každý <label> je propojený s polem (WCAG 1.3.1, 4.1.2)', async () => {
  const orphans = []
  for (const file of await vueFiles()) {
    const tpl = template(await readFile(file, 'utf8'))
    for (const m of tpl.matchAll(/<label\b([^>]*)>([\s\S]*?)<\/label>/g)) {
      if (/\s:?for=/.test(m[1]) || CONTROL.test(m[2])) continue
      orphans.push(`${rel(file)}: ${m[0].slice(0, 90).replace(/\s+/g, ' ')}`)
    }
  }
  assert.deepEqual(orphans, [])
})

test('Modal je přístupný dialog s pastí fokusu', async () => {
  const modal = await readFile(new URL('components/ui/Modal.vue', root), 'utf8')
  assert.match(modal, /role="dialog"/)
  assert.match(modal, /aria-modal="true"/)
  assert.match(modal, /:aria-labelledby="titleId"/)
  assert.match(modal, /e\.key !== 'Tab'/)
  assert.match(modal, /opener\?\.isConnected\) opener\.focus\(\)/)
})

test('stránky mají titulek okna z meta.title', async () => {
  const router = await readFile(new URL('router/index.ts', root), 'utf8')
  const app = await readFile(new URL('App.vue', root), 'utf8')
  const withComponent = [...router.matchAll(/name: '([a-z-]+)',[^\n]*component:[^\n]*/g)]
  for (const [line, name] of withComponent) {
    if (name === 'not-found') continue
    assert.match(line, /title: '[a-z_]+\.[a-z_]+'/, `route ${name} nemá meta.title`)
  }
  assert.match(app, /document\.title = /)
})
