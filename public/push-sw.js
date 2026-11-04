/*
 * Lumberjack's service worker for browser push notifications. Registered from the
 * profile settings with the site root as its scope, so a click can focus or open any
 * Lumberjack page. It does nothing else: no caching, no offline pages.
 */

self.addEventListener('push', (event) => {
    let message = {};

    try {
        message = event.data ? event.data.json() : {};
    } catch {
        message = { title: event.data ? event.data.text() : 'Lumberjack' };
    }

    event.waitUntil(
        (async () => {
            // A Lumberjack tab in front already shows it as a toast, if wanted.
            const tabs = await self.clients.matchAll({ type: 'window' });

            if (
                tabs.some(
                    (tab) => tab.focused && tab.visibilityState === 'visible',
                )
            ) {
                return;
            }

            await self.registration.showNotification(
                message.title || 'Lumberjack',
                {
                    body: message.body,
                    icon: message.icon,
                    tag: message.tag,
                    data: message.data || {},
                },
            );
        })(),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(
        (event.notification.data && event.notification.data.url) || '/',
        self.location.origin,
    ).href;

    event.waitUntil(
        (async () => {
            const tabs = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const same = tabs.find((tab) => tab.url === url);

            if (same) {
                return same.focus();
            }

            // An open Lumberjack tab goes to the page; without one, a new tab opens it.
            const open = tabs.find(
                (tab) => new URL(tab.url).origin === self.location.origin,
            );

            if (open) {
                try {
                    const focused = await open.focus();

                    return await focused.navigate(url);
                } catch {
                    // A tab opened before the worker took over cannot be navigated by it.
                }
            }

            return self.clients.openWindow(url);
        })(),
    );
});
