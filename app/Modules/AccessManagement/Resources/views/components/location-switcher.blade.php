@php
    $user = auth()->user();
    $locations = $user->isGlobalSuperAdmin()
        ? \App\Modules\AccessManagement\Models\Location::query()->where('is_active', true)->orderBy('name')->get()
        : $user->locations()->where('is_active', true)->orderBy('name')->get();
    $current = session(\App\Modules\AccessManagement\Services\LocationContext::SESSION_KEY);
@endphp
<form method="post" action="{{ route('access-management.location.update') }}" class="fox-location-switcher">
    @csrf
    <label for="current-location">{{ __('Current Location') }}</label>
    <select id="current-location" name="location_id" onchange="this.form.requestSubmit()">
        @if($user->isGlobalSuperAdmin())
            <option value="global" @selected($current === null)>{{ __('Global administration scope') }}</option>
        @endif
        @foreach($locations as $location)
            <option value="{{ $location->id }}" @selected((int) $current === $location->id)>{{ $location->name }}</option>
        @endforeach
    </select>
    <noscript><button type="submit">{{ __('Switch Location') }}</button></noscript>
    @error('location_id') <span role="alert">{{ $message }}</span> @enderror
</form>
