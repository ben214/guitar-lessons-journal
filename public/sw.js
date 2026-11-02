// Offline support: the app opens without internet; data syncs when back online.
const CACHE = 'glb-v2';
const SHELL = ['./', './index.html', './manifest.webmanifest', './icon.svg', './icon-192.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).catch(() => {})); self.skipWaiting(); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))); self.clients.claim(); });
self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin || u.pathname.endsWith('api.php') || u.pathname.includes('/data/')) return;
  // network first, so updates show up right away; cached copy when offline
  e.respondWith(fetch(e.request).then(r => { if (r.ok) { const cp = r.clone(); caches.open(CACHE).then(c => c.put(e.request, cp)); } return r; })
    .catch(() => caches.match(e.request).then(r => r || caches.match('./index.html'))));
});

// phone notifications sent by the server (cron.php)
self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'יומן שיעורי גיטרה', {
    body: d.body || '', icon: 'icon-192.png', badge: 'badge-72.png', tag: d.tag || 'glb', renotify: true,
    lang: 'he', dir: 'rtl', data: { url: d.url || './' }
  }));
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL(e.notification.data && e.notification.data.url || './', self.registration.scope).href;
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(cs => {
    for (const c of cs) if (c.url.startsWith(self.registration.scope)) { return c.focus().then(w => w && w.navigate ? w.navigate(url) : w); }
    return clients.openWindow(url);
  }));
});
