<div class="fox-commercial-snapshot" data-commercial-snapshot="{{ $kind }}">
    <span class="fox-commercial-mobile-label">{{ __($kind === 'tax' ? 'Taxes' : 'Discounts') }}</span>
    @if($snapshot === null)
        <small>{{ __('Historical amount; definition not recorded.') }}</small>
    @elseif($kind === 'discount')
        @if(!empty($snapshot['discount']))
            <span class="fox-commercial-chip">{{ $snapshot['discount']['name'] }} · <bdi>{{ $snapshot['discount']['value'] }}{{ $snapshot['discount']['type'] === 'percentage' ? '%' : ' EGP' }}</bdi></span>
        @else
            <small>{{ __('No discount applied') }}</small>
        @endif
    @else
        @forelse($snapshot['taxes'] ?? [] as $tax)
            <span class="fox-commercial-chip">{{ $tax['name'] }} · <bdi>{{ $tax['rate'] }}{{ $tax['type'] === 'percentage' ? '%' : ' EGP' }}</bdi></span>
        @empty
            <small>{{ __('No tax applied') }}</small>
        @endforelse
    @endif
    <strong><bdi>{{ $amount }}</bdi> <small>EGP</small></strong>
</div>
