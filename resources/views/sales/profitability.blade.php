<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="applyFilters" class="flex flex-wrap items-end gap-4">
            <label class="space-y-1 text-sm">{{ __('From') }}<x-filament::input.wrapper><x-filament::input type="date" wire:model="from" /></x-filament::input.wrapper></label>
            <label class="space-y-1 text-sm">{{ __('Until') }}<x-filament::input.wrapper><x-filament::input type="date" wire:model="until" /></x-filament::input.wrapper></label>
            <label class="space-y-1 text-sm">{{ __('Branch') }}<x-filament::input.wrapper><x-filament::input.select wire:model="locationId">
                <option value="">{{ __('All permitted branches') }}</option>
                @foreach ($this->branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper></label>
            <label class="space-y-1 text-sm">{{ __('Group by') }}<x-filament::input.wrapper><x-filament::input.select wire:model="group">
                @foreach (['product' => 'Product', 'variant' => 'Variant', 'location' => 'Branch', 'order' => 'Sales Order'] as $key => $label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper></label>
            <x-filament::button type="submit">{{ __('Apply filters') }}</x-filament::button>
        </form>
        @error('until')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
    </x-filament::section>
    @php($report = $this->report)
    <x-filament::section>
        @include('components.profitability-summary', ['estimated' => false, 'profitability' => $report['summary']])
        @if ($report['summary']['missing'])
            <p class="mt-4 text-sm text-danger-600">{{ __('Incomplete costing evidence: COGS, profit and percentages are unavailable.') }} ({{ $report['summary']['missing'] }})</p>
        @endif
        <p class="mt-4 text-sm text-gray-500">{{ __('Delivery basis, by posting date. Net revenue excludes VAT. Physical returns reverse agreed revenue and original COGS; posted credits adjust only the difference. Invoices do not add revenue again. All amounts are EGP.') }}</p>
        <p class="mt-2 text-sm text-gray-500">{{ __('Return losses and standard-cost variances are excluded from gross profit and shown separately. This report is not a general ledger profit and loss statement.') }}</p>
        <p class="mt-2 text-sm">{{ __('Return losses') }}: {{ \App\Models\MoneyFormatter::format($report['summary']['return_loss']) }} · {{ __('Return valuation variance') }}: {{ \App\Models\MoneyFormatter::format($report['summary']['return_variance']) }}</p>
    </x-filament::section>
    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead><tr>
                    @foreach (['Group', 'Net sales revenue', 'Actual COGS', 'Gross profit', 'Gross margin %'] as $label)<th class="p-3 text-start">{{ __($label) }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        @php($amounts = app(\App\Modules\InventoryCosting\Services\ProfitabilityCalculator::class)->calculate((string) $row->revenue, $row->cost === null ? null : (string) $row->cost))
                        <tr wire:key="profit-{{ $group }}-{{ $row->id }}">
                            <td class="p-3">{{ $row->label }}</td>
                            @foreach (['revenue', 'cost', 'profit'] as $field)<td class="p-3 tabular-nums">{{ $amounts[$field] === null ? __('N/A') : \App\Models\MoneyFormatter::format($amounts[$field]) }}</td>@endforeach
                            <td class="p-3 tabular-nums">{{ $amounts['margin'] === null ? __('N/A') : \App\Models\PercentageFormatter::format($amounts['margin']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-3" colspan="5">{{ __('No posted sales in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $report['rows']->links() }}
    </x-filament::section>
</x-filament-panels::page>
