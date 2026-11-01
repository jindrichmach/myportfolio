import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)

test('topbar user menu (desktop and mobile) links profile tabs, lock and logout', async () => {
  const layout = await readFile(new URL('components/layout/AppLayout.vue', root), 'utf8')
  const userMenu = layout.match(/<TopbarMenu[^>]*t\('nav\.user_menu'\)[\s\S]*?<\/TopbarMenu>/)?.[0] || ''

  assert.doesNotMatch(userMenu.split('\n')[0], /hidden/)
  assert.match(userMenu, /\{\{ auth\.user\?\.name \}\}/)
  assert.match(userMenu, /path: '\/profile', query: \{ tab: item\.tab \}/)
  assert.match(userMenu, /v-if="canLockSession"[\s\S]*?sessionSecurity\.lock\(\)[\s\S]*?logout\(\)/)
  assert.doesNotMatch(layout, /Mobile only: profil/)
})
