import assert from 'node:assert/strict'
import test from 'node:test'

import {
  fromBase64Url,
  requestOptionsFromJson,
  toBase64Url,
} from '../src/security/webauthn.ts'

test('WebAuthn binary values round-trip as unpadded base64url', () => {
  const bytes = Uint8Array.from([0, 1, 2, 127, 128, 254, 255])
  const encoded = toBase64Url(bytes.buffer)

  assert.equal(encoded, 'AAECf4D-_w')
  assert.doesNotMatch(encoded, /=/)
  assert.deepEqual([...fromBase64Url(encoded)], [...bytes])
})

test('request options decode unpadded challenge and credential IDs', () => {
  const options = requestOptionsFromJson({
    challenge: 'AQI',
    allowCredentials: [{ type: 'public-key', id: '_-4' }],
    userVerification: 'required',
  })

  assert.deepEqual([...new Uint8Array(options.challenge)], [1, 2])
  assert.deepEqual(
    [...new Uint8Array(options.allowCredentials?.[0]?.id ?? new ArrayBuffer(0))],
    [255, 238],
  )
  assert.equal(options.userVerification, 'required')
})

test('discoverable request options keep an empty credential allow-list', () => {
  const options = requestOptionsFromJson({
    challenge: 'AQI',
    allowCredentials: [],
    userVerification: 'required',
  })

  assert.deepEqual(options.allowCredentials, [])
})
