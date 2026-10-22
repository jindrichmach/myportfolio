// Kill-switch pro PWA service worker.
//
// Jakmile je service worker jednou zaregistrovaný, žije v prohlížečích dál i po
// odstranění z kódu — nainstalovaní klienti si ho drží. Tenhle soubor je cesta
// zpět: odregistruje SW, smaže jeho cache a obnoví otevřená okna.
//
// Nasazení (po `pnpm build`, před publikací):
//   cp web/public/service-worker.kill.js web/dist/service-worker.js
//
// `service-worker.js` se servíruje s `no-store` (viz .htaccess / web.config /
// docker/nginx.conf), takže prohlížeč náhradu vezme při první další návštěvě.
// Registrace v `main.ts` se nechává být — nainstaluje tenhle soubor, který se
// hned zase odregistruje. Až se prokáže, že klienti jsou čistí, jde registrace
// odstranit i z `main.ts`.

const STATIC_CACHE_PREFIX = 'myportfolio-static-'

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting())
})

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const cacheNames = await caches.keys()
    await Promise.all(
      cacheNames
        .filter((name) => name.startsWith(STATIC_CACHE_PREFIX))
        .map((name) => caches.delete(name)),
    )

    await self.registration.unregister()

    const clients = await self.clients.matchAll({ type: 'window' })
    clients.forEach((client) => client.navigate(client.url))
  })())
})

// Žádný fetch handler — requesty jdou rovnou na síť.
