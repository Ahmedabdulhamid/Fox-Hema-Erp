@if ($row)
    <dl class="fox-credit-line">
        @foreach (['invoice_quantity' => 'credits.invoiced_quantity', 'quantity' => 'credits.returned_quantity', 'remaining' => 'credits.remaining', 'remaining_amount' => 'credits.available_amount', 'unit_price' => 'invoices.unit_price', 'discount_amount' => 'invoices.discount', 'tax_amount' => 'invoices.tax', 'line_total' => 'credits.original_line_total'] as $field => $label)
            <div @class(['is-available' => in_array($field, ['remaining', 'remaining_amount'])])>
                <dt>{{ __($label) }}</dt>
                <dd><bdi>{{ \App\Models\NumericFormatter::format($row[$field], in_array($field, ['invoice_quantity', 'quantity', 'remaining'], true) ? 'quantity' : ($field === 'unit_price' ? 'precise' : 'money')) }}</bdi></dd>
            </div>
        @endforeach
    </dl>
@else
    <p class="fox-credit-notice">{{ __('credits.eligibility') }}</p>
@endif
