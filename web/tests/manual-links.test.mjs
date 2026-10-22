import assert from 'node:assert/strict'
import { readdir, readFile } from 'node:fs/promises'
import test from 'node:test'

// Manuál ve webu (pages/Manual.vue) převádí odkazy NN_Kapitola.md#kotva na routy — kotvy musí odpovídat
// nadpisům stejně jako headingSlug() v src/utils/manual.ts.
const dir = new URL('../../manual/', import.meta.url)
const slug = text => text.trim().toLowerCase().replace(/[^\p{L}\p{N}\s-]/gu, '').replace(/\s/g, '-')

test('manual cross-links point to existing chapters and headings', async () => {
  const files = (await readdir(dir)).filter(f => /^\d{2}_.+\.md$/.test(f))
  assert.ok(files.length >= 10)
  const anchors = new Map()
  const sources = new Map()
  for (const f of files) {
    const src = await readFile(new URL(f, dir), 'utf8')
    sources.set(f, src)
    anchors.set(f, new Set([...src.matchAll(/^#{1,4} (.+)$/gm)].map(m => slug(m[1]))))
  }
  const broken = []
  for (const [f, src] of sources) {
    for (const [, target, hash] of src.matchAll(/\]\(((?:\d{2}_[^)#]+\.md)?)(#[^)]+)?\)/g)) {
      const file = target || f
      if (!anchors.has(file)) broken.push(`${f}: ${target}`)
      else if (hash && !anchors.get(file).has(decodeURIComponent(hash.slice(1)))) broken.push(`${f}: ${file}${hash}`)
    }
  }
  assert.deepEqual(broken, [])
})

test('manual page is routed and linked from the menu', async () => {
  const router = await readFile(new URL('../src/router/index.ts', import.meta.url), 'utf8')
  const layout = await readFile(new URL('../src/components/layout/AppLayout.vue', import.meta.url), 'utf8')
  assert.match(router, /path: 'manual\/:chapter\?'/)
  assert.match(layout, /to: '\/manual'/)
})
