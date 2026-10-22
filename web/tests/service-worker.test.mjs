import assert from 'node:assert/strict'
import test from 'node:test'

import { createWorker, loadWorkerSource } from './helpers/worker-harness.mjs'

const source = await loadWorkerSource('service-worker.js')

test('API requests always bypass caches', async () => {
  for (const pathname of ['/api', '/api/auth/me']) {
    const worker = createWorker(source)
    const result = worker.dispatchFetch({
      url: `https://portfolio.test${pathname}`,
      method: 'GET',
      destination: '',
    })

    assert.ok(result)
    await result
    assert.equal(worker.cacheOpenCount, 0)
    assert.equal(worker.fetchCalls.length, 1)
    assert.equal(worker.fetchCalls[0][1].cache, 'no-store')
  }
})

test('hashed Vite assets use cache-first', async () => {
  const worker = createWorker(source)
  const request = {
    url: 'https://portfolio.test/assets/app-deadbeef.js',
    method: 'GET',
    destination: 'script',
  }

  await worker.dispatchFetch(request)

  assert.equal(worker.cacheOpenCount, 1)
  assert.equal(worker.fetchCalls.length, 1)
  assert.equal(worker.cachePutCalls.length, 1)
  assert.equal(worker.cachePutCalls[0][0], request)
})

test('cached hashed asset is served without hitting the network', async () => {
  const url = 'https://portfolio.test/assets/app-deadbeef.js'
  const worker = createWorker(source, { cached: { [url]: { source: 'cache' } } })

  const response = await worker.dispatchFetch({ url, method: 'GET', destination: 'script' })

  assert.equal(response.source, 'cache')
  assert.equal(worker.fetchCalls.length, 0)
})

test('unhashed /styles/ asset revalidates in the background', async () => {
  const url = 'https://portfolio.test/styles/logo.svg'
  const worker = createWorker(source, { cached: { [url]: { source: 'cache' } } })

  const response = await worker.dispatchFetch({ url, method: 'GET', destination: 'style' })

  // Odpověď přijde okamžitě z cache…
  assert.equal(response.source, 'cache')

  // …ale na pozadí se stáhne a uloží aktuální verze, takže se změna
  // propíše bez ručního bumpu STATIC_CACHE.
  await worker.settle()
  assert.equal(worker.fetchCalls.length, 1)
  assert.equal(worker.cachePutCalls.length, 1)
})

test('uncached /styles/ asset falls back to the network response', async () => {
  const worker = createWorker(source)
  const request = {
    url: 'https://portfolio.test/styles/logo.svg',
    method: 'GET',
    destination: 'image',
  }

  const response = await worker.dispatchFetch(request)

  assert.equal(response, worker.networkResponse)
  assert.equal(worker.fetchCalls.length, 1)
  assert.equal(worker.cachePutCalls.length, 1)
})

test('non-GET requests are never cached', async () => {
  const worker = createWorker(source)
  const result = worker.dispatchFetch({
    url: 'https://portfolio.test/styles/invoice.css',
    method: 'POST',
    destination: 'style',
  })

  assert.equal(result, undefined)
  assert.equal(worker.cacheOpenCount, 0)
})

test('HTML navigation stays on the network without service-worker caching', () => {
  const worker = createWorker(source)
  const result = worker.dispatchFetch({
    url: 'https://portfolio.test/invoices',
    method: 'GET',
    destination: 'document',
  })

  assert.equal(result, undefined)
  assert.equal(worker.cacheOpenCount, 0)
  assert.equal(worker.fetchCalls.length, 0)
})

test('activation drops stale MyPortfolio caches only', async () => {
  const worker = createWorker(source, {
    existingCaches: ['myportfolio-static-v0', 'myportfolio-static-v1', 'other-app-cache'],
  })

  await worker.dispatchActivate()

  assert.deepEqual(worker.deletedCaches, ['myportfolio-static-v0'])
})
