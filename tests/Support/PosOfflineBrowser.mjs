import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, rm, mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import { deflateSync } from 'node:zlib';
import { createHash } from 'node:crypto';

const outboxStatus = (action) => ({ seal: 'sealed', freeze: 'frozen', verify: 'verified' }[action] ?? 'registered');

function pngChunk(type, data) {
    const body = Buffer.concat([Buffer.from(type), data]);
    let crc = 0xffffffff;
    for (const byte of body) {
        crc ^= byte;
        for (let bit = 0; bit < 8; bit += 1) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
    }
    const length = Buffer.alloc(4);
    const checksum = Buffer.alloc(4);
    length.writeUInt32BE(data.length);
    checksum.writeUInt32BE((crc ^ 0xffffffff) >>> 0);

    return Buffer.concat([length, body, checksum]);
}

const pixelImage = Buffer.concat([
    Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
    pngChunk('IHDR', Buffer.from([0, 0, 0, 1, 0, 0, 0, 1, 8, 6, 0, 0, 0])),
    pngChunk('IDAT', deflateSync(Buffer.from([0, 220, 220, 220, 255]))),
    pngChunk('IEND', Buffer.alloc(0)),
]);

async function launchBrowser(profile) {
    const browserPath = process.env.POS_TEST_BROWSER ?? [
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
    ].find(existsSync);
    assert.ok(browserPath, 'A native Chromium browser is required.');

    const child = spawn(browserPath, [
        '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-extensions', '--remote-debugging-address=127.0.0.1',
        '--remote-debugging-port=0', `--user-data-dir=${profile}`,
    ], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    let output = '';
    let socket;
    child.stderr.on('data', (chunk) => { output += chunk; });
    const stopBrowser = async () => {
        if (child.exitCode !== null || child.signalCode !== null) return;
        child.kill();
        await new Promise((resolve) => {
            if (child.exitCode !== null || child.signalCode !== null) {
                resolve();
                return;
            }
            child.once('exit', resolve);
        });
    };

    try {
        const endpoint = await new Promise((resolve, reject) => {
            const deadline = Date.now() + 20000;
            let settled = false;
            const finish = (error, value) => {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                error ? reject(error) : resolve(value);
            };
            const timer = setTimeout(() => {
                finish(new Error(`Browser startup timed out${output ? `: ${output.trim()}` : ''}`));
            }, 20000);
            child.once('error', (error) => finish(error));
            child.once('exit', (code, signal) => {
                finish(new Error(`Browser exited before startup (code ${code}, signal ${signal})${output ? `: ${output.trim()}` : ''}`));
            });

            const poll = async () => {
                if (settled) return;
                if (Date.now() >= deadline) {
                    finish(new Error(`Browser startup timed out${output ? `: ${output.trim()}` : ''}`));
                    return;
                }
                try {
                    const activePort = await readFile(join(profile, 'DevToolsActivePort'), 'utf8');
                    const [port] = activePort.trim().split(/\r?\n/);
                    const response = await fetch(`http://127.0.0.1:${port}/json/version`);
                    if (response.ok) {
                        const version = await response.json();
                        if (typeof version.webSocketDebuggerUrl === 'string') {
                            finish(null, version.webSocketDebuggerUrl);
                            return;
                        }
                    }
                } catch {
                    // The browser may not have created its debugging endpoint yet.
                }
                setTimeout(poll, 100);
            };

            poll();
        });

        socket = new WebSocket(endpoint);
        await new Promise((resolve, reject) => {
            socket.addEventListener('open', resolve, { once: true });
            socket.addEventListener('error', reject, { once: true });
        });
        const pending = new Map();
        const diagnostics = [];
        const sequence = { value: 0 };
        socket.addEventListener('message', ({ data }) => {
            const message = JSON.parse(data);
            if (message.method === 'Runtime.exceptionThrown') {
                diagnostics.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
            } else if (message.method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(message.params.type)) {
                diagnostics.push(message.params.args.map((argument) => argument.value ?? argument.description ?? '').join(' '));
            }
            const callback = pending.get(message.id);
            if (!callback) return;
            pending.delete(message.id);
            if (message.error) callback.reject(new Error(message.error.message));
            else callback.resolve(message.result);
        });
        const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
            const id = ++sequence.value;
            pending.set(id, { resolve, reject });
            socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
        });
        const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
        const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
        const call = (method, params = {}) => send(method, params, sessionId);
        await call('Runtime.enable');
        await call('Page.enable');
        await call('Network.enable');

        return {
            async resize(width, height = 1000) {
                await call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
            },
            async pdf(width) {
                const result = await call('Page.printToPDF', { printBackground: true, paperWidth: width / 25.4,
                    paperHeight: 297 / 25.4, marginTop: 3 / 25.4, marginBottom: 3 / 25.4,
                    marginLeft: 3 / 25.4, marginRight: 3 / 25.4, displayHeaderFooter: false });
                return Buffer.from(result.data, 'base64');
            },
            async screenshot() {
                const result = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
                return Buffer.from(result.data, 'base64');
            },
            async navigate(url) {
                await call('Page.navigate', { url });
            },
            async evaluate(expression) {
                let timer;
                let result;
                try {
                    result = await Promise.race([
                        call('Runtime.evaluate', {
                            expression,
                            awaitPromise: true,
                            returnByValue: true,
                        }),
                        new Promise((resolve, reject) => {
                            timer = setTimeout(() => reject(new Error(`Browser evaluation timed out: ${expression.slice(0, 100)}`)), 15000);
                        }),
                    ]);
                } finally {
                    clearTimeout(timer);
                }
                assert.ok(!result.exceptionDetails, result.exceptionDetails?.exception?.description ?? result.exceptionDetails?.text);
                return result.result.value;
            },
            async setOffline(offline) {
                await call('Network.emulateNetworkConditions', {
                    offline,
                    latency: 0,
                    downloadThroughput: -1,
                    uploadThroughput: -1,
                });
            },
            async wait(expression, label) {
                const deadline = Date.now() + 15000;
                while (Date.now() < deadline) {
                    if (await this.evaluate(expression)) return;
                    await new Promise((resolve) => setTimeout(resolve, 75));
                }
                assert.fail(`Timed out: ${label}${diagnostics.length ? `; browser: ${diagnostics.join(' | ')}` : ''}`);
            },
            async close() {
                await Promise.race([
                    send('Browser.close').catch(() => {}),
                    new Promise((resolve) => setTimeout(resolve, 1000)),
                ]);
                socket.close();
                await stopBrowser();
            },
        };
    } catch (error) {
        socket?.close();
        await stopBrowser();
        throw error;
    }
}

async function removeBrowserProfile(profile) {
    for (let attempt = 0; attempt < 5; attempt += 1) {
        try {
            await rm(profile, { recursive: true, force: true });
            return;
        } catch (error) {
            if (!['EBUSY', 'EPERM'].includes(error.code) || attempt === 4) throw error;
            await new Promise((resolve) => setTimeout(resolve, 200 * (attempt + 1)));
        }
    }
}

function terminalPage(scope, sync) {
    const scopeJson = JSON.stringify(scope);
    const syncUrl = sync ? 'data-pos-sync-url="/sync"' : '';

    return `<!doctype html>
        <main data-pos-terminal>
            <section data-pos-local data-pos-context="test-${Object.values(scope).join(':')}" data-pos-scope='${scopeJson}' data-pos-device-code="BR01-POS01" data-pos-local-labels='{}'
                data-pos-csrf="test" data-pos-outbox-url="/outbox" ${syncUrl}></section>
        </main>
        <script type="module">
            document.cookie = 'fox_pos_context=test-${Object.values(scope).join(':')}; Path=/; SameSite=Strict';
            import { attachOfflineTerminal } from '/pos-offline.js';
            const scope = ${scopeJson};
            const wire = {
                async offlineCatalogPage(page) {
                    return {
                        scope,
                        context: 'test-' + Object.values(scope).join(':'),
                        catalog: {
                            items: [{
                                id: 1, name: 'Test shirt', sku: 'SHIRT-1', image: '/images/test-product.svg', discounts: [],
                                variants: [{
                                    id: 9, label: 'Blue / M', sku: 'SHIRT-1-M', barcode: null,
                                    image: '/images/test-variant.svg', price: '10.0000', available: '5.0000', taxes: [],
                                }],
                            }, {
                                id: 2, name: 'Test trousers', sku: 'TROUSERS-1', image: '/images/test-product.svg', discounts: [],
                                variants: [{
                                    id: 10, label: 'Black / M', sku: 'TROUSERS-1-M', barcode: null,
                                    price: '15.0000', available: '3.0000', taxes: [],
                                }],
                            }, {
                                id: 3, name: 'Image-free product', sku: 'NO-IMAGE', discounts: [],
                                variants: [{
                                    id: 11, label: 'Default', sku: 'NO-IMAGE-1', barcode: null,
                                    price: '5.0000', available: '2.0000', taxes: [],
                                }],
                            }],
                            page, last_page: 1,
                        },
                        payment_methods: [{ id: 11, name: 'Cash', type: 'cash' }],
                    };
                },
            };
            await attachOfflineTerminal(document.querySelector('[data-pos-terminal]'), wire, scope);
            globalThis.testReady = true;
        </script>`;
}

