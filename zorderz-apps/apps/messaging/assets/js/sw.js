/**
 * TS Internal Messaging — Service Worker
 *
 * Minimal surface: receive Web Push, render a notification, and on click
 * focus-or-open the deep-link URL the server attached.
 *
 * The payload shape is fixed by ZIM_Notifications::fire_group():
 *   { title, body, url, tag, conversation_id }
 *
 * `tag` is `zim-{conversation_id}`, which means a second push for the
 * same conversation REPLACES the first notification rather than stacking —
 * the desired behaviour when the user hasn't read the first one yet.
 */

self.addEventListener('install', (event) => {
  // Take over as soon as the old worker goes away.
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    // Malformed payload — show a generic notification rather than drop it.
    payload = { title: 'New message', body: '' };
  }

  const title = payload.title || 'New message';
  const options = {
    body: payload.body || '',
    tag: payload.tag || 'zim-generic',
    renotify: true,
    data: {
      url: payload.url || '/',
      conversation_id: payload.conversation_id || 0,
    },
    // Badge/icon optional — supplied by server if present.
    icon: payload.icon || undefined,
    badge: payload.badge || undefined,
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil((async () => {
    const allClients = await self.clients.matchAll({
      type: 'window',
      includeUncontrolled: true,
    });

    // If any tab is already on the target URL (or same origin + has focus),
    // prefer focusing that tab instead of opening a new one.
    const target = new URL(url, self.location.origin);
    for (const client of allClients) {
      const clientUrl = new URL(client.url);
      if (clientUrl.origin === target.origin) {
        if (client.url === target.href) {
          return client.focus();
        }
      }
    }
    // Fall back to opening a new window.
    if (self.clients.openWindow) {
      return self.clients.openWindow(target.href);
    }
  })());
});

// Optional: respond to subscription-change events by POSTing back up to the
// server. Browsers fire this when the push subscription is rotated out from
// under us (e.g. Chrome decides the endpoint is stale). We have no credentials
// in the SW, so the best we can do is notify the page to re-subscribe.
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window' });
    for (const c of clients) {
      c.postMessage({ type: 'zim-push-resubscribe' });
    }
  })());
});
