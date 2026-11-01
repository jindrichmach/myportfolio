import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const src = new URL('../src/', import.meta.url)
const read = path => readFile(new URL(path, src), 'utf8')

test('hlášky HTML5 validace mají klíče v cs i en a composable je zapnutý v App.vue', async () => {
  const composable = await read('composables/useValidationMessages.ts')
  const keys = [...new Set([...composable.matchAll(/'validation\.([a-z_]+)'/g)].map(m => m[1]))]
  assert.ok(keys.length >= 10)
  for (const locale of ['cs', 'en']) {
    const messages = JSON.parse(await read(`i18n/${locale}.json`)).validation
    for (const key of keys) assert.equal(typeof messages?.[key], 'string', `${locale}: validation.${key}`)
  }
  assert.match(await read('App.vue'), /useValidationMessages\(\)/)
})

test('tooltip při najetí na neplatné pole je přeložený (Chrome jinak ukáže validationMessage prohlížeče)', async () => {
  const composable = await read('composables/useValidationMessages.ts')
  assert.match(composable, /addEventListener\('mouseover', onHover, true\)/)
  assert.match(composable, /removeEventListener\('mouseover', onHover, true\)/)
  assert.match(composable, /el\.title = text/)
})
