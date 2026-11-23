<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>FOX &amp; HEMA POS</title>
    <link rel="stylesheet" href="{{ $stylesheet }}">
    <script type="module" src="{{ $script }}"></script>
</head>
<body class="fi-body">
    <main hidden class="fi-main fox-pos-terminal" data-pos-offline-shell data-pos-terminal lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
        @include('pos.terminal-header', [
            'presentation' => $presentation,
            'canViewShifts' => $presentation['can_view_shifts'] ?? false,
            'administrationUrl' => $presentation['administration_url'] ?? null,
            'initiallyOffline' => true,
        ])
        @include('pos.shell-status')
        @include('pos.terminal-navigation')
        <section
            class="fox-pos-local-terminal"
            data-pos-local
            data-pos-context="{{ $context ?? '' }}"
            data-pos-offline-shell
            data-pos-scope="{{ json_encode($scope, JSON_THROW_ON_ERROR) }}"
            data-pos-device-code="{{ $presentation['device_code'] ?? '' }}"
            data-pos-local-labels="{{ json_encode(__('pos.offline'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) }}"
            aria-label="{{ __('pos.offline.title') }}"
        ></section>
        <div data-pos-returns-host></div>
    </main>
</body>
</html>
