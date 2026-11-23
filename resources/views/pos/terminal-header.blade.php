<header class="fox-pos-toolbar">
    <div class="fox-pos-heading">
        <span class="fox-pos-brand-mark" aria-hidden="true">@svg('heroicon-o-shopping-bag')</span>
        <div><strong dir="ltr">FOX &amp; HEMA</strong><h1>{{ __('pos.terminal_heading') }}</h1></div>
    </div>
    <nav class="fox-pos-header-actions" aria-label="{{ __('pos.ui.navigation') }}">
        <div class="fox-pos-connection">
            <span class="fox-pos-header-network" data-pos-header-network>{{ __('pos.shell.network_checking') }}</span>
            <span class="fox-pos-operating-mode" data-pos-operating-mode data-mode="{{ $initiallyOffline ? 'offline' : 'online' }}">{{ __('pos.shell.mode_'.($initiallyOffline ? 'offline' : 'online')) }}</span>
            <small data-pos-mode-description>{{ __('pos.shell.mode_'.($initiallyOffline ? 'offline' : 'online').'_description') }}</small>
        </div>
        <a class="fox-pos-cart-jump" href="#pos-cart-heading">@svg('heroicon-o-shopping-cart') {{ __('pos.cart.title') }}</a>
        @if ($canViewShifts)
            <a class="fox-pos-header-link" data-pos-shift-link href="{{ route('filament.admin.resources.pos-sessions.index') }}">@svg('heroicon-o-clock') {{ __('pos.fields.shift') }}</a>
        @endif
        @if ($administrationUrl !== null)
            <a class="fox-pos-header-link" data-pos-administration href="{{ $administrationUrl }}">@svg('heroicon-o-arrow-top-right-on-square') {{ __('pos.ui.administration') }}</a>
        @endif
    </nav>
    <div class="fox-pos-session-strip">
        <div><small>{{ __('pos.fields.branch') }}</small><strong data-pos-branch><bdi>{{ $presentation['branch_name'] }}</bdi></strong></div>
        <div><small>{{ __('pos.fields.cashier') }}</small><strong data-pos-cashier><bdi>{{ $presentation['cashier_name'] }}</bdi></strong></div>
        <div><small>{{ __('pos.fields.device_code') }}</small><strong><bdi>{{ $presentation['device_code'] }}</bdi></strong></div>
        <div><small>{{ __('pos.fields.shift') }}</small><strong><bdi>{{ $presentation['shift_code'] }}</bdi></strong></div>
        <div><small>{{ __('pos.fields.opened_at') }}</small><strong><bdi>{{ $presentation['opened_at'] }}</bdi></strong></div>
        <div><small>{{ __('pos.fields.status') }}</small><strong><bdi>{{ $presentation['status'] }}</bdi></strong></div>
        <div><small>{{ __('pos.fields.opening_cash') }}</small><strong><bdi>{{ $presentation['opening_cash'] }}</bdi></strong></div>
    </div>
</header>
