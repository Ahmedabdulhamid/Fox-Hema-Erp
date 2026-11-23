<article class="reporting-kpi-card reporting-kpi-card--{{ $tone ?? 'teal' }}">
    <div class="reporting-kpi-card__top">
        <p class="reporting-kpi-card__label">{{ $label }}</p>
        <span class="reporting-kpi-card__icon" aria-hidden="true">
            <x-filament::icon :icon="$icon" />
        </span>
    </div>
    <p class="reporting-kpi-card__value" dir="ltr"><bdi>{{ $value }}</bdi></p>
    @if (isset($detail))
        <p class="reporting-kpi-card__detail">{{ $detail }}</p>
    @endif
</article>
