const CACHE_NAME = 'goo-envios-v1';
const urlsToCache = [
  '/',
  '/index.php',
  '/login.php'
];

// Instalación del Service Worker
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        return cache.addAll(urlsToCache);
      })
  );
  self.skipWaiting();
});

// Activación y limpieza de cachés viejas
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  self.clients.claim();
});

// Estrategia de Fetch: Network First (Priorizamos red, luego caché)
// Esto asegura que la base de datos y la sincronización sigan funcionando en tiempo real.
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') {
    return; // No cachear peticiones POST (como actualizaciones de GPS o aceptar pedidos)
  }

  event.respondWith(
    fetch(event.request).catch(() => {
      return caches.match(event.request);
    })
  );
});
