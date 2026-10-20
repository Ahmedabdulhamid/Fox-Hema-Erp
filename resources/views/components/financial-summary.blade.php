@if ($totals)
    <dl @class(['fox-financial-summary', 'fox-commercial-summary' => $commercial ?? false]) aria-live="polite">
        @foreach (($commercial ?? false) ? ['subtotal' => 'Subtotal', 'discount_amount' => 'Discounts', 'tax_amount' => 'Taxes', 'total_amount' => 'Grand total'] : ['subtotal' => 'Subtotal', 'discount_amount' => 'Discount', 'tax_amount' => 'Tax', 'total_amount' => 'Total (EGP)'] as $field => $label)
            <div @class(['fox-credit-total' => $field === 'total_amount'])>
                <dt>{{ __($label) }}</dt>
                <dd><bdi>@if ($commercial ?? false)<span class="fox-currency">EGP</span> @endif{{ $totals[$field] }}</bdi></dd>
            </div>
        @endforeach
    </dl>
@else
    <p class="fox-credit-notice">{{ $notice }}</p>
@endif