function offlineShellPage(scope) {
    const scopeJson = JSON.stringify(scope);

    return `<!doctype html>
        <main data-pos-terminal data-pos-offline-shell>
            <section data-pos-local data-pos-context="test-${Object.values(scope).join(':')}" data-pos-scope='${scopeJson}' data-pos-local-labels='{}'></section>
        </main>
        <script type="module">
            import { attachOfflineTerminal } from '/pos-offline.js';
            await attachOfflineTerminal(document.querySelector('[data-pos-terminal]'), null, ${scopeJson}, {
                offlineShell: true,
            });
            globalThis.testReady = true;
        </script>`;
}

test('offline sale is stored across shell reload and synchronized once on reconnect', { timeout: 90000 }, async () => {
    const scope = { branch_id: 1, device_id: 2, session_id: 3, cashier_id: 4 };
    const source = await readFile(new URL('../../resources/js/pos-offline.js', import.meta.url));
    let synchronizedEnvelope = null;
    let syncCount = 0;
    const server = createServer((request, response) => {
        if (request.url === '/pos-offline.js') {
            response.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8' });
            response.end(source);
            return;
        }
        if (request.url?.startsWith('/images/')) {
            response.writeHead(200, { 'Content-Type': 'image/png' });
            response.end(pixelImage);
            return;
        }
        if (request.method === 'POST' && request.url === '/sync') {
            let body = '';
            request.on('data', (chunk) => { body += chunk; });
            request.on('end', () => {
                synchronizedEnvelope = JSON.parse(body);
                syncCount += 1;
                response.writeHead(200, { 'Content-Type': 'application/json' });
                response.end(JSON.stringify({
                    client_transaction_id: synchronizedEnvelope.client_transaction_id,
                    status: 'completed',
                    result: { invoice_number: 'INV-TEST-1' },
                }));
            });
            return;
        }
        if (request.method === 'POST' && request.url === '/outbox') {
            let body = '';
            request.on('data', (chunk) => { body += chunk; });
            request.on('end', () => {
                const envelope = JSON.parse(body);
                response.writeHead(200, { 'Content-Type': 'application/json' });
                response.end(JSON.stringify({ outbox_id: envelope.outbox_id,
                    status: outboxStatus(envelope.action) }));
            });
            return;
        }
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end(request.url === '/shell' ? offlineShellPage(scope) : terminalPage(scope, request.url === '/sync-terminal'));
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const profile = await mkdtemp(join(tmpdir(), 'fox-hema-pos-offline-'));
    let browser;

    try {
        browser = await launchBrowser(profile);
        await browser.navigate(`${origin}/terminal`);
        await browser.wait('globalThis.testReady === true', 'online catalog capture');
        await browser.wait(
            `new Promise((resolve) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => {
                    const query = request.result.transaction('offline_catalog_v1', 'readonly').objectStore('offline_catalog_v1').get('1:2:3:4');
                    query.onsuccess = () => resolve(Object.values(query.result.offline_images ?? {}).filter((image) => image.type === 'image/webp').length === 2);
                };
            })`,
            'cached product and variant thumbnails',
        );
        assert.match(await browser.evaluate(`document.querySelectorAll('.fox-pos-product-card')[0].querySelector('img').src`), /^blob:/);
        assert.match(await browser.evaluate(`document.querySelectorAll('.fox-pos-product-card')[2].querySelector('img').src`), /^data:image\/svg\+xml/);
        await browser.setOffline(true);
        await browser.wait("document.querySelector('[data-pos-local]').hidden === false", 'offline checkout mode');
        await browser.evaluate("document.querySelector('[data-local-products] .fox-pos-add-button').click()");
        await browser.evaluate("document.querySelector('[data-local-complete]').click()");
        await browser.wait(
            `new Promise((resolve) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => {
                    const transaction = request.result.transaction('offline_sales_v1', 'readonly');
                    const query = transaction.objectStore('offline_sales_v1').getAll();
                    query.onsuccess = () => resolve(query.result.length === 1
                        && query.result[0].status === 'queued'
                        && query.result[0].local_reference === 'BR01-POS01-S0001'
                        && /^[0-9a-f-]{36}$/i.test(query.result[0].client_transaction_id));
                };
            })`,
            'durable queued sale',
        );
        await browser.wait(
            "document.querySelector('[data-pos-local] .fox-pos-product-price span').textContent === 'In stock: 4'",
            'local stock reduced after the first offline sale',
        );
        await browser.evaluate(`(() => {
            document.querySelector('[data-pos-local] .fox-pos-add-button').click();
            document.querySelector('[data-pos-local] [data-local-checkout]').click();
            document.querySelector('[data-pos-local] [data-local-complete]').click();
        })()`);
        await browser.wait(
            `new Promise((resolve, reject) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => {
                    const transaction = request.result.transaction('offline_sales_v1', 'readonly');
                    const query = transaction.objectStore('offline_sales_v1').index('scopeKey').getAll('1:2:3:4');
                    query.onsuccess = () => resolve(query.result.length === 2
                        && query.result.every((sale) => sale.status === 'queued')
                        && query.result.map((sale) => sale.local_reference).sort().join(',') === 'BR01-POS01-S0001,BR01-POS01-S0002');
                    query.onerror = () => reject(query.error);
                };
                request.onerror = () => reject(request.error);
            })`,
            'sequential offline sales receive unique references',
        );
        await browser.evaluate(`(async () => {
            const secondScope = { branch_id: 1, device_id: 6, session_id: 7, cashier_id: 4 };
            const database = await new Promise((resolve, reject) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });
            await new Promise((resolve, reject) => {
                const transaction = database.transaction('offline_catalog_v1', 'readwrite');
                const store = transaction.objectStore('offline_catalog_v1');
                store.put({
                    scopeKey: '1:6:7:4',
                    offline_outbox: { id: crypto.randomUUID(), registered: true, sealed: false },
                    scope: secondScope,
                    products: [{
                        id: 1, name: 'Test shirt', sku: 'SHIRT-1', discounts: [],
                        variants: [{
                            id: 9, label: 'Blue / M', sku: 'SHIRT-1-M', barcode: null,
                            price: '10.0000', available: '5.0000', taxes: [],
                        }],
                    }],
                    payment_methods: [{ id: 11, name: 'Cash', type: 'cash' }],
                    captured_at: new Date().toISOString(),
                });
                transaction.oncomplete = resolve;
                transaction.onerror = () => reject(transaction.error);
            });
            const cachedCatalog = await new Promise((resolve, reject) => {
                const transaction = database.transaction('offline_catalog_v1', 'readonly');
                const request = transaction.objectStore('offline_catalog_v1').get('1:6:7:4');
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });
            const root = document.createElement('main');
            root.dataset.posTerminal = '';
            const panel = document.createElement('section');
            panel.dataset.posLocal = '';
            panel.dataset.posScope = JSON.stringify(secondScope);
            panel.dataset.posDeviceCode = 'BR01-POS02';
            panel.dataset.posCsrf = 'test';
            root.append(panel);
            document.body.append(root);
            await globalThis.FoxHemaPosOffline.attachOfflineTerminal(root, null, secondScope, { offlineShell: true });
            const addButton = root.querySelector('[data-local-products] .fox-pos-add-button');
            const checkoutButton = root.querySelector('[data-local-checkout]');
            const completeButton = root.querySelector('[data-local-complete]');
            if (!addButton || !checkoutButton || !completeButton) {
                const productGrid = root.querySelector('[data-local-products]');
                throw new Error('Second device UI incomplete (add=' + Boolean(addButton)
                    + ', checkout=' + Boolean(checkoutButton) + ', complete=' + Boolean(completeButton)
                    + ', cards=' + root.querySelectorAll('.fox-pos-product-card').length
                    + ', catalog=' + (productGrid?.textContent ?? 'missing')
                    + ', stored-products=' + (cachedCatalog?.products?.length ?? -1)
                    + ', stored-variants=' + (cachedCatalog?.products?.[0]?.variants?.length ?? -1)
                    + '): ' + (panel.querySelector('[data-local-status]')?.textContent ?? 'no status'));
            }
            addButton.click();
            checkoutButton.click();
            completeButton.click();
        })()`);
        await browser.wait(
            `new Promise((resolve, reject) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => {
                    const transaction = request.result.transaction('offline_sales_v1', 'readonly');
                    const query = transaction.objectStore('offline_sales_v1').getAll();
                    query.onsuccess = () => resolve(query.result.length === 3
                        && query.result.some((sale) => sale.local_reference === 'BR01-POS02-S0001')
                        && new Set(query.result.map((sale) => sale.local_reference)).size === 3);
                    query.onerror = () => reject(query.error);
                };
                request.onerror = () => reject(request.error);
            })`,
            'device-specific offline references',
        );

        await browser.evaluate(`(async () => {
            const scopes = [
                { branch_id: 2, device_id: 21, session_id: 31, cashier_id: 4 },
                { branch_id: 2, device_id: 22, session_id: 32, cashier_id: 4 },
            ];
            const database = await new Promise((resolve, reject) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });
            await new Promise((resolve, reject) => {
                const transaction = database.transaction('offline_catalog_v1', 'readwrite');
                for (const scope of scopes) {
                    transaction.objectStore('offline_catalog_v1').put({
                        scopeKey: [scope.branch_id, scope.device_id, scope.session_id, scope.cashier_id].join(':'),
                        offline_outbox: { id: crypto.randomUUID(), registered: true, sealed: false },
                        scope,
                        products: [{
                            id: 1, name: 'Race test shirt', sku: 'RACE-1', discounts: [],
                            variants: [{
                                id: 9, label: 'Blue / M', sku: 'RACE-1-M', barcode: null,
                                price: '10.0000', available: '1.0000', taxes: [],
                            }],
                        }],
                        payment_methods: [{ id: 11, name: 'Cash', type: 'cash' }],
                        captured_at: new Date().toISOString(),
                    });
                }
                transaction.oncomplete = resolve;
                transaction.onerror = () => reject(transaction.error);
            });
            const roots = [];
            for (const scope of scopes) {
                const root = document.createElement('main');
                root.dataset.posTerminal = '';
                const panel = document.createElement('section');
                panel.dataset.posLocal = '';
                panel.dataset.posScope = JSON.stringify(scope);
                panel.dataset.posDeviceCode = 'RACE-POS';
                panel.dataset.posCsrf = 'test';
                root.append(panel);
                document.body.append(root);
                await globalThis.FoxHemaPosOffline.attachOfflineTerminal(root, null, scope, { offlineShell: true });
                root.querySelector('[data-local-products] .fox-pos-add-button').click();
                root.querySelector('[data-local-checkout]').click();
                roots.push(root);
            }
            roots[0].querySelector('[data-local-complete]').click();
            roots[1].querySelector('[data-local-complete]').click();
        })()`);
        await browser.wait(
            `new Promise((resolve, reject) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => {
                    const transaction = request.result.transaction('offline_sales_v1', 'readonly');
                    const query = transaction.objectStore('offline_sales_v1').index('branchId').getAll(2);
                    query.onsuccess = () => resolve(query.result.length === 1);
                    query.onerror = () => reject(query.error);
                };
                request.onerror = () => reject(request.error);
            })`,
            'concurrent terminals reserve a branch stock unit once',
        );
        await browser.wait(
            "Array.from(document.querySelectorAll('[data-pos-scope]')).filter((panel) => panel.dataset.posScope.includes('\"branch_id\":2')).some((panel) => panel.querySelector('[data-local-status]').textContent.includes('insufficient'))",
            'the losing concurrent checkout reports insufficient cached stock',
        );

        await browser.setOffline(false);
        await browser.navigate(`${origin}/shell`);
        await browser.wait("location.pathname === '/shell' && globalThis.testReady === true", 'cached shell ready');
        await browser.wait('globalThis.testReady === true', 'cached shell rehydration');
        await browser.wait(
            `new Promise((resolve) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => {
                    const transaction = request.result.transaction('offline_sales_v1', 'readonly');
                    const query = transaction.objectStore('offline_sales_v1').getAll();
                    query.onsuccess = () => resolve(query.result.length === 4
                        && query.result.some((sale) => sale.local_reference === 'BR01-POS01-S0001' && sale.status === 'queued'));
                };
            })`,
            'sale preserved after shell reload',
        );

        await browser.navigate(`${origin}/sync-terminal`);
        await browser.wait("location.pathname === '/sync-terminal' && globalThis.testReady === true", 'sync terminal ready');
        await browser.wait(
            `new Promise((resolve) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => {
                    const transaction = request.result.transaction('offline_sales_v1', 'readonly');
                    const query = transaction.objectStore('offline_sales_v1').getAll();
                    query.onsuccess = () => resolve(query.result.length === 4
                        && query.result.some((sale) => sale.local_reference === 'BR01-POS01-S0002'
                            && sale.status === 'completed'));
                };
            })`,
            'automatic synchronization on reconnect',
        );
        assert.ok(synchronizedEnvelope);
        assert.equal(syncCount, 2);
        assert.equal(synchronizedEnvelope.scope.session_id, scope.session_id);
        assert.equal(synchronizedEnvelope.sale.items[0].variant_id, 9);
        assert.match(synchronizedEnvelope.outbox_id, /^[0-9a-f-]{36}$/i);
    } finally {
        if (browser) await browser.close();
        await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
        await removeBrowserProfile(profile);
    }
});

test('offline identity changes deny stale scopes without deleting the owners pending records', { timeout: 90000 }, async () => {
    const scope = { branch_id: 1, device_id: 2, session_id: 3, cashier_id: 4 };
    const source = await readFile(new URL('../../resources/js/pos-offline.js', import.meta.url));
    let serverForgotOutbox = false;
    const server = createServer((request, response) => {
        if (request.url === '/pos-offline.js') {
            response.writeHead(200, { 'Content-Type': 'text/javascript' }); response.end(source);
        } else if (request.url?.startsWith('/images/')) {
            response.writeHead(200, { 'Content-Type': 'image/png' }); response.end(pixelImage);
        } else if (request.method === 'POST') {
            let body = '';
            request.on('data', (chunk) => { body += chunk; });
            request.on('end', () => {
                if (serverForgotOutbox && JSON.parse(body).action === 'verify') {
                    response.writeHead(409, { 'Content-Type': 'application/json' });
                    response.end(JSON.stringify({ message: 'Registered outbox absent from this server generation' }));
                    return;
                }
                response.writeHead(200, { 'Content-Type': 'application/json' });
                response.end(JSON.stringify({ outbox_id: JSON.parse(body).outbox_id, status: outboxStatus(JSON.parse(body).action) }));
            });
        } else {
            response.writeHead(200, { 'Content-Type': 'text/html' }); response.end(terminalPage(scope, false));
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const profile = await mkdtemp(join(tmpdir(), 'fox-hema-pos-identity-'));
    let browser;
    try {
        browser = await launchBrowser(profile);
        await browser.navigate(`http://127.0.0.1:${server.address().port}/terminal`);
        await browser.wait('globalThis.testReady === true', 'owner terminal');
        await browser.setOffline(true);
        await browser.evaluate(`document.querySelector('[data-local-products] .fox-pos-add-button').click();
            document.querySelector('[data-local-complete]').click()`);
        await browser.wait(`document.querySelector('[data-local-status]').textContent.includes('Queued for secure synchronization')`, 'owner sale');
        await browser.evaluate(`window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }))`);
        assert.equal(await browser.evaluate(`document.querySelector('[data-pos-terminal]').textContent.includes('Test shirt')`), false, 'Back-forward cache must not retain private POS UI');
        for (const context of ['', 'test-1:2:3:9', 'test-8:2:3:4', 'test-1:7:3:4', 'test-1:2:6:4']) {
            const leaked = await browser.evaluate(`(async () => {
                document.cookie = 'fox_pos_context=${context}; Path=/; SameSite=Strict';
                const root = document.createElement('main'); root.dataset.posTerminal = '';
                const panel = document.createElement('section'); panel.dataset.posLocal = '';
                panel.dataset.posContext = '${context || 'test-1:2:3:4'}'; panel.dataset.posScope = JSON.stringify(${JSON.stringify(scope)});
                root.append(panel);
                document.body.replaceChildren(root);
                await FoxHemaPosOffline.attachOfflineTerminal(root, null, ${JSON.stringify(scope)}, { offlineShell: true });
                return root.textContent.includes('Test shirt') || root.querySelector('[data-local-sales]')?.textContent.includes('BR01-POS01');
            })()`);
            assert.equal(Boolean(leaked), false, 'A missing or different authenticated context must not reveal the old catalog or receipt');
        }
        assert.equal(await browser.evaluate(`new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos', 10);
            r.onsuccess = () => {
                const q = r.result.transaction('offline_sales_v1', 'readonly').objectStore('offline_sales_v1').getAll();
                q.onsuccess = () => resolve(q.result.length === 1 && q.result[0].scope.cashier_id === 4 && q.result[0].status === 'queued');
            };
        })`), true);
        assert.equal(await browser.evaluate(`(async () => {
            document.cookie = 'fox_pos_context=test-1:2:3:4; Path=/; SameSite=Strict';
            const root = document.createElement('main'); root.dataset.posTerminal = '';
            const panel = document.createElement('section'); panel.dataset.posLocal = '';
            panel.dataset.posContext = 'test-1:2:3:4'; panel.dataset.posScope = JSON.stringify(${JSON.stringify(scope)});
            root.append(panel); document.body.replaceChildren(root);
            await FoxHemaPosOffline.attachOfflineTerminal(root, null, ${JSON.stringify(scope)}, { offlineShell: true });
            return root.textContent.includes('Test shirt') && root.textContent.includes('Queued for secure synchronization');
        })()`), true, 'The rightful owner can recover the original pending sale');
        serverForgotOutbox = true;
        await browser.setOffline(false);
        await browser.navigate(`http://127.0.0.1:${server.address().port}/terminal`);
        await browser.wait('globalThis.testReady === true', 'missing server outbox fails closed');
        assert.equal(await browser.evaluate(`document.querySelector('[data-pos-terminal]').textContent.includes('Test shirt')`), false);
        assert.ok(await browser.evaluate(`document.querySelector('[data-pos-terminal]').textContent.includes('no verified membership')`));
        assert.equal(await browser.evaluate(`new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos'); r.onsuccess = () => {
                const q = r.result.transaction('offline_sales_v1', 'readonly').objectStore('offline_sales_v1').getAll();
                q.onsuccess = () => { resolve(q.result.length === 1 && q.result[0].status === 'queued'); r.result.close(); };
            };
        })`), true, 'Database replacement must preserve historical transactions without adopting them');
    } finally {
        if (browser) await browser.close();
        await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
        await removeBrowserProfile(profile);
    }
});

test('offline cash follows the cart total and refuses an explicitly insufficient payment', { timeout: 90000 }, async () => {
    const scope = { branch_id: 2, device_id: 1, session_id: 5, cashier_id: 1 };
    const source = await readFile(new URL('../../resources/js/pos-offline.js', import.meta.url));
    const server = createServer((request, response) => {
        if (request.url === '/pos-offline.js') {
            response.writeHead(200, { 'Content-Type': 'text/javascript' }); response.end(source);
        } else if (request.url?.startsWith('/images/')) {
            response.writeHead(200, { 'Content-Type': 'image/png' }); response.end(pixelImage);
        } else if (request.method === 'POST') {
            let body = '';
            request.on('data', (chunk) => { body += chunk; });
            request.on('end', () => {
                const envelope = JSON.parse(body);
                response.writeHead(200, { 'Content-Type': 'application/json' });
                response.end(JSON.stringify({ outbox_id: envelope.outbox_id, status: outboxStatus(envelope.action) }));
            });
        } else {
            response.writeHead(200, { 'Content-Type': 'text/html' }); response.end(terminalPage(scope, false));
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const profile = await mkdtemp(join(tmpdir(), 'fox-hema-pos-cash-'));
    let browser;
    try {
        browser = await launchBrowser(profile);
        await browser.navigate(`http://127.0.0.1:${server.address().port}/terminal`);
        await browser.wait('globalThis.testReady === true', 'terminal ready');
        await browser.setOffline(true);
        await browser.evaluate(`document.querySelector('[data-local-products] .fox-pos-add-button').click();
            document.querySelector('[data-local-products] .fox-pos-add-button').click()`);
        assert.equal(await browser.evaluate(`document.querySelector('[data-local-cash-received]').value`), '20.0000');
        await browser.evaluate(`const input = document.querySelector('[data-local-cash-received]');
            input.value = '5'; input.dispatchEvent(new Event('input'));
            document.querySelector('[data-local-complete]').click()`);
        await browser.wait(`document.querySelector('[data-local-status]').textContent.includes('Cash received is less')`, 'short cash refused');
        assert.equal(await browser.evaluate(`new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos', 10);
            r.onsuccess = () => {
                const q = r.result.transaction('offline_sales_v1', 'readonly').objectStore('offline_sales_v1').count();
                q.onsuccess = () => resolve(q.result);
            };
        })`), 0);
        await browser.evaluate(`const correctedCashInput = document.querySelector('[data-local-cash-received]');
            correctedCashInput.value = '25'; correctedCashInput.dispatchEvent(new Event('input'));
            document.querySelector('[data-local-complete]').click()`);
        await browser.wait(`document.querySelector('[data-local-status]').textContent.includes('Queued for secure synchronization')`, 'valid cash queued');
        assert.deepEqual(await browser.evaluate(`new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos', 10);
            r.onsuccess = () => {
                const q = r.result.transaction('offline_sales_v1', 'readonly').objectStore('offline_sales_v1').getAll();
                q.onsuccess = () => resolve([q.result.length, q.result[0].sale.cash_received, q.result[0].sale.tenders[0].amount]);
            };
        })`), [1, '25', '20.0000']);
    } finally {
        if (browser) await browser.close();
        await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
        await removeBrowserProfile(profile);
    }
});

test('rejected offline discount preserves evidence and acknowledges terminal dispositions without sealing', { timeout: 90000 }, async () => {
    const scope = { branch_id: 2, device_id: 1, session_id: 6, cashier_id: 1 };
    const source = await readFile(new URL('../../resources/js/pos-offline.js', import.meta.url));
    const error = 'Choose an active discount available for this product.';
    const requests = [];
    const seals = [];
    let cancelledOnServer = false;
    const orphanId = '032a31a9-42bf-40c3-b633-7a89eebedeb1';
    const postedId = '7136240b-4aa3-427a-839f-0a87fe91a3e2';
    const page = terminalPage(scope, true)
        .replace("discounts: []", "discounts: [{ id: 17, name: 'Saved discount', value: '15.0000' }]")
        .replace("price: '10.0000'", "price: '299.0000'");
    const server = createServer((request, response) => {
        if (request.url === '/pos-offline.js') {
            response.writeHead(200, { 'Content-Type': 'text/javascript' });
            response.end(source);
        } else if (request.url?.startsWith('/images/')) {
            response.writeHead(200, { 'Content-Type': 'image/png' });
            response.end(pixelImage);
        } else if (request.method === 'POST') {
            let body = '';
            request.on('data', (chunk) => { body += chunk; });
            request.on('end', () => {
                const envelope = JSON.parse(body);
                if (request.url === '/sync') {
                    requests.push(envelope);
                    if (cancelledOnServer && envelope.client_transaction_id === orphanId && envelope.acknowledgement_only === true) {
                        response.writeHead(200, { 'Content-Type': 'application/json' });
                        response.end(JSON.stringify({ client_transaction_id: orphanId, status: 'archived',
                            result: { local_historical_orphan_archive: true, outbox_id: envelope.outbox_id,
                                disposition: 'historical_orphan_without_current_posting' } }));
                        return;
                    }
                    if (cancelledOnServer && envelope.acknowledgement_only === true) {
                        response.writeHead(200, { 'Content-Type': 'application/json' });
                        response.end(JSON.stringify({ client_transaction_id: envelope.client_transaction_id, status: 'cancelled',
                            result: { local_test_cancellation: true, outbox_id: envelope.outbox_id, reason: 'Authorized local test cancellation' } }));
                        return;
                    }
                    response.writeHead(422, { 'Content-Type': 'application/json' });
                    response.end(JSON.stringify({ message: error, errors: { 'cart.9.discount_id': [error] } }));
                } else {
                    if (envelope.action === 'seal') seals.push(envelope);
                    response.writeHead(200, { 'Content-Type': 'application/json' });
                    response.end(JSON.stringify({ outbox_id: envelope.outbox_id, status: outboxStatus(envelope.action) }));
                }
            });
        } else {
            response.writeHead(200, { 'Content-Type': 'text/html' });
            response.end(request.url === '/new-shift' ? terminalPage({ ...scope, session_id: 7 }, true) : page);
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const profile = await mkdtemp(join(tmpdir(), 'fox-hema-pos-discount-'));
    let browser;
    try {
        browser = await launchBrowser(profile);
        await browser.navigate(`http://127.0.0.1:${server.address().port}/terminal`);
        await browser.wait('globalThis.testReady === true', 'discount catalog');
        await browser.setOffline(true);
        await browser.evaluate(`document.querySelector('[data-local-products] .fox-pos-add-button').click();
            document.querySelector('[data-local-complete]').click()`);
        await browser.wait(`document.querySelector('[data-local-status]').textContent.includes('Queued for secure synchronization')`, 'saved discounted sale');
        await browser.evaluate(`globalThis.readDiscountSale = () => new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos');
            r.onsuccess = () => {
                const q = r.result.transaction('offline_sales_v1', 'readonly').objectStore('offline_sales_v1').getAll();
                q.onsuccess = () => { resolve(q.result[0]); r.result.close(); };
            };
        })`);
        await browser.evaluate(`new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos');
            r.onsuccess = () => {
                const tx = r.result.transaction('offline_sales_v1', 'readwrite');
                const store = tx.objectStore('offline_sales_v1');
                const q = store.getAll();
                q.onsuccess = () => store.put({ ...q.result[0], original_sale: {
                    ...q.result[0].sale, cash_received: '54.1500'
                } });
                tx.oncomplete = () => { r.result.close(); resolve(); };
            };
        })`);
        const original = await browser.evaluate('readDiscountSale()');
        assert.equal(original.quote.total_amount, '254.1500');
        assert.equal(original.sale.items[0].discount_id, 17);
        await browser.setOffline(false);
        await browser.wait(`readDiscountSale().then(s => s.status === 'conflict')`, 'server discount rejection');
        assert.equal(await browser.evaluate(`document.querySelector('[data-local-finish-offline]') === null`), true);
        const rejected = await browser.evaluate('readDiscountSale()');
        assert.equal(rejected.status, 'conflict');
        assert.ok(rejected.error.includes(error));
        for (const field of ['client_transaction_id', 'scope', 'scopeKey', 'outbox_id', 'sale', 'original_sale', 'quote', 'receipt_snapshot', 'created_at']) {
            assert.deepEqual(rejected[field], original[field], `Rejection must preserve ${field}`);
        }
        assert.ok(requests.length > 0);
        for (const envelope of requests) {
            assert.equal(envelope.client_transaction_id, original.client_transaction_id);
            assert.deepEqual(envelope.sale, original.sale);
            assert.deepEqual(envelope.quote, original.quote);
        }
        assert.equal(seals.length, 0, 'An invalid discount must never be force-sealed');
        cancelledOnServer = true;
        await browser.evaluate(`new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos');
            r.onsuccess = () => {
                const tx = r.result.transaction(['offline_sales_v1', 'offline_catalog_v1'], 'readwrite');
                const store = tx.objectStore('offline_sales_v1');
                const q = store.getAll();
                q.onsuccess = () => { const sale = q.result[0]; delete sale.outbox_id; sale.status = 'queued'; store.put(sale);
                    const catalogs = tx.objectStore('offline_catalog_v1'); const c = catalogs.get(sale.scopeKey);
                    c.onsuccess = () => catalogs.put({ ...c.result, offline_outbox: { ...c.result.offline_outbox, transaction_ids: [sale.client_transaction_id] } });
                };
                tx.oncomplete = () => { r.result.close(); resolve(); };
            };
        })`);
        await browser.evaluate(`window.dispatchEvent(new Event('online'))`);
        await browser.wait(`readDiscountSale().then(s => s.status === 'cancelled')`, 'verified cancellation acknowledgement');
        const cancelled = await browser.evaluate('readDiscountSale()');
        assert.equal(cancelled.outbox_id, undefined, 'Acknowledgement must not adopt the legacy sale into an outbox');
        for (const field of ['client_transaction_id', 'scope', 'scopeKey', 'sale', 'original_sale', 'quote', 'receipt_snapshot', 'created_at']) {
            assert.deepEqual(cancelled[field], original[field]);
        }
        const historical = { ...original, client_transaction_id: orphanId, status: 'completed',
            result: { invoice_number: 'HISTORICAL-ACK', sales_invoice_uuid: '8ec0da50-75db-5113-b989-0c4cfadbefd9' },
            synced_at: '2026-11-08T19:45:29.598Z' };
        delete historical.outbox_id;
        await browser.evaluate(`new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos'); r.onsuccess = () => {
                const tx = r.result.transaction(['offline_sales_v1', 'offline_catalog_v1'], 'readwrite');
                const sales = tx.objectStore('offline_sales_v1');
                sales.put(${JSON.stringify(historical)});
                sales.put({ ...${JSON.stringify(original)}, client_transaction_id: '${postedId}', status: 'completed', result: { invoice_number: 'CURRENT-POSTED' } });
                const catalogs = tx.objectStore('offline_catalog_v1'); const q = catalogs.getAll();
                q.onsuccess = () => { const c = q.result[0]; c.offline_outbox.transaction_ids = ['${orphanId}', '${original.client_transaction_id}', '${postedId}'].sort(); catalogs.put(c); };
                tx.oncomplete = () => { r.result.close(); resolve(); };
            };
        })`);
        await browser.navigate(`http://127.0.0.1:${server.address().port}/terminal`);
        await browser.wait('globalThis.testReady === true', 'reload after cancellation');
        await browser.wait(`new Promise(resolve => { const r = indexedDB.open('fox-hema-pos'); r.onsuccess = () => { const q = r.result.transaction('offline_sales_v1', 'readonly').objectStore('offline_sales_v1').get('${orphanId}'); q.onsuccess = () => { resolve(q.result?.status === 'archived'); r.result.close(); }; }; })`, 'historical disposition acknowledged');
        assert.equal(seals.length, 0);
        await browser.navigate(`http://127.0.0.1:${server.address().port}/terminal`);
        await browser.wait('globalThis.testReady === true', 'persisted terminal dispositions after reload');
        await browser.navigate(`http://127.0.0.1:${server.address().port}/new-shift`);
        await browser.wait('globalThis.testReady === true', 'independent new shift');
        await browser.setOffline(true);
        await browser.evaluate(`document.querySelector('[data-local-products] .fox-pos-add-button').click();
            document.querySelector('[data-local-complete]').click()`);
        await browser.wait(`document.querySelector('[data-local-status]').textContent.includes('Queued for secure synchronization')`, 'new-shift offline checkout');
        const saved = await browser.evaluate(`new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos'); r.onsuccess = () => {
                const q = r.result.transaction('offline_sales_v1', 'readonly').objectStore('offline_sales_v1').getAll();
                q.onsuccess = () => { resolve(q.result); r.result.close(); };
            };
        })`);
        assert.equal(saved.find(s => s.client_transaction_id === original.client_transaction_id).status, 'cancelled');
        const archived = saved.find(s => s.client_transaction_id === orphanId);
        assert.equal(archived.status, 'archived');
        for (const field of ['client_transaction_id', 'scope', 'scopeKey', 'sale', 'original_sale', 'quote', 'receipt_snapshot', 'created_at', 'result', 'synced_at']) {
            assert.deepEqual(archived[field], historical[field], `Archival preserves historical ${field}`);
        }
        assert.equal(archived.outbox_id, undefined);
        assert.equal(saved.find(s => s.client_transaction_id === postedId).status, 'completed');
        const next = saved.find(s => s.scope.session_id === 7);
        assert.equal(next.status, 'queued');
        assert.notEqual(next.outbox_id, original.outbox_id);
    } finally {
        await browser?.close();
        server.closeAllConnections();
        await new Promise((resolve) => server.close(resolve));
        await removeBrowserProfile(profile);
    }
});

test('pending offline sales survive shift changes and synchronize only after owner verification without freeze or seal controls', { timeout: 90000 }, async () => {
    const scope = { branch_id: 2, device_id: 1, session_id: 6, cashier_id: 1 };
    const source = await readFile(new URL('../../resources/js/pos-offline.js', import.meta.url));
    const synchronized = [];
    const actions = [];
    let allowSynchronization = false;
    const server = createServer((request, response) => {
        if (request.url === '/pos-offline.js') {
            response.writeHead(200, { 'Content-Type': 'text/javascript' }); response.end(source);
        } else if (request.url?.startsWith('/images/')) {
            response.writeHead(200, { 'Content-Type': 'image/png' }); response.end(pixelImage);
        } else if (request.method === 'POST') {
            let body = ''; request.on('data', (chunk) => { body += chunk; });
            request.on('end', () => {
                const envelope = JSON.parse(body);
                if (request.url === '/sync' && !allowSynchronization) { response.writeHead(503, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ message: 'Temporarily unavailable' })); return; }
                response.writeHead(200, { 'Content-Type': 'application/json' });
                if (request.url === '/sync') {
                    synchronized.push(envelope);
                    response.end(JSON.stringify({ client_transaction_id: envelope.client_transaction_id, status: 'completed', result: { invoice_number: 'VERIFIED-LATE' } }));
                } else {
                    actions.push(envelope.action);
                    response.end(JSON.stringify({ outbox_id: envelope.outbox_id, status: outboxStatus(envelope.action) }));
                }
            });
        } else {
            response.writeHead(200, { 'Content-Type': 'text/html' });
            response.end(terminalPage(request.url === '/new-shift' ? { ...scope, session_id: 7 } : scope, true));
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const profile = await mkdtemp(join(tmpdir(), 'fox-hema-pos-late-sync-'));
    let browser;
    try {
        browser = await launchBrowser(profile);
        await browser.navigate(`http://127.0.0.1:${server.address().port}/terminal`);
        await browser.wait('globalThis.testReady === true', 'authorized shift 6 catalog');
        await browser.setOffline(true);
        assert.equal(await browser.evaluate(`document.querySelector('[data-local-finish-offline]') === null`), true);
        await browser.evaluate(`new Promise((resolve) => {
            const r = indexedDB.open('fox-hema-pos'); r.onsuccess = () => {
                const tx = r.result.transaction('offline_catalog_v1', 'readwrite'); const store = tx.objectStore('offline_catalog_v1');
                const q = store.getAll(); q.onsuccess = () => { const c = q.result[0]; c.offline_outbox.frozen = true; c.offline_outbox.sealed = true; c.offline_outbox.transaction_ids = ['historical-evidence']; store.put(c); };
                tx.oncomplete = () => { r.result.close(); resolve(); };
            };
        })`);
        await browser.evaluate(`document.querySelector('[data-local-products] .fox-pos-add-button').click(); document.querySelector('[data-local-complete]').click()`);
        await browser.wait(`document.querySelector('[data-local-status]').textContent.includes('Queued for secure synchronization')`, 'offline checkout ignores historical flags');
        const read = `new Promise((resolve) => { const r = indexedDB.open('fox-hema-pos'); r.onsuccess = () => { const q = r.result.transaction('offline_sales_v1', 'readonly').objectStore('offline_sales_v1').getAll(); q.onsuccess = () => { resolve(q.result); r.result.close(); }; }; })`;
        const original = (await browser.evaluate(read))[0];
        const privateSales = ['cashier_id', 'branch_id', 'device_id'].map((field, index) => {
            const privateScope = { ...scope, [field]: 999 };
            return { ...original, client_transaction_id: `00000000-0000-4000-8000-00000000000${index}`,
                scope: privateScope, scopeKey: Object.values(privateScope).join(':'), outbox_id: `private-${field}` };
        });
        await browser.evaluate(`new Promise(resolve => { const r = indexedDB.open('fox-hema-pos'); r.onsuccess = () => {
            const tx = r.result.transaction('offline_sales_v1', 'readwrite');
            for (const sale of ${JSON.stringify(privateSales)}) tx.objectStore('offline_sales_v1').put(sale);
            tx.oncomplete = () => { r.result.close(); resolve(); };
        }; })`);
        await browser.setOffline(false);
        await browser.navigate(`http://127.0.0.1:${server.address().port}/new-shift`);
        await browser.wait('globalThis.testReady === true', 'new shift authorized');
        assert.equal(await browser.evaluate(`document.querySelector('[data-pos-terminal]').dataset.posCatalogReady`), 'true',
            await browser.evaluate(`document.querySelector('[data-local-status]')?.textContent`));
        allowSynchronization = true;
        await browser.evaluate(`window.dispatchEvent(new Event('online'))`);
        await browser.wait(`${read}.then(rows => rows.some(s => s.client_transaction_id === '${original.client_transaction_id}' && s.status === 'completed'))`, 'original shift synchronized after authenticated verification');
        const saved = (await browser.evaluate(read)).find(s => s.client_transaction_id === original.client_transaction_id);
        assert.deepEqual(saved.scope, scope);
        assert.deepEqual(saved.sale, original.sale);
        assert.equal(saved.outbox_id, original.outbox_id);
        assert.equal(synchronized.filter(e => e.client_transaction_id === original.client_transaction_id).length, 1);
        assert.equal(synchronized.length, 1, 'Other owners, branches and devices must never synchronize through this terminal');
        const finalSales = await browser.evaluate(read);
        for (const privateSale of privateSales) {
            assert.deepEqual(finalSales.find(s => s.client_transaction_id === privateSale.client_transaction_id), privateSale);
        }
        assert.equal(synchronized[0].client_occurred_at, original.created_at);
        assert.deepEqual(synchronized[0].scope, scope);
        assert.equal(actions.some(a => a === 'freeze' || a === 'seal'), false);
    } finally {
        await browser?.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); await removeBrowserProfile(profile);
    }
});

test('real POS Blade layouts fit requested viewports in LTR and RTL, including checkout', { timeout: 120000 }, async () => {
    const { assets } = await posUiAssets();
    const fixtures = new Map();
    for (const locale of ['en', 'ar']) {
        fixtures.set(`/layout/${locale}`, await posLayoutHtml(locale));
        fixtures.set(`/checkout/${locale}`, await posLayoutHtml(locale, true));
    }
    const server = createServer((request, response) => {
        const pathname = new URL(request.url, 'http://test').pathname;
        response.setHeader('Content-Type', pathname.endsWith('.css') ? 'text/css' : 'text/html; charset=utf-8');
        response.end(assets.get(pathname) ?? fixtures.get(pathname) ?? '');
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const profile = await mkdtemp(join(tmpdir(), 'fox-hema-pos-responsive-'));
    const artifactDirectory = new URL('../../storage/framework/testing/pos-ui-verification/', import.meta.url);
    await mkdir(artifactDirectory, { recursive: true });
    let browser;
    try {
        browser = await launchBrowser(profile);
        for (const locale of ['en', 'ar']) {
            for (const checkout of [false, true]) {
                const pathname = `/${checkout ? 'checkout' : 'layout'}/${locale}`;
                await browser.navigate(origin + pathname);
                await browser.wait(`location.pathname === '${pathname}' && document.querySelectorAll('.fox-pos-product-card').length === 8`, 'rendered Blade catalog');
                await browser.wait(`document.readyState === 'complete'`, 'loaded stylesheet and layout assets');
                for (const width of [320, 375, 390, 768, 1024, 1440]) {
                    await browser.resize(width);
                    const metrics = await browser.evaluate(`(() => {
                        const cards = [...document.querySelectorAll('.fox-pos-product-card')].map((card) => {
                            const box = card.getBoundingClientRect();
                            const title = card.querySelector('h3');
                            return { width: box.width, overflow: card.scrollWidth - card.clientWidth,
                                titleOverflow: title.scrollHeight - title.clientHeight, titleWhiteSpace: getComputedStyle(title.querySelector('bdi')).whiteSpace,
                                controls: [...card.querySelectorAll('button')].map((button) => ({ className: button.className, width: button.getBoundingClientRect().width, height: button.getBoundingClientRect().height })) };
                        });
                        const modal = document.querySelector('.fox-pos-payment-modal')?.getBoundingClientRect();
                        return { overflow: document.documentElement.scrollWidth - innerWidth, cards,
                            direction: document.querySelector('[data-pos-terminal]').dir,
                            contentDirections: [...document.querySelectorAll('.fox-pos-catalog, .fox-pos-cart')].map((element) => getComputedStyle(element).direction),
                            modal: modal ? { left: modal.left, right: modal.right, top: modal.top, bottom: modal.bottom } : null };
                    })()`);
                    assert.ok(metrics.overflow <= 1, `${locale}/${width}: page overflow ${metrics.overflow}`);
                    assert.equal(metrics.direction, locale === 'ar' ? 'rtl' : 'ltr');
                    assert.ok(metrics.contentDirections.every((direction) => direction === metrics.direction), `${locale}/${width}: catalog/cart direction mismatch`);
                    for (const card of metrics.cards) {
                        assert.ok(card.width >= 180 && card.overflow <= 1, `${locale}/${width}: card ${JSON.stringify(card)}`);
                        assert.notEqual(card.titleWhiteSpace, 'nowrap', `${locale}/${width}: product title must wrap`);
                        if (width <= 1024) assert.ok(card.titleOverflow <= 1, `${locale}/${width}: clipped product title`);
                        assert.ok(card.controls.every((control) => control.width >= 43 && control.height >= 43), `${locale}/${width}: small touch controls ${JSON.stringify(card.controls)}`);
                    }
                    if (checkout) assert.ok(metrics.modal && metrics.modal.left >= -1 && metrics.modal.right <= width + 1
                        && metrics.modal.top >= -1 && metrics.modal.bottom <= 1001, `${locale}/${width}: checkout outside viewport`);
                    if (!checkout && ((width === 768 && locale === 'en') || (width === 320 && locale === 'ar'))) {
                        await writeFile(new URL(`layout-${locale}-${width}.png`, artifactDirectory), await browser.screenshot());
                    }
                }
            }
        }
    } finally {
        if (browser) await browser.close();
        await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
        await removeBrowserProfile(profile);
    }
});

for (const locale of ['en', 'ar']) {
    test(`scoped images survive real service-worker reload and receipt PDF reprints use saved snapshots (${locale})`, { timeout: 120000 }, async () => {
        const scope = { branch_id: 1, device_id: 2, session_id: 3, cashier_id: 4 };
        const { assets } = await posUiAssets();
        const fixture = await posLayoutHtml(locale);
        const labels = JSON.parse(await readFile(new URL(`../../storage/framework/testing/pos-labels-${locale}.json`, import.meta.url), 'utf8'));
        const worker = await readFile(new URL('../../resources/js/pos-service-worker.js', import.meta.url), 'utf8');
        let revision = 0;
        let privateRequests = 0;
        let imageCookies = 0;
        let syncRequests = 0;
        const boot = (offline) => fixture.replace('</body>', `<script type="module">
            import { attachOfflineTerminal } from '/pos-offline.js';
            const scope = ${JSON.stringify(scope)};
            const root = document.querySelector('[data-pos-terminal]');
            const panel = root.querySelector('[data-pos-local]');
            panel.dataset.posSyncUrl = '/sync'; panel.dataset.posOutboxUrl = '/outbox';
            panel.dataset.posScope = JSON.stringify(scope);
            panel.dataset.posContext = 'test-' + Object.values(scope).join(':');
            ${offline ? '' : "document.cookie = 'fox_pos_context=test-' + Object.values(scope).join(':') + '; Path=/; SameSite=Strict';"}
            const wire = { async offlineCatalogPage() { return (await fetch('/catalog', { cache: 'no-store' })).json(); } };
            globalThis.cachedCatalog = () => new Promise((resolve) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => {
                    const query = request.result.transaction('offline_catalog_v1', 'readonly').objectStore('offline_catalog_v1').get('1:2:3:4');
                    query.onsuccess = () => resolve(query.result);
                };
            });
            globalThis.savedSales = () => new Promise((resolve) => {
                const request = indexedDB.open('fox-hema-pos', 10);
                request.onsuccess = () => {
                    const query = request.result.transaction('offline_sales_v1', 'readonly').objectStore('offline_sales_v1').getAll();
                    query.onsuccess = () => resolve(query.result);
                };
            });
            await attachOfflineTerminal(root, ${offline ? 'null' : 'wire'}, scope, { offlineShell: ${offline}, labels: ${JSON.stringify(labels)} });
            ${offline ? '' : `await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                await navigator.serviceWorker.ready;`}
            globalThis.testReady = true;
        </script></body>`);
        const configuration = { version: `pos-ui-${locale}`, terminal: '/terminal', shell: boot(true),
            assets: Object.fromEntries([...assets].map(([url, data]) => [url, createHash('sha256').update(data).digest('hex')])) };
        const server = createServer((request, response) => {
            const pathname = new URL(request.url, 'http://test').pathname;
            if (assets.has(pathname)) {
                response.setHeader('Content-Type', pathname.endsWith('.js') ? 'text/javascript' : pathname.endsWith('.css') ? 'text/css' : 'font/woff2');
                response.end(assets.get(pathname));
            } else if (pathname === '/sw.js') {
                response.setHeader('Content-Type', 'text/javascript');
                response.end(worker.replace('__POS_SHELL_CONFIG__', JSON.stringify(configuration)));
            } else if (pathname === '/catalog') {
                response.setHeader('Content-Type', 'application/json'); response.setHeader('Cache-Control', 'private, no-store');
                response.end(JSON.stringify({ scope, context: 'test-' + Object.values(scope).join(':'), catalog: { page: 1, last_page: 1, items: [{
                    id: 1, name: revision > 1 ? 'CHANGED PRODUCT' : 'Original cotton shirt / قميص أصلي', image: revision > 1 ? null : '/storage/products/product.png',
                    discounts: [{ id: 7, type: 'percentage', value: '10' }],
                    variants: [{ id: 9, sku: 'SHIRT-M-BLUE', size: 'M', color: 'Blue', label: 'M / Blue',
                        image: '/storage/products/variant.svg', price: '100.0000', available: '5.0000',
                        taxes: [{ type: 'percentage', price_inclusion: 'excluded', rate: '14' }] }],
                }, { id: 2, name: 'Private image excluded', image: '/private/photo.png', discounts: [],
                    variants: [{ id: 10, image: '/images/protected.png', price: '5', available: '2', taxes: [] }] },
                ...(revision < 2 ? [{ id: 3, name: 'Deleted socks', image: '/storage/products/deleted.png', discounts: [],
                    variants: [{ id: 11, price: '5', available: '1', taxes: [] }] }] : [])] },
                    payment_methods: [{ id: 11, name: 'Cash / نقدي', type: 'cash' }] }));
            } else if (pathname.startsWith('/storage/products/') || pathname.startsWith('/images/')) {
                if (request.headers.cookie) imageCookies += 1;
                if (pathname === '/images/protected.png') response.setHeader('Cache-Control', 'private, no-store');
                if (revision > 1 && pathname.endsWith('/product.png')) response.statusCode = 404;
                response.setHeader('Content-Type', pathname.endsWith('.svg') ? 'image/svg+xml' : 'image/png');
                response.end(pathname.endsWith('.svg') ? `<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="${revision ? 'red' : 'blue'}"/></svg>` : pixelImage);
            } else if (pathname === '/private/photo.png') {
                privateRequests += 1; response.statusCode = 403; response.end();
            } else if (pathname === '/outbox') {
                let body = ''; request.on('data', (chunk) => { body += chunk; });
                request.on('end', () => { response.setHeader('Content-Type', 'application/json');
                    response.end(JSON.stringify({ outbox_id: JSON.parse(body).outbox_id, status: outboxStatus(JSON.parse(body).action) })); });
            } else if (pathname === '/sync') {
                syncRequests += 1; response.statusCode = 503; response.end();
            } else {
                response.setHeader('Content-Type', 'text/html; charset=utf-8');
                response.setHeader('Set-Cookie', 'pos_test=not-for-images; Path=/; SameSite=Lax');
                response.end(boot(false));
            }
        });
        await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
        const origin = `http://127.0.0.1:${server.address().port}`;
        const profile = await mkdtemp(join(tmpdir(), `fox-hema-pos-images-${locale}-`));
        const artifactDirectory = new URL('../../storage/framework/testing/pos-ui-verification/', import.meta.url);
        await mkdir(artifactDirectory, { recursive: true });
        let browser;
        try {
            browser = await launchBrowser(profile);
            await browser.navigate(`${origin}/terminal`);
            await browser.wait('globalThis.testReady === true', 'authorized catalog and installed service worker');
            await browser.navigate(`${origin}/terminal`);
            await browser.wait('globalThis.testReady === true && navigator.serviceWorker.controller !== null', 'controlled terminal reload');
            assert.equal(await browser.evaluate(`cachedCatalog().then((catalog) => Object.keys(catalog.offline_images).length)`), 3);
            assert.equal(privateRequests, 0); assert.equal(imageCookies, 0);
            const imageHash = () => browser.evaluate(`cachedCatalog().then(async (catalog) => [...new Uint8Array(await crypto.subtle.digest('SHA-256', await catalog.offline_images[location.origin + '/storage/products/variant.svg'].arrayBuffer()))].join(','))`);
            const firstImage = await imageHash();
            revision = 1;
            await browser.evaluate(`document.querySelector('[data-local-refresh]').click()`);
            await browser.wait(`!document.querySelector('[data-local-refresh]').disabled`, 'refreshed image revision');
            assert.notEqual(await imageHash(), firstImage, 'same URL must update its cached pixels');
            await browser.setOffline(true);
            await browser.navigate(`${origin}/terminal`);
            await browser.wait('globalThis.testReady === true && !navigator.onLine', 'service-worker offline reload');
            assert.ok(await browser.evaluate(`[...document.querySelectorAll('[data-pos-local] .fox-pos-product-card img')].every((image) => image.complete && image.naturalWidth > 0)`));
            assert.match(await browser.evaluate(`document.querySelector('[data-pos-local] .fox-pos-product-card img').src`), /^blob:/);
            assert.ok(await browser.evaluate(`(async () => {
                const otherScope = { branch_id: 2, device_id: 20, session_id: 30, cashier_id: 40 };
                const otherKey = '2:20:30:40';
                const source = await cachedCatalog();
                const database = await new Promise((resolve) => { const request = indexedDB.open('fox-hema-pos', 10); request.onsuccess = () => resolve(request.result); });
                await new Promise((resolve) => {
                    const transaction = database.transaction('offline_catalog_v1', 'readwrite');
                    transaction.objectStore('offline_catalog_v1').put({ ...source, scopeKey: otherKey, scope: otherScope,
                        offline_images: {}, image_revision: 'uncached-other-user' });
                    transaction.oncomplete = resolve;
                });
                const root = document.createElement('main'); root.dataset.posTerminal = ''; root.className = 'fox-pos-terminal';
                const panel = document.createElement('section'); panel.dataset.posLocal = ''; root.append(panel); document.body.append(root);
                await FoxHemaPosOffline.attachOfflineTerminal(root, null, otherScope, { offlineShell: true });
                const isolated = !root.querySelector('.fox-pos-product-card img') && root.textContent.includes('Reconnect');
                root.remove(); database.close(); return isolated;
            })()`), 'another branch/cashier cannot reuse the first scope\'s cached image');
            for (const width of [320, 375, 390, 768, 1024, 1440]) {
                await browser.resize(width);
                const metrics = await browser.evaluate(`({ overflow: document.documentElement.scrollWidth - innerWidth,
                    cards: [...document.querySelectorAll('[data-pos-local] .fox-pos-product-card')].map((card) => ({ width: card.getBoundingClientRect().width, overflow: card.scrollWidth - card.clientWidth })) })`);
                assert.ok(metrics.overflow <= 1 && metrics.cards.every((card) => card.width >= 180 && card.overflow <= 1), `${locale}/${width}: offline cards overflow ${JSON.stringify(metrics)}`);
            }
            await browser.evaluate(`document.querySelector('[data-pos-local] .fox-pos-add-button').click(); document.querySelector('[data-local-checkout]').click();`);
            for (const width of [320, 375, 390, 768, 1024, 1440]) {
                await browser.resize(width);
                const modal = await browser.evaluate(`(() => { const modal = document.querySelector('[data-local-payment-modal] .fox-pos-payment-modal') ?? document.querySelector('[data-pos-local] .fox-pos-payment-modal'); const box = modal.getBoundingClientRect(); return { left: box.left, right: box.right, top: box.top, bottom: box.bottom, overflow: modal.scrollWidth - modal.clientWidth }; })()`);
                assert.ok(modal.left >= -1 && modal.right <= width + 1 && modal.top >= -1 && modal.bottom <= 1001 && modal.overflow <= 1, `${locale}/${width}: offline checkout ${JSON.stringify(modal)}`);
            }
            await browser.evaluate(`document.querySelector('[data-local-complete]').click()`);
            await browser.wait(`savedSales().then((sales) => sales.length === 1 && sales[0].receipt_snapshot?.items[0].name.startsWith('Original'))`, 'saved receipt snapshot');
            const transactionReference = await browser.evaluate(`savedSales().then((sales) => sales[0].local_reference)`);
            for (const width of [320, 375, 390, 768, 1024, 1440]) {
                await browser.resize(width);
                const preview = await browser.evaluate(`(() => { const receipt = document.querySelector('[data-local-receipt]'); receipt.scrollIntoView({ block: 'center' }); const box = receipt.getBoundingClientRect(); const workspace = document.querySelector('[data-pos-local] .fox-pos-workspace').getBoundingClientRect(); return { left: box.left, right: box.right, height: box.height, overflow: receipt.scrollWidth - receipt.clientWidth, overlap: workspace.bottom - box.top }; })()`);
                assert.ok(preview.left >= -1 && preview.right <= width + 1 && preview.height <= 801 && preview.overflow <= 1 && preview.overlap <= 1, `${locale}/${width}: receipt overlaps or overflows ${JSON.stringify(preview)}`);
            }
            await browser.resize(390);
            await browser.wait(`document.querySelector('[data-local-receipt]').getBoundingClientRect().height > 0`, 'offline receipt preview');
            await writeFile(new URL(`receipt-${locale}-390.png`, artifactDirectory), await browser.screenshot());
            for (const width of [58, 80, 210]) {
                await browser.evaluate(`(() => {
                    const receipt = document.querySelector('[data-local-receipt]');
                    const paper = receipt.querySelector('.fox-pos-receipt-actions select');
                    paper.value = '${width === 210 ? 190 : width}mm'; paper.dispatchEvent(new Event('change', { bubbles: true }));
                    window.addEventListener('beforeprint', () => { globalThis.printedReceipt = document.querySelector('[data-pos-print-root]')?.textContent; }, { once: true });
                })()`);
                const pdf = await browser.pdf(width);
                await writeFile(new URL(`receipt-${locale}-${width}mm.pdf`, artifactDirectory), pdf);
                assert.ok(pdf.length > 8000 && pdf.subarray(0, 4).toString() === '%PDF', 'actual browser PDF must contain rendered content');
                const printed = await browser.evaluate('globalThis.printedReceipt');
                assert.ok(printed.includes('Original cotton shirt') && printed.includes('SHIRT-M-BLUE')
                    && printed.includes('102.60') && printed.includes(transactionReference), `PDF print source must contain saved identity and totals: ${printed}`);
                assert.ok(!printed.includes('INV-'), 'pending receipts cannot invent server invoices');
                await writeFile(new URL(`receipt-${locale}-${width}mm.pdf`, artifactDirectory), pdf);
            }
            assert.equal(await browser.evaluate('savedSales().then((sales) => sales.length)'), 1);
            assert.equal(syncRequests, 0, 'offline printing cannot trigger posting');
            await browser.setOffline(false);
            revision = 2;
            await browser.evaluate(`document.querySelector('[data-local-refresh]').click()`);
            await browser.wait(`!document.querySelector('[data-local-refresh]').disabled`, 'deleted product-image pruning');
            assert.ok(await browser.evaluate(`cachedCatalog().then((catalog) => !Object.hasOwn(catalog.offline_images, location.origin + '/storage/products/product.png')
                && !Object.hasOwn(catalog.offline_images, location.origin + '/storage/products/deleted.png'))`));
            await browser.setOffline(true);
            await browser.navigate(`${origin}/terminal`);
            await browser.wait('globalThis.testReady === true && !navigator.onLine', 'reload with changed catalog');
            await browser.evaluate(`document.querySelector('[data-pos-nav="history"]').click(); document.querySelector('.fox-pos-recent-sale > button').click();`);
            assert.ok((await browser.evaluate(`document.querySelector('[data-local-receipt]').textContent`)).includes('Original cotton shirt'));
            assert.equal(await browser.evaluate('savedSales().then((sales) => sales.length)'), 1);
            assert.equal(privateRequests, 0); assert.equal(imageCookies, 0);
            const cachePaths = await browser.evaluate(`(async () => Promise.all((await caches.keys()).map(async (name) => (await (await caches.open(name)).keys()).map((request) => new URL(request.url).pathname))).then((paths) => paths.flat()))()`);
            assert.ok(!cachePaths.some((path) => /catalog|storage\/products|private|outbox|sync/.test(path)), 'service worker must not cache business or image responses globally');
            await browser.setOffline(false);
            await browser.evaluate(`Object.defineProperty(navigator.storage, 'estimate', { configurable: true, value: async () => ({ quota: 0, usage: 0 }) });
                document.querySelector('[data-local-refresh]').click()`);
            await browser.wait(`!document.querySelector('[data-local-refresh]').disabled`, 'low-storage image fallback');
            assert.ok(await browser.evaluate(`cachedCatalog().then((catalog) => Object.keys(catalog.offline_images).length === 0 && catalog.offline_outbox.registered)`));
            assert.equal(await browser.evaluate('savedSales().then((sales) => sales.length)'), 1, 'image storage pressure must preserve the offline sale');
        } finally {
            if (browser) await browser.close();
            await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
            await removeBrowserProfile(profile);
        }
    });
}
