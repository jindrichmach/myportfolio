import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)

test('mobile navigation links the signed-in user to the profile', async () => {
  const layout = await readFile(new URL('components/layout/AppLayout.vue', root), 'utf8')
  const mobileFooter = layout.match(/<!-- Mobile only: profil[\s\S]*?<\/aside>/)?.[0] || ''

  assert.match(mobileFooter, /<RouterLink[\s\S]*?to="\/profile\/password"/)
  assert.match(mobileFooter, /@click="mobileOpen = false"/)
  assert.match(mobileFooter, /\{\{ auth\.user\?\.name \}\}/)
  assert.match(mobileFooter, /:class="canLockSession \? 'grid-cols-2' : 'grid-cols-1'"/)
  assert.match(mobileFooter, /v-if="canLockSession"[\s\S]*?sessionSecurity\.lock[\s\S]*?logout/)
})
