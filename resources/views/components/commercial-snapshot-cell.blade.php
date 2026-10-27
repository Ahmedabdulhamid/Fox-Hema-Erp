<div class="fox-commercial-snapshot" data-commercial-snapshot="{{ $kind }}">
    <span class="fox-commercial-mobile-label">{{ __($kind === 'tax' ? 'Taxes' : 'Discounts') }}</span>
    @if($snapshot === null)
        <small>{{ __('Historical amount; definition not recorded.') }}</small>
    @elseif($kind === 'discount')
        @if(!empty($snapshot['discount']))
            <span class="fox-commercial-chip">{{ $snapshot['discount']['name'] }} · <bdi>{{ \App\Models\NumericFormatter::definition($snapshot['discount']['value'], $snapshot['discount']['type']) }}</bdi></span>
        @else
            <small>{{ __('No discount applied') }}</small>
        @endif
    @else
        @forelse($snapshot['taxes'] ?? [] as $tax)
            <span class="fox-commercial-chip">{{ $tax['name'] }} · <bdi>{{ \App\Models\NumericFormatter::definition($tax['rate'], $tax['type']) }}</bdi></span>
        @empty
            <small>{{ __('No tax applied') }}</small>
        @endforelse
    @endif
    <strong><bdi>{{ \App\Models\MoneyFormatter::format($amount) }}</bdi></strong>
</div>
