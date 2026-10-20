<x-filament-panels::page>
    @php($profileUser = $this->getUser()->loadMissing(['locations', 'roles']))
    <div class="fox-profile" x-data="{ tab: 'account' }">
        <section class="fox-profile-hero">
            <div class="fox-profile-avatar">{{ $this->initials() }}<span><x-filament::icon icon="heroicon-m-camera" class="h-4 w-4" /></span></div>
            <div class="fox-profile-identity">
                <p class="fox-profile-eyebrow">{{ __('My profile') }}</p>
                <h1>{{ $profileUser->name }}</h1>
                <div class="fox-profile-details">
                    <span><x-filament::icon icon="heroicon-m-envelope" class="h-4 w-4" />{{ $profileUser->email }}</span>
                    <span><x-filament::icon icon="heroicon-m-calendar-days" class="h-4 w-4" />{{ __('Member since :date', ['date' => $profileUser->created_at?->translatedFormat('j M Y')]) }}</span>
                </div>
            </div>
            <div class="fox-profile-role">
                <span>{{ __('Your roles') }}</span>
                @forelse($profileUser->roles->unique('id')->take(3) as $role)<strong>♛ {{ __($role->name) }}</strong>@empty<strong>{{ __('No assigned role') }}</strong>@endforelse
            </div>
        </section>

        <nav class="fox-profile-tabs" aria-label="{{ __('Profile sections') }}">
            @foreach(['account' => __('Account'), 'security' => __('Security'), 'sessions' => __('Sessions'), 'activity' => __('Activity')] as $key => $label)
                <button type="button" x-on:click="tab='{{ $key }}'" x-bind:class="tab === '{{ $key }}' && 'is-active'">{{ $label }}</button>
            @endforeach
        </nav>

        <div x-show="tab === 'account'" class="fox-profile-grid">
            <section class="fox-profile-card fox-profile-form-card">
                <div class="fox-profile-card-heading"><div><h2>{{ __('Account details') }}</h2><p>{{ __('Update your personal information.') }}</p></div><x-filament::icon icon="heroicon-m-user" class="h-5 w-5" /></div>
                <form wire:submit="save" class="space-y-6">{{ $this->form }}<x-filament::button type="submit" icon="heroicon-m-check">{{ __('Save changes') }}</x-filament::button></form>
            </section>
            <aside class="space-y-4">
                <section class="fox-profile-card"><div class="fox-profile-card-heading"><div><h2>{{ __('Assigned locations') }}</h2><p>{{ __('Your accessible workplaces.') }}</p></div><x-filament::icon icon="heroicon-m-map-pin" class="h-5 w-5" /></div><div class="fox-profile-tags">@forelse($profileUser->locations as $location)<span>{{ $location->name }}</span>@empty<p>{{ __('No locations assigned.') }}</p>@endforelse</div></section>
                <section class="fox-profile-card fox-company-card"><img src="{{ asset('images/fox-hema-logo.svg') }}" alt="Fox & Hema POS"><div><strong>Fox &amp; Hema POS</strong><small>{{ __('Smart Retail Management') }}</small></div></section>
            </aside>
        </div>

        <section x-show="tab === 'security'" x-cloak class="fox-profile-card">
            <div class="fox-profile-card-heading"><div><h2>{{ __('Security settings') }}</h2><p>{{ __('Keep your account and password protected.') }}</p></div><x-filament::icon icon="heroicon-m-shield-check" class="h-6 w-6" /></div>
            <div class="fox-security-row"><div><strong>{{ __('Password') }}</strong><p>{{ __('Last changed :date', ['date' => $profileUser->password_changed_at?->diffForHumans() ?? __('not recorded')]) }}</p></div><a href="{{ route('access-management.password.edit') }}" class="fox-outline-button">{{ __('Change password') }}</a></div>
            <div class="fox-security-row"><div><strong>{{ __('Account status') }}</strong><p>{{ __('Your account is currently :status.', ['status' => $profileUser->is_active ? __('active') : __('disabled')]) }}</p></div><span class="fox-status-dot">{{ $profileUser->is_active ? __('Active') : __('Disabled') }}</span></div>
        </section>

        <section x-show="tab === 'sessions'" x-cloak class="fox-profile-card">
            <div class="fox-profile-card-heading"><div><h2>{{ __('Recent sessions') }}</h2><p>{{ __('Devices currently signed in to your account.') }}</p></div><a href="{{ \App\Modules\AccessManagement\Filament\Resources\Sessions\DatabaseSessionResource::getUrl() }}">{{ __('Manage all') }}</a></div>
            <div class="fox-profile-list">@forelse($this->sessions() as $session)<div><span class="fox-list-icon"><x-filament::icon icon="heroicon-m-computer-desktop" class="h-5 w-5" /></span><div><strong>{{ $session->ip_address ?? __('Unknown IP') }}</strong><p>{{ str($session->user_agent)->limit(75) }}</p></div><time>{{ $session->last_activity?->diffForHumans() }}</time></div>@empty<p class="fox-empty-state">{{ __('No active sessions.') }}</p>@endforelse</div>
        </section>

        <section x-show="tab === 'activity'" x-cloak class="fox-profile-card">
            <div class="fox-profile-card-heading"><div><h2>{{ __('Security activity') }}</h2><p>{{ __('Your recent authentication and account events.') }}</p></div><a href="{{ \App\Modules\AccessManagement\Filament\Resources\SecurityEvents\AuthEventResource::getUrl() }}">{{ __('View all') }}</a></div>
            <div class="fox-profile-list">@forelse($this->securityEvents() as $event)<div><span class="fox-list-icon"><x-filament::icon icon="heroicon-m-shield-check" class="h-5 w-5" /></span><div><strong>{{ __(\Illuminate\Support\Str::headline($event->event)) }}</strong><p>{{ $event->ip_address ?? __('Unknown IP') }}</p></div><time>{{ $event->occurred_at?->diffForHumans() }}</time></div>@empty<p class="fox-empty-state">{{ __('No security activity recorded.') }}</p>@endforelse</div>
        </section>
    </div>
</x-filament-panels::page>
