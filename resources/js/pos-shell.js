import { attachOfflineTerminal } from './pos-offline.js';

const fallback = Object.freeze({
    checking: 'Checking offline shell…',
    ready: 'Offline shell ready',
    unavailable: 'Offline shell unavailable',
    connected: 'Connected to server',
    disconnected: 'Disconnected from server',
    mode_online: 'ONLINE SALES',
    mode_offline: 'OFFLINE SALES',
    mode_online_description: 'Live catalog · checkout through the server',
    mode_offline_description: 'Completed sales are saved on this device and synchronized when online',
    assets_unavailable: 'Offline shell assets could not load',
    unsupported: 'Offline shell: browser unsupported',
    insecure: 'Offline shell requires HTTPS or localhost',
    update: 'Update waiting. Finish work, then close terminal tabs to update safely.',
});

async function shellStatus(worker) {
    if (!worker) return false;

    return new Promise((resolve) => {
        const channel = new MessageChannel();
        const timer = setTimeout(() => {
            channel.port1.close();
            resolve(false);
        }, 5000);
        channel.port1.onmessage = ({ data }) => {
            clearTimeout(timer);
            channel.port1.close();
            resolve(data?.ready === true);
        };
        worker.postMessage({ type: 'POS_SHELL_STATUS' }, [channel.port2]);
    });
}

function showOperatingMode(root, messages) {
    const offline = !navigator.onLine || root.hasAttribute('data-pos-offline-shell');
    const mode = root.querySelector('[data-pos-operating-mode]');
    const description = root.querySelector('[data-pos-mode-description]');
    const localPanel = root.querySelector('[data-pos-local]');
    const onlinePanel = root.querySelector('[data-pos-online]');

    if (mode) {
        mode.textContent = offline ? messages.mode_offline : messages.mode_online;
        mode.dataset.mode = offline ? 'offline' : 'online';
    }
    if (description) {
        description.textContent = offline ? messages.mode_offline_description : messages.mode_online_description;
    }
    if (localPanel) localPanel.hidden = !offline;
    if (onlinePanel) onlinePanel.hidden = offline;
    root.dispatchEvent(new CustomEvent('pos-offline-mode', { detail: { offline } }));
}

export async function installOfflineShell(root, scope, urls = {}) {
    if (!root || root.dataset.shellAttached) return;
    root.dataset.shellAttached = 'true';
    const status = root.querySelector('[data-pos-shell-status]');
    const labels = status ? JSON.parse(status.dataset.posShellLabels ?? '{}') : {};
    const messages = { ...fallback, ...labels };
    const readiness = status?.querySelector('[data-pos-shell-readiness]');
    const network = status?.querySelector('[data-pos-shell-network]');
    const catalogReadiness = status?.querySelector('[data-pos-catalog-readiness]');
    const update = status?.querySelector('[data-pos-shell-update]');
    const headerNetwork = root.querySelector('[data-pos-header-network]');
    const offlineShell = root.hasAttribute('data-pos-offline-shell');

    const showNetwork = () => {
        const label = navigator.onLine ? messages.connected : messages.disconnected;
        if (network) network.textContent = label;
        if (headerNetwork) {
            headerNetwork.textContent = label;
            headerNetwork.dataset.state = navigator.onLine ? 'online' : 'offline';
        }
    };
    const showCatalogReadiness = (ready = root.dataset.posCatalogReady === 'true') => {
        if (catalogReadiness) {
            catalogReadiness.textContent = ready ? messages.catalog_ready : messages.catalog_unavailable;
        }
    };
    const showReadiness = async (worker = navigator.serviceWorker?.controller) => {
        const ready = await shellStatus(worker);
        if (!readiness) return;
        readiness.textContent = ready ? messages.ready : messages.unavailable;
        readiness.dataset.state = ready ? 'ready' : 'unavailable';
        if (status) status.dataset.posShellReason = ready ? '' : 'incomplete-shell-cache';
    };
    const networkChanged = () => {
        showNetwork();
        showCatalogReadiness();
        showOperatingMode(root, messages);
        if (offlineShell && navigator.onLine) {
            location.reload();
        }
    };

    if (offlineShell) {
        const panel = root.querySelector('[data-pos-local]');
        if (panel) {
            const localLabels = JSON.parse(panel.dataset.posLocalLabels ?? '{}');
            await attachOfflineTerminal(root, null, scope, {
                offlineShell: true,
                labels: localLabels,
            });
        }
        await showReadiness();
        showCatalogReadiness();
        root.addEventListener('pos-offline-catalog', ({ detail }) => showCatalogReadiness(detail?.ready === true));
        window.addEventListener('online', networkChanged);
        window.addEventListener('offline', networkChanged);
        networkChanged();
        return;
    }

    if (readiness) {
        readiness.textContent = messages.checking;
        readiness.dataset.state = 'checking';
    }
    showNetwork();
    showCatalogReadiness();
    root.addEventListener('pos-offline-catalog', ({ detail }) => showCatalogReadiness(detail?.ready === true));
    showOperatingMode(root, messages);

    if (!scope || !('serviceWorker' in navigator) || !isSecureContext) {
        if (readiness) {
            readiness.textContent = !('serviceWorker' in navigator) ? messages.unsupported : messages.insecure;
            readiness.dataset.state = 'unavailable';
        }
        return;
    }

    try {
        const registration = await navigator.serviceWorker.register(urls.worker, {
            scope: urls.terminal,
            updateViaCache: 'none',
        });
        const availableUpdate = () => {
            if (update) update.textContent = registration.waiting ? messages.update : '';
        };
        const watchInstallation = () => {
            registration.installing?.addEventListener('statechange', () => {
                availableUpdate();
                showReadiness(registration.active);
            });
        };
        registration.addEventListener('updatefound', watchInstallation);
        watchInstallation();
        availableUpdate();
        navigator.serviceWorker.ready.then(() => showReadiness());
        await showReadiness(registration.active);
    } catch (error) {
        if (readiness) {
            readiness.textContent = messages.unavailable;
            readiness.dataset.state = 'unavailable';
        }
        if (status) status.dataset.posShellReason = 'worker-registration-failed';
        console.error('POS offline shell registration failed', error);
    }

    window.addEventListener('online', networkChanged);
    window.addEventListener('offline', networkChanged);
}

const offlineRoot = document.querySelector('[data-pos-offline-shell][data-pos-terminal]');
if (offlineRoot) {
    try {
        const panel = offlineRoot.querySelector('[data-pos-local]');
        const scope = JSON.parse(panel?.dataset.posScope ?? 'null');
        installOfflineShell(offlineRoot, scope).catch((error) => {
            const readiness = offlineRoot.querySelector('[data-pos-shell-readiness]');
            if (readiness) {
                readiness.textContent = fallback.assets_unavailable;
                readiness.dataset.state = 'unavailable';
            }
            console.error('POS cached offline terminal initialization failed', error);
        });
    } catch (error) {
        console.error('POS cached offline terminal configuration is invalid', error);
    }
}

globalThis.FoxHemaPosShell = Object.freeze({ installOfflineShell });
