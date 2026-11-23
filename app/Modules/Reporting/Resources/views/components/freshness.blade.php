@php
    $isCurrent = $state['projected_days'] === $state['expected_days'] && $state['pending_days'] === 0;
    $oldestProjection = $state['oldest_projection']
        ? \Carbon\CarbonImmutable::parse($state['oldest_projection'])->locale(app()->getLocale())->translatedFormat('d M Y H:i')
        : null;
@endphp
<section class="reporting-freshness" aria-label="{{ __('reporting.freshness.coverage') }}">
    <div class="reporting-freshness__top">
        <h2>{{ __('reporting.freshness.coverage') }}</h2>
        <span @class(['reporting-status', 'reporting-status--success' => $isCurrent, 'reporting-status--warning' => ! $isCurrent])>
            {{ $isCurrent ? __('reporting.freshness.current') : __('reporting.freshness.needs_review') }}
        </span>
    </div>
    <dl class="reporting-freshness__grid">
        <div>
            <dt>{{ __('reporting.freshness.projected') }}</dt>
            <dd><bdi dir="ltr">{{ $state['projected_days'] }}/{{ $state['expected_days'] }}</bdi> {{ $unit }}</dd>
        </div>
        <div>
            <dt>{{ __('reporting.freshness.pending') }}</dt>
            <dd><bdi dir="ltr">{{ $state['pending_days'] }}</bdi></dd>
        </div>
        <div>
            <dt>{{ __('reporting.freshness.oldest') }}</dt>
            <dd>
                @if ($oldestProjection)
                    <bdi dir="ltr">{{ $oldestProjection }}</bdi>
                @else
                    {{ __('reporting.values.unavailable') }}
                @endif
            </dd>
        </div>
    </dl>
    @unless ($isCurrent)
        <p class="reporting-freshness__warning">{{ __('reporting.freshness.provisional') }}</p>
    @endunless
</section>
