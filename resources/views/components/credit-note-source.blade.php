@if ($source)
    <dl class="fox-credit-line">
        <div><dt>{{ __('invoices.branch') }}</dt><dd>{{ $source->location?->name }}</dd></div>
        <div><dt>{{ __('invoices.customer') }}</dt><dd>{{ $invoice?->customer_name ?? '—' }}</dd></div>
        <div><dt>{{ __('credits.invoice') }}</dt><dd><bdi>{{ $invoice?->invoice_number ?? '—' }}</bdi></dd></div>
        <div><dt>{{ __('invoices.currency') }}</dt><dd>{{ $invoice?->currency ?? '—' }}</dd></div>
    </dl>
@endif
