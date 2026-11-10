/* Mfuko POS service worker: instant repeat loads + offline shell for the sell screen.
   Static assets: stale-while-revalidate. PHP pages/API: network-first (never cached except the sell shell). */
const CACHE = 'mfuko-v2';
const SHELL = ['assets/css/mfuko.css', 'assets/css/sell.css', 'assets/js/mfuko.js', 'assets/js/sell.js', 'assets/img/logo.svg', 'assets/img/favicon.svg', 'assets/img/icon-192.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const req = e.request; if (req.method !== 'GET') return;
  const url = new URL(req.url); if (url.origin !== location.origin) return;
  if (url.pathname.includes('/assets/') || url.pathname.includes('/uploads/products/')) {
    e.respondWith(caches.open(CACHE).then(async c => {
      const hit = await c.match(req, { ignoreSearch: true });
      const net = fetch(req).then(r => { if (r.ok) c.put(req, r.clone()); return r; }).catch(() => hit);
      return hit || net;
    }));
    return;
  }
  if (url.searchParams.get('p') === 'sell' && req.mode === 'navigate') {
    e.respondWith(fetch(req).then(r => { if (r.ok) caches.open(CACHE).then(c => c.put('sell-shell', r.clone())); return r; })
      .catch(() => caches.match('sell-shell')));
  }
});
