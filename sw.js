const CACHE = 'intranet-v4';
const OFFLINE_URL = '/buuruljuut/offline.php';

const STATIC_ASSETS = [
  '/buuruljuut/dashboard.php',
  '/buuruljuut/assets/icons/icon-192.png',
  '/buuruljuut/assets/icons/icon-512.png',
  'https://cdn.tailwindcss.com?plugins=forms',
  'https://code.jquery.com/jquery-3.7.1.min.js',
  'https://fonts.googleapis.com/icon?family=Material+Icons+Outlined',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(cache => cache.addAll(STATIC_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;

  e.respondWith(
    fetch(e.request)
      .then(res => {
        const clone = res.clone();
        caches.open(CACHE).then(cache => cache.put(e.request, clone));
        return res;
      })
      .catch(() => caches.match(e.request).then(cached => cached || caches.match(OFFLINE_URL)))
  );
});
