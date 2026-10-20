<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ __('landing.description') }}">
        <meta name="color-scheme" content="light">
        <meta name="theme-color" content="#132441">
        <title>{{ __('landing.title') }} — Fox &amp; Hema POS</title>
        <link rel="icon" href="{{ asset('images/fox-hema-icon.svg') }}" type="image/svg+xml">
        @vite('resources/css/app.css')
    </head>
    <body class="fox-landing">
        @php
            $workspaceUrl = auth()->check()
                ? route('filament.admin.pages.dashboard')
                : route('filament.admin.auth.login');
            $workspaceLabel = auth()->check() ? __('landing.dashboard') : __('landing.login');
        @endphp
        <a class="landing-skip" href="#main">{{ __('landing.skip') }}</a>
        <header class="landing-header">
            <div class="landing-container landing-header-inner">
                <a class="landing-brand" href="{{ url('/') }}" aria-label="Fox &amp; Hema POS">@include('components.brand-logo')</a>
                <div class="landing-header-actions">
                    @include('components.language-switcher')
                    <a class="landing-button landing-button-small" href="{{ $workspaceUrl }}">{{ $workspaceLabel }}<x-filament::icon icon="heroicon-o-arrow-right" class="landing-arrow" /></a>
                </div>
            </div>
        </header>
        <main id="main">
            <section class="landing-hero" aria-labelledby="hero-title">
                <div class="landing-container landing-hero-grid">
                    <div class="landing-hero-copy">
                        <p class="landing-eyebrow"><span aria-hidden="true"></span>{{ __('landing.eyebrow') }}</p>
                        <h1 id="hero-title">{{ __('landing.headline') }} <span>{{ __('landing.headline_accent') }}</span></h1>
                        <p class="landing-description">{{ __('landing.description') }}</p>
                        <div class="landing-hero-actions">
                            <a class="landing-button" href="{{ $workspaceUrl }}">{{ $workspaceLabel }}<x-filament::icon icon="heroicon-o-arrow-right" class="landing-arrow" /></a>
                            <a class="landing-text-link" href="#workspace">{{ __('landing.explore') }}<x-filament::icon icon="heroicon-o-arrow-down" /></a>
                        </div>
                        <ul class="landing-highlights" aria-label="{{ __('landing.highlights') }}">
                            @foreach (['bilingual' => 'heroicon-o-globe-alt', 'locations' => 'heroicon-o-share', 'permissions' => 'heroicon-o-shield-check', 'setup' => 'heroicon-o-cog-6-tooth'] as $highlight => $icon)
                                <li><span><x-filament::icon :icon="$icon" /></span>{{ __('landing.'.$highlight) }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <figure class="landing-preview">
                        <div class="landing-preview-image">
                            <img src="{{ asset('images/landing-retail-experience.png') }}" alt="{{ __('landing.preview_alt') }}" width="1672" height="941" fetchpriority="high">
                        </div>
                        <figcaption>{{ __('landing.preview_caption') }}</figcaption>
                    </figure>
                </div>
            </section>
            <section id="workspace" class="landing-workspace" aria-labelledby="workspace-title">
                <div class="landing-container">
                    <div class="landing-section-heading">
                        <div>
                            <p class="landing-overline">{{ __('landing.workspace_eyebrow') }}</p>
                            <h2 id="workspace-title">{{ __('landing.workspace_title') }}</h2>
                        </div>
                        <p>{{ __('landing.workspace_description') }}</p>
                    </div>
                    <div class="landing-module-grid">
                        @foreach ([
                            'products' => 'heroicon-o-cube',
                            'inventory' => 'heroicon-o-building-storefront',
                            'sales' => 'heroicon-o-shopping-bag',
                            'purchasing' => 'heroicon-o-truck',
                            'pos' => 'heroicon-o-computer-desktop',
                            'access' => 'heroicon-o-shield-check',
                        ] as $module => $icon)
                            <article class="landing-module">
                                <div class="landing-module-icon"><x-filament::icon :icon="$icon" /></div>
                                <h3>{{ __('landing.modules.'.$module.'.title') }}</h3>
                                <p>{{ __('landing.modules.'.$module.'.description') }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        </main>
        <footer class="landing-footer">
            <div class="landing-container landing-footer-inner">
                <a class="landing-brand" href="{{ url('/') }}" aria-label="Fox &amp; Hema POS">@include('components.brand-logo')</a>
                <p>{{ __('landing.footer') }}</p>
                <a class="landing-footer-link" href="{{ $workspaceUrl }}">{{ $workspaceLabel }}<x-filament::icon icon="heroicon-o-arrow-right" class="landing-arrow" /></a>
            </div>
        </footer>
    </body>
</html>
