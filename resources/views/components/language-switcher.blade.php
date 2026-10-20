<nav aria-label="{{ __('Language') }}" class="fox-language-switcher">
    <x-filament::icon icon="heroicon-o-globe-alt" />
    @foreach(['ar' => 'العربية', 'en' => 'EN'] as $locale => $label)
        @if(! $loop->first)<span class="fox-language-separator" aria-hidden="true">|</span>@endif
        <form method="post" action="{{ route('locale.update', $locale) }}">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
            <button type="submit" lang="{{ $locale }}" aria-label="{{ $locale === 'ar' ? 'العربية' : 'English' }}" @if(app()->isLocale($locale)) aria-current="true" @endif>{{ $label }}</button>
        </form>
    @endforeach
</nav>
