const CACHE_NAME = 'goo-envios-v2';
const urlsToCache = [
  'login.php',
  'assets/img/goologo.png'
];

// Instalación del Service Worker
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(urlsToCache).catch(err => {
        console.warn('SW: Error precacheando recursos opcionales:', err);
      });
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

// Estrategia de Fetch Segura para Tiempo Real y PWA
self.addEventListener('fetch', event => {
  // 1. Ignorar cualquier método que no sea GET (POST, PUT, DELETE pasan directo a la red)
  if (event.request.method !== 'GET') {
    return;
  }

  const url = new URL(event.request.url);

  // 2. Ignorar APIs en tiempo real, GPS, polling y llamadas dinámicas para que NUNCA se bloqueen ni cacheen
  if (url.pathname.includes('/api_') || url.pathname.includes('cron_')) {
    return;
  }

  // 3. Network-First con fallback seguro (evita el error Uncaught TypeError: Failed to convert value to Response)
  event.respondWith(
    fetch(event.request)
      .then(response => {
        return response;
      })
      .catch(async () => {
        // Si la red falla, intentar buscar en caché
        const cachedResponse = await caches.match(event.request);
        if (cachedResponse) {
          return cachedResponse;
        }

        // Si es navegación de página y no hay red ni caché, intentar mostrar login o mensaje amigable
        if (event.request.mode === 'navigate') {
          const fallbackLogin = await caches.match('login.php');
          if (fallbackLogin) {
            return fallbackLogin;
          }
        }

        // Respuesta de respaldo válida para evitar rechazo de promesa en el navegador
        return new Response(
          '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Sin Conexión</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f8fafc;color:#1e293b;text-align:center;padding:20px;}h2{color:#2563eb;}button{background:#2563eb;color:#fff;border:none;padding:12px 24px;border-radius:12px;font-weight:bold;margin-top:15px;cursor:pointer;}</style></head><body><div><h2>📡 Sin Conexión</h2><p>No se pudo conectar con el servidor. Revisa tu señal de internet.</p><button onclick="window.location.reload()">Reintentar</button></div></body></html>',
          {
            status: 503,
            statusText: 'Service Unavailable',
            headers: new Headers({ 'Content-Type': 'text/html; charset=utf-8' })
          }
        );
      })
  );
});
