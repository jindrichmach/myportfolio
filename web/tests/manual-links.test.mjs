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

async function vueFiles(d) {
  const out = []
  for (const e of await readdir(d, { withFileTypes: true })) {
    const url = new URL(e.name + (e.isDirectory() ? '/' : ''), d)
    if (e.isDirectory()) out.push(...await vueFiles(url))
    else if (e.name.endsWith('.vue')) out.push(url)
  }
  return out
}

test('odkazy z aplikace do manuálu (ManualLink, /manual/…) míří na existující kapitolu a nadpis', async () => {
  const anchors = new Map()
  for (const f of (await readdir(dir)).filter(f => /^\d{2}_.+\.md$/.test(f))) {
    const src = await readFile(new URL(f, dir), 'utf8')
    anchors.set(f.replace(/\.md$/, ''), new Set([...src.matchAll(/^#{1,4} (.+)$/gm)].map(m => slug(m[1]))))
  }
  const links = []
  for (const file of await vueFiles(new URL('../src/', import.meta.url))) {
    const src = await readFile(file, 'utf8')
    for (const [, target] of src.matchAll(/<ManualLink\b[^>]*\sto="([^"]+)"/g)) links.push(target)
    for (const [, target] of src.matchAll(/to="\/manual\/([^"]+)"/g)) links.push(target)
  }
  assert.ok(links.length >= 6)
  const broken = links.filter((l) => {
    const [chapter, hash] = l.split('#')
    return !anchors.has(chapter) || (hash !== undefined && !anchors.get(chapter).has(hash))
  })
  assert.deepEqual(broken, [])
})

test('manual page is routed and linked from the menu', async () => {
  const router = await readFile(new URL('../src/router/index.ts', import.meta.url), 'utf8')
  const layout = await readFile(new URL('../src/components/layout/AppLayout.vue', import.meta.url), 'utf8')
  assert.match(router, /path: 'manual\/:chapter\?'/)
  assert.match(layout, /to: '\/manual'/)
})
