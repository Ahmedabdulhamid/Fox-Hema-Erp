@auth
    @if(\App\Modules\AccessManagement\Filament\Resources\SecurityEvents\AuthEventResource::canViewAny())
        <a class="fox-topbar-events" href="{{ \App\Modules\AccessManagement\Filament\Resources\SecurityEvents\AuthEventResource::getUrl() }}" aria-label="{{ __('Security Events') }}" title="{{ __('Security Events') }}">
            <x-filament::icon icon="heroicon-o-bell" />
        </a>
    @endif
    <div class="fox-topbar-identity">
        <strong>{{ auth()->user()->name }}</strong>
        <span>{{ auth()->user()->getRoleNames()->map(fn (string $role): string => __($role))->join(' · ') ?: __('Account') }}</span>
    </div>
@endauth
