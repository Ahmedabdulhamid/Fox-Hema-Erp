<div class="fox-commercial-value {{ ($emphasized ?? false) ? 'fox-commercial-value-total' : '' }}" data-commercial-value="{{ $field ?? '' }}">
    <span class="fox-commercial-mobile-label">{{ $label }}</span>
    <strong><bdi>{{ $value ?? '—' }}</bdi></strong>
    @if(!empty($notice))
        <small class="fox-commercial-notice">{{ $notice }}</small>
    @endif
</div>

@if (isset($subtotal))
<small class="fox-commercial-notice">{{ __('Subtotal') }} ? <bdi>{{ $subtotal }}</bdi></small>
@endif
