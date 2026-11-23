const databaseName = 'fox-hema-pos';
const databaseVersion = 10;
const catalogStoreName = 'offline_catalog_v1';
const salesStoreName = 'offline_sales_v1';
const returnsStoreName = 'offline_returns_v1';
const offlineImageBudget = 16 * 1024 * 1024;
const placeholderImage = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="160" height="120" viewBox="0 0 160 120"><rect width="160" height="120" fill="#eef2f7"/><path d="m30 90 30-35 25 25 20-20 25 30z" fill="#a3b4c7"/><circle cx="110" cy="34" r="10" fill="#a3b4c7"/></svg>');
const scopeFields = ['branch_id', 'device_id', 'session_id', 'cashier_id'];
const decimalPattern = /^\d+(?:\.\d{1,4})?$/;
let databaseTask;
const scopeContexts = new Map();
const catalogContexts = new Map();
const contextCookie = () => document.cookie.split('; ').find((item) => item.startsWith('fox_pos_context='))?.slice(16) ?? '';
function assertScope(key) {
    if (!scopeContexts.get(key) || scopeContexts.get(key) !== contextCookie()) {
        throw new Error('Reconnect and sign in to verify this offline POS scope. Saved transactions are preserved.');
    }
}
async function scopedDatabase(key, requireCatalog = true) {
    assertScope(key);
    if (requireCatalog && catalogContexts.get(key) !== contextCookie()) throw new Error(labels.unavailable);
    const database = await openDatabase();
    assertScope(key);
    if (requireCatalog && catalogContexts.get(key) !== contextCookie()) throw new Error(labels.unavailable);
    return database;
}

const labels = {
    catalog: 'Offline catalog',
    noCatalog: 'Connect once to cache this terminal catalog before working offline.',
    refresh: 'Refresh catalog',
    outboxPending: 'The saved offline sales limit has been reached. Reconnect to synchronize; saved sales are preserved.',
    outboxMembership: 'This saved transaction has no verified membership in this outbox. Reconnect for review; its evidence is preserved.',
    cancelled: 'Local test sale cancelled; original evidence preserved.',
    archived: 'Historical orphan archived; no current posting. Original acknowledgement preserved.',
    search: 'Search products',
    categories: 'Product categories',
    allCategories: 'All categories',
    allBrands: 'All brands',
    allSizes: 'All sizes',
    allColors: 'All colors',
    brand: 'Brand',
    size: 'Size',
    color: 'Color',
    productsCount: 'Products: :count',
    noProducts: 'No matching products',
    outOfStock: 'Out of stock',
    stockCount: 'In stock: :count',
    discountBadge: ':value% off',
    variants: 'Available sizes and colors',
    add: 'Add to cart',
    cart: 'Current sale',
    clearCart: 'Clear cart',
    payment: 'Payment and checkout',
    paymentMethod: 'Payment method',
    received: 'Cash received',
    cashReceivedShort: 'Cash received is less than the applied cash amount.',
    reference: 'Payment reference',
    checkout: 'Checkout',
    cancel: 'Cancel',
    complete: 'Complete offline sale',
    empty: 'Add products to get started.',
    subtotal: 'Subtotal',
    discount: 'Discount',
    tax: 'Tax',
    unitPrice: 'Unit price',
    currency: 'EGP',
    noDiscount: 'No discount',
    pending: 'Queued for secure synchronization',
    completed: 'Synchronized',
    conflict: 'Could not synchronize; the sale is preserved for review.',
    permanentFailure: 'The server rejected this sale. It was saved and will not be retried automatically.',
    retry: 'Correct details and retry',
    retrying: 'Retrying sale…',
    reference: 'Payment reference',
    retryScheduled: 'Temporary synchronization failure. Retrying in :seconds seconds.',
    unavailable: 'Offline sales are unavailable until a shift, catalog, and payment method are cached.',
    syncing: 'Synchronizing completed offline sales…',
    total: 'Total',
    quantity: 'Quantity',
    wholeQuantity: 'Enter a positive whole-number quantity (1, 2, 3, ...).',
    selectReturnItem: 'Select item to return',
    remove: 'Remove',
    pendingCount: 'Pending synchronization',
    syncErrors: 'Synchronization errors',
    savedSales: 'Saved sales',
    receiptTitle: 'Sales receipt',
    invoice: 'Invoice',
    localReference: 'Offline reference',
    date: 'Date',
    cashier: 'Cashier',
    item: 'Item',
    amount: 'Amount',
    paymentBreakdown: 'Payment details',
    thankYou: 'Thank you for visiting — Fox & Hema',
    newSale: 'New sale',
    printReceipt: 'Print receipt',
    paperSize: 'Receipt paper',
    browserPaper: 'Standard paper',
    pendingReceipt: 'Pending offline sale — not a finalized invoice',
    legacyReceipt: 'Product descriptions were not saved on this older receipt; original item IDs are shown.',
    lineDiscount: 'Discount',
    returns: 'Offline returns',
    startReturn: 'Start return',
    returnSource: 'Original synchronized sale',
    returnQuantity: 'Return quantity',
    returnDisposition: 'Disposition',
    returnNote: 'A queued return is not a refund and does not restore stock until it is approved and posted online.',
    returnSubmit: 'Save return for synchronization',
    returnQueued: 'Return saved locally and queued for secure synchronization.',
    returnSyncing: 'Synchronizing offline returns…',
    returnSynced: 'Return draft synchronized for server review. No refund or stock change has been posted.',
    returnConflict: 'Return synchronization failed; the return is preserved for review.',
    returnPermissionExpired: 'Offline return permission is missing or expired. Reconnect and refresh authorization.',
    returnSourceUnavailable: 'The original sale is not synchronized or is not eligible for an offline return.',
    returnQuantityExceeded: 'Return quantity exceeds the locally available sold quantity.',
    returnNoLines: 'No eligible quantities remain on this sale.',
    returnRestock: 'Restock after approval',
    returnDamaged: 'Damaged',
    returnScrap: 'Scrap',
    returnRetry: 'Retry synchronization',
    returnRefresh: 'Refresh return authorization and eligible sales',
    returnSourcesHint: 'Online and synchronized offline sales from this branch, device, cashier, and shift are eligible. Connect and refresh to cache them before working offline.',
    returnNoSales: 'No eligible original sales are cached for this branch, device, cashier, and shift. Connect and refresh eligible sales.',
    returnSourcesRefreshFailed: 'Eligible sales could not be refreshed. Reconnect and refresh Returns before saving a return.',
    returnAllQuantitiesUsed: 'All cached return quantities have already been returned or reserved by local returns.',
    returnPendingState: 'Pending Sync',
    returnSyncedState: 'Synced',
    returnFailedState: 'Failed',
    pendingDocument: 'Pending synchronization',
    productFallback: 'Product',
    returnsTitle: 'Returns',
    salesHistory: 'Sales history',
    syncTitle: 'Synchronization',
    newReturn: 'New return',
    returnHistoryTitle: 'Return history',
    noReturnHistory: 'No returns saved for this shift.',
    noSalesHistory: 'No sales available in this history yet.',
    authorized: 'Authorized',
    validUntil: 'Valid until',
    purchased: 'Purchased',
    returnable: 'Returnable',
    closeDrawer: 'Close panel',
    syncDescription: 'Saved offline sales and synchronization errors. Return history is available in the Returns panel.',
    syncErrorHelp: 'Unable to synchronize. Refresh authorization and retry. Ask a supervisor if the error continues.',
};

function scopeKey(scope) {
    if (!scope || scopeFields.some((field) => !Number.isSafeInteger(Number(scope[field])) || Number(scope[field]) < 1)) {
        throw new Error('Invalid offline POS scope');
    }

    return scopeFields.map((field) => Number(scope[field])).join(':');
}

function snapshotCart(cart) {
    return Object.fromEntries(Object.entries(cart ?? {}).map(([variantId, line]) => [
        variantId,
        { quantity: line.quantity, discount_id: line.discount_id ?? null },
    ]));
}

function openDatabase() {
    if (databaseTask) return databaseTask;
    databaseTask = new Promise((resolve, reject) => {
        const request = indexedDB.open(databaseName, databaseVersion);
        request.onupgradeneeded = () => {
            const database = request.result;
            if (!database.objectStoreNames.contains(catalogStoreName)) {
                database.createObjectStore(catalogStoreName, { keyPath: 'scopeKey' });
            }
            const store = database.objectStoreNames.contains(salesStoreName)
                ? request.transaction.objectStore(salesStoreName)
                : database.createObjectStore(salesStoreName, { keyPath: 'client_transaction_id' });
            if (!store.indexNames.contains('scopeKey')) store.createIndex('scopeKey', 'scopeKey');
            if (!store.indexNames.contains('branchId')) store.createIndex('branchId', 'scope.branch_id');
            if (!store.indexNames.contains('deviceId')) store.createIndex('deviceId', 'scope.device_id');
            const returns = database.objectStoreNames.contains(returnsStoreName)
                ? request.transaction.objectStore(returnsStoreName)
                : database.createObjectStore(returnsStoreName, { keyPath: 'client_transaction_id' });
            if (!returns.indexNames.contains('scopeKey')) returns.createIndex('scopeKey', 'scopeKey');
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error ?? new Error('Unable to open offline POS storage'));
        request.onblocked = () => reject(new Error('Close other POS tabs to upgrade offline storage'));
    });

    return databaseTask;
}

function storeRequest(database, storeName, mode, perform) {
    return new Promise((resolve, reject) => {
        const transaction = database.transaction(storeName, mode);
        const request = perform(transaction.objectStore(storeName));
        let result;
        request.onsuccess = () => { result = request.result; };
        request.onerror = () => reject(request.error ?? new Error('Offline POS storage request failed'));
        transaction.oncomplete = () => resolve(result);
        transaction.onabort = () => reject(transaction.error ?? new Error('Offline POS storage transaction aborted'));
    });
}

async function saveCatalog(catalog) {
    const allowedImages = new Set((catalog.products ?? []).flatMap((product) =>
        [product.image, ...(product.variants ?? []).map((variant) => variant.image)]).map(publicImageUrl).filter(Boolean));
    return updateCatalog(catalog.scopeKey, (existing) => ({
        ...catalog,
        offline_outbox: existing?.offline_outbox ?? catalog.offline_outbox,
        offline_images: Object.fromEntries(Object.entries(catalog.offline_images ?? existing?.offline_images ?? {})
            .filter(([url]) => allowedImages.has(url))),
        image_revision: catalog.image_revision ?? existing?.image_revision,
    }));
}

async function updateCatalog(key, update) {
    const database = await scopedDatabase(key, false);
    return new Promise((resolve, reject) => {
        const transaction = database.transaction(catalogStoreName, 'readwrite');
        const store = transaction.objectStore(catalogStoreName);
        const request = store.get(key);
        let result;
        let failure;
        request.onsuccess = () => {
            try {
                assertScope(key);
                result = update(request.result);
                store.put(result);
            } catch (error) {
                failure = error;
                transaction.abort();
            }
        };
        transaction.oncomplete = () => resolve(result);
        transaction.onabort = () => reject(failure ?? transaction.error ?? new Error('Unable to update offline outbox'));
    });
}

async function readCatalog(key) {
    const database = await scopedDatabase(key, false);
    const catalog = await storeRequest(database, catalogStoreName, 'readonly', (store) => store.get(key));
    if (!catalog || catalog.context !== contextCookie() || scopeKey(catalog.scope) !== key) return null;
    catalogContexts.set(key, catalog.context);
    return catalog;
}

async function saveSale(sale) {
    const database = await scopedDatabase(sale.scopeKey);
    return storeRequest(database, salesStoreName, 'readwrite', (store) => store.put(sale));
}

async function listSales(key) {
    const database = await scopedDatabase(key);
    return storeRequest(database, salesStoreName, 'readonly', (store) => store.index('scopeKey').getAll(key));
}

async function listSalesForBranch(branchId, key) {
    if (Number(key.split(':')[0]) !== Number(branchId)) throw new Error(labels.unavailable);
    const database = await scopedDatabase(key);
    return storeRequest(database, salesStoreName, 'readonly', (store) => store.index('branchId').getAll(Number(branchId)));
}

async function listReturns(key) {
    const database = await scopedDatabase(key);
    return storeRequest(database, returnsStoreName, 'readonly', (store) => store.index('scopeKey').getAll(key));
}

function returnSources(catalog, sales, key) {
    const sources = new Map(sales.filter((sale) => sale.scopeKey === key && sale.status === 'completed')
        .map((sale) => [sale.client_transaction_id, sale]));
    for (const sale of catalog?.return_sources ?? []) {
        if (scopeKey(sale.scope) === key && sale.status === 'completed') {
            sources.set(sale.client_transaction_id, { ...sale, scopeKey: key });
        }
    }
    return [...sources.values()];
}

async function updateSale(transactionId, update) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
        const transaction = database.transaction(salesStoreName, 'readwrite');
        const store = transaction.objectStore(salesStoreName);
        const request = store.get(transactionId);
        request.onsuccess = () => {
            if (!request.result || catalogContexts.get(request.result.scopeKey) !== contextCookie()) {
                transaction.abort();
                reject(new Error('Offline sale no longer exists'));
                return;
            }
            store.put({ ...request.result, ...update });
        };
        request.onerror = () => reject(request.error ?? new Error('Unable to read offline sale'));
        transaction.oncomplete = () => resolve();
        transaction.onabort = () => reject(transaction.error ?? new Error('Unable to update offline sale'));
    });
}

async function updateReturn(transactionId, update) {
    const database = await openDatabase();
    return new Promise((resolve, reject) => {
        const transaction = database.transaction(returnsStoreName, 'readwrite');
        const store = transaction.objectStore(returnsStoreName);
        const request = store.get(transactionId);
        request.onsuccess = () => {
            if (!request.result || catalogContexts.get(request.result.scopeKey) !== contextCookie()) {
                transaction.abort();
                reject(new Error('Offline return no longer exists'));
                return;
            }
            store.put({ ...request.result, ...update });
        };
        request.onerror = () => reject(request.error ?? new Error('Unable to read offline return'));
        transaction.oncomplete = () => resolve();
        transaction.onabort = () => reject(transaction.error ?? new Error('Unable to update offline return'));
    });
}

function decimalUnits(value) {
    const text = String(value);
    if (!decimalPattern.test(text)) throw new Error('Invalid cached decimal');
    const [whole, fraction = ''] = text.split('.');
    return BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0'));
}

function decimalText(units) {
    return `${units / 10000n}.${String(units % 10000n).padStart(4, '0')}`;
}

function wholeQuantityUnits(value) {
    if (!decimalPattern.test(String(value))) throw new Error(labels.wholeQuantity);
    const units = decimalUnits(value);
    if (units < 10000n || units % 10000n !== 0n) throw new Error(labels.wholeQuantity);
    return units;
}

function quantityText(value) {
    return String(value).replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1');
}

function returnQuantityStep(value, direction, available) {
    const current = wholeQuantityUnits(value);
    const next = current + BigInt(direction) * 10000n;
    return quantityText(decimalText(next < 10000n || next > available ? current : next));
}

