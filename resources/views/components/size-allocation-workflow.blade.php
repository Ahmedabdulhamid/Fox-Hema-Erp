<nav class="allocation-workflow" aria-label="{{ __('Allocation workflow') }}">
    @foreach ([['Brand', $context['brand_selected']], ['Product', $context['product_selected']], ['Source stock', $context['source_selected']], ['Allocate quantities', $context['is_valid']]] as $step)
        <div class="allocation-workflow-step {{ $step[1] ? 'is-complete' : '' }}">
            <span class="allocation-step-circle">@if($step[1])<x-heroicon-m-check />@else{{ $loop->iteration }}@endif</span>
            <span><strong>{{ __($step[0]) }}</strong><small>{{ $step[1] ? __('Selected') : __('Awaiting selection') }}</small></span>
        </div>
    @endforeach
    <span class="allocation-status">{{ $context['record_status'] === 'new' ? __('New allocation') : __(ucfirst($context['record_status'])) }}</span>
</nav>
