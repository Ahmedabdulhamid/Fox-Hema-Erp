<x-filament-widgets::widget>
    <section class="fox-dashboard-hero">
        <div class="relative z-10 max-w-3xl">
            <p class="fox-dashboard-eyebrow">Fox &amp; Hema POS · {{ __('Smart Retail Management') }}</p>
            <h1 class="fox-dashboard-title">{{ __('Welcome back, :name 👋', ['name' => auth()->user()->name]) }}</h1>
            <p class="fox-dashboard-copy">{{ __('Here’s what’s happening across your users, locations, and security activity today.') }}</p>
            <div class="fox-dashboard-meta">
                <span class="fox-dashboard-pill">
                    <x-filament::icon icon="heroicon-m-map-pin" class="h-4 w-4 text-primary-600" />
                    {{ $this->currentLocationName() }}
                </span>
                <span class="fox-dashboard-pill">
                    <x-filament::icon icon="heroicon-m-calendar-days" class="h-4 w-4 text-primary-600" />
                    {{ now()->translatedFormat('l، j M Y') }}
                </span>
            </div>
        </div>
    </section>
</x-filament-widgets::widget>
