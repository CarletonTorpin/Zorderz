/**
 * The chat's notification worker ("Chat answers").
 *
 * Registered by Settings -> Notifications (the theme's zdz-push.js) at this folder's scope, so it controls no page:
 * it only shows the chat's notifications and opens the chat when one is pressed. The payload is
 * { title, body, url, tag } from ZANA_Push::send(); a newer notification with the same tag replaces the older one.
 * The url is always this site's; anything else opens the site's home page.
 */
'use strict';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  var title = (data && typeof data.title === 'string' && data.title) ? data.title.slice(0, 80) : 'Chat';
  var options = {
    body: (data && typeof data.body === 'string') ? data.body.slice(0, 200) : '',
    tag: (data && typeof data.tag === 'string' && data.tag) ? data.tag.slice(0, 64) : 'zana-chat',
    renotify: true,
    data: { url: (data && typeof data.url === 'string') ? data.url : '/' }
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var want = '/';
  try {
    var u = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin);
    want = u.origin === self.location.origin ? u.href : self.location.origin + '/';
  } catch (e) { want = self.location.origin + '/'; }
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        var c = list[i];
        if (c.url.indexOf(self.location.origin) === 0 && 'focus' in c) {
          if ('navigate' in c) { c.navigate(want); }
          return c.focus();
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(want) : null;
    })
  );
});
