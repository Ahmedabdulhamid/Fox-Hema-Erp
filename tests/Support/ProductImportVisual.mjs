import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { readFile, writeFile, mkdtemp, rm } from 'node:fs/promises';
import { resolve, join, sep } from 'node:path';
import { tmpdir } from 'node:os';

test('rendered import page supports RTL, LTR, real thumbnails, drop events and mobile layout', { timeout: 60000 }, async () => {
    const root = resolve('storage/framework/testing/product-import-ui');
    const publicRoot = resolve('public');
    const manifest = JSON.parse(await readFile(join(publicRoot, 'build/manifest.json'), 'utf8'));
    const theme = manifest['resources/css/filament/admin/theme.css'].file;
    const server = createServer(async (request, response) => {
        try {
            const pathname = new URL(request.url, 'http://localhost').pathname;
            if (/^\/(ar|en)$/.test(pathname)) {
                const locale = pathname.slice(1);
                const content = await readFile(join(root, locale + '.html'), 'utf8');
                response.setHeader('Content-Type', 'text/html; charset=utf-8');
                response.end(`<!doctype html><html lang="${locale}" dir="${locale === 'ar' ? 'rtl' : 'ltr'}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/${theme}"><style>body { margin:0; background:#f4f7fb; font-family:'Segoe UI',Tahoma,Arial,sans-serif } .visual-test-content { max-width:1240px; margin:auto; padding:24px } @media(max-width:760px) { .visual-test-content {padding:12px} }</style></head><body><div class="visual-test-content">${content}</div></body></html>`);
                return;
            }
            const path = resolve(publicRoot, '.' + pathname);
            if (!path.startsWith(publicRoot + sep) || !pathname.startsWith('/build/')) {
                response.writeHead(404).end();
                return;
            }
            response.setHeader('Content-Type', path.endsWith('.css') ? 'text/css' : 'application/octet-stream');
            response.end(await readFile(path));
        } catch {
            response.writeHead(404).end();
        }
    });
    await new Promise((done) => server.listen(0, '127.0.0.1', done));
    const profile = await mkdtemp(join(tmpdir(), 'product-import-visual-'));
    const executable = process.env.PRODUCT_IMPORT_CHROMIUM;
    assert.ok(executable, 'Set PRODUCT_IMPORT_CHROMIUM to an installed standalone Chromium executable.');
    const chrome = spawn(executable, ['--headless', '--disable-gpu', '--no-first-run', '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1', '--user-data-dir=' + profile, 'about:blank'], { windowsHide: true });
    let socket;
    try {
        const endpoint = await new Promise((done, reject) => {
            const timeout = setTimeout(() => reject(new Error('Chromium startup timed out.')), 15000);
            chrome.stderr.on('data', (chunk) => {
                const match = chunk.toString().match(/DevTools listening on (ws:\/\/[^\s]+)/);
                if (match) { clearTimeout(timeout); done(match[1]); }
            });
            chrome.on('error', reject);
        });
        socket = new WebSocket(endpoint);
        await new Promise((done, reject) => { socket.addEventListener('open', done, { once: true }); socket.addEventListener('error', reject, { once: true }); });
        let sequence = 0;
        const pending = new Map();
        socket.addEventListener('message', ({ data }) => {
            const message = JSON.parse(data);
            if (!pending.has(message.id)) return;
            const { done, reject, timeout } = pending.get(message.id);
            pending.delete(message.id);
            clearTimeout(timeout);
            message.error ? reject(new Error(message.error.message)) : done(message.result);
        });
        const send = (method, params = {}, sessionId) => new Promise((done, reject) => {
            const id = ++sequence;
            const timeout = setTimeout(() => { pending.delete(id); reject(new Error(method + ' timed out')); }, 15000);
            pending.set(id, { done, reject, timeout });
            socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
        });
        const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
        const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
        const page = (method, params = {}) => send(method, params, sessionId);
        await page('Page.enable');
        const evaluate = async (expression) => {
            const result = await page('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
            return result.result.value;
        };
        for (const [locale, width, height] of [['ar', 1280, 900], ['en', 1280, 900], ['ar', 390, 1200]]) {
            await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 760 });
            await page('Page.navigate', { url: `http://127.0.0.1:${server.address().port}/${locale}` });
            await evaluate(`new Promise(resolve => { const ready = async () => { if (document.readyState !== 'complete') { setTimeout(ready, 30); return; } await document.fonts.ready; requestAnimationFrame(() => requestAnimationFrame(resolve)); }; ready(); })`);
            const layout = await evaluate(`(() => { const main = document.querySelector('.fox-import__main').getBoundingClientRect(); const aside = document.querySelector('.fox-import__aside').getBoundingClientRect(); return { direction: getComputedStyle(document.querySelector('.fox-import')).direction, overflow: document.documentElement.scrollWidth > innerWidth, mainX: main.x, asideX: aside.x, mainBottom: main.bottom, asideY: aside.y, images: [...document.querySelectorAll('.fox-import__thumbnail img')].every(image => image.complete && image.naturalWidth > 0) }; })()`);
            assert.equal(layout.direction, locale === 'ar' ? 'rtl' : 'ltr');
            assert.equal(layout.overflow, false, 'Page must not overflow at ' + width);
            assert.equal(layout.images, true, 'Real uploaded thumbnails must load');
            if (width > 760) assert.ok(locale === 'ar' ? layout.mainX > layout.asideX : layout.mainX < layout.asideX);
            else assert.ok(layout.asideY >= layout.mainBottom, 'Resources stack below uploads on mobile');
            const drop = await evaluate(`(() => { const host = document.querySelector('.fox-import'); const controller = Function('return (' + host.getAttribute('x-data') + ')')(); const input = host.querySelector('input[multiple]'); const files = new DataTransfer(); files.items.add(new File(['image fixture'], 'original-name.png', {type:'image/png'})); let changes = 0; input.addEventListener('change', () => changes++); controller.dropFiles({dataTransfer:files}, input); return {name:input.files[0].name, changes, dragging:controller.dragging}; })()`);
            assert.deepEqual(drop, { name: 'original-name.png', changes: 1, dragging: null });
            await evaluate(`document.querySelector('.fox-import__guide summary').click()`);
            assert.equal(await evaluate(`document.querySelector('.fox-import__guide').open`), true);
            await evaluate(`document.querySelector('.fox-import__guide summary').click()`);
            const screenshot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
            await writeFile(join(root, `${locale}-${width}.png`), Buffer.from(screenshot.data, 'base64'));
            console.log(JSON.stringify({ locale, width, ...layout }));
        }
        await send('Browser.close');
    } finally {
        socket?.close();
        chrome.kill();
        await new Promise((done) => server.close(done));
        assert.ok(resolve(profile).startsWith(resolve(tmpdir()) + sep) && profile.includes('product-import-visual-'), 'Only delete the generated test browser profile.');
        await rm(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
    }
});
