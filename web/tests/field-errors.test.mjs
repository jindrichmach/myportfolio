import assert from 'node:assert/strict'
import test from 'node:test'
import { isDecimal, useFieldErrors } from '../src/composables/useFieldErrors.ts'

const apiError = fields => ({ response: { data: { error: { message: 'Validace selhala.', fields } } } })
const input = value => ({ target: { value } })
const number = v => (isDecimal(v) ? null : 'Zadej číslo.')

test('isDecimal přijme totéž co backend Decimal::parse', () => {
  for (const ok of ['', '13660', '13 660,5', '0.015', '-2', '1 000']) assert.ok(isDecimal(ok), ok)
  for (const bad of ['1,3660,00', 'abc', '13.', '1e5', '1.2.3']) assert.ok(!isDecimal(bad), bad)
})

test('chyby z API patří k polím; oprava pole vrátí do výchozího stavu, blur neplatnou hodnotu znovu označí', () => {
  const fe = useFieldErrors(n => `f-${n}`, { amount: number })
  assert.equal(fe.fromApi(apiError({ amount: ['Neplatné číslo.'], note: 'Moc dlouhé.' })), true)
  assert.deepEqual(fe.attrs('amount')['aria-invalid'], true)
  assert.equal(fe.attrs('amount')['aria-describedby'], 'f-amount-error')

  fe.attrs('amount').onInput(input('1,3'))
  assert.equal(fe.invalid('amount'), false, 'platná hodnota při psaní chybu smaže')
  fe.attrs('amount').onInput(input('1,3,'))
  assert.equal(fe.invalid('amount'), false, 'rozepsaná hodnota při psaní nebliká')
  fe.attrs('amount').onBlur(input('1,3,'))
  assert.deepEqual(fe.errors.amount, ['Zadej číslo.'])

  fe.attrs('note').onInput(input('x'))
  assert.equal(fe.invalid('note'), false, 'pole bez validátoru se úpravou vyčistí')
  assert.equal(fe.attrs('note')['aria-describedby'], undefined)
})

test('odpověď bez chyb polí vrátí false (hláška zůstane pod formulářem)', () => {
  const fe = useFieldErrors(n => n)
  assert.equal(fe.fromApi(new Error('Network Error')), false)
  assert.equal(fe.fromApi(apiError(undefined)), false)
})
