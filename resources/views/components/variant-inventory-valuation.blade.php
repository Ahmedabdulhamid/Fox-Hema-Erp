@php
    use App\Models\MoneyFormatter;
    use App\Models\QuantityFormatter;
    use Brick\Math\BigDecimal;
    use Brick\Math\RoundingMode;
@endphp
<div class="space-y-6">
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Inventory valuation is location-specific. Reference cost and sale price are maintained separately.') }}</p>
    @if ($locations === [])
        <x-filament::section>{{ __('No authorized inventory locations are available.') }}</x-filament::section>
    @else
        <div class="space-y-2">
            <label for="valuation-location-{{ $variantId }}" class="text-sm font-medium">{{ __('Location') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="locationId" id="valuation-location-{{ $variantId }}">
                    <option value="">{{ __('Select a location') }}</option>
                    @foreach ($locations as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        @if ($valuation)
            <x-filament::section>
                @include('components.profitability-summary', ['profitability' => $valuation['profitability'], 'source' => __('Location cost estimate for one unit; before discounts and taxes')])
            </x-filament::section>
            <x-filament::section :heading="__('Inventory Valuation')" icon="heroicon-o-calculator">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach ([__('Product') => $valuation['variant']->product->name, __('Variant') => $valuation['variant']->displayName(), __('SKU') => $valuation['variant']->sku, __('Location') => $valuation['location']->name, __('Costing method') => __($valuation['method_label'])] as $label => $value)
                        <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="font-medium">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </x-filament::section>
            <x-filament::section :heading="__('Physical inventory')">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach ([__('Quantity on hand') => $valuation['quantity'], __('Quantity reserved') => $valuation['balance']?->quantity_reserved ?? '0', __('Quantity available') => $valuation['balance']?->quantity_available ?? '0'] as $label => $value)
                        <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="text-xl font-semibold" dir="ltr">{{ QuantityFormatter::format($value) }}</dd></div>
                    @endforeach
                </dl>
            </x-filament::section>
            <x-filament::section :heading="__('Actual inventory valuation')">
                @if ($valuation['mismatch'] || $valuation['fifo_mismatch'])
                    <p role="status" class="mb-4 font-medium text-warning-600 dark:text-warning-400">{{ __('Inventory quantities or FIFO values disagree. Review the records; no values have been adjusted.') }}</p>
                @endif
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Inventory value') }}</dt><dd class="text-xl font-semibold" dir="ltr">{{ $valuation['state'] ? MoneyFormatter::format($valuation['state']->total_value) : __('Not valued') }}</dd></div>
                    <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Valued quantity') }}</dt><dd dir="ltr">{{ QuantityFormatter::format($valuation['state']?->on_hand_qty) }}</dd></div>
                    @if ($valuation['method'] === 'avco')
                        <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Average inventory cost') }}</dt><dd class="text-xl font-semibold" dir="ltr">{{ $valuation['state'] ? MoneyFormatter::format($valuation['state']->avg_cost) : __('Not valued') }}</dd></div>
                    @elseif ($valuation['method'] === 'standard')
                        <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Effective standard unit cost') }}</dt><dd dir="ltr">{{ $valuation['standard_problem'] ? __('Conflicting standard-cost revisions') : ($valuation['standard_revision'] ? MoneyFormatter::format($valuation['standard_revision']->new_cost) : __('No effective standard-cost revision')) }}</dd></div>
                        @if ($valuation['standard_revision'] && ! $valuation['standard_problem'])
                            <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Effective date') }}</dt><dd>{{ $valuation['standard_revision']->effective_at->format('Y-m-d H:i:s') }}</dd></div>
                            <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Standard-cost revision') }}</dt><dd>#{{ $valuation['standard_revision']->id }}</dd></div>
                        @endif
                    @endif
                </dl>
                @if ($valuation['method'] === 'fifo')
                    <div class="mt-4 space-y-3">
                        <p>{{ __('Remaining FIFO inventory value') }}: <span dir="ltr">{{ MoneyFormatter::format($valuation['fifo_value']) }}</span></p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('FIFO costs belong to individual layers, not a universal unit cost.') }}</p>
                        <div class="overflow-x-auto">
                            <table class="fox-data-table w-full text-sm">
                                <thead><tr class="border-b text-start">
                                    @foreach (['Movement reference', 'Original quantity', 'Remaining quantity', 'Unit cost', 'Remaining value', 'Receipt date'] as $label)
                                        <th class="p-2 text-start font-medium">{{ __($label) }}</th>
                                    @endforeach
                                </tr></thead>
                                <tbody>
                                    @forelse ($valuation['layers'] as $layer)
                                        <tr class="border-b" wire:key="valuation-layer-{{ $layer->id }}">
                                            <td class="p-2">#{{ $layer->stock_movement_id }} @if ($layer->stockMovement?->reference_id) · {{ __('Receipt item') }} #{{ $layer->stockMovement->reference_id }} @endif</td>
                                            <td class="p-2" dir="ltr">{{ QuantityFormatter::format($layer->quantity) }}</td>
                                            <td class="p-2" dir="ltr">{{ QuantityFormatter::format($layer->remaining_qty) }}</td>
                                            <td class="p-2" dir="ltr" title="{{ $layer->unit_cost }}">{{ MoneyFormatter::format($layer->unit_cost) }}</td>
                                            <td class="p-2" dir="ltr">{{ MoneyFormatter::format((string) BigDecimal::of($layer->remaining_qty)->multipliedBy($layer->unit_cost)->toScale(4, RoundingMode::HalfUp)) }}</td>
                                            <td class="p-2">{{ ($layer->received_at ?? $layer->created_at)?->format('Y-m-d H:i:s') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="p-4 text-gray-500">{{ __('No remaining FIFO layers.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        {{ $valuation['layers']->links() }}
                    </div>
                @endif
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Money is displayed to two decimals; stored quantities and costs retain their full precision.') }}</p>
            </x-filament::section>
            <x-filament::section :heading="__('Purchase and catalog prices')">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @if ($valuation['can_view_purchase'])
                        <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Latest posted purchase unit cost') }}</dt><dd dir="ltr">{{ $valuation['latest_purchase'] ? MoneyFormatter::format($valuation['latest_purchase']->purchase_unit_cost) : __('No posted purchase cost') }}</dd>
                            @if ($valuation['latest_purchase']) <p class="text-sm text-gray-500">{{ $valuation['latest_purchase']->receipt_number }}</p> @endif
                        </div>
                    @endif
                    @if ($valuation['can_view_reference'])
                        <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Reference cost') }}</dt><dd dir="ltr">{{ MoneyFormatter::format($valuation['reference_cost']) }}</dd></div>
                    @endif
                    <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Catalog sale price') }}</dt><dd dir="ltr">{{ MoneyFormatter::format($valuation['variant']->sale_price) }}</dd></div>
                </dl>
            </x-filament::section>
        @else
            <p>{{ __('Select a location to inspect inventory valuation.') }}</p>
        @endif
    @endif
</div>
