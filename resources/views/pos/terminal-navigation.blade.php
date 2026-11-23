<nav class="fox-pos-navigation" aria-label="{{ __('pos.ui.navigation') }}" wire:ignore.self>
    <button type="button" data-pos-nav="sales" aria-current="page" wire:ignore>@svg('heroicon-o-shopping-cart')<span>{{ __('pos.workspace.sales') }}</span></button>
    <button type="button" data-pos-nav="returns" aria-haspopup="dialog" aria-controls="pos-returns-drawer" aria-expanded="false" wire:ignore @disabled(! ($navigationEnabled ?? true))>@svg('heroicon-o-arrow-uturn-left')<span>{{ __('pos.workspace.returns') }}</span></button>
    @if (isset($cashRefundAction))
        <span data-pos-online-only>{{ $cashRefundAction }}</span>
    @endif
    <button type="button" data-pos-nav="history" aria-haspopup="dialog" aria-expanded="false" wire:ignore @disabled(! ($navigationEnabled ?? true))>@svg('heroicon-o-clock')<span>{{ __('pos.workspace.history') }}</span></button>
    <button type="button" data-pos-nav="sync" aria-haspopup="dialog" aria-controls="pos-sync-drawer" aria-expanded="false" wire:ignore @disabled(! ($navigationEnabled ?? true))>@svg('heroicon-o-arrow-path')<span>{{ __('pos.workspace.sync') }}</span><i data-pos-sync-indicator aria-hidden="true"></i></button>
</nav>