async function validateSyncQuantities(record, items, update) {
    try {
        for (const item of items) wholeQuantityUnits(item.quantity);
        return true;
    } catch (error) {
        await update(record.client_transaction_id, {
            status: 'conflict', next_retry_at: null, error: labels.wholeQuantity,
            error_history: appendSyncError(record, 422, labels.wholeQuantity),
        });
        return false;
    }
}

function roundedRatio(numerator, denominator) {
    return (numerator + denominator / 2n) / denominator;
}

function isSaleReservedAgainstCatalog(sale, capturedAt) {
    if (['cancelled', 'archived'].includes(sale.status)) return false;
    return sale.status !== 'completed'
        || !sale.synced_at
        || Date.parse(sale.synced_at) > Date.parse(capturedAt);
}

function applyLocalStock(catalog, sales) {
    if (!catalog) return null;
    const reservations = new Map();
    for (const sale of sales) {
        if (!isSaleReservedAgainstCatalog(sale, catalog.captured_at)) continue;
        for (const item of sale.sale?.items ?? []) {
            const key = Number(item.variant_id);
            reservations.set(key, (reservations.get(key) ?? 0n) + decimalUnits(item.quantity));
        }
    }

    return {
        ...catalog,
        products: catalog.products.map((product) => ({
            ...product,
            variants: product.variants.map((variant) => {
                const available = decimalUnits(variant.available ?? '0') - (reservations.get(Number(variant.id)) ?? 0n);
                return { ...variant, available: decimalText(available > 0n ? available : 0n) };
            }),
        })),
    };
}

async function queueOfflineSale(sale, deviceId, deviceCode) {
    for (const item of [...sale.sale.items, ...sale.quote.items]) wholeQuantityUnits(item.quantity);
    if (sale.scopeKey !== scopeKey(sale.scope) || Number(deviceId) !== Number(sale.scope.device_id)) throw new Error(labels.unavailable);
    const database = await scopedDatabase(sale.scopeKey);
    return new Promise((resolve, reject) => {
        const transaction = database.transaction([catalogStoreName, salesStoreName], 'readwrite');
        const catalogRequest = transaction.objectStore(catalogStoreName).get(sale.scopeKey);
        const store = transaction.objectStore(salesStoreName);
        const branchSalesRequest = store.index('branchId').getAll(Number(sale.scope.branch_id));
        const deviceSalesRequest = store.index('scopeKey').getAll(sale.scopeKey);
        const results = {};
        let failure = null;
        const rejectTransaction = (error) => {
            failure = error;
            transaction.abort();
        };
        const validateAndQueue = () => {
            assertScope(sale.scopeKey);
            if (Object.keys(results).length < 3) return;
            if (!results.catalog) {
                rejectTransaction(new Error('The cached catalog is unavailable. Refresh before selling offline.'));
                return;
            }
            const catalog = results.catalog;
            if (!catalog.offline_outbox?.registered) {
                rejectTransaction(new Error(labels.outboxMembership));
                return;
            }
            if (results.branchSales.filter((existing) => existing.scopeKey === sale.scopeKey).length >= 10000) {
                rejectTransaction(new Error(labels.outboxPending));
                return;
            }
            sale.outbox_id = catalog.offline_outbox.id;
            const effective = applyLocalStock(catalog, results.branchSales);
            const availableByVariant = new Map(effective.products.flatMap((product) => product.variants.map((variant) => [
                Number(variant.id),
                decimalUnits(variant.available ?? '0'),
            ])));
            for (const item of sale.sale.items) {
                if (decimalUnits(item.quantity) > (availableByVariant.get(Number(item.variant_id)) ?? 0n)) {
                    rejectTransaction(new Error('The cached stock estimate is insufficient after pending offline sales.'));
                    return;
                }
            }

            const deviceSales = results.deviceSales.sort((left, right) => left.created_at.localeCompare(right.created_at));
            let sequence = deviceSales.reduce((highest, existing) => {
                const match = existing.local_reference?.match(/-S(\d+)$/);
                return match ? Math.max(highest, Number(match[1])) : highest;
            }, 0);
            for (const existing of deviceSales) {
                if (existing.local_reference) continue;
                sequence += 1;
                existing.local_reference = readableSaleReference(deviceCode, sequence);
                store.put(existing);
            }
            sequence += 1;
            sale.local_reference = readableSaleReference(deviceCode, sequence);
            store.add(sale);
        };
        catalogRequest.onsuccess = () => { results.catalog = catalogRequest.result; validateAndQueue(); };
        branchSalesRequest.onsuccess = () => { results.branchSales = branchSalesRequest.result; validateAndQueue(); };
        deviceSalesRequest.onsuccess = () => { results.deviceSales = deviceSalesRequest.result; validateAndQueue(); };
        for (const request of [catalogRequest, branchSalesRequest, deviceSalesRequest]) {
            request.onerror = () => rejectTransaction(request.error ?? new Error('Unable to reserve local stock'));
        }
        transaction.oncomplete = () => resolve(sale);
        transaction.onabort = () => reject(failure ?? transaction.error ?? new Error('Unable to reserve local stock'));
    });
}

async function queueOfflineReturn(offlineReturn) {
    for (const item of offlineReturn.return.items) wholeQuantityUnits(item.quantity);
    if (offlineReturn.scopeKey !== scopeKey(offlineReturn.scope)) throw new Error(labels.unavailable);
    const database = await scopedDatabase(offlineReturn.scopeKey);
    return new Promise((resolve, reject) => {
        const transaction = database.transaction([catalogStoreName, salesStoreName, returnsStoreName], 'readwrite');
        const catalogRequest = transaction.objectStore(catalogStoreName).get(offlineReturn.scopeKey);
        const salesRequest = transaction.objectStore(salesStoreName).get(offlineReturn.source_client_transaction_id);
        const store = transaction.objectStore(returnsStoreName);
        const returnsRequest = store.index('scopeKey').getAll(offlineReturn.scopeKey);
        const results = {};
        let failure = null;
        const rejectTransaction = (error) => {
            failure = error;
            transaction.abort();
        };
        const validateAndQueue = () => {
            assertScope(offlineReturn.scopeKey);
            if (Object.keys(results).length < 3) return;
            if (!results.catalog) {
                rejectTransaction(new Error(labels.unavailable));
                return;
            }
            const sourceSale = returnSources(results.catalog, results.sourceSale ? [results.sourceSale] : [], offlineReturn.scopeKey)
                .find((sale) => sale.client_transaction_id === offlineReturn.source_client_transaction_id);
            if (!sourceSale || results.catalog.return_sources_refresh_failed) {
                rejectTransaction(new Error(labels.returnSourceUnavailable));
                return;
            }
            const authorization = results.catalog.return_authorization;
            if (!authorization?.can_create_returns || !authorization.token
                || !Number.isFinite(Date.parse(authorization.expires_at))
                || Date.parse(authorization.expires_at) <= Date.now()) {
                rejectTransaction(new Error(labels.returnPermissionExpired));
                return;
            }
            if (sourceSale.status !== 'completed' || sourceSale.scopeKey !== offlineReturn.scopeKey
                || !Array.isArray(sourceSale.result?.return_lines)) {
                rejectTransaction(new Error(labels.returnSourceUnavailable));
                return;
            }
            const localReservations = new Map();
            for (const existing of results.returns) {
                if (existing.source_client_transaction_id !== offlineReturn.source_client_transaction_id
                    || existing.status === 'cancelled') continue;
                for (const item of existing.return.items) {
                    const itemId = Number(item.sales_delivery_item_id);
                    localReservations.set(itemId, (localReservations.get(itemId) ?? 0n) + decimalUnits(item.quantity));
                }
            }
            const sourceLines = new Map(sourceSale.result.return_lines.map((line) => [Number(line.sales_delivery_item_id), line]));
            const requestedItems = new Set();
            for (const item of offlineReturn.return.items) {
                const sourceLine = sourceLines.get(Number(item.sales_delivery_item_id));
                if (!sourceLine || requestedItems.has(Number(item.sales_delivery_item_id))
                    || Number(sourceLine.sales_invoice_item_id) !== Number(item.sales_invoice_item_id)) {
                    rejectTransaction(new Error(labels.returnSourceUnavailable));
                    return;
                }
                const available = decimalUnits(sourceLine.remaining ?? sourceLine.quantity)
                    - (localReservations.get(Number(item.sales_delivery_item_id)) ?? 0n);
                requestedItems.add(Number(item.sales_delivery_item_id));
                if (decimalUnits(item.quantity) <= 0n || decimalUnits(item.quantity) > available) {
                    rejectTransaction(new Error(labels.returnQuantityExceeded));
                    return;
                }
            }
            offlineReturn.authorization_token = authorization.token;
            store.add(offlineReturn);
        };
        catalogRequest.onsuccess = () => { results.catalog = catalogRequest.result; validateAndQueue(); };
        salesRequest.onsuccess = () => { results.sourceSale = salesRequest.result; validateAndQueue(); };
        returnsRequest.onsuccess = () => { results.returns = returnsRequest.result; validateAndQueue(); };
        for (const request of [catalogRequest, salesRequest, returnsRequest]) {
            request.onerror = () => rejectTransaction(request.error ?? new Error('Unable to reserve local return quantity'));
        }
        transaction.oncomplete = () => resolve(offlineReturn);
        transaction.onabort = () => reject(failure ?? transaction.error ?? new Error('Unable to reserve local return quantity'));
    });
}

function localQuote(catalog, cart) {
    const variants = new Map(catalog.products.flatMap((product) => product.variants.map((variant) => [
        variant.id,
        { ...variant, product, discounts: product.discounts ?? [] },
    ])));
    const items = Object.entries(cart).map(([id, line]) => {
        const variant = variants.get(Number(id));
        const quantity = wholeQuantityUnits(line.quantity);
        if (!variant || quantity > decimalUnits(variant.available ?? '0')) {
            throw new Error('The cached stock estimate is insufficient');
        }
        const subtotal = roundedRatio(quantity * decimalUnits(variant.price), 10000n);
        const discount = line.discount_id === null ? null : variant.discounts.find((item) => item.id === line.discount_id);
        if (line.discount_id !== null && !discount) throw new Error('The cached discount is no longer available');
        const discountAmount = discount
            ? roundedRatio(subtotal * decimalUnits(discount.value), 1000000n)
            : 0n;
        const taxable = subtotal - discountAmount;
        const taxAmount = (variant.taxes ?? []).reduce((sum, tax) => {
            if (tax.type !== 'percentage' || tax.price_inclusion !== 'excluded') {
                throw new Error('The cached tax policy cannot be estimated offline');
            }
            return sum + roundedRatio(taxable * decimalUnits(tax.rate), 1000000n);
        }, 0n);

        return {
            variant_id: Number(id),
            quantity: decimalText(quantity),
            discount_id: line.discount_id,
            unit_price: decimalText(decimalUnits(variant.price)),
            subtotal: decimalText(subtotal),
            discount_amount: decimalText(discountAmount),
            tax_amount: decimalText(taxAmount),
            line_total: decimalText(taxable + taxAmount),
        };
    }).sort((left, right) => left.variant_id - right.variant_id);
    const totals = {
        subtotal: items.reduce((sum, item) => sum + decimalUnits(item.subtotal), 0n),
        discount_amount: items.reduce((sum, item) => sum + decimalUnits(item.discount_amount), 0n),
        tax_amount: items.reduce((sum, item) => sum + decimalUnits(item.tax_amount), 0n),
        total_amount: items.reduce((sum, item) => sum + decimalUnits(item.line_total), 0n),
    };

    return Object.fromEntries([
        ['items', items],
        ...Object.entries(totals).map(([field, amount]) => [field, decimalText(amount)]),
    ]);
}

function makeElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = cashierText(text);
    return element;
}

function cashierText(value) {
    const text = String(value ?? '');
    if (/SQLSTATE|Exception|No query results for model|\b\w+_id\b|idempotency|authorization_token|\bid\s*[:=#]\s*\d+/i.test(text)) {
        return labels.syncErrorHelp;
    }
    return text.replace(/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/gi, labels.pendingDocument);
}

function installTerminalPresentation(root, elements, host) {
    const makeDrawer = (id, title) => {
        const dialog = makeElement('dialog', 'fox-pos-drawer');
        dialog.id = id;
        const heading = makeElement('header', 'fox-pos-drawer-heading');
        const titleElement = makeElement('h2', '', title);
        titleElement.id = `${id}-heading`;
        dialog.setAttribute('aria-labelledby', titleElement.id);
        const close = makeElement('button', 'fox-pos-drawer-close', '×');
        close.type = 'button';
        close.dataset.posDialogClose = '';
        close.setAttribute('aria-label', labels.closeDrawer);
        heading.append(titleElement, close);
        dialog.append(heading);
        host.append(dialog);
        return dialog;
    };
    const returns = makeDrawer('pos-returns-drawer', labels.returnsTitle);
    returns.append(elements.returns);
    const history = makeDrawer('pos-local-history-drawer', labels.salesHistory);
    const sync = makeDrawer('pos-sync-drawer', labels.syncTitle);
    sync.append(makeElement('p', 'fox-pos-drawer-description', labels.syncDescription), elements.status.parentElement);
    const queue = makeElement('div', 'fox-pos-drawer-content');
    sync.append(queue);
    const tabs = makeElement('div', 'fox-pos-drawer-tabs');
    const newReturn = makeElement('button', '', labels.newReturn);
    const returnHistory = makeElement('button', '', labels.returnHistoryTitle);
    newReturn.type = returnHistory.type = 'button';
    newReturn.setAttribute('aria-controls', 'pos-return-form');
    returnHistory.setAttribute('aria-controls', 'pos-return-history');
    elements.returnForm.id = 'pos-return-form';
    elements.returnHistory.id = 'pos-return-history';
    const showReturnView = (showHistory) => {
        elements.returnForm.hidden = showHistory;
        elements.returnHistory.hidden = !showHistory;
        newReturn.setAttribute('aria-pressed', String(!showHistory));
        returnHistory.setAttribute('aria-pressed', String(showHistory));
    };
    newReturn.addEventListener('click', () => showReturnView(false));
    returnHistory.addEventListener('click', () => showReturnView(true));
    tabs.append(newReturn, returnHistory);
    elements.returns.prepend(tabs);
    showReturnView(false);
    let opener = null;
    const openDrawer = (dialog, trigger) => {
        if (!dialog || dialog.open) return;
        root.querySelectorAll('dialog[open]').forEach((current) => current.close());
        opener = trigger ?? document.activeElement;
        root.querySelectorAll('[data-pos-nav][aria-expanded]').forEach((button) => {
            button.setAttribute('aria-expanded', String(button === trigger));
        });
        dialog.showModal();
        dialog.querySelector('[data-pos-dialog-close]')?.focus();
    };
    const openReturns = (trigger) => {
        showReturnView(false);
        openDrawer(returns, trigger ?? root.querySelector('[data-pos-nav="returns"]'));
    };
    const onClick = (event) => {
        if (event.target.closest('button')?.getAttribute('wire:click')?.startsWith('openReceipt')) {
            event.target.closest('dialog')?.close();
        }
        const close = event.target.closest('[data-pos-dialog-close]');
        if (close) {
            close.closest('dialog')?.close();
            return;
        }
        const trigger = event.target.closest('[data-pos-nav]');
        if (!trigger) return;
        const view = trigger.dataset.posNav;
        if (view === 'returns') openReturns(trigger);
        if (view === 'history') {
            history.append(elements.sales);
            openDrawer(root.dataset.posOperatingMode === 'online'
                ? root.querySelector('[data-pos-online-history-dialog]') : history, trigger);
        }
        if (view === 'sync') {
            queue.append(elements.sales);
            openDrawer(sync, trigger);
        }
        if (view === 'sales') {
            root.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());
            root.querySelector('[data-pos-online]:not([hidden]) input[type="search"], [data-pos-local]:not([hidden]) input[type="search"]')?.focus();
        }
    };
    const onClose = (event) => {
        if (!(event.target instanceof HTMLDialogElement)) return;
        root.querySelectorAll('[data-pos-nav][aria-expanded]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
        if (!root.querySelector('dialog[open]') && opener?.isConnected) opener.focus();
    };
    root.addEventListener('click', onClick);
    root.addEventListener('close', onClose, true);
    const onKeydown = (event) => {
        if (event.key === 'F12' && root.querySelector('dialog[open]')) {
            event.preventDefault();
            event.stopPropagation();
        }
    };
    root.addEventListener('keydown', onKeydown);
    const observer = new MutationObserver(() => {
        for (const element of root.querySelectorAll('[data-local-status], [data-local-return-status]')) {
            const safeText = cashierText(element.textContent);
            if (safeText !== element.textContent) element.textContent = safeText;
        }
    });
    observer.observe(root, { childList: true, characterData: true, subtree: true });
    window.addEventListener('pagehide', () => {
        observer.disconnect();
        root.removeEventListener('click', onClick);
        root.removeEventListener('close', onClose, true);
        root.removeEventListener('keydown', onKeydown);
    }, { once: true });
    return { openReturns };
}

function uuid() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    throw new Error('This browser cannot create secure offline sale identifiers');
}

