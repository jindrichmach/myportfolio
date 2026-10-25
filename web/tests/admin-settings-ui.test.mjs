import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const src = new URL('../src/', import.meta.url)
const read = (path) => readFile(new URL(path, src), 'utf8')

test('admin settings page replaces the separate SMTP page', async () => {
  const router = await read('router/index.ts')
  assert.match(router, /path: 'admin\/settings',\s+name: 'admin-settings'[^\n]*adminOnly: true/)
  assert.doesNotMatch(router, /path: 'admin\/smtp'/)
  const page = await read('pages/admin/Settings.vue')
  assert.match(page, /TAB_KEYS = \['smtp', 'appearance'\] as const/)
  assert.match(page, /useTabQuery<SettingsTab>\(TAB_KEYS, 'smtp'\)/)

  const layout = await read('components/layout/AppLayout.vue')
  assert.match(layout, /to: '\/admin\/settings'/)
  assert.doesNotMatch(layout, /to: '\/admin\/smtp'/)
})

test('admin settings messages exist in both locales', async () => {
  const cs = JSON.parse(await read('i18n/cs.json'))
  const en = JSON.parse(await read('i18n/en.json'))
  assert.deepEqual(Object.keys(cs.admin_settings).sort(), Object.keys(en.admin_settings).sort())
  assert.equal(typeof cs.nav.settings, 'string')
  assert.equal(typeof en.nav.settings, 'string')
})
