// Firebase Messaging Service Worker for VY AI CRM
importScripts('https://www.gstatic.com/firebasejs/9.22.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.22.0/firebase-messaging-compat.js');

const firebaseConfig = {
  apiKey: "AIzaSyCSKm7fQtto31AmYrNH1MRey3bcsjkBoek",
  authDomain: "vy-crm.firebaseapp.com",
  projectId: "vy-crm",
  storageBucket: "vy-crm.firebasestorage.app",
  messagingSenderId: "1081193546240",
  appId: "1:1081193546240:web:4b61825cac088e7df04ee5",
  measurementId: "G-6QYXTXV5X9"
};

firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
  console.log('[firebase-messaging-sw.js] Received background message:', payload);
  const title = payload.notification?.title || payload.data?.title || 'VY AI CRM';
  const body = payload.notification?.body || payload.data?.body || '';

  const notificationOptions = {
    body: body,
    icon: payload.notification?.icon || '/images/logo.png',
    badge: '/images/logo.png',
    requireInteraction: true,
    data: payload.data || {}
  };

  return self.registration.showNotification(title, notificationOptions);
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow('/dashboard.php');
      }
    })
  );
});
