<section class="allocation-stock-banner" aria-label="{{ __('Available unallocated stock') }}" aria-live="polite">
    <div class="allocation-stock-main"><span class="allocation-cube"><x-heroicon-o-cube /></span><div><span>{{ __('Available unallocated stock') }}</span><strong>{{ \App\Models\NumericFormatter::format($context['available'], 'quantity') }} <small>{{ __('pieces') }}</small></strong></div></div>
    <div class="allocation-stock-detail"><span>{{ __('Source location') }}</span><strong>{{ $context['location'] ?? __('Select source location') }}</strong></div>
    <div class="allocation-stock-detail"><span>{{ __('Unallocated stock source') }}</span><strong dir="ltr">{{ $context['source']?->sku ?? __('No source selected') }}</strong></div>
    @if($context['receipt'])
        <div class="allocation-stock-detail"><span>{{ __('Latest posted receipt (pooled stock)') }}</span><strong dir="ltr">{{ $context['receipt']->receipt_number }}</strong></div>
    @endif
    @if($context['cost'])
        <div class="allocation-stock-detail"><span>{{ __('Current carrying value') }}</span><strong>{{ \App\Models\MoneyFormatter::format($context['cost']->total_value) }}</strong></div>
    @endif
</section>
