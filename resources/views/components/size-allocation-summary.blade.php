<aside class="allocation-aside">
    <section class="allocation-product-card" aria-label="{{ __('Selected product') }}">
        <div class="allocation-product-heading"><img src="{{ $context['image'] }}" alt="{{ $context['product']?->name ?? __('Product') }}"><div><strong>{{ $context['product']?->name ?? __('Select Product') }}</strong><span>{{ $context['color'] }}</span><small>{{ $context['product']?->brand?->name ?? __('No brand') }}</small></div></div>
        <dl><div><dt>{{ __('Source location') }}</dt><dd>{{ $context['location'] ?? __('Not selected') }}</dd></div>
            <div><dt>{{ __('Source type') }}</dt><dd>{{ __('Explicit unallocated stock') }}</dd></div>
            @if($context['receipt'])<div><dt>{{ __('Latest posted receipt (pooled stock)') }}</dt><dd dir="ltr">{{ $context['receipt']->receipt_number }}</dd></div>@endif
        </dl>
        <p class="allocation-provenance-note">{{ __('Posting preserves the original cost provenance of the pooled stock.') }}</p>
    </section>
    <section class="allocation-totals {{ $context['over_allocated'] || ! $context['valid_quantities'] ? 'is-warning' : ($context['is_valid'] ? 'is-valid' : '') }}" aria-live="polite" aria-label="{{ __('Allocation summary') }}">
        <h3><x-heroicon-o-check-circle />{{ __('Allocation summary') }}</h3>
        <dl><div><dt>{{ __('Total available') }}</dt><dd data-allocation-available>{{ $context['available'] }} {{ __('pieces') }}</dd></div>
            <div><dt>{{ __('Total allocated') }}</dt><dd data-allocation-total>{{ $context['total'] }} {{ __('pieces') }}</dd></div>
            <div><dt>{{ __('Remaining unallocated') }}</dt><dd data-allocation-remaining>{{ $context['remaining'] }} {{ __('pieces') }}</dd></div>
        </dl>
        @if($context['over_allocated'])<p role="alert">{{ __('Allocation exceeds available unallocated stock at this location.') }}</p>
        @elseif(! $context['valid_quantities'])<p role="alert">{{ __('Choose eligible source stock and enter positive whole-piece quantities.') }}</p>
        @elseif($context['is_valid'])<p>{{ __('Ready to save or confirm allocation.') }}</p>
        @else<p>{{ __('Choose source stock and enter the quantities to allocate.') }}</p>@endif
    </section>
    <p class="allocation-footnote"><x-heroicon-o-lock-closed />{{ __('Stock and permissions are checked again when you confirm.') }}</p>
</aside>
