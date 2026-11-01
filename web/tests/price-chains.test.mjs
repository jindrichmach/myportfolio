import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

// Formulář instrumentu (Nová investice) ukazuje identifikátory podle řetězce zdrojů — PRICE_CHAINS
// v src/utils/instrumentForm.ts musí odpovídat PriceService::CHAINS v API.
const parse = (src, start) => {
  const block = src.slice(src.indexOf(start))
  const body = block.slice(0, block.indexOf('}'))
  return Object.fromEntries([...body.matchAll(/'?(\w+)'?\s*(?:=>|:)\s*\[([^\]]*)\]/g)]
    .map(([, key, list]) => [key, [...list.matchAll(/'(\w+)'/g)].map(m => m[1])]))
}

test('PRICE_CHAINS ve frontendu odpovídá PriceService::CHAINS', async () => {
  const php = await readFile(new URL('../../api/src/Service/Price/PriceService.php', import.meta.url), 'utf8')
  const ts = await readFile(new URL('../src/utils/instrumentForm.ts', import.meta.url), 'utf8')
  const api = parse(php, 'private const CHAINS')
  assert.ok(Object.keys(api).length >= 4)
  assert.deepEqual(parse(ts, 'export const PRICE_CHAINS'), api)
})
