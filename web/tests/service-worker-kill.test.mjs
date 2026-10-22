import assert from 'node:assert/strict'
import test from 'node:test'

import { createWorker, loadWorkerSource } from './helpers/worker-harness.mjs'

const source = await loadWorkerSource('service-worker.kill.js')

test('kill switch purges every MyPortfolio cache and unregisters itself', async () => {
  const worker = createWorker(source, {
    existingCaches: ['myportfolio-static-v0', 'myportfolio-static-v1', 'other-app-cache'],
    windows: ['https://invoice.test/invoices'],
  })

  await worker.dispatchActivate()

  assert.deepEqual(worker.deletedCaches, ['myportfolio-static-v0', 'myportfolio-static-v1'])
  assert.equal(worker.unregisterCount, 1)
  assert.deepEqual(worker.navigatedClients, ['https://invoice.test/invoices'])
})

test('kill switch registers no fetch handler', () => {
  const worker = createWorker(source)

  assert.throws(() => worker.dispatchFetch({
    url: 'https://invoice.test/assets/app-deadbeef.js',
    method: 'GET',
    destination: 'script',
  }))
})
