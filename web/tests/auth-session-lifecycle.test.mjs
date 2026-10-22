import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const client = await readFile(new URL('api/client.ts', root), 'utf8')
const auth = await readFile(new URL('stores/auth.ts', root), 'utf8')
const layout = await readFile(new URL('components/layout/AppLayout.vue', root), 'utf8')
const forcedSetup = await readFile(new URL('pages/ForcedMfaSetup.vue', root), 'utf8')
const sessionSecurity = await readFile(new URL('stores/sessionSecurity.ts', root), 'utf8')

test('MFA reauthentication redirects explicitly without redirecting every 401 response', () => {
  assert.match(client, /status === 401 && \[[\s\S]*'mfa_reauthentication_required'[\s\S]*\]\.includes\(code\)/)
  assert.doesNotMatch(client, /if \(status === 401\) \{\s*window\.location\.href = '\/login'/)
})

test('logout clears private state even when the server request fails and isolates its retry CSRF', () => {
  assert.match(auth, /const requestCsrfToken = logoutRetryCsrfToken \|\| csrfToken\.value/)
  assert.match(auth, /if \(requestCsrfToken\) setCsrfToken\(requestCsrfToken\)/)
  assert.match(auth, /catch \(error\) \{\s*logoutRetryCsrfToken = requestCsrfToken[\s\S]*throw error/)
  assert.match(auth, /finally \{\s*clearPrivateState\(\)/)
  assert.match(auth, /clearPrivateState[\s\S]*setCsrfToken\(null\)[\s\S]*lockedSession\.value = null/)
})

test('logout failures are surfaced by both private layout and forced MFA setup', () => {
  assert.match(layout, /catch \{[\s\S]*sessionSecurity\.error = 'logout_failed'[\s\S]*toast\.error\(t\('auth\.logout_failed'\)\)/)
  assert.match(layout, /try \{[\s\S]*sessionSecurity\.clear\(\)[\s\S]*router\.replace\('\/login'\)[\s\S]*catch \{[\s\S]*sessionSecurity\.markLocked\(\)/)
  assert.match(forcedSetup, /catch \{\s*error\.value = t\('auth\.logout_failed'\)/)
  assert.match(forcedSetup, /finally \{\s*sessionSecurity\.clear\(\)/)
})

test('manual lock is available only for a session with passkey unlock', () => {
  assert.match(layout, /canLockSession[\s\S]*unlock_methods\.includes\('passkey'\)/)
  assert.equal((layout.match(/v-if="canLockSession"/g) || []).length, 2)
  assert.match(
    sessionSecurity,
    /async function lock\(\) \{[\s\S]*!state\.value\.unlock_methods\.includes\('passkey'\)[\s\S]*return/,
  )
})
