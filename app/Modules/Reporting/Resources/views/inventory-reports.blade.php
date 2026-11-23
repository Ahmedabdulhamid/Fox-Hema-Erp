<x-filament-panels::page class="reporting-page" :dir="app()->getLocale() === 'ar' ? 'rtl' : 'ltr'" :lang="app()->getLocale()">
    <div class="reporting-dashboard">
        <div wire:init="loadReport"></div>

        <x-filament::section class="reporting-filter-panel">
            <form wire:submit="applyFilters" class="reporting-filter-grid">
                <label class="reporting-filter-field">
                    {{ __('reporting.filters.from') }}
                    <x-filament::input.wrapper><x-filament::input type="date" wire:model="from" /></x-filament::input.wrapper>
                </label>
                <label class="reporting-filter-field">
                    {{ __('reporting.filters.until') }}
                    <x-filament::input.wrapper><x-filament::input type="date" wire:model="until" /></x-filament::input.wrapper>
                </label>
                <label class="reporting-filter-field">
                    {{ __('reporting.filters.stock_location') }}
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="locationId">
                            <option value="">{{ __('reporting.filters.all_locations') }}</option>
                            @foreach ($this->branches as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                @include('reporting::components.filter-actions')
            </form>
            <div class="reporting-errors">
                @foreach (['from', 'until', 'locationId'] as $field)
                    @error($field)<p>{{ $message }}</p>@enderror
                @endforeach
            </div>
            <p class="reporting-filter-note">
                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                <span>{{ __('reporting.notes.inventory_basis') }}</span>
            </p>
        </x-filament::section>

        @if ($isLoaded)
            @php($report = $this->report)
            <section class="reporting-kpis" aria-label="{{ __('reporting.sections.current_inventory') }}">
                @foreach ([
                    ['on_hand', 'on_hand', 'heroicon-o-archive-box', 'teal'],
                    ['reserved', 'reserved', 'heroicon-o-lock-closed', 'amber'],
                    ['available', 'available', 'heroicon-o-check-circle', 'blue'],
                    ['low_count', 'low_stock_pairs', 'heroicon-o-exclamation-triangle', 'rose'],
                ] as [$field, $label, $icon, $tone])
                    @include('reporting::components.kpi-card', [
                        'label' => __('reporting.kpis.'.$label),
                        'value' => $field === 'low_count'
                            ? number_format($report['totals'][$field])
                            : \App\Models\NumericFormatter::format($report['totals'][$field], 'quantity'),
                        'icon' => $icon,
                        'tone' => $tone,
                    ])
                @endforeach
                @if ($report['costs'])
                    @include('reporting::components.kpi-card', [
                        'label' => __('reporting.kpis.current_value'),
                        'value' => $report['totals']['value'] === null
                            ? __('reporting.values.unavailable')
                            : \App\Models\MoneyFormatter::precise($report['totals']['value'], 'EGP'),
                        'icon' => 'heroicon-o-currency-dollar',
                        'tone' => 'violet',
                    ])
                    @include('reporting::components.kpi-card', [
                        'label' => __('reporting.kpis.missing_cost'),
                        'value' => number_format($report['totals']['missing_cost'] ?? 0),
                        'icon' => 'heroicon-o-question-mark-circle',
                        'tone' => 'amber',
                    ])
                @endif
            </section>

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.current_inventory')">
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead>
                            <tr>
                                <th>{{ __('reporting.tables.location') }}</th>
                                <th>{{ __('reporting.variant_fields.heading') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.on_hand') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.reserved') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.available') }}</th>
                                @if ($report['costs'])
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.carrying_value') }} (EGP)</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['stock']['items'] as $row)
                                <tr>
                                    <td>{{ $row['location'] }}</td>
                                    <td>
                                        @include('reporting::components.variant-attributes', [
                                            'brand' => $row['brand_name'] ?? null,
                                            'color' => $row['color_name'] ?? null,
                                            'hex' => $row['color_hex'] ?? null,
                                            'size' => $row['size_name'] ?? null,
                                        ])
                                    </td>
                                    @foreach (['quantity_on_hand', 'quantity_reserved', 'quantity_available'] as $field)
                                        <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\NumericFormatter::format($row[$field], 'quantity') }}</td>
                                    @endforeach
                                    @if ($report['costs'])
                                        <td class="reporting-table__numeric" dir="ltr">
                                            {{ $row['value'] === null ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::precise($row['value'], 'EGP') }}
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td class="reporting-table__empty" colspan="{{ $report['costs'] ? 6 : 5 }}">{{ __('reporting.empty.stock') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['stock'], 'stockPage')->links() }}
            </x-filament::section>

            <div class="reporting-grid-two">
                <x-filament::section class="reporting-panel" :heading="__('reporting.sections.low_stock')">
                    <p class="reporting-filter-note">{{ __('reporting.notes.low_stock_basis') }}</p>
                    <div class="reporting-table-wrap">
                        <table class="reporting-table">
                            <thead>
                                <tr>
                                    <th>{{ __('reporting.tables.location') }}</th>
                                    <th>{{ __('reporting.tables.product') }}</th>
                                    <th>{{ __('reporting.variant_fields.heading') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.available') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.reorder_threshold') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($report['low'] as $row)
                                    <tr>
                                        <td>{{ $row['location'] }}</td>
                                        <td>{{ $row['product'] }}</td>
                                        <td>
                                            @include('reporting::components.variant-attributes', [
                                                'brand' => $row['brand_name'] ?? null,
                                                'color' => $row['color_name'] ?? null,
                                                'hex' => $row['color_hex'] ?? null,
                                                'size' => $row['size_name'] ?? null,
                                            ])
                                        </td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\NumericFormatter::format($row['quantity_available'], 'quantity') }}</td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\NumericFormatter::format($row['reorder_threshold'], 'quantity') }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="reporting-table__empty" colspan="5">{{ __('reporting.empty.low_stock') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>

                <x-filament::section class="reporting-panel" :heading="__('reporting.sections.movement_summary')">
                    @include('reporting::components.freshness', ['state' => $report['freshness'], 'unit' => __('reporting.freshness.location_days')])
                    <div class="reporting-table-wrap reporting-table-wrap--spaced">
                        <table class="reporting-table">
                            <thead>
                                <tr>
                                    <th>{{ __('reporting.tables.movement_type') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.received_quantity') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.issued_quantity') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.movement_count') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.reversal_count') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($report['trend']['types'] as $row)
                                    <tr>
                                        <td>{{ __('reporting.values.'.$row['type']) }}</td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\NumericFormatter::format($row['received'], 'quantity') }}</td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\NumericFormatter::format($row['issued'], 'quantity') }}</td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ number_format($row['movements']) }}</td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ number_format($row['reversals']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="reporting-table__empty" colspan="5">{{ __('reporting.empty.movements') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            </div>

            @if (collect($report['trend']['types'])->sum('movements') > 0)
                @livewire(
                    \App\Modules\Reporting\Filament\Widgets\DailyInventoryChart::class,
                    ['from' => $from, 'until' => $until, 'locationId' => $locationId],
                    key('inventory-chart-'.hash('sha256', json_encode([$from, $until, $locationId])))
                )
            @endif

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.historical_closing')">
                <div class="reporting-summary-list">
                    <div class="reporting-summary-list__row">
                        <span class="reporting-summary-list__label">{{ __('reporting.filters.until') }}</span>
                        <strong class="reporting-summary-list__value" dir="ltr">{{ $until }}</strong>
                    </div>
                    <div class="reporting-summary-list__row">
                        <span class="reporting-summary-list__label">{{ __('reporting.tables.closing_quantity') }}</span>
                        <strong class="reporting-summary-list__value" dir="ltr">{{ $report['closing']['totals']['quantity'] === null ? __('reporting.values.unavailable') : \App\Models\NumericFormatter::format($report['closing']['totals']['quantity'], 'quantity') }}</strong>
                    </div>
                    <div class="reporting-summary-list__row">
                        <span class="reporting-summary-list__label">{{ __('reporting.tables.incomplete_quantity') }}</span>
                        <strong class="reporting-summary-list__value" dir="ltr">{{ number_format($report['closing']['totals']['missing_quantity'] ?? 0) }}</strong>
                    </div>
                    @if ($report['costs'])
                        <div class="reporting-summary-list__row">
                            <span class="reporting-summary-list__label">{{ __('reporting.kpis.current_value') }}</span>
                            <strong class="reporting-summary-list__value" dir="ltr">{{ $report['closing']['totals']['value'] === null ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::precise($report['closing']['totals']['value'], 'EGP') }}</strong>
                        </div>
                        <div class="reporting-summary-list__row">
                            <span class="reporting-summary-list__label">{{ __('reporting.tables.incomplete_cost') }}</span>
                            <strong class="reporting-summary-list__value" dir="ltr">{{ number_format($report['closing']['totals']['missing_cost'] ?? 0) }}</strong>
                        </div>
                    @endif
                </div>
                <p class="reporting-filter-note">{{ __('reporting.notes.historical_unavailable') }}</p>
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead>
                            <tr>
                                <th>{{ __('reporting.tables.location') }}</th>
                                <th>{{ __('reporting.variant_fields.heading') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.closing_quantity') }}</th>
                                @if ($report['costs'])
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.carrying_value') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['closing']['rows']['items'] as $row)
                                <tr>
                                    <td>{{ $row['location'] }}</td>
                                    <td>
                                        @include('reporting::components.variant-attributes', [
                                            'brand' => $row['brand_name'] ?? null,
                                            'color' => $row['color_name'] ?? null,
                                            'hex' => $row['color_hex'] ?? null,
                                            'size' => $row['size_name'] ?? null,
                                        ])
                                    </td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ $row['closing_quantity'] === null ? __('reporting.values.unavailable') : \App\Models\NumericFormatter::format($row['closing_quantity'], 'quantity') }}</td>
                                    @if ($report['costs'])
                                        <td class="reporting-table__numeric" dir="ltr">{{ $row['closing_value'] === null ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::precise($row['closing_value'], 'EGP') }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td class="reporting-table__empty" colspan="{{ $report['costs'] ? 4 : 3 }}">{{ __('reporting.empty.closing') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['closing']['rows'], 'closingPage')->links() }}
            </x-filament::section>

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.movement_history')">
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead>
                            <tr>
                                <th>{{ __('reporting.tables.recorded_at') }}</th>
                                <th>{{ __('reporting.tables.location') }}</th>
                                <th>{{ __('reporting.variant_fields.heading') }}</th>
                                <th>{{ __('reporting.tables.movement_type') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.signed_quantity') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.reverses_movement') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['history']['items'] as $row)
                                <tr>
                                    <td dir="ltr">{{ \Carbon\CarbonImmutable::parse($row['created_at'])->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</td>
                                    <td>{{ $row['location'] }}</td>
                                    <td>
                                        @include('reporting::components.variant-attributes', [
                                            'brand' => $row['brand_name'] ?? null,
                                            'color' => $row['color_name'] ?? null,
                                            'hex' => $row['color_hex'] ?? null,
                                            'size' => $row['size_name'] ?? null,
                                        ])
                                    </td>
                                    <td>{{ __('reporting.values.'.$row['type']) }}</td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\NumericFormatter::format($row['quantity'], 'quantity') }}</td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ $row['reverses_movement_id'] ?? __('reporting.values.not_applicable') }}</td>
                                </tr>
                            @empty
                                <tr><td class="reporting-table__empty" colspan="6">{{ __('reporting.empty.movements') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['history'], 'movementPage')->links() }}
            </x-filament::section>
        @else
            <p wire:loading wire:target="loadReport" role="status" class="reporting-empty">{{ __('reporting.loading.inventory') }}</p>
        @endif
    </div>
</x-filament-panels::page>
