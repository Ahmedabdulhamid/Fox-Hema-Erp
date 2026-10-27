<div class="fox-commercial-value {{ ($emphasized ?? false) ? 'fox-commercial-value-total' : '' }}" data-commercial-value="{{ $field ?? '' }}">
    <span class="fox-commercial-mobile-label">{{ $label }}</span>
    <strong><bdi>{{ \App\Models\NumericFormatter::format($value ?? null, $kind ?? \App\Models\NumericFormatter::kind($field ?? '') ?? 'money') }}</bdi></strong>
    @if(!empty($notice))
        <small class="fox-commercial-notice">{{ $notice }}</small>
    @endif
</div>

@if (isset($subtotal))
<small class="fox-commercial-notice">{{ __('Subtotal') }} ? <bdi>{{ \App\Models\MoneyFormatter::format($subtotal) }}</bdi></small>
@endif
