<div class="fox-pos-shell-status" wire:ignore data-pos-shell-status data-pos-shell-labels="{{ json_encode(__('pos.shell')) }}" role="status" aria-live="polite">
    <span class="fox-pos-operating-mode" data-pos-operating-mode data-mode="online">{{ __('pos.shell.mode_online') }}</span>
    <span data-pos-shell-readiness data-state="checking">{{ __('pos.shell.checking') }}</span>
    <span data-pos-shell-network></span>
    <span data-pos-catalog-readiness>{{ __('pos.shell.catalog_unavailable') }}</span>
    <span data-pos-offline-sales-readiness>{{ __('pos.shell.offline_sales_ready') }}</span>
    <span data-pos-sync-errors></span>
    <span data-pos-shell-update></span>
</div>