function readableSaleReference(deviceCode, sequence) {
    const code = String(deviceCode).replace(/[^A-Z0-9-]/gi, '') || 'POS';

    return `${code}-S${String(sequence).padStart(4, '0')}`;
}

function syncFailureMessage(response, body) {
    if (typeof body?.message === 'string' && body.message.trim() !== '') {
        const validationErrors = Object.values(body.errors ?? {}).flat().filter((message) => typeof message === 'string');

        return [...new Set([body.message, ...validationErrors])].join(' ');
    }

    if (response.redirected) {
        return 'Sign in again, then retry synchronization.';
    }

    return `Synchronization rejected: ${response.status} ${response.statusText || 'Unknown server response'}`;
}

function appendSyncError(sale, status, message) {
    const history = Array.isArray(sale.error_history) ? sale.error_history : [];

    return [...history.slice(-9), { status, message, at: new Date().toISOString() }];
}

async function assignLocalReferences(key, deviceCode, newSale = null) {
    const database = await scopedDatabase(key);
    return new Promise((resolve, reject) => {
        const transaction = database.transaction(salesStoreName, 'readwrite');
        const store = transaction.objectStore(salesStoreName);
        const request = store.index('scopeKey').getAll(key);
        let sales = [];

        request.onsuccess = () => {
            assertScope(key);
            sales = request.result.sort((left, right) => left.created_at.localeCompare(right.created_at));
            let sequence = sales.reduce((highest, sale) => {
                const match = sale.local_reference?.match(/-S(\d+)$/);

                return match ? Math.max(highest, Number(match[1])) : highest;
            }, 0);

            for (const sale of sales) {
                if (sale.local_reference) continue;
                sequence += 1;
                sale.local_reference = readableSaleReference(deviceCode, sequence);
                store.put(sale);
            }

            if (newSale) {
                sequence += 1;
                newSale.local_reference = readableSaleReference(deviceCode, sequence);
                store.add(newSale);
                sales.push(newSale);
            }
        };
        request.onerror = () => reject(request.error ?? new Error('Unable to allocate an offline sale reference'));
        transaction.oncomplete = () => resolve(sales);
        transaction.onabort = () => reject(transaction.error ?? new Error('Unable to save the offline sale reference'));
    });
}

function publicImageUrl(image) {
    if (!image) return null;
    try {
        const url = new URL(image, location.href);
        if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash
            || !/^\/(?:storage\/products|images)\/[a-zA-Z0-9_./%-]+\.(?:png|jpe?g|webp|gif|svg|avif)$/i.test(url.pathname)
            || /(?:\.\.|%2e|%2f|%5c)/i.test(url.pathname)) return null;
        return new URL(url.pathname, location.origin).href;
    } catch {
        return null;
    }
}

