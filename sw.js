// sw.js - AgroDon Service Worker v5 (Bulletproof)

self.addEventListener('install', (event) => {
    // Prisilno i trenutno preuzmi kontrolu
    self.skipWaiting(); 
});

self.addEventListener('activate', (event) => {
    // Obriši apsolutno sve stare predmemorije da ne smetaju
    event.waitUntil(caches.keys().then(keys => Promise.all(
        keys.map(key => caches.delete(key))
    )));
    self.clients.claim(); 
});

// Prazan Fetch listener: OBAVEZAN je da bi aplikacija radila kao PWA, 
// ali s obzirom da vraća 'return', preglednik će sam učitavati stranice 
// i Service Worker se više nikada neće srušiti s 'TypeError' greškom.
self.addEventListener('fetch', (event) => {
    return; 
});

// PUSH NOTIFICATIONS LISTENER
self.addEventListener('push', function(event) {
    console.log('[Service Worker] Push Received.');
    
    let payload = { title: '🐐 AgroDon', body: 'Nova obavijest s farme!', url: 'https://dontv.shop/KozaFarm/' };
    
    if (event.data) {
        try {
            // Parsira čisti JSON iz cron_alerts.php
            payload = event.data.json();
        } catch (e) {
            payload.body = event.data.text();
        }
    }

    const options = {
        body: payload.body,
        icon: 'icon-192.png',
        badge: 'icon-192.png', 
        vibrate: [200, 100, 200, 100, 200],
        data: { url: payload.url || 'https://dontv.shop/KozaFarm/' }
    };

    event.waitUntil(self.registration.showNotification(payload.title || '🐐 AgroDon', options));
});

// NOTIFICATION CLICK LISTENER
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    event.waitUntil(clients.openWindow(event.notification.data.url || 'https://dontv.shop/KozaFarm/'));
});
