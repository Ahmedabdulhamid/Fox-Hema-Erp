<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Change password') }} — Fox &amp; Hema POS</title>
        @vite('resources/css/filament/admin/theme.css')
    </head>
    <body class="fox-public">
        <main class="fox-public-card">
            <div class="fox-public-brand"><img src="{{ asset('images/fox-hema-logo.svg') }}" alt="Fox & Hema POS"></div>
            @include('components.language-switcher')
            <h1>{{ __('Change your password') }}</h1>
            <p>{{ __('You must choose a new password before continuing.') }}</p>
            @if($errors->any())
                <div class="fox-public-errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form method="post" action="{{ route('access-management.password.update') }}" class="fox-public-form">
                @csrf
                @method('PUT')
                <label>{{ __('Current password') }}<input type="password" name="current_password" required autocomplete="current-password"></label>
                <label>{{ __('New password') }}<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
                <label>{{ __('Confirm new password') }}<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
                <button type="submit" class="fox-public-primary">{{ __('Change password') }}</button>
            </form>
            <form method="post" action="{{ route('filament.admin.auth.logout') }}">
                @csrf
                <button type="submit" class="fox-public-secondary">{{ __('Log out') }}</button>
            </form>
        </main>
    </body>
</html>
