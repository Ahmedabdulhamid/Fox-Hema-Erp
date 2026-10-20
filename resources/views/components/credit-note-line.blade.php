@if ($row)
    <dl class="fox-credit-line">
        @foreach (['invoice_quantity' => 'credits.invoiced_quantity', 'quantity' => 'credits.returned_quantity', 'remaining' => 'credits.remaining', 'remaining_amount' => 'credits.available_amount', 'unit_price' => 'invoices.unit_price', 'discount_amount' => 'invoices.discount', 'tax_amount' => 'invoices.tax', 'line_total' => 'credits.original_line_total'] as $field => $label)
            <div @class(['is-available' => in_array($field, ['remaining', 'remaining_amount'])])>
                <dt>{{ __($label) }}</dt>
                <dd><bdi>{{ $row[$field] }}</bdi></dd>
            </div>
        @endforeach
    </dl>
@else
    <p class="fox-credit-notice">{{ __('credits.eligibility') }}</p>
@endif
