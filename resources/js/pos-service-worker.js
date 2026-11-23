const configuration = __POS_SHELL_CONFIG__;
const cacheName = `fox-hema-pos-shell-v1-${configuration.version}`;
const shellKey = `${configuration.terminal}/__neutral_shell__`;
const assetUrls = Object.keys(configuration.assets);
const unavailable = () => new Response('<!doctype html><meta charset="utf-8"><title>POS offline</title><h1>POS offline terminal unavailable</h1><p>Reconnect and retry; locally saved sales remain on this device.</p>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } });

async function digest(response) {
    const bytes = await crypto.subtle.digest('SHA-256', await response.clone().arrayBuffer());
    return Array.from(new Uint8Array(bytes), (value) => value.toString(16).padStart(2, '0')).join('');
}

async function complete(cache) {
    for (const path of assetUrls) {
        const response = await cache.match(path);
        if (!response || await digest(response) !== configuration.assets[path]) return false;
    }
    const shell = await cache.match(shellKey);
    return shell && await shell.text() === configuration.shell;
}

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(cacheName);
        try {
            for (const path of assetUrls) {
                const response = await fetch(path, { credentials: 'omit', cache: 'no-store', redirect: 'error' });
                if (!response.ok || await digest(response) !== configuration.assets[path]) throw new Error('Incomplete POS build');
                await cache.put(path, response);
            }
            await cache.put(shellKey, new Response(configuration.shell, { headers: { 'Content-Type': 'text/html; charset=utf-8' } }));
            if (!await complete(cache)) throw new Error('Incomplete POS shell');
        } catch (error) {
            await caches.delete(cacheName);
            throw error;
        }
    })());
});

// Browser lifecycle activation only. Old caches remain available for existing clients; business storage is never touched.
self.addEventListener('message', (event) => {
    if (event.data?.type !== 'POS_SHELL_STATUS' || !event.ports[0]) return;
    event.waitUntil((async () => {
        const ready = await complete(await caches.open(cacheName));
        event.ports[0].postMessage({ ready: Boolean(ready), version: configuration.version });
    })());
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin || event.request.method !== 'GET') return;
    if (event.request.mode === 'navigate' && url.pathname === configuration.terminal) {
        event.respondWith((async () => {
            try {
                // Authentication redirects and HTTP failures are authoritative; only network failure uses the cached terminal.
                return await fetch(new Request(event.request, { cache: 'no-store' }));
            } catch {
                const cache = await caches.open(cacheName);
                return await complete(cache) ? await cache.match(shellKey) : unavailable();
            }
        })());
    } else if (!url.search && Object.hasOwn(configuration.assets, url.pathname)) {
        event.respondWith((async () => {
            const response = await (await caches.open(cacheName)).match(url.pathname);
            if (response && await digest(response) === configuration.assets[url.pathname]) return response;
            try {
                const fresh = await fetch(url.pathname, { credentials: 'omit', cache: 'no-store', redirect: 'error' });
                if (fresh.ok && await digest(fresh) === configuration.assets[url.pathname]) {
                    await (await caches.open(cacheName)).put(url.pathname, fresh.clone());
                    return fresh;
                }
            } catch { /* Missing assets leave the shell unavailable until a verified copy can be fetched. */ }
            return new Response('POS asset unavailable; reconnect.', { status: 503 });
        })());
    }
});
