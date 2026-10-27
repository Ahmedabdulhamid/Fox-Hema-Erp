<div class="fox-inventory-page space-y-4">
    @include('components.inventory-product', ['variant' => $record->variant])
    <dl class="fox-smart-facts">
        @foreach (['Reference' => $record->display_reference, 'Location' => $record->location->name, 'Quantity' => \App\Models\QuantityFormatter::format((string) $record->quantity), 'Variant' => $record->variant->isUnallocated() ? __('Unallocated pieces') : $record->variant->imageLabel(), 'Created at' => $record->received_at?->format('Y-m-d H:i')] as $label => $value)<div><dt>{{ __($label) }}</dt><dd>@if ($label === 'Variant')<x-inventory-variant :variant="$record->variant" />@else{{ $value }}@endif</dd></div>@endforeach
    </dl>
    <x-filament::badge color="warning">{{ __('Quarantined; unavailable for allocation') }}</x-filament::badge>
    <section class="fox-inventory-panel"><header><h3>{{ __('returns.condition_notes') }}</h3></header><p class="fox-smart-help">{{ $record->condition_notes }}</p></section>
    <section class="fox-inventory-panel"><header><h3>{{ __('returns.inspection_observations') }}</h3></header>
        @forelse ($record->inspections as $inspection)<div class="fox-inventory-evidence"><p>{{ $inspection->observations }}</p><small>{{ $inspection->inspectedBy?->name }} · {{ $inspection->inspected_at ? \Carbon\CarbonImmutable::parse($inspection->inspected_at)->format('Y-m-d H:i') : '—' }}</small></div>@empty<p class="fox-smart-help">{{ __('returns.pending_inspection') }}</p>@endforelse
    </section>
    <p class="fox-smart-help">{{ __('Observation only: quarantined stock remains separate until an authorized disposition is supported.') }}</p>
</div>
