<div aria-live="polite">
    @if ($totals)
        <dl class="fox-credit-summary">
            @foreach (['subtotal' => 'invoices.subtotal', 'discount_amount' => 'invoices.discount', 'tax_amount' => 'invoices.tax', 'total_amount' => 'credits.total'] as $field => $label)
                <div @class(['fox-credit-total' => $field === 'total_amount'])>
                    <dt>{{ __($label) }}</dt>
                    <dd><bdi>{{ $totals[$field] }}</bdi></dd>
                </div>
            @endforeach
        </dl>
    @else
        <p class="fox-credit-notice">{{ __('credits.eligibility') }}</p>
    @endif
</div>
