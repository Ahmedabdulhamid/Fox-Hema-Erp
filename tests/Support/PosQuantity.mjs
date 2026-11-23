import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/pos-offline.js', import.meta.url), 'utf8');
const scope = { branch_id: 1, device_id: 2, session_id: 3, cashier_id: 4 };
const key = '1:2:3:4';

function terminal(database) {
    const context = vm.createContext({ structuredClone,
        document: { cookie: 'fox_pos_context=test', addEventListener() {} }, window: { addEventListener() {} } });
    vm.runInContext(source.replace('export async function', 'async function') + `
        scopeContexts.set('1:2:3:4', 'test');
        catalogContexts.set('1:2:3:4', 'test');
        globalThis.quantityApi = { wholeQuantityUnits, quantityText, returnQuantityStep, validateSyncQuantities, localQuote, queueOfflineSale, queueOfflineReturn };
        globalThis.useDatabase = (database) => { databaseTask = Promise.resolve(database); };
    `, context);
    context.useDatabase(database);
    return context.quantityApi;
}

function memoryDatabase() {
    const stores = new Map(['offline_catalog_v1', 'offline_sales_v1', 'offline_returns_v1'].map((name) => [name, new Map()]));
    return {
        stores,
        transaction() {
            let pending = 0;
            let aborted = false;
            const before = structuredClone(stores);
            const transaction = {
                abort() {
                    aborted = true;
                    for (const [name, rows] of before) stores.set(name, rows);
                    queueMicrotask(() => transaction.onabort?.());
                },
                objectStore(name) {
                    const rows = stores.get(name);
                    const request = (operation) => {
                        const result = {};
                        pending++;
                        queueMicrotask(() => {
                            if (aborted) return;
                            try {
                                result.result = structuredClone(operation());
                                result.onsuccess?.();
                            } catch (error) {
                                transaction.error = error;
                                transaction.abort();
                            }
                            pending--;
                            if (!pending && !aborted) queueMicrotask(() => transaction.oncomplete?.());
                        });
                        return result;
                    };
                    return {
                        get: (id) => request(() => rows.get(id)),
                        put: (row) => request(() => rows.set(row.client_transaction_id ?? row.scopeKey, structuredClone(row))),
                        index: (field) => ({ getAll: (id) => request(() => [...rows.values()].filter((row) => row[field] === id)) }),
                        add: (row) => request(() => {
                            if (rows.has(row.client_transaction_id)) throw new Error('Duplicate transaction');
                            rows.set(row.client_transaction_id, structuredClone(row));
                        }),
                    };
                },
            };
            return transaction;
        },
    };
}

function offlineReturn(quantity, id = 'return-1') {
    return { client_transaction_id: id, source_client_transaction_id: 'sale-1', scope, scopeKey: key, status: 'queued',
        return: { items: [{ sales_delivery_item_id: 10, sales_invoice_item_id: 11, quantity, disposition: 'restock' }] } };
}

test('POS quantities reject fractions before quoting or queueing sales and returns', async () => {
    const api = terminal({ transaction: () => { throw new Error('Invalid quantities must not reach IndexedDB'); } });
    for (const quantity of ['0.001', '0.0002', '1.5', '2.25', '1.00001', '0', '-1', '', '1e0']) {
        assert.throws(() => api.wholeQuantityUnits(quantity), /whole-number/);
        assert.throws(() => api.localQuote({ products: [] }, { 1: { quantity } }), /whole-number/);
        await assert.rejects(api.queueOfflineReturn(offlineReturn(quantity)), /whole-number/);
        await assert.rejects(api.queueOfflineSale({ sale: { items: [{ quantity }] }, quote: { items: [] } }, 2, 'Register'), /whole-number/);
        await assert.rejects(api.queueOfflineSale({ sale: { items: [{ quantity: '1' }] }, quote: { items: [{ quantity }] } }, 2, 'Register'), /whole-number/);
    }
    assert.equal(api.wholeQuantityUnits('1.0000'), 10000n);
    assert.equal(api.quantityText('1.0000'), '1');
    assert.equal(api.quantityText('10.0000'), '10');
    assert.equal(api.quantityText('1.5000'), '1.5');
});

test('return controls move by one and never clamp to a fractional remainder or below one', () => {
    const api = terminal();
    assert.equal(api.returnQuantityStep('1', -1, 20000n), '1');
    assert.equal(api.returnQuantityStep('1', 1, 20000n), '2');
    assert.equal(api.returnQuantityStep('2', -1, 20000n), '1');
    assert.equal(api.returnQuantityStep('2', 1, 25000n), '2');
    assert.throws(() => api.returnQuantityStep('1.5', 1, 30000n), /whole-number/);
});

test('fractional saved payloads are blocked before synchronization without rewriting their quantities', async () => {
    const api = terminal();
    const record = offlineReturn('1.5');
    const before = structuredClone(record);
    const updates = [];
    assert.equal(await api.validateSyncQuantities(record, record.return.items, async (...args) => updates.push(args)), false);
    assert.equal(updates[0][0], record.client_transaction_id);
    assert.equal(updates[0][1].status, 'conflict');
    assert.equal(updates[0][1].next_retry_at, null);
    assert.deepEqual(record, before);
    assert.equal(await api.validateSyncQuantities(record, [{ quantity: '1.0000' }], async () => assert.fail('Valid quantities must retain their sync flow')), true);
});

test('offline return admission reserves whole units without changing existing records', async () => {
    const database = memoryDatabase();
    const original = { client_transaction_id: 'sale-1', scope, scopeKey: key, status: 'completed',
        result: { return_lines: [{ sales_delivery_item_id: 10, sales_invoice_item_id: 11, quantity: '2.0000', remaining: '2.0000' }] } };
    database.stores.get('offline_sales_v1').set('sale-1', original);
    database.stores.get('offline_catalog_v1').set(key, { return_authorization: {
        can_create_returns: true, token: 'cached-authorization', expires_at: new Date(Date.now() + 60000).toISOString(),
    } });
    const api = terminal(database);
    await api.queueOfflineReturn(offlineReturn('1.0000'));
    await assert.rejects(api.queueOfflineReturn(offlineReturn('0.001', 'fraction')), /whole-number/);
    await assert.rejects(api.queueOfflineReturn(offlineReturn('2', 'over-limit')), /exceeds/);
    await assert.rejects(api.queueOfflineReturn(offlineReturn('1', 'return-1')), /Duplicate/);
    await api.queueOfflineReturn(offlineReturn('1', 'return-2'));
    await assert.rejects(api.queueOfflineReturn(offlineReturn('1', 'return-3')), /exceeds/);
    const rows = database.stores.get('offline_returns_v1');
    assert.equal(rows.size, 2);
    assert.equal(rows.get('return-1').return.items[0].quantity, '1.0000');
    assert.equal(rows.get('return-1').authorization_token, 'cached-authorization');
    assert.deepEqual(database.stores.get('offline_sales_v1').get('sale-1'), original);
});
