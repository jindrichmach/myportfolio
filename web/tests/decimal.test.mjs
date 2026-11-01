import assert from 'node:assert/strict'
import test from 'node:test'
import { decimalMinus } from '../src/utils/decimal.ts'

test('decimalMinus odčítá stringy bez floatu a ořízne koncové nuly', () => {
  assert.equal(decimalMinus('0.500000000000000000', ''), '0.5')
  assert.equal(decimalMinus('0.3', '0.1'), '0.2')
  assert.equal(decimalMinus('1.000000000000000001', '0.000000000000000001'), '1')
  assert.equal(decimalMinus('12', '0,0005'), '11.9995')
  assert.equal(decimalMinus('0.0001', '0.0002'), '-0.0001')
  assert.equal(decimalMinus('5', '5'), '0')
  assert.equal(decimalMinus('1', 'abc'), null)
  assert.equal(decimalMinus('1', '0.0000000000000000001'), null, 'víc než 18 míst')
})