async function thumbnailResponse(url) {
    const response = await fetch(url, { credentials: 'omit', cache: 'no-store', redirect: 'error', signal: AbortSignal.timeout(5000) });
    if (response.status === 404 || response.status === 410) return null;
    if (!response.ok) throw new Error('Public image unavailable');
    const type = response.headers.get('content-type') ?? '';
    if (!type.startsWith('image/') || /private|no-store/i.test(response.headers.get('cache-control') ?? '')) return null;
    const reader = response.body.getReader();
    const chunks = [];
    let bytes = 0;
    while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        bytes += value.byteLength;
        if (bytes > 8 * 1024 * 1024) {
            await reader.cancel();
            return null;
        }
        chunks.push(value);
    }
    const objectUrl = URL.createObjectURL(new Blob(chunks, { type }));
    try {
        const image = new Image();
        image.src = objectUrl;
        await image.decode();
        const scale = Math.min(1, 480 / Math.max(image.naturalWidth, image.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
        return await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', 0.82));
    } finally {
        URL.revokeObjectURL(objectUrl);
    }
}

async function cacheCatalogImages(catalog) {
    const urls = new Set((catalog.products ?? []).flatMap((product) =>
        [product.image, ...(product.variants ?? []).map((variant) => variant.image)]).map(publicImageUrl).filter(Boolean));
    const previous = (await readCatalog(catalog.scopeKey))?.offline_images ?? {};
    let budget = offlineImageBudget;
    try {
        const estimate = await navigator.storage?.estimate();
        if (Number.isFinite(estimate?.quota) && Number.isFinite(estimate?.usage)) {
            const replacedBytes = Object.values(previous).reduce((sum, image) => sum + (image instanceof Blob ? image.size : 0), 0);
            budget = Math.min(budget, Math.max(0, estimate.quota - estimate.usage - 4 * 1024 * 1024 + replacedBytes));
        }
    } catch { /* Storage estimates are advisory; the bounded write still handles quota failures. */ }
    const images = {};
    let bytes = 0;
    const pending = [...urls].slice(0, 512);
    await Promise.all(Array.from({ length: 4 }, async () => {
        while (pending.length) {
            const url = pending.pop();
            let image;
            try {
                image = await thumbnailResponse(url);
            } catch {
                image = previous[url];
            }
            if (image instanceof Blob && bytes + image.size <= budget) {
                images[url] = image;
                bytes += image.size;
            }
        }
    }));
    try {
        await updateCatalog(catalog.scopeKey, (current) => current.captured_at === catalog.captured_at
            ? { ...current, offline_images: images, image_revision: uuid() } : current);
    } catch (error) {
        console.warn('POS image storage is full or unavailable; saved sales are unaffected.', error);
    }
}

let selectedReceipt = null;
document.addEventListener('click', (event) => {
    const receipt = event.target.closest?.('.fox-pos-receipt');
    if (receipt && event.target.closest('button')) selectedReceipt = receipt;
}, true);
window.addEventListener('beforeprint', () => {
    document.querySelector('[data-pos-print-root]')?.remove();
    const isAvailable = (receipt) => receipt?.isConnected
        && (receipt.getClientRects().length > 0 || receipt.closest('dialog')?.open);
    const receipt = isAvailable(selectedReceipt) ? selectedReceipt
        : [...document.querySelectorAll('[data-pos-terminal] .fox-pos-receipt:not([hidden])')]
            .reverse().find(isAvailable);
    if (!receipt) return;
    const copy = receipt.cloneNode(true);
    copy.dataset.posPrintRoot = '';
    copy.dir = getComputedStyle(receipt).direction;
    copy.removeAttribute('id');
    document.documentElement.style.setProperty('--pos-paper-width', receipt.dataset.paperWidth ?? '80mm');
    document.body.append(copy);
});
window.addEventListener('afterprint', () => document.querySelector('[data-pos-print-root]')?.remove());

async function captureCatalog(wire, expectedScope) {
    const first = await wire.offlineCatalogPage(1);
    const key = scopeKey(expectedScope);
    if (first.context !== contextCookie() || scopeKey(first.scope) !== key || first.catalog.page !== 1 || first.catalog.last_page > 200) {
        throw new Error('The terminal scope or offline catalog size changed');
    }
    const products = [...first.catalog.items];
    for (let page = 2; page <= first.catalog.last_page; page += 1) {
        const next = await wire.offlineCatalogPage(page);
        if (next.context !== first.context || scopeKey(next.scope) !== key || next.catalog.page !== page) {
            throw new Error('The terminal changed while the offline catalog was loading');
        }
        products.push(...next.catalog.items);
    }
    if (!first.payment_methods.length) throw new Error('No payment methods are configured for this terminal');

    return {
        scopeKey: key,
        context: first.context,
        scope: expectedScope,
        products,
        payment_methods: first.payment_methods,
        return_authorization: first.return_authorization,
        captured_at: new Date().toISOString(),
    };
}

function mountOfflineUi(panel) {
    const toolbar = makeElement('div', 'fox-pos-receipt-actions');
    const status = makeElement('p', 'fox-pos-mode-reason');
    status.dataset.localStatus = '';
    const refresh = makeElement('button', 'fox-pos-button', labels.refresh);
    refresh.type = 'button';
    refresh.dataset.localRefresh = '';
    toolbar.append(status, refresh);

    const catalog = makeElement('main', 'fox-pos-catalog');
    catalog.setAttribute('aria-label', labels.catalog);
    const categories = makeElement('nav', 'fox-pos-categories');
    categories.setAttribute('aria-label', labels.categories);
    const catalogToolbar = makeElement('div', 'fox-pos-catalog-toolbar');
    const searchLabel = makeElement('label', 'fox-pos-search');
    const search = makeElement('input');
    search.type = 'search';
    search.placeholder = labels.search;
    search.setAttribute('aria-label', labels.search);
    search.dataset.localSearch = '';
    searchLabel.append(search);
    const filter = (name, allLabel, key) => {
        const label = makeElement('label');
        const labelText = makeElement('span', 'sr-only', name);
        const select = makeElement('select');
        select.dataset[key] = '';
        const allOption = makeElement('option', '', allLabel);
        allOption.value = '';
        select.append(allOption);
        label.append(labelText, select);
        return { label, select };
    };
    const brandFilter = filter(labels.brand, labels.allBrands, 'localBrand');
    const sizeFilter = filter(labels.size, labels.allSizes, 'localSize');
    const colorFilter = filter(labels.color, labels.allColors, 'localColor');
    const productCount = makeElement('span', 'fox-pos-product-count');
    catalogToolbar.append(searchLabel, brandFilter.label, sizeFilter.label, colorFilter.label, productCount);
    const products = makeElement('div', 'fox-pos-product-grid');
    products.dataset.localProducts = '';
    catalog.append(categories, catalogToolbar, products);

    const workspace = makeElement('div', 'fox-pos-workspace');
    const cartSection = makeElement('aside', 'fox-pos-cart');
    cartSection.setAttribute('aria-labelledby', 'pos-cart-heading');
    const cartSale = makeElement('div', 'fox-pos-cart-sale');
    const cartHeading = makeElement('div', 'fox-pos-section-heading');
    const cartTitle = makeElement('h2', '', labels.cart);
    cartTitle.id = 'pos-cart-heading';
    const cartCount = makeElement('span', '', '(0)');
    cartTitle.append(' ', cartCount);
    const clearCart = makeElement('button', 'fox-pos-clear', labels.clearCart);
    clearCart.type = 'button';
    clearCart.dataset.localClear = '';
    cartHeading.append(cartTitle, clearCart);
    const cart = makeElement('div', 'fox-pos-cart-items');
    cart.dataset.localCart = '';
    const emptyCart = makeElement('div', 'fox-pos-cart-empty', labels.empty);
    const footer = makeElement('div', 'fox-pos-cart-footer');
    const totals = makeElement('dl', 'fox-pos-totals');
    const totalRow = (title, key, className = '') => {
        const row = makeElement('div', className);
        const term = makeElement('dt', '', title);
        const value = makeElement('dd');
        value.dataset[key] = '';
        row.append(term, value);
        totals.append(row);
        return value;
    };
    const subtotal = totalRow(labels.subtotal, 'localSubtotal');
    const discount = totalRow(labels.discount, 'localDiscount', 'fox-pos-discount-total');
    const tax = totalRow(labels.tax, 'localTax');
    const total = totalRow(labels.total, 'localTotal', 'fox-pos-total');
    const complete = makeElement('button', 'fox-pos-checkout-button', labels.checkout);
    complete.type = 'button';
    complete.dataset.localCheckout = '';
    footer.append(totals, complete);
    cartSale.append(cartHeading, cart, emptyCart, footer);

    const paymentModal = makeElement('div', 'fox-pos-modal-backdrop');
    paymentModal.hidden = true;
    paymentModal.dataset.localPaymentModal = '';
    const paymentDialog = makeElement('section', 'fox-pos-payment-modal');
    paymentDialog.setAttribute('role', 'dialog');
    paymentDialog.setAttribute('aria-modal', 'true');
    paymentDialog.setAttribute('aria-labelledby', 'pos-offline-payment-heading');
    const paymentHeader = makeElement('header');
    const paymentHeading = makeElement('div');
    paymentHeading.append(makeElement('p', '', labels.checkout), makeElement('h2', '', labels.payment));
    paymentHeading.lastChild.id = 'pos-offline-payment-heading';
    const closePayment = makeElement('button', 'fox-pos-remove', labels.cancel);
    closePayment.type = 'button';
    closePayment.dataset.localPaymentClose = '';
    paymentHeader.append(paymentHeading, closePayment);
    const paymentTotal = makeElement('div', 'fox-pos-payment-total');
    paymentTotal.append(makeElement('span', '', labels.total));
    const paymentAmount = makeElement('strong');
    paymentAmount.dataset.localPaymentTotal = '';
    paymentTotal.append(paymentAmount);
    const paymentFields = makeElement('div', 'fox-pos-payment-customer');
    const paymentMethodLabel = makeElement('label', '', labels.paymentMethod);
    const paymentMethod = makeElement('select');
    paymentMethod.dataset.localPaymentMethod = '';
    paymentMethodLabel.append(paymentMethod);
    const referenceLabel = makeElement('label', '', labels.reference);
    const reference = makeElement('input');
    reference.type = 'text';
    reference.maxLength = 191;
    reference.dataset.localReference = '';
    referenceLabel.append(reference);
    const cashLabel = makeElement('label', '', labels.received);
    const cashReceived = makeElement('input');
    cashReceived.type = 'number';
    cashReceived.min = '0';
    cashReceived.step = '0.01';
    cashReceived.dataset.localCashReceived = '';
    cashLabel.append(cashReceived);
    paymentFields.append(paymentMethodLabel, referenceLabel, cashLabel);
    const paymentActions = makeElement('footer');
    const cancelPayment = makeElement('button', 'fox-pos-button', labels.cancel);
    cancelPayment.type = 'button';
    cancelPayment.dataset.localPaymentClose = '';
    const completeSale = makeElement('button', 'fox-pos-button fox-pos-button--primary', labels.complete);
    completeSale.type = 'button';
    completeSale.dataset.localComplete = '';
    paymentActions.append(cancelPayment, completeSale);
    paymentDialog.append(paymentHeader, paymentTotal, paymentFields, paymentActions);
    paymentModal.append(paymentDialog);

    const sales = makeElement('section', 'fox-pos-recent');
    sales.dataset.localSales = '';
    const returns = makeElement('section', 'fox-pos-return-workspace');
    returns.dataset.localReturns = '';
    const returnNote = makeElement('p', '', labels.returnNote);
    const returnStatus = makeElement('p');
    returnStatus.dataset.localReturnStatus = '';
    returnStatus.setAttribute('role', 'status');
    const returnAvailability = makeElement('p');
    returnAvailability.setAttribute('role', 'status');
    const refreshReturns = makeElement('button', 'fox-pos-button', labels.returnRefresh);
    refreshReturns.type = 'button';
    const returnSource = makeElement('select');
    returnSource.setAttribute('aria-label', labels.returnSource);
    returnSource.dataset.localReturnSource = '';
    const returnItems = makeElement('div');
    returnItems.dataset.localReturnItems = '';
    const completeReturn = makeElement('button', 'fox-pos-button fox-pos-button--primary', labels.returnSubmit);
    completeReturn.type = 'button';
    completeReturn.dataset.localReturnComplete = '';
    const returnHistory = makeElement('div');
    returnHistory.dataset.localReturnHistory = '';
    const returnForm = makeElement('div', 'fox-pos-return-form');
    const returnAuthorization = makeElement('p', 'fox-pos-return-authorization');
    const authorizationRow = makeElement('div', 'fox-pos-authorization-row');
    authorizationRow.append(returnAuthorization, refreshReturns);
    const sourceLabel = makeElement('label', 'fox-pos-return-source-label', labels.returnSource);
    sourceLabel.append(returnSource);
    returnNote.className = 'fox-pos-return-notice';
    returnForm.append(returnAvailability, sourceLabel, returnItems, returnNote, completeReturn,
        makeElement('p', 'fox-pos-return-hint', labels.returnSourcesHint));
    returns.append(returnStatus, authorizationRow, returnForm, returnHistory);
    const receipt = makeElement('section', 'fox-pos-receipt');
    receipt.hidden = true;
    receipt.dataset.localReceipt = '';
    cartSection.append(cartSale, sales);
    workspace.append(catalog, cartSection);
    panel.replaceChildren(toolbar, workspace, returns, receipt, paymentModal);

    return {
        status, refresh, search, products, categories, brand: brandFilter.select, size: sizeFilter.select,
        color: colorFilter.select, productCount, cart, cartCount, emptyCart, clearCart, subtotal, discount,
        tax, total, checkout: complete, paymentModal, paymentMethod, paymentTotal: paymentAmount,
        reference, cashReceived, complete: completeSale, closePayment, cancelPayment, receipt, sales,
        returns, returnSource, returnItems, completeReturn, returnHistory, returnStatus, returnAvailability, refreshReturns, returnForm, returnAuthorization,
    };
}

export async function attachOfflineTerminal(root, wire, scope, options = {}) {
    if (!root || root.dataset.offlineAttached) return;
    root.dataset.offlineAttached = 'true';
    const panel = root.querySelector('[data-pos-local]');
    if (!panel) return;
    const authorizedScope = JSON.parse(panel.dataset.posScope ?? 'null');
    const context = panel.dataset.posContext;
    if (!context || context !== contextCookie() || !authorizedScope
        || scopeKey(scope ?? authorizedScope) !== scopeKey(authorizedScope)) {
        root.replaceChildren(makeElement('p', '', 'Reconnect and sign in to verify this offline POS scope. Saved transactions are preserved.'));
        root.hidden = false;
        return;
    }
    scopeContexts.set(scopeKey(authorizedScope), context);
    root.hidden = false;
    const strings = { ...labels, ...(options.labels ?? {}) };
    Object.assign(labels, strings);
    const elements = mountOfflineUi(panel);
    root.append(elements.receipt);
    const returnsHost = root.querySelector('[data-pos-returns-host]');
    if (returnsHost) returnsHost.append(elements.returns);
    const presentation = installTerminalPresentation(root, elements, returnsHost ?? panel);
    const onlinePanel = root.querySelector('[data-pos-online]');
    const parsedScope = scope ?? JSON.parse(panel.dataset.posScope ?? 'null');
    if (!parsedScope) {
        elements.status.textContent = labels.unavailable;
        return;
    }
    const key = scopeKey(parsedScope);
    const syncUrl = panel.dataset.posSyncUrl;
    const outboxUrl = panel.dataset.posOutboxUrl;
    const returnSyncUrl = panel.dataset.posReturnSyncUrl;
    const deviceCode = panel.dataset.posDeviceCode || `POS${parsedScope.device_id}`;
    const fallbackImage = placeholderImage;
    const imageSources = new Map();
    let imageRevision = null;
    const imageSource = (image) => imageSources.get(publicImageUrl(image)) ?? fallbackImage;
    let catalog = null;
    let serverCatalog = null;
    let cart = {};
    let selectedReturnSaleId = '';
    let savingReturn = false;
    let cashReceivedEdited = false;
    const hasReturnAuthorization = () => catalog?.return_authorization?.can_create_returns === true
        && Boolean(catalog.return_authorization.token)
        && Number.isFinite(Date.parse(catalog.return_authorization.expires_at))
        && Date.parse(catalog.return_authorization.expires_at) > Date.now();
    const setReturnStatus = (message) => {
        elements.returnStatus.textContent = message;
        elements.status.textContent = message;
    };
    const selectedVariantIds = {};
    let activeMode = null;
    let offlineCartDirty = false;
    let offlinePaymentDirty = false;
    let receiptTransactionId = null;
    let syncing = false;
    let attached = true;
    let retryTimer = null;
    const stockChannel = typeof BroadcastChannel === 'function'
        ? new BroadcastChannel(`fox-hema-pos-stock-${parsedScope.branch_id}`)
        : null;
    const checkContext = () => {
        try {
            if (context !== contextCookie()) throw new Error(labels.unavailable);
            assertScope(key);
            return true;
        } catch {
            attached = false;
            clearInterval(contextTimer);
            selectedReceipt = null;
            document.querySelector('[data-pos-print-root]')?.remove();
            clearTimeout(retryTimer);
            for (const url of imageSources.values()) URL.revokeObjectURL(url);
            imageSources.clear();
            root.replaceChildren(makeElement('p', '', 'Reconnect and sign in to verify this offline POS scope. Saved transactions are preserved.'));
            return false;
        }
    };
    const contextTimer = setInterval(checkContext, 250);
    const guardContext = (event) => {
        if (!checkContext()) { event.preventDefault(); event.stopImmediatePropagation(); }
    };
    root.addEventListener('click', guardContext, true);
    root.addEventListener('keydown', guardContext, true);
    window.addEventListener('focus', checkContext);
    window.addEventListener('pageshow', checkContext);
    document.addEventListener('visibilitychange', checkContext);

    const setMode = (offline) => {
        if (!offline) elements.receipt.hidden = true;
        if (offline) root.querySelector('[data-pos-online-history-dialog][open]')?.close();
        if (onlinePanel) onlinePanel.hidden = offline;
        panel.hidden = !offline;
        root.dataset.posOperatingMode = offline ? 'offline' : 'online';
        const changed = activeMode !== offline;
        activeMode = offline;
        if (changed && wire && typeof wire.$get === 'function') {
            if (offline) {
                const onlineCart = wire.$get('cart');
                if (onlineCart && !offlineCartDirty) cart = snapshotCart(onlineCart);
                const onlineTenders = wire.$get('tenders');
                const tenderEntries = onlineTenders && Object.entries(onlineTenders);
                if (tenderEntries?.length === 1) {
                    elements.paymentMethod.value = String(tenderEntries[0][0]);
                    elements.reference.value = tenderEntries[0][1].external_reference ?? '';
                }
                const cashReceived = wire.$get('cashReceived');
                if (cashReceived !== undefined && cashReceived !== null) {
                    elements.cashReceived.value = String(cashReceived);
                    cashReceivedEdited = decimalUnits(cashReceived) > 0n;
                }
                renderProducts();
                renderCart();
            } else if ((offlineCartDirty || offlinePaymentDirty) && typeof wire.$set === 'function') {
                Promise.resolve(wire.$set('cart', structuredClone(cart)))
                    .then(async () => {
                        const quote = localQuote(catalog, cart);
                        const method = catalog?.payment_methods.find((item) => String(item.id) === elements.paymentMethod.value);
                        if (method) {
                            await wire.$set('tenders', {
                                [method.id]: {
                                    amount: quote.total_amount,
                                    external_reference: elements.reference.value.trim(),
                                },
                            });
                            await wire.$set('cashReceived', elements.cashReceived.value || quote.total_amount);
                        }
                        offlineCartDirty = false;
                        offlinePaymentDirty = false;
                    })
                    .catch((error) => {
                        elements.status.textContent = error instanceof Error ? error.message : labels.unavailable;
                    });
            } else {
                const onlineCart = wire.$get('cart');
                if (onlineCart) cart = snapshotCart(onlineCart);
                renderCart();
            }
        }
    };
    const setCatalogReadiness = (ready) => {
        root.dataset.posCatalogReady = ready ? 'true' : 'false';
        root.dispatchEvent(new CustomEvent('pos-offline-catalog', { detail: { ready } }));
    };

    const refreshLocalStock = async () => {
        if (!serverCatalog) {
            catalog = null;
            return;
        }
        const local = await readCatalog(key);
        serverCatalog = { ...serverCatalog, offline_outbox: local?.offline_outbox };
        if (local?.image_revision !== imageRevision) {
            for (const url of imageSources.values()) URL.revokeObjectURL(url);
            imageSources.clear();
            for (const [url, image] of Object.entries(local?.offline_images ?? {})) {
                if (image instanceof Blob) imageSources.set(url, URL.createObjectURL(image));
            }
            imageRevision = local?.image_revision;
        }
        catalog = applyLocalStock(serverCatalog, await listSalesForBranch(parsedScope.branch_id, key));
    };

    const renderPaymentMethods = () => {
        const selected = elements.paymentMethod.value;
        elements.paymentMethod.replaceChildren(...(catalog?.payment_methods ?? []).map((method) => {
            const option = makeElement('option', '', method.name);
            option.value = String(method.id);
            return option;
        }));
        if ([...elements.paymentMethod.options].some((option) => option.value === selected)) {
            elements.paymentMethod.value = selected;
        }
        if (!elements.paymentMethod.value) {
            elements.paymentMethod.value = String(catalog?.payment_methods?.[0]?.id ?? '');
        }
    };

    const findVariant = (variantId) => {
        for (const product of catalog?.products ?? []) {
            const variant = product.variants.find((candidate) => candidate.id === variantId);
            if (variant) return { ...variant, product, discounts: product.discounts ?? [] };
        }
        return null;
    };

    const outboxRequest = async (action, outbox) => {
        if (!navigator.onLine || !outboxUrl) throw new Error(labels.outboxReconnect);
        const response = await fetch(outboxUrl, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': panel.dataset.posCsrf },
            body: JSON.stringify({ action, scope: parsedScope, outbox_id: outbox.id,
                 }),
        });
        const body = await response.json().catch(() => null);
        if (!response.ok || response.redirected || body?.outbox_id !== outbox.id
            || body?.status !== (action === 'verify' ? 'verified' : 'registered')) {
            const error = new Error(syncFailureMessage(response, body));
            error.outboxAuthorizationFailed = action === 'verify' && (response.redirected || [401, 403, 404, 409].includes(response.status));
            throw error;
        }
        return body;
    };

    const refreshCatalog = async () => {
        if (!wire || !navigator.onLine || elements.refresh.disabled) return;
        elements.refresh.disabled = true;
        elements.status.textContent = labels.refresh;
        try {
            let local = await updateCatalog(key, (existing) => ({
                ...existing, scopeKey: key, scope: parsedScope,
                offline_outbox: existing?.offline_outbox ?? { id: uuid(), registered: false },
            }));
            if (local.offline_outbox.registered) {
                const verified = await outboxRequest('verify', local.offline_outbox);
                if (verified.transaction_ids !== null && verified.transaction_ids !== undefined) {
                    local = await updateCatalog(key, (existing) => ({ ...existing, offline_outbox: {
                        ...existing.offline_outbox, transaction_ids: verified.transaction_ids,
                    } }));
                }
            } else {
                await outboxRequest('register', local.offline_outbox);
                local = await updateCatalog(key, (existing) => ({ ...existing,
                    offline_outbox: { ...existing.offline_outbox, registered: true } }));
            }
            const fresh = await captureCatalog(wire, parsedScope);
            fresh.offline_outbox = local.offline_outbox;
            await saveCatalog(fresh);
            serverCatalog = fresh;
            if (fresh.return_authorization?.can_create_returns && typeof wire.offlineReturnSources === 'function') {
                try {
                    const sources = new Map();
                    let afterId = 0;
                    do {
                        const response = await wire.offlineReturnSources(null, afterId);
                        if (scopeKey(response?.scope) !== key || !Array.isArray(response.sales)) {
                            throw new Error(labels.returnSourcesRefreshFailed);
                        }
                        for (const sale of response.sales) {
                            if (scopeKey(sale.scope) !== key) throw new Error(labels.returnSourcesRefreshFailed);
                            sources.set(sale.client_transaction_id, sale);
                        }
                        const nextId = response.next_after_id;
                        if (nextId !== null && (!Number.isSafeInteger(nextId) || nextId <= afterId)) {
                            throw new Error(labels.returnSourcesRefreshFailed);
                        }
                        afterId = nextId;
                    } while (afterId !== null);
                    fresh.return_sources = [...sources.values()];
                } catch (error) {
                    fresh.return_sources_refresh_failed = true;
                    setReturnStatus(labels.returnSourcesRefreshFailed);
                    console.warn('POS offline return sources could not be refreshed.', error);
                }
            }
            await saveCatalog(fresh);
            await refreshLocalStock();
            await cacheCatalogImages(fresh);
            await refreshLocalStock();
            renderPaymentMethods();
            setCatalogReadiness(true);
            elements.status.textContent = fresh.return_sources_refresh_failed ? labels.returnSourcesRefreshFailed
                : `${labels.catalog} · ${new Date(fresh.captured_at).toLocaleString()}`;
            renderProducts();
            renderCart();
            renderSales();
        } catch (error) {
            if (error?.outboxAuthorizationFailed) {
                serverCatalog = null;
                catalog = null;
                setCatalogReadiness(false);
                scopeContexts.delete(key);
                catalogContexts.delete(key);
                checkContext();
                root.replaceChildren(makeElement('p', '', labels.outboxMembership));
            }
            elements.status.textContent = error instanceof Error ? error.message : labels.unavailable;
        } finally {
            elements.refresh.disabled = false;
        }
    };

    const addCartLine = (product, variant) => {
        const discountId = product.discounts?.length === 1 ? product.discounts[0].id : null;
        const existing = cart[variant.id];
        cart[variant.id] = {
            quantity: decimalText((existing ? wholeQuantityUnits(existing.quantity) : 0n) + 10000n),
            discount_id: existing?.discount_id ?? discountId,
        };
        offlineCartDirty = activeMode === true;
        renderCart();
    };

    const renderProducts = () => {
        elements.products.replaceChildren();
        const term = elements.search.value.trim().toLocaleLowerCase();
        const products = catalog?.products ?? [];
        const activeCategory = elements.categories.dataset.selectedCategory ?? '';
        const activeBrand = elements.brand.value;
        const activeSize = elements.size.value;
        const activeColor = elements.color.value;
        const categories = [...new Set(products.map((product) => product.category).filter(Boolean))].sort();
        const brands = [...new Set(products.map((product) => product.brand).filter(Boolean))].sort();
        const sizes = [...new Set(products.flatMap((product) => product.variants.map((variant) => variant.size).filter(Boolean)))].sort();
        const colors = [...new Set(products.flatMap((product) => product.variants.map((variant) => variant.color).filter(Boolean)))].sort();
        const setOptions = (select, allLabel, values, selected) => {
            const allOption = makeElement('option', '', allLabel);
            allOption.value = '';
            select.replaceChildren(allOption, ...values.map((value) => makeElement('option', '', value)));
            select.value = values.includes(selected) ? selected : '';
        };
        setOptions(elements.brand, labels.allBrands, brands, activeBrand);
        setOptions(elements.size, labels.allSizes, sizes, activeSize);
        setOptions(elements.color, labels.allColors, colors, activeColor);

        const categoryButtons = [makeElement('button', activeCategory ? '' : 'is-selected', labels.allCategories)];
        categoryButtons[0].type = 'button';
        categoryButtons[0].addEventListener('click', () => {
            elements.categories.dataset.selectedCategory = '';
            renderProducts();
        });
        for (const category of categories) {
            const button = makeElement('button', category === activeCategory ? 'is-selected' : '', category);
            button.type = 'button';
            button.addEventListener('click', () => {
                elements.categories.dataset.selectedCategory = category;
                renderProducts();
            });
            categoryButtons.push(button);
        }
        elements.categories.replaceChildren(...categoryButtons);

        const visibleProducts = products.flatMap((product) => {
            if (activeCategory && product.category !== activeCategory) return [];
            if (activeBrand && product.brand !== activeBrand) return [];
            const variants = product.variants.filter((variant) =>
                (!activeSize || variant.size === activeSize) && (!activeColor || variant.color === activeColor));
            const searchable = `${product.name} ${product.sku ?? ''} ${product.brand ?? ''} ${product.category ?? ''} ${variants.map((variant) => `${variant.label} ${variant.sku ?? ''} ${variant.barcode ?? ''}`).join(' ')}`.toLocaleLowerCase();
            if (!variants.length || (term && !searchable.includes(term))) return [];

            return [{ product, variants }];
        });
        elements.productCount.textContent = labels.productsCount.replace(':count', String(visibleProducts.length));
        if (!catalog || visibleProducts.length === 0) {
            const empty = makeElement('div', 'fox-pos-no-products');
            empty.append(makeElement('strong', '', catalog ? labels.noProducts : labels.noCatalog));
            elements.products.append(empty);
            return;
        }

        for (const { product, variants } of visibleProducts) {
            const selectedId = Number(product.selected_variant_id ?? product.variants?.find((variant) => variant.id === selectedVariantIds[product.id])?.id);
            const variant = variants.find((candidate) => candidate.id === selectedId) ?? variants[0];
            selectedVariantIds[product.id] = variant.id;
            const card = makeElement('article', 'fox-pos-product-card');
            if (product.discounts?.length === 1) {
                card.append(makeElement('span', 'fox-pos-product-badge', labels.discountBadge.replace(':value', String(product.discounts[0].value).replace(/\.?0+$/, ''))));
            }
            const image = makeElement('img', 'fox-pos-product-image');
            image.src = imageSource(variant.image || product.image);
            image.alt = product.name;
            image.loading = 'lazy';
            image.decoding = 'async';
            image.addEventListener('error', () => {
                image.onerror = null;
                image.src = fallbackImage;
            }, { once: true });
            card.append(image);
            const title = makeElement('h3');
            title.title = product.name;
            title.append(makeElement('bdi', '', product.name));
            card.append(title);
            card.dataset.cardProduct = String(product.id);
            card.classList.toggle('is-out-of-stock', decimalUnits(variant.available ?? '0') <= 0n);
            const selectors = makeElement('div', 'fox-pos-card-variants');
            for (const [field, label, otherField] of [['size', labels.size, 'color'], ['color', labels.color, 'size']]) {
                const values = [...new Set(variants.map((option) => option[field]).filter(Boolean))];
                if (!values.length) continue;
                const group = makeElement('div', 'fox-pos-card-variant-group');
                group.setAttribute('role', 'group');
                group.setAttribute('aria-label', label);
                group.append(makeElement('span', 'fox-pos-card-variant-label', label));
                const options = makeElement('div', field === 'size' ? 'fox-pos-card-sizes' : 'fox-pos-card-colors');
                for (const value of values) {
                    const matching = variants.filter((option) => option[field] === value);
                    const option = matching.find((candidate) => candidate[otherField] === variant[otherField]) ?? matching[0];
                    const colorLabel = String(value).replace(/#[0-9a-f]{3,8}\b/gi, '').trim() || labels.color;
                    const button = makeElement('button', field === 'color' ? 'fox-pos-color-swatch' : '', field === 'size' ? value : '');
                    button.type = 'button';
                    button.classList.toggle('is-selected', variant[field] === value);
                    button.setAttribute('aria-pressed', String(variant[field] === value));
                    button.setAttribute('aria-label', field === 'color' ? colorLabel : value);
                    button.title = field === 'color' ? colorLabel : value;
                    button.dataset.dimension = field;
                    button.dataset.optionValue = value;
                    if (field === 'color') {
                        const hasColor = /^#[0-9a-f]{6}$/i.test(option.color_hex ?? '');
                        button.classList.toggle('is-unset', !hasColor);
                        if (hasColor) button.style.setProperty('--fox-swatch', option.color_hex);
                    }
                    button.addEventListener('click', () => {
                        selectedVariantIds[product.id] = option.id;
                        renderProducts();
                        elements.products.querySelector(`[data-card-product="${product.id}"] [data-dimension="${field}"][data-option-value="${CSS.escape(value)}"]`)?.focus();
                    });
                    options.append(button);
                }
                group.append(options);
                selectors.append(group);
            }
            card.append(selectors);
            const price = makeElement('div', 'fox-pos-product-price');
            price.append(makeElement('strong', '', `${Number(variant.price).toFixed(2)} ${labels.currency}`));
            price.append(makeElement('span', '', decimalUnits(variant.available ?? '0') <= 0n
                ? labels.outOfStock
                : labels.stockCount.replace(':count', quantityText(variant.available))));
            card.append(price);
            const add = makeElement('button', 'fox-pos-add-button', decimalUnits(variant.available ?? '0') <= 0n ? labels.outOfStock : labels.add);
            add.type = 'button';
            add.disabled = decimalUnits(variant.available ?? '0') <= 0n;
            add.addEventListener('click', () => addCartLine(product, variant));
            card.append(add);
            elements.products.append(card);
        }
    };

    const renderCart = () => {
        elements.cart.replaceChildren();
        const isEmpty = !catalog || Object.keys(cart).length === 0;
        elements.emptyCart.hidden = !isEmpty;
        elements.cart.hidden = isEmpty;
        elements.clearCart.disabled = isEmpty;
        const offlineUnavailable = !catalog?.offline_outbox?.registered;
        elements.checkout.disabled = isEmpty || offlineUnavailable || !catalog?.payment_methods?.length;
        elements.complete.disabled = elements.checkout.disabled;
        elements.checkout.title = elements.complete.title = offlineUnavailable ? labels.outboxMembership : '';
        let quote = { items: [], subtotal: '0.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '0.0000' };
        if (!isEmpty) {
            try {
                quote = localQuote(catalog, cart);
            } catch (error) {
                elements.status.textContent = error instanceof Error ? error.message : labels.unavailable;
                elements.checkout.disabled = true;
                return;
            }
        }
        for (const line of quote.items) {
            const variant = findVariant(line.variant_id);
            const row = makeElement('article', 'fox-pos-cart-item');
            const image = makeElement('img');
            image.src = imageSource(variant?.image || variant?.product.image);
            image.alt = '';
            image.loading = 'lazy';
            image.addEventListener('error', () => {
                image.onerror = null;
                image.src = fallbackImage;
            }, { once: true });
            const details = makeElement('div', 'fox-pos-cart-item-body');
            details.append(makeElement('strong', '', variant?.product.name ?? labels.productFallback));
            details.append(makeElement('small', '', variant?.label ?? ''));
            details.append(makeElement('small', 'fox-pos-unit-price', `${labels.unitPrice} ${Number(line.unit_price).toFixed(2)} ${labels.currency}`));
            if (variant?.discounts?.length) {
                const discountLabel = makeElement('label', 'fox-pos-cart-discount');
                discountLabel.append(makeElement('span', '', labels.discount));
                const discountSelect = makeElement('select');
                discountSelect.append(makeElement('option', '', labels.noDiscount));
                for (const discount of variant.discounts) {
                    const option = makeElement('option', '', `${discount.name} (${discount.value}%)`);
                    option.value = String(discount.id);
                    option.selected = Number(line.discount_id) === discount.id;
                    discountSelect.append(option);
                }
                discountSelect.value = line.discount_id === null ? '' : String(line.discount_id);
                discountSelect.addEventListener('change', () => {
                    cart[line.variant_id].discount_id = discountSelect.value ? Number(discountSelect.value) : null;
                    offlineCartDirty = activeMode === true;
                    renderCart();
                });
                discountLabel.append(discountSelect);
                details.append(discountLabel);
            }
            const controls = makeElement('div', 'fox-pos-cart-controls');
            controls.append(makeElement('span', 'fox-pos-cart-line-total', `${Number(line.line_total).toFixed(2)} ${labels.currency}`));
            const quantity = makeElement('div', 'fox-pos-quantity');
            const decrease = makeElement('button', '', '−');
            decrease.type = 'button';
            decrease.disabled = wholeQuantityUnits(line.quantity) === 10000n;
            decrease.setAttribute('aria-label', `${labels.quantity} −`);
            decrease.addEventListener('click', () => {
                const quantity = wholeQuantityUnits(cart[line.variant_id].quantity) - 10000n;
                if (quantity < 10000n) return;
                cart[line.variant_id].quantity = decimalText(quantity);
                offlineCartDirty = activeMode === true;
                renderCart();
            });
            const increase = makeElement('button', '', '+');
            increase.type = 'button';
            increase.setAttribute('aria-label', `${labels.quantity} +`);
            increase.addEventListener('click', () => {
                cart[line.variant_id].quantity = decimalText(wholeQuantityUnits(cart[line.variant_id].quantity) + 10000n);
                offlineCartDirty = activeMode === true;
                renderCart();
            });
            const quantityCount = makeElement('span');
            quantityCount.append(makeElement('bdi', '', quantityText(line.quantity)));
            quantity.append(decrease, quantityCount, increase);
            controls.append(quantity);
            details.append(controls);
            const remove = makeElement('button', 'fox-pos-remove', labels.remove);
            remove.type = 'button';
            remove.addEventListener('click', () => {
                delete cart[line.variant_id];
                offlineCartDirty = activeMode === true;
                renderCart();
            });
            row.append(image, details, remove);
            elements.cart.append(row);
        }
        elements.cartCount.textContent = `(${quote.items.length})`;
        elements.subtotal.textContent = `${Number(quote.subtotal).toFixed(2)} ${labels.currency}`;
        elements.discount.textContent = `− ${Number(quote.discount_amount).toFixed(2)} ${labels.currency}`;
        elements.tax.textContent = `${Number(quote.tax_amount).toFixed(2)} ${labels.currency}`;
        elements.total.textContent = `${Number(quote.total_amount).toFixed(2)} ${labels.currency}`;
        elements.paymentTotal.textContent = `${Number(quote.total_amount).toFixed(2)} ${labels.currency}`;
        if (isEmpty) return;
        if (elements.paymentMethod.value === '') {
            elements.paymentMethod.value = String(catalog.payment_methods[0]?.id ?? '');
        }
        const method = catalog.payment_methods.find((item) => String(item.id) === elements.paymentMethod.value);
        elements.reference.closest('label').hidden = !method?.requires_reference || method?.type === 'cash';
        elements.cashReceived.closest('label').hidden = method?.type !== 'cash';
        if (method?.type === 'cash' && !cashReceivedEdited) {
            elements.cashReceived.value = quote.total_amount;
        }
    };

    const historicalSalesForSynchronization = async () => {
        if (!navigator.onLine || !checkContext()) return [];
        const database = await scopedDatabase(key);
        const candidates = await storeRequest(database, salesStoreName, 'readonly', (store) => store.index('deviceId').getAll(Number(parsedScope.device_id)));
        const owned = candidates.filter((sale) => sale.scopeKey !== key
            && (['queued', 'conflict'].includes(sale.status) || (sale.status === 'completed' && !sale.outbox_id))
            && Number(sale.scope?.branch_id) === Number(parsedScope.branch_id)
            && Number(sale.scope?.cashier_id) === Number(parsedScope.cashier_id)
            && Number(sale.scope?.device_id) === Number(parsedScope.device_id)
            && scopeKey(sale.scope) === sale.scopeKey);
        const verified = [];
        for (const sale of owned) {
            try {
                const historicalCatalog = sale.outbox_id ? null : await storeRequest(database, catalogStoreName, 'readonly', (store) => store.get(sale.scopeKey));
                const outboxId = sale.outbox_id ?? historicalCatalog?.offline_outbox?.id;
                if (!outboxId) continue;
                const response = await fetch(outboxUrl, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': panel.dataset.posCsrf },
                    body: JSON.stringify({ action: 'verify', scope: sale.scope, outbox_id: outboxId }),
                });
                const body = await response.json().catch(() => null);
                if (!checkContext() || !response.ok || response.redirected || body?.status !== 'verified' || body.outbox_id !== outboxId
                    || (!sale.outbox_id && !body.transaction_ids?.includes(sale.client_transaction_id))) continue;
                scopeContexts.set(sale.scopeKey, contextCookie());
                catalogContexts.set(sale.scopeKey, contextCookie());
                verified.push(sale.outbox_id ? sale : { ...sale, acknowledgement_outbox_id: outboxId });
            } catch { /* Keep historical transactions untouched until their owner reconnects. */ }
        }
        return verified;
    };

    const renderSales = async () => {
        const allDeviceSales = [...await assignLocalReferences(key, deviceCode), ...await historicalSalesForSynchronization()];
        const scopedSales = await listSales(key);
        const returns = await listReturns(key);
        await refreshLocalStock();
        renderProducts();
        const pendingCount = allDeviceSales.filter((sale) => !['completed', 'cancelled', 'archived'].includes(sale.status)).length;
        const errorCount = allDeviceSales.filter((sale) => sale.status === 'conflict').length;
        const syncIndicator = root.querySelector('[data-pos-sync-indicator]');
        if (syncIndicator) syncIndicator.dataset.state = errorCount ? 'failed' : pendingCount ? 'pending' : 'synced';
        const pendingStatus = root.querySelector('[data-pos-offline-sales-readiness]');
        const errorStatus = root.querySelector('[data-pos-sync-errors]');
        if (pendingStatus) pendingStatus.textContent = `${labels.pendingCount}: ${pendingCount}`;
        if (errorStatus) errorStatus.textContent = errorCount ? `${labels.syncErrors}: ${errorCount}` : '';
        const sales = allDeviceSales
            .filter((sale) => scopeContexts.get(sale.scopeKey) === contextCookie())
            .sort((left, right) => right.created_at.localeCompare(left.created_at));
        elements.sales.replaceChildren();
        if (sales.length === 0) {
            elements.sales.hidden = false;
            elements.sales.append(makeElement('p', 'fox-pos-drawer-empty', labels.noSalesHistory));
            elements.receipt.hidden = true;
        } else {
            elements.sales.hidden = false;
            elements.sales.append(makeElement('h3', '', labels.savedSales));
        }
        for (const sale of sales.slice(0, 25)) {
            const state = sale.status === 'queued' ? labels.pending : labels[sale.status] ?? sale.status;
            const invoiceNumber = sale.result?.invoice_number;
            const error = sale.error ? ` · ${labels.syncErrors}: ${sale.error}` : '';
            const entry = makeElement('div', 'fox-pos-recent-sale');
            const item = makeElement('button', '', `${invoiceNumber || labels.pendingDocument} · ${Number(sale.quote.total_amount).toFixed(2)} ${labels.currency} · ${state}${error}`);
            item.type = 'button';
            if (sale.error) item.title = cashierText(sale.error);
            item.addEventListener('click', () => {
                receiptTransactionId = sale.client_transaction_id;
                renderReceipt(sale);
                requestAnimationFrame(() => elements.receipt.scrollIntoView({ block: 'nearest' }));
                root.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());
            });
            entry.append(item);
            if (sale.status === 'conflict') {
                const controls = makeElement('div', 'fox-pos-retry-controls');
                const tender = sale.sale.tenders[0];
                const method = catalog?.payment_methods.find((paymentMethod) => paymentMethod.id === tender?.payment_method_id);
                let reference = null;
                let cashCorrection = null;
                if (method?.type === 'cash') {
                    const cashLabel = makeElement('label', '', labels.received);
                    cashCorrection = makeElement('input');
                    cashCorrection.type = 'number';
                    cashCorrection.min = '0';
                    cashCorrection.step = '0.0001';
                    cashCorrection.value = sale.sale.cash_received;
                    cashCorrection.dataset.localRetryCashReceived = '';
                    cashLabel.append(cashCorrection);
                    controls.append(cashLabel);
                }
                if (method && method.type !== 'cash') {
                    const referenceLabel = makeElement('label', '', labels.reference);
                    reference = makeElement('input');
                    reference.type = 'text';
                    reference.maxLength = 191;
                    reference.value = tender.external_reference ?? '';
                    referenceLabel.append(reference);
                    controls.append(referenceLabel);
                }
                const retry = makeElement('button', '', labels.retry);
                retry.type = 'button';
                retry.addEventListener('click', async () => {
                    retry.disabled = true;
                    elements.status.textContent = labels.retrying;
                    const correctedSale = {
                        ...sale.sale,
                        cash_received: cashCorrection ? cashCorrection.value : sale.sale.cash_received,
                        tenders: sale.sale.tenders.map((existingTender, index) => index === 0 && reference
                            ? { ...existingTender, external_reference: reference.value.trim() || null }
                            : existingTender),
                    };
                    try {
                        if (cashCorrection && decimalUnits(correctedSale.cash_received) < decimalUnits(tender.amount)) {
                            throw new Error(labels.cashReceivedShort);
                        }
                        await updateSale(sale.client_transaction_id, {
                            original_sale: sale.original_sale ?? sale.sale,
                            sale: correctedSale,
                            status: 'queued',
                            next_retry_at: null,
                            retry_count: 0,
                        });
                        await renderSales();
                        await syncSales([sale.client_transaction_id]);
                    } catch (error) {
                        elements.status.textContent = error instanceof Error ? error.message : labels.conflict;
                        retry.disabled = false;
                    }
                });
                controls.append(retry);
                entry.append(controls);
            }
            if (sale.status === 'completed' && Array.isArray(sale.result?.return_lines)
                && catalog?.return_authorization?.can_create_returns) {
                const startReturn = makeElement('button', 'fox-pos-button', labels.startReturn);
                startReturn.type = 'button';
                startReturn.addEventListener('click', () => {
                    selectedReturnSaleId = sale.client_transaction_id;
                    renderReturnForm(scopedSales, returns);
                    presentation.openReturns();
                });
                entry.append(startReturn);
            }
            elements.sales.append(entry);
        }
        const activeReceipt = sales.find((sale) => sale.client_transaction_id === receiptTransactionId) ?? sales[0];
        if (activeMode === true && receiptTransactionId) renderReceipt(activeReceipt);
        renderReturnForm(scopedSales, returns);
        renderReturns(returns);
    };

    const localReturnReservations = (returns, sourceTransactionId, deliveryItemId) => returns
        .filter((entry) => entry.source_client_transaction_id === sourceTransactionId && entry.status !== 'cancelled')
        .flatMap((entry) => entry.return.items)
        .filter((item) => Number(item.sales_delivery_item_id) === Number(deliveryItemId))
        .reduce((sum, item) => sum + decimalUnits(item.quantity), 0n);

    const renderReturnForm = (scopedSales, returns) => {
        const authorized = hasReturnAuthorization() && !catalog?.return_sources_refresh_failed;
        elements.returnAuthorization.textContent = hasReturnAuthorization()
            ? `${labels.authorized} · ${labels.validUntil}: ${new Date(catalog.return_authorization.expires_at).toLocaleString()}`
            : labels.returnPermissionExpired;
        elements.returnAuthorization.dataset.authorized = String(hasReturnAuthorization());
        const originalSales = returnSources(catalog, scopedSales, key)
            .filter((sale) => Array.isArray(sale.result?.return_lines) && sale.result.return_lines.length > 0);
        const eligibleSales = originalSales.filter((sale) => sale.status === 'completed'
            && Array.isArray(sale.result?.return_lines)
            && sale.result.return_lines.some((line) => decimalUnits(line.remaining ?? line.quantity)
                >= 10000n + localReturnReservations(returns, sale.client_transaction_id, line.sales_delivery_item_id)));
        if (!eligibleSales.some((sale) => sale.client_transaction_id === selectedReturnSaleId)) {
            selectedReturnSaleId = eligibleSales[0]?.client_transaction_id ?? '';
        }
        elements.returns.hidden = false;
        const availability = !hasReturnAuthorization() ? labels.returnPermissionExpired
            : catalog?.return_sources_refresh_failed ? labels.returnSourcesRefreshFailed
                : originalSales.length === 0 ? labels.returnNoSales
                    : eligibleSales.length === 0 ? labels.returnAllQuantitiesUsed : '';
        elements.returnAvailability.textContent = availability;
        elements.refreshReturns.disabled = !navigator.onLine || !wire;
        elements.returnSource.disabled = !authorized || eligibleSales.length === 0;
        elements.completeReturn.disabled = savingReturn || !authorized || eligibleSales.length === 0;
        const options = [makeElement('option', '', labels.returnSource)];
        options[0].value = '';
        for (const sale of eligibleSales) {
            const option = makeElement('option', '', `${sale.result?.invoice_number || labels.pendingDocument} · ${Number(sale.quote.total_amount).toFixed(2)} ${labels.currency}`);
            option.value = sale.client_transaction_id;
            options.push(option);
        }
        elements.returnSource.replaceChildren(...options);
        elements.returnSource.value = selectedReturnSaleId;
        elements.returnItems.replaceChildren();
        const source = eligibleSales.find((sale) => sale.client_transaction_id === selectedReturnSaleId);
        if (!source) {
            return;
        }
        const lines = source.result.return_lines.filter((line) => {
            const remaining = decimalUnits(line.remaining ?? line.quantity)
                - localReturnReservations(returns, source.client_transaction_id, line.sales_delivery_item_id);
            return remaining >= 10000n;
        });
        if (!lines.length) {
            elements.returnItems.append(makeElement('p', '', labels.returnNoLines));
            return;
        }
        for (const line of lines) {
            const available = decimalUnits(line.remaining ?? line.quantity)
                - localReturnReservations(returns, source.client_transaction_id, line.sales_delivery_item_id);
            const row = makeElement('div', 'fox-pos-cart-item fox-pos-return-item');
            const detail = makeElement('div', 'fox-pos-cart-item-body');
            const variant = findVariant(line.variant_id);
            const image = makeElement('img', 'fox-pos-return-image');
            image.src = imageSource(variant?.image || variant?.product.image);
            image.alt = '';
            image.loading = 'lazy';
            detail.append(makeElement('strong', '', variant?.product.name || line.label || labels.productFallback));
            detail.append(makeElement('small', '', [variant?.color, variant?.size].filter(Boolean).join(' · ') || line.label || ''));
            detail.append(makeElement('small', 'fox-pos-return-quantities', `${labels.purchased}: ${quantityText(line.quantity)} · ${labels.returnable}: ${quantityText(decimalText(available))}`));
            const quantityLabel = makeElement('label');
            quantityLabel.append(makeElement('span', '', labels.returnQuantity));
            const quantity = makeElement('input');
            quantity.type = 'number';
            quantity.inputMode = 'numeric';
            quantity.min = '1';
            quantity.max = quantityText(decimalText(available));
            quantity.step = '1';
            quantity.value = '1';
            quantity.disabled = true;
            quantity.dataset.returnQuantity = '';
            quantity.dataset.deliveryItemId = String(line.sales_delivery_item_id);
            quantity.dataset.invoiceItemId = String(line.sales_invoice_item_id);
            quantity.addEventListener('beforeinput', (event) => {
                if (event.data && /[^0-9]/.test(event.data)) {
                    event.preventDefault();
                    setReturnStatus(labels.wholeQuantity);
                }
            });
            const quantityControls = makeElement('div', 'fox-pos-return-stepper');
            const adjust = (direction) => {
                const button = makeElement('button', '', direction < 0 ? '−' : '+');
                button.type = 'button';
                button.disabled = true;
                button.dataset.returnStep = '';
                button.setAttribute('aria-label', `${direction < 0 ? '−' : '+'} ${labels.returnQuantity}`);
                button.addEventListener('click', () => {
                    try {
                        quantity.value = returnQuantityStep(quantity.value, direction, available);
                    } catch (error) {
                        quantity.setCustomValidity(labels.wholeQuantity);
                        quantity.reportValidity();
                        return;
                    }
                    quantity.dispatchEvent(new Event('input', { bubbles: true }));
                });
                return button;
            };
            quantity.addEventListener('input', () => {
                try {
                    const current = wholeQuantityUnits(quantity.value);
                    quantity.setCustomValidity(current > available ? labels.returnQuantityExceeded : '');
                } catch (error) {
                    quantity.setCustomValidity(labels.wholeQuantity);
                }
            });
            quantityControls.append(adjust(-1), quantity, adjust(1));
            const selectLabel = makeElement('label', 'fox-pos-return-select');
            const selected = makeElement('input');
            selected.type = 'checkbox';
            selected.disabled = !authorized || savingReturn;
            selected.addEventListener('change', () => {
                quantity.disabled = !selected.checked;
                for (const button of quantityControls.querySelectorAll('button')) button.disabled = !selected.checked;
            });
            selectLabel.append(selected, makeElement('span', '', labels.selectReturnItem));
            detail.append(selectLabel);
            quantityLabel.append(quantityControls);
            const dispositionLabel = makeElement('label');
            dispositionLabel.append(makeElement('span', '', labels.returnDisposition));
            const disposition = makeElement('select');
            disposition.dataset.returnDisposition = '';
            disposition.disabled = !authorized || savingReturn;
            for (const [value, label] of [
                ['restock', labels.returnRestock],
                ['damaged', labels.returnDamaged],
                ['scrap', labels.returnScrap],
            ]) {
                const option = makeElement('option', '', label);
                option.value = value;
                disposition.append(option);
            }
            dispositionLabel.append(disposition);
            const fields = makeElement('div', 'fox-pos-return-fields');
            fields.append(quantityLabel, dispositionLabel);
            row.append(image, detail, fields);
            elements.returnItems.append(row);
        }
    };

    const renderReturns = (returns) => {
        const list = makeElement('div');
        for (const offlineReturn of returns.sort((left, right) => right.client_occurred_at.localeCompare(left.client_occurred_at))) {
            const row = makeElement('div', 'fox-pos-recent-sale');
            const result = offlineReturn.result?.return_number || labels.pendingDocument;
            const state = offlineReturn.status === 'queued' ? labels.returnPendingState
                : offlineReturn.status === 'synced' ? labels.returnSyncedState
                    : labels.returnFailedState;
            row.dataset.returnState = offlineReturn.status;
            row.append(makeElement('strong', '', result), makeElement('span', 'fox-pos-state-badge', state),
                makeElement('small', '', new Date(offlineReturn.client_occurred_at).toLocaleString()));
            if (offlineReturn.error) row.append(makeElement('p', 'fox-pos-return-error', offlineReturn.error));
            if (offlineReturn.status === 'synced') row.append(makeElement('small', '', labels.returnSynced));
            if (offlineReturn.status === 'conflict') {
                const retry = makeElement('button', 'fox-pos-button', labels.returnRetry);
                retry.type = 'button';
                retry.dataset.returnRetry = '';
                retry.disabled = !hasReturnAuthorization();
                retry.addEventListener('click', async () => {
                    if (!hasReturnAuthorization()) {
                        setReturnStatus(labels.returnPermissionExpired);
                        return;
                    }
                    retry.disabled = true;
                    try {
                        await updateReturn(offlineReturn.client_transaction_id, {
                            status: 'queued',
                            authorization_token: catalog.return_authorization.token,
                            next_retry_at: null,
                            retry_count: 0,
                        });
                        await syncReturns([offlineReturn.client_transaction_id]);
                    } catch (error) {
                        setReturnStatus(error instanceof Error ? error.message : labels.returnConflict);
                    } finally {
                        await renderSales();
                    }
                });
                row.append(retry);
            }
            list.append(row);
        }
        if (!returns.length) list.append(makeElement('p', 'fox-pos-drawer-empty', labels.noReturnHistory));
        elements.returnHistory.replaceChildren(list);
    };

    const renderReceipt = (sale) => {
        elements.receipt.replaceChildren();
        if (!sale) {
            elements.receipt.hidden = true;
            return;
        }
        elements.receipt.hidden = false;
        const actions = makeElement('div', 'fox-pos-receipt-actions');
        const print = makeElement('button', 'fox-pos-button fox-pos-button--primary', labels.printReceipt);
        print.type = 'button';
        print.addEventListener('click', () => window.print());
        const newSale = makeElement('button', 'fox-pos-button', labels.newSale);
        newSale.type = 'button';
        newSale.addEventListener('click', () => {
            receiptTransactionId = null;
            elements.receipt.hidden = true;
            renderCart();
        });
        actions.append(print, newSale);
        const paper = makeElement('select');
        paper.setAttribute('aria-label', labels.paperSize);
        for (const [value, label] of [['55mm', '55 mm'], ['58mm', '58 mm'], ['80mm', '80 mm'], ['190mm', labels.browserPaper]]) {
            const option = makeElement('option', '', label);
            option.value = value;
            paper.append(option);
        }
        paper.value = elements.receipt.dataset.paperWidth ?? '80mm';
        paper.addEventListener('change', () => { elements.receipt.dataset.paperWidth = paper.value; });
        actions.append(paper);
        const header = makeElement('header', 'fox-pos-receipt-header');
        const logo = makeElement('strong', 'fox-pos-receipt-logo', 'Fox & Hema');
        const receiptBranch = makeElement('div', 'fox-pos-receipt-branch');
        receiptBranch.append(makeElement('h2', '', labels.receiptTitle));
        const snapshot = sale.receipt_snapshot;
        const branchName = snapshot?.branch ?? root.querySelector('[data-pos-branch]')?.textContent?.trim() ?? String(sale.scope.branch_id);
        receiptBranch.append(makeElement('strong', '', branchName));
        const cashierName = snapshot?.cashier ?? root.querySelector('[data-pos-cashier]')?.textContent?.trim() ?? String(sale.scope.cashier_id);
        const metadata = makeElement('div', 'fox-pos-receipt-meta');
        const invoice = sale.status === 'completed' && sale.result?.invoice_number ? sale.result.invoice_number : labels.pendingDocument;
        for (const [label, value] of [
            [labels.invoice, invoice],
            [labels.date, new Date(sale.created_at).toLocaleString()],
            [labels.cashier, cashierName],
            [labels.localReference, sale.local_reference || sale.client_transaction_id],
        ]) {
            const entry = makeElement('div');
            entry.append(makeElement('span', '', label), makeElement('strong', '', value));
            metadata.append(entry);
        }
        header.append(logo, receiptBranch, metadata);
        const lines = makeElement('div', 'fox-pos-receipt-lines');
        const linesHeading = makeElement('div', 'fox-pos-receipt-items-heading');
        linesHeading.append(makeElement('span', '', labels.item), makeElement('span', '', labels.amount));
        lines.append(linesHeading);
        for (const line of snapshot?.items ?? sale.quote.items) {
            const row = makeElement('article', 'fox-pos-receipt-line');
            const detail = makeElement('div', 'fox-pos-receipt-item');
            detail.append(makeElement('strong', '', line.name ?? `${labels.productFallback} #${line.variant_id}`));
            detail.append(makeElement('small', '', [line.sku, line.size, line.color, line.label].filter(Boolean).join(' · ')));
            if (decimalUnits(line.discount_amount) > 0n) detail.append(makeElement('small', '',
                `${labels.lineDiscount}: ${Number(line.discount_amount).toFixed(2)} ${labels.currency}`));
            detail.append(makeElement('span', 'fox-pos-receipt-item-calculation', `${quantityText(line.quantity)} × ${Number(line.unit_price).toFixed(2)}`));
            row.append(detail, makeElement('strong', 'fox-pos-receipt-item-total', Number(line.line_total).toFixed(2)));
            lines.append(row);
        }
        const totals = makeElement('dl', 'fox-pos-receipt-totals');
        for (const [label, value, className] of [
            [labels.subtotal, sale.quote.subtotal, ''],
            [labels.discount, sale.quote.discount_amount, ''],
            [labels.tax, sale.quote.tax_amount, ''],
            [labels.total, sale.quote.total_amount, 'fox-pos-receipt-total'],
        ]) {
            const row = makeElement('div', className);
            row.append(makeElement('dt', '', label), makeElement('dd', '', `${Number(value).toFixed(2)} ${labels.currency}`));
            totals.append(row);
        }
        const paymentSection = makeElement('section', 'fox-pos-receipt-payment-section');
        paymentSection.append(makeElement('h3', '', labels.paymentBreakdown));
        for (const tender of sale.sale.tenders) {
            const paymentRow = makeElement('div', 'fox-pos-receipt-line');
            const method = (snapshot?.payment_methods ?? catalog?.payment_methods ?? []).find((item) => item.id === tender.payment_method_id);
            paymentRow.append(makeElement('strong', '', method?.name ?? labels.paymentMethod),
                makeElement('strong', 'fox-pos-receipt-item-total', `${Number(tender.amount).toFixed(2)} ${labels.currency}`));
            paymentSection.append(paymentRow);
        }
        if (invoice === labels.pendingDocument) header.append(makeElement('strong', 'fox-pos-receipt-status', labels.pendingReceipt));
        if (!snapshot) header.append(makeElement('small', '', labels.legacyReceipt));
        elements.receipt.append(actions, header, lines, totals, paymentSection, makeElement('p', 'fox-pos-receipt-thanks', labels.thankYou));
    };

    const scheduleSyncRetry = async () => {
        clearTimeout(retryTimer);
        retryTimer = null;
        if (!attached || !navigator.onLine) return;
        const queuedSales = [...await listSales(key), ...await historicalSalesForSynchronization()]
            .filter((sale) => sale.status === 'queued' && sale.next_retry_at);
        const queuedReturns = (await listReturns(key)).filter((offlineReturn) =>
            offlineReturn.status === 'queued' && offlineReturn.next_retry_at);
        const retryTimes = [...queuedSales, ...queuedReturns]
            .map((transaction) => Date.parse(transaction.next_retry_at))
            .filter(Number.isFinite);
        if (!retryTimes.length) return;
        const nextRetry = Math.min(...retryTimes);
        if (!Number.isFinite(nextRetry)) return;
        retryTimer = setTimeout(() => {
            syncSales();
            syncReturns();
        }, Math.max(0, nextRetry - Date.now()));
    };

    let salesSyncTask = null;
    const syncSales = (transactionIds = null) => {
        if (!salesSyncTask) {
            salesSyncTask = performSalesSync(transactionIds).finally(() => { salesSyncTask = null; });
        }
        return salesSyncTask;
    };
    const performSalesSync = async (transactionIds = null) => {
        if (!navigator.onLine || syncing || !syncUrl || !catalog || !checkContext()) return;
        syncing = true;
        elements.status.textContent = labels.syncing;
        let failureMessage = null;
        let completedSale = false;
        let lockAcquired = true;
        try {
            const synchronize = async () => {
                for (const sale of [...await listSales(key), ...await historicalSalesForSynchronization()]) {
                    const historicalAcknowledgement = sale.status === 'completed' && !sale.outbox_id
                        && (sale.acknowledgement_outbox_id || catalog?.offline_outbox?.transaction_ids?.includes(sale.client_transaction_id));
                    if ((sale.status !== 'queued' && !historicalAcknowledgement)
                        || (transactionIds && !transactionIds.includes(sale.client_transaction_id))
                        || (sale.next_retry_at && Date.parse(sale.next_retry_at) > Date.now())) continue;
                    try {
                        const activeOutbox = sale.scopeKey === key ? (await readCatalog(key))?.offline_outbox : { id: sale.outbox_id ?? sale.acknowledgement_outbox_id };
                        const acknowledgementOnly = !sale.outbox_id && (sale.acknowledgement_outbox_id || activeOutbox?.transaction_ids?.includes(sale.client_transaction_id));
                        if ((!sale.outbox_id && !acknowledgementOnly) || (sale.outbox_id && sale.outbox_id !== activeOutbox?.id)) {
                            await updateSale(sale.client_transaction_id, { status: 'conflict', next_retry_at: null,
                                error: labels.outboxMembership });
                            failureMessage = elements.status.textContent = labels.outboxMembership;
                            continue;
                        }
                        if (!await validateSyncQuantities(sale, [...sale.sale.items, ...sale.quote.items], updateSale)) {
                            failureMessage = elements.status.textContent = labels.wholeQuantity;
                            continue;
                        }
                        const response = await fetch(syncUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            cache: 'no-store',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': panel.dataset.posCsrf,
                            },
                            body: JSON.stringify({
                                client_transaction_id: sale.client_transaction_id,
                                outbox_id: sale.outbox_id ?? activeOutbox.id,
                                ...(acknowledgementOnly ? { acknowledgement_only: true } : {}),
                                scope: sale.scope,
                                client_occurred_at: sale.created_at,
                                sale: sale.sale,
                                quote: sale.quote,
                            }),
                        });
                        const body = await response.json().catch(() => null);
                        if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
                            const serverError = syncFailureMessage(response, body);
                            const temporary = response.status === 408 || response.status === 429 || response.status >= 500;
                            if (temporary) {
                                const retryCount = (sale.retry_count ?? 0) + 1;
                                const retryDelay = Math.min(300000, 5000 * (2 ** Math.min(retryCount - 1, 6)));
                                const error = labels.retryScheduled.replace(':seconds', String(Math.ceil(retryDelay / 1000))) + ` ${serverError}`;
                                await updateSale(sale.client_transaction_id, {
                                    status: 'queued',
                                    retry_count: retryCount,
                                    next_retry_at: new Date(Date.now() + retryDelay).toISOString(),
                                    error,
                                    error_history: appendSyncError(sale, response.status, serverError),
                                });
                                elements.status.textContent = error;
                                failureMessage = error;
                                break;
                            }
                            const error = `${labels.permanentFailure} ${serverError}`;
                            await updateSale(sale.client_transaction_id, {
                                status: 'conflict',
                                next_retry_at: null,
                                error,
                                error_history: appendSyncError(sale, response.status, serverError),
                            });
                            elements.status.textContent = error;
                            failureMessage = error;
                            continue;
                        }
                        if (body?.client_transaction_id === sale.client_transaction_id
                            && ((body?.status === 'cancelled' && body?.result?.local_test_cancellation === true)
                                || (body?.status === 'archived' && body?.result?.local_historical_orphan_archive === true))
                            && body?.result?.outbox_id === activeOutbox.id) {
                            await updateSale(sale.client_transaction_id, { status: body.status, cancellation: body.result,
                                next_retry_at: null, error: null });
                            continue;
                        }
                        if (acknowledgementOnly || body?.client_transaction_id !== sale.client_transaction_id
                            || body?.status !== 'completed'
                            || typeof body?.result?.invoice_number !== 'string'
                            || body.result.invoice_number.trim() === '') {
                            const serverError = typeof body?.message === 'string'
                                ? body.message
                                : 'Server did not confirm a completed sale with an official invoice number.';
                            const error = `${labels.permanentFailure} ${serverError}`;
                            await updateSale(sale.client_transaction_id, {
                                status: 'conflict',
                                next_retry_at: null,
                                error,
                                error_history: appendSyncError(sale, response.status, serverError),
                            });
                            elements.status.textContent = error;
                            failureMessage = error;
                            continue;
                        }
                        await updateSale(sale.client_transaction_id, {
                            status: 'completed',
                            result: body.result,
                            synced_at: new Date().toISOString(),
                            next_retry_at: null,
                            error: null,
                        });
                        completedSale = true;
                    } catch (error) {
                        const serverError = error instanceof Error ? error.message : labels.conflict;
                        const retryCount = (sale.retry_count ?? 0) + 1;
                        const retryDelay = Math.min(300000, 5000 * (2 ** Math.min(retryCount - 1, 6)));
                        const message = labels.retryScheduled.replace(':seconds', String(Math.ceil(retryDelay / 1000))) + ` ${serverError}`;
                        await updateSale(sale.client_transaction_id, {
                            status: 'queued',
                            retry_count: retryCount,
                            next_retry_at: new Date(Date.now() + retryDelay).toISOString(),
                            error: message,
                            error_history: appendSyncError(sale, null, serverError),
                        });
                        elements.status.textContent = message;
                        failureMessage = message;
                        break;
                    }
                }
                if (failureMessage === null) elements.status.textContent = labels.completed;
            };
            if (navigator.locks?.request) {
                await navigator.locks.request(`fox-hema-pos-sync-${key}`, { ifAvailable: true }, async (lock) => {
                    if (!lock) {
                        lockAcquired = false;
                        return;
                    }
                    await synchronize();
                });
            } else {
                await synchronize();
            }
        } catch (error) {
            elements.status.textContent = error instanceof Error ? error.message : labels.conflict;
        } finally {
            syncing = false;
            await renderSales();
            await scheduleSyncRetry();
            if (completedSale && navigator.onLine && wire) {
                stockChannel?.postMessage({ type: 'stock-updated' });
                await refreshCatalog();
            }
            if (!lockAcquired) {
                clearTimeout(retryTimer);
                retryTimer = setTimeout(() => syncSales(transactionIds), 1000);
            }
        }
    };

    let syncingReturns = false;
    let returnsSyncTask = null;
    const syncReturns = (transactionIds = null) => {
        if (!returnsSyncTask) {
            returnsSyncTask = performReturnsSync(transactionIds).finally(() => { returnsSyncTask = null; });
        }
        return returnsSyncTask;
    };
    const performReturnsSync = async (transactionIds = null) => {
        if (!navigator.onLine || syncingReturns || !returnSyncUrl) return;
        syncingReturns = true;
        let failureMessage = null;
        let synchronizedCount = 0;
        let lockAcquired = true;
        try {
            const synchronize = async () => {
                for (const offlineReturn of await listReturns(key)) {
                    if (offlineReturn.status !== 'queued'
                        || (transactionIds && !transactionIds.includes(offlineReturn.client_transaction_id))
                        || (offlineReturn.next_retry_at && Date.parse(offlineReturn.next_retry_at) > Date.now())) continue;
                    try {
                        if (!await validateSyncQuantities(offlineReturn, offlineReturn.return.items, updateReturn)) {
                            failureMessage = labels.wholeQuantity;
                            setReturnStatus(failureMessage);
                            continue;
                        }
                        const response = await fetch(returnSyncUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            cache: 'no-store',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': panel.dataset.posCsrf,
                            },
                            body: JSON.stringify({
                                client_transaction_id: offlineReturn.client_transaction_id,
                                source_client_transaction_id: offlineReturn.source_client_transaction_id,
                                client_occurred_at: offlineReturn.client_occurred_at,
                                authorization_token: offlineReturn.authorization_token,
                                scope: offlineReturn.scope,
                                return: offlineReturn.return,
                            }),
                        });
                        const body = await response.json().catch(() => null);
                        if (!response.ok || response.redirected
                            || !response.headers.get('content-type')?.includes('application/json')) {
                            const serverError = syncFailureMessage(response, body);
                            const temporary = response.status === 408 || response.status === 429 || response.status >= 500;
                            if (temporary) {
                                const retryCount = (offlineReturn.retry_count ?? 0) + 1;
                                const retryDelay = Math.min(300000, 5000 * (2 ** Math.min(retryCount - 1, 6)));
                                const error = labels.retryScheduled.replace(':seconds', String(Math.ceil(retryDelay / 1000))) + ` ${serverError}`;
                                await updateReturn(offlineReturn.client_transaction_id, {
                                    status: 'queued',
                                    retry_count: retryCount,
                                    next_retry_at: new Date(Date.now() + retryDelay).toISOString(),
                                    error,
                                    error_history: appendSyncError(offlineReturn, response.status, serverError),
                                });
                                setReturnStatus(error);
                                failureMessage = error;
                                break;
                            }
                            const error = `${labels.returnConflict} ${serverError}`;
                            await updateReturn(offlineReturn.client_transaction_id, {
                                status: 'conflict',
                                next_retry_at: null,
                                error,
                                error_history: appendSyncError(offlineReturn, response.status, serverError),
                            });
                            setReturnStatus(error);
                            failureMessage = error;
                            continue;
                        }
                        if (body?.client_transaction_id !== offlineReturn.client_transaction_id
                            || body?.status !== 'completed'
                            || typeof body?.result?.return_number !== 'string'
                            || body.result.return_status !== 'draft'
                            || body.result.refund_status !== 'not_started'
                            || body.result.inventory_status !== 'not_posted') {
                            const serverError = typeof body?.message === 'string'
                                ? body.message
                                : 'The server did not confirm an unposted return draft.';
                            const error = `${labels.returnConflict} ${serverError}`;
                            await updateReturn(offlineReturn.client_transaction_id, {
                                status: 'conflict',
                                next_retry_at: null,
                                error,
                                error_history: appendSyncError(offlineReturn, response.status, serverError),
                            });
                            setReturnStatus(error);
                            failureMessage = error;
                            continue;
                        }
                        await updateReturn(offlineReturn.client_transaction_id, {
                            status: 'synced',
                            result: body.result,
                            synced_at: new Date().toISOString(),
                            next_retry_at: null,
                            error: null,
                        });
                        synchronizedCount += 1;
                        stockChannel?.postMessage({ type: 'returns-updated' });
                    } catch (error) {
                        const serverError = error instanceof Error ? error.message : labels.returnConflict;
                        const retryCount = (offlineReturn.retry_count ?? 0) + 1;
                        const retryDelay = Math.min(300000, 5000 * (2 ** Math.min(retryCount - 1, 6)));
                        const message = labels.retryScheduled.replace(':seconds', String(Math.ceil(retryDelay / 1000))) + ` ${serverError}`;
                        await updateReturn(offlineReturn.client_transaction_id, {
                            status: 'queued',
                            retry_count: retryCount,
                            next_retry_at: new Date(Date.now() + retryDelay).toISOString(),
                            error: message,
                            error_history: appendSyncError(offlineReturn, null, serverError),
                        });
                        setReturnStatus(message);
                        failureMessage = message;
                        break;
                    }
                }
                if (failureMessage === null && synchronizedCount > 0) setReturnStatus(labels.returnSynced);
            };
            if (navigator.locks?.request) {
                await navigator.locks.request(`fox-hema-pos-return-sync-${key}`, { ifAvailable: true }, async (lock) => {
                    if (!lock) {
                        lockAcquired = false;
                        return;
                    }
                    await synchronize();
                });
            } else {
                await synchronize();
            }
        } catch (error) {
            setReturnStatus(error instanceof Error ? error.message : labels.returnConflict);
        } finally {
            syncingReturns = false;
            await renderSales();
            await scheduleSyncRetry();
            if (!lockAcquired) {
                clearTimeout(retryTimer);
                retryTimer = setTimeout(() => syncReturns(transactionIds), 1000);
            }
        }
    };

    const completeSale = async () => {
        if (!catalog || Object.keys(cart).length === 0) return;
        elements.complete.disabled = true;
        try {
            const quote = localQuote(catalog, cart);
            const method = catalog.payment_methods.find((item) => String(item.id) === elements.paymentMethod.value);
            if (!method) throw new Error('Choose a configured payment method');
            const cashReceived = method.type === 'cash' ? (elements.cashReceived.value || quote.total_amount) : '0.0000';
            if (method.type === 'cash' && decimalUnits(cashReceived) < decimalUnits(quote.total_amount)) {
                throw new Error(labels.cashReceivedShort);
            }
            const sale = {
                customer_id: null,
                customer_name: null,
                customer_phone: null,
                items: quote.items.map((item) => ({
                    variant_id: item.variant_id,
                    quantity: item.quantity,
                    discount_id: item.discount_id,
                })),
                tenders: [{
                    payment_method_id: method.id,
                    amount: quote.total_amount,
                    external_reference: elements.reference.value.trim() || null,
                }],
                cash_received: cashReceived,
            };
            const transactionId = uuid();
            const queuedSale = await queueOfflineSale({
                client_transaction_id: transactionId,
                scopeKey: key,
                scope: parsedScope,
                sale,
                quote,
                receipt_snapshot: {
                    branch: root.querySelector('[data-pos-branch]')?.textContent?.trim() || String(parsedScope.branch_id),
                    cashier: root.querySelector('[data-pos-cashier]')?.textContent?.trim() || String(parsedScope.cashier_id),
                    payment_methods: catalog.payment_methods.map(({ id, name, type }) => ({ id, name, type })),
                    items: quote.items.map((line) => {
                        const variant = findVariant(line.variant_id);
                        return { ...line, name: variant?.product.name ?? `${labels.productFallback} #${line.variant_id}`,
                            sku: variant?.sku ?? null, size: variant?.size ?? null, color: variant?.color ?? null, label: variant?.label ?? null };
                    }),
                },
                status: 'queued',
                retry_count: 0,
                next_retry_at: null,
                created_at: new Date().toISOString(),
            }, parsedScope.device_id, deviceCode);
            receiptTransactionId = queuedSale.client_transaction_id;
            cart = {};
            offlineCartDirty = activeMode === true;
            offlinePaymentDirty = false;
            await refreshLocalStock();
            stockChannel?.postMessage({ type: 'stock-updated' });
            elements.paymentModal.hidden = true;
            elements.reference.value = '';
            elements.cashReceived.value = '';
            cashReceivedEdited = false;
            renderCart();
            renderReceipt(queuedSale);
            requestAnimationFrame(() => elements.receipt.scrollIntoView({ block: 'nearest' }));
            await renderSales();
            elements.status.textContent = labels.pending;
            if (navigator.onLine) await syncSales();
        } catch (error) {
            elements.status.textContent = error instanceof Error ? error.message : labels.unavailable;
            elements.complete.disabled = false;
        }
    };

    const completeReturn = async () => {
        if (savingReturn) return;
        const authorization = catalog?.return_authorization;
        if (!hasReturnAuthorization()) {
            setReturnStatus(labels.returnPermissionExpired);
            return;
        }
        if (catalog?.return_sources_refresh_failed) {
            setReturnStatus(labels.returnSourcesRefreshFailed);
            return;
        }
        savingReturn = true;
        elements.completeReturn.disabled = true;
        try {
            const sourceSale = returnSources(catalog, await listSales(key), key)
                .find((sale) => sale.client_transaction_id === elements.returnSource.value);
            if (!sourceSale || sourceSale.status !== 'completed' || !Array.isArray(sourceSale.result?.return_lines)) {
                throw new Error(labels.returnSourceUnavailable);
            }
            const items = [...elements.returnItems.querySelectorAll('[data-return-quantity]')]
                .filter((input) => !input.disabled)
                .map((input) => {
                    const quantity = wholeQuantityUnits(input.value);
                    if (quantity > decimalUnits(input.max)) throw new Error(labels.returnQuantityExceeded);
                    return {
                        sales_delivery_item_id: Number(input.dataset.deliveryItemId),
                        sales_invoice_item_id: Number(input.dataset.invoiceItemId),
                        quantity: decimalText(quantity),
                        disposition: input.closest('.fox-pos-cart-item')?.querySelector('[data-return-disposition]')?.value || 'restock',
                        notes: null,
                    };
                })
                .filter(Boolean);
            if (!items.length) throw new Error(labels.returnNoLines);
            const transactionId = uuid();
            const occurredAt = new Date().toISOString();
            const offlineReturn = {
                client_transaction_id: transactionId,
                source_client_transaction_id: sourceSale.client_transaction_id,
                scopeKey: key,
                scope: parsedScope,
                authorization_token: authorization.token,
                client_occurred_at: occurredAt,
                return: {
                    return_date: occurredAt.slice(0, 10),
                    reason: null,
                    notes: null,
                    items,
                },
                status: 'queued',
                retry_count: 0,
                next_retry_at: null,
                created_at: occurredAt,
            };
            await queueOfflineReturn(offlineReturn);
            setReturnStatus(labels.returnQueued);
            stockChannel?.postMessage({ type: 'returns-updated' });
            await renderSales();
            if (navigator.onLine) await syncReturns([transactionId]);
        } catch (error) {
            setReturnStatus(error instanceof Error ? error.message : labels.returnConflict);
        } finally {
            savingReturn = false;
            await renderSales();
        }
    };

    const onNetworkChange = () => {
        if (!attached) return;
        setMode(!navigator.onLine);
        if (navigator.onLine) {
            syncSales();
            syncReturns();
            refreshCatalog().then(() => {
                syncSales();
                syncReturns();
            });
        }
    };
    const onPageVisible = () => {
        if (document.visibilityState === 'visible' && navigator.onLine) {
            syncSales();
            syncReturns();
        }
    };
    const onShellStatus = ({ detail }) => {
        setMode(detail?.offline === true);
        if (!detail?.offline) {
            syncSales();
            syncReturns();
        }
    };
    elements.search.addEventListener('input', renderProducts);
    elements.brand.addEventListener('change', renderProducts);
    elements.size.addEventListener('change', renderProducts);
    elements.color.addEventListener('change', renderProducts);
    elements.refresh.addEventListener('click', refreshCatalog);
    elements.complete.addEventListener('click', completeSale);
    elements.returnSource.addEventListener('change', async () => {
        selectedReturnSaleId = elements.returnSource.value;
        renderReturnForm(await listSales(key), await listReturns(key));
    });
    elements.completeReturn.addEventListener('click', completeReturn);
    elements.refreshReturns.addEventListener('click', async () => {
        elements.refreshReturns.disabled = true;
        await refreshCatalog();
        setReturnStatus(elements.status.textContent);
    });
    const returnAuthorizationTimer = setInterval(() => {
        if (!hasReturnAuthorization()) {
            elements.returnAvailability.textContent = labels.returnPermissionExpired;
            elements.returnAuthorization.textContent = labels.returnPermissionExpired;
            elements.returnAuthorization.dataset.authorized = 'false';
            elements.completeReturn.disabled = true;
            elements.returnSource.disabled = true;
            elements.returns.querySelectorAll('input, select, [data-return-retry], [data-return-step]').forEach((input) => {
                input.disabled = true;
            });
        }
    }, 1000);
    elements.checkout.addEventListener('click', () => {
        renderCart();
        elements.paymentModal.hidden = false;
    });
    elements.closePayment.addEventListener('click', () => { elements.paymentModal.hidden = true; });
    elements.cancelPayment.addEventListener('click', () => { elements.paymentModal.hidden = true; });
    elements.paymentModal.addEventListener('click', (event) => {
        if (event.target === elements.paymentModal) elements.paymentModal.hidden = true;
    });
    elements.clearCart.addEventListener('click', () => {
        cart = {};
        cashReceivedEdited = false;
        elements.cashReceived.value = '';
        offlineCartDirty = activeMode === true;
        renderCart();
    });
    elements.paymentMethod.addEventListener('change', () => {
        offlinePaymentDirty = activeMode === true;
        renderCart();
    });
    elements.reference.addEventListener('input', () => { offlinePaymentDirty = activeMode === true; });
    elements.cashReceived.addEventListener('input', () => {
        offlinePaymentDirty = activeMode === true;
        cashReceivedEdited = elements.cashReceived.value !== '';
    });
    window.addEventListener('keydown', (event) => {
        if (event.key === 'F12' && activeMode === true && !elements.checkout.disabled) {
            event.preventDefault();
            elements.paymentModal.hidden = false;
        }
    });
    window.addEventListener('online', onNetworkChange);
    window.addEventListener('offline', onNetworkChange);
    window.addEventListener('focus', onPageVisible);
    window.addEventListener('pageshow', onPageVisible);
    document.addEventListener('visibilitychange', onPageVisible);
    navigator.serviceWorker?.addEventListener('controllerchange', onPageVisible);
    root.addEventListener('pos-offline-mode', onShellStatus);
    stockChannel?.addEventListener('message', async ({ data }) => {
        if (attached && data?.type === 'returns-updated') {
            renderReturnForm(await listSales(key), await listReturns(key));
            renderReturns(await listReturns(key));
            return;
        }
        if (!attached || data?.type !== 'stock-updated') return;
        await refreshLocalStock();
        renderProducts();
        renderCart();
        await renderSales();
    });
    window.addEventListener('pagehide', () => {
        selectedReceipt = null;
        document.querySelector('[data-pos-print-root]')?.remove();
        root.replaceChildren(makeElement('p', '', 'Reconnect and sign in to verify this offline POS scope. Saved transactions are preserved.'));
        clearInterval(contextTimer);
        window.removeEventListener('focus', checkContext);
        window.removeEventListener('pageshow', checkContext);
        document.removeEventListener('visibilitychange', checkContext);
        attached = false;
        window.removeEventListener('online', onNetworkChange);
        window.removeEventListener('offline', onNetworkChange);
        window.removeEventListener('focus', onPageVisible);
        window.removeEventListener('pageshow', onPageVisible);
        document.removeEventListener('visibilitychange', onPageVisible);
        navigator.serviceWorker?.removeEventListener('controllerchange', onPageVisible);
        root.removeEventListener('pos-offline-mode', onShellStatus);
        stockChannel?.close();
        for (const url of imageSources.values()) URL.revokeObjectURL(url);
        clearInterval(returnAuthorizationTimer);
        clearTimeout(retryTimer);
    }, { once: true });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) location.reload();
    });

    try {
        serverCatalog = await readCatalog(key);
        await refreshLocalStock();
        if (catalog) {
            setCatalogReadiness(true);
            elements.status.textContent = `${labels.catalog} · ${new Date(catalog.captured_at).toLocaleString()}`;
            renderPaymentMethods();
        } else {
            setCatalogReadiness(false);
            elements.status.textContent = labels.noCatalog;
        }
        if (wire && navigator.onLine) await refreshCatalog();
        if (options.offlineShell || !navigator.onLine) setMode(true);
        else setMode(false);
        renderProducts();
        renderCart();
        await renderSales();
        if (navigator.onLine) await syncSales();
        if (navigator.onLine) await syncReturns();
    } catch (error) {
        elements.status.textContent = error instanceof Error ? error.message : labels.unavailable;
    }
}

globalThis.FoxHemaPosOffline = Object.freeze({ attachOfflineTerminal });
