import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'
import ts from 'typescript'
import { ref, watch, nextTick } from 'vue'

const source = await readFile(new URL('../src/pages/Login.vue', import.meta.url), 'utf8')
const autoSubmit = source.slice(source.indexOf('let pendingTotpSubmit'), source.indexOf('onMounted(async'))

function setup() {
  let requests = 0
  const state = {
    ref, watch,
    totp: ref(''),
    turnstile: { token: ref('') },
    totpRequired: ref(true),
    auth: { loading: false },
    passwordlessBusy: ref(false),
    passkeyFlow: ref(null),
    captchaRequired: ref(false),
    loginForm: ref({ requestSubmit() { requests++ } }),
  }
  vm.runInNewContext(autoSubmit, state)
  return { ...state, requests: () => requests }
}

test('typing submits only after six digits, including leading zero', async () => {
  const s = setup()
  for (const code of ['0', '01', '012', '0123', '01234']) {
    s.totp.value = code
    await nextTick()
    assert.equal(s.requests(), 0)
  }
  s.totp.value = '012345'
  await nextTick()
  assert.equal(s.requests(), 1)
})

test('paste submits a complete code, but rejects non-digits and wrong lengths', async () => {
  const s = setup()
  for (const code of ['12345x', '12345', '1234567']) {
    s.totp.value = code
    await nextTick()
    assert.equal(s.requests(), 0)
  }
  s.totp.value = '123456'
  await nextTick()
  assert.equal(s.requests(), 1)
})

test('waits for captcha and does not retry when captcha refreshes after failure', async () => {
  const s = setup()
  s.captchaRequired.value = true
  s.totp.value = '123456'
  await nextTick()
  assert.equal(s.requests(), 0)
  s.turnstile.token.value = 'synthetic-token'
  await nextTick()
  assert.equal(s.requests(), 1)
  s.turnstile.token.value = ''
  await nextTick()
  s.turnstile.token.value = 'synthetic-new-token'
  await nextTick()
  assert.equal(s.requests(), 1)
  s.totp.value = ''
  await nextTick()
  s.totp.value = '654321'
  await nextTick()
  assert.equal(s.requests(), 2)
})

test('clearing an incomplete attempt while waiting cancels automatic submission', async () => {
  const s = setup()
  s.captchaRequired.value = true
  s.totp.value = '123456'
  await nextTick()
  s.totp.value = '12345'
  await nextTick()
  s.turnstile.token.value = 'synthetic-token'
  await nextTick()
  assert.equal(s.requests(), 0)
})

test('does not submit during another login or outside the TOTP step', async () => {
  for (const block of [s => { s.auth.loading = true }, s => { s.passwordlessBusy.value = true },
    s => { s.passkeyFlow.value = {} }, s => { s.totpRequired.value = false }]) {
    const s = setup()
    block(s)
    s.totp.value = '123456'
    await nextTick()
    assert.equal(s.requests(), 0)
  }
})

test('Enter during automatic login does not start a second request', async () => {
  let requests = 0
  let finish
  const state = {
    auth: {
      loading: false,
      async login() {
        requests++
        this.loading = true
        await new Promise(resolve => { finish = resolve })
        this.loading = false
      },
    },
    passwordlessBusy: ref(false), passkeyFlow: ref(null), captchaRequired: ref(false),
    turnstile: { token: ref('') },
    error: ref(''), otpInfo: ref(''), mfaMethods: ref([]),
    email: ref('test@example.invalid'), password: ref('synthetic-password'),
    totp: ref('012345'), emailOtp: ref(''), recoveryCode: ref(''), rememberDevice: ref(false),
    router: { push() {} }, pendingTotpSubmit: true,
  }
  vm.createContext(state)
  const submitSource = source.slice(source.indexOf('async function submit()'), source.indexOf('async function loginWithPasskey()'))
  vm.runInContext(ts.transpileModule(submitSource, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText, state)
  const automatic = state.submit()
  await state.submit()
  assert.equal(requests, 1)
  assert.equal(state.pendingTotpSubmit, false)
  finish()
  await automatic
})
