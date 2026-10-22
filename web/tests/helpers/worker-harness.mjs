import { readFile } from 'node:fs/promises'
import vm from 'node:vm'

export async function loadWorkerSource(name) {
  return readFile(new URL(`../../public/${name}`, import.meta.url), 'utf8')
}

export function createWorker(source, { cached = {}, existingCaches = [], windows = [] } = {}) {
  const listeners = new Map()
  const fetchCalls = []
  const cachePutCalls = []
  const deletedCaches = []
  const navigatedClients = []
  const pending = []
  let cacheOpenCount = 0
  let unregisterCount = 0

  const networkResponse = { ok: true, type: 'basic', source: 'network' }
  networkResponse.clone = () => networkResponse

  const context = {
    URL,
    console,
    fetch: async (...args) => {
      fetchCalls.push(args)
      return networkResponse
    },
    caches: {
      keys: async () => existingCaches,
      delete: async (name) => {
        deletedCaches.push(name)
        return true
      },
      open: async () => {
        cacheOpenCount += 1
        return {
          match: async (request) => cached[request.url],
          put: async (...args) => {
            cachePutCalls.push(args)
          },
        }
      },
    },
    self: {
      location: { origin: 'https://portfolio.test' },
      registration: {
        unregister: async () => {
          unregisterCount += 1
          return true
        },
      },
      clients: {
        claim: async () => undefined,
        matchAll: async () => windows.map((url) => ({
          url,
          navigate: (target) => navigatedClients.push(target),
        })),
      },
      skipWaiting: async () => undefined,
      addEventListener: (type, listener) => listeners.set(type, listener),
    },
  }

  vm.runInNewContext(source, context)

  return {
    networkResponse,
    fetchCalls,
    cachePutCalls,
    deletedCaches,
    navigatedClients,
    get cacheOpenCount() { return cacheOpenCount },
    get unregisterCount() { return unregisterCount },

    dispatchFetch(request) {
      let responsePromise
      listeners.get('fetch')({
        request,
        respondWith: (promise) => { responsePromise = promise },
        waitUntil: (promise) => { pending.push(promise) },
      })
      return responsePromise
    },

    async dispatchActivate() {
      const activated = []
      listeners.get('activate')({ waitUntil: (promise) => activated.push(promise) })
      await Promise.all(activated)
    },

    async settle() {
      await Promise.all(pending)
    },
  }
}
