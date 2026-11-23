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
                    {{ __('reporting.filters.branch') }}
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="locationId">
                            <option value="">{{ __('reporting.filters.all_branches') }}</option>
                            @foreach ($this->branches as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="reporting-filter-field">
                    {{ __('reporting.filters.group_by') }}
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="group">
                            <option value="variant">{{ __('reporting.filters.variant') }}</option>
                            <option value="product">{{ __('reporting.filters.product') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                @include('reporting::components.filter-actions')
            </form>
            <div class="reporting-errors">
                @foreach (['from', 'until', 'locationId', 'group'] as $field)
                    @error($field)<p>{{ $message }}</p>@enderror
                @endforeach
            </div>
            <p class="reporting-filter-note">
                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                <span>{{ __('reporting.notes.profitability_basis') }} {{ __('reporting.notes.profitability_exclusions') }}</span>
            </p>
        </x-filament::section>

        @if ($isLoaded)
            @php($report = $this->report)
            @php($freshness = $this->freshness)
            <section class="reporting-kpis" aria-label="{{ __('reporting.pages.profitability.title') }}">
                @foreach ([
                    ['revenue', 'revenue', 'heroicon-o-banknotes', 'teal'],
                    ['cost', 'cogs', 'heroicon-o-receipt-percent', 'blue'],
                    ['profit', 'gross_profit', 'heroicon-o-chart-bar', 'violet'],
                ] as [$field, $label, $icon, $tone])
                    @include('reporting::components.kpi-card', [
                        'label' => __('reporting.kpis.'.$label),
                        'value' => $report['totals'][$field] === null
                            ? __('reporting.values.unavailable')
                            : \App\Models\MoneyFormatter::format($report['totals'][$field], $report['currency']),
                        'icon' => $icon,
                        'tone' => $tone,
                    ])
                @endforeach
                @include('reporting::components.kpi-card', [
                    'label' => __('reporting.kpis.gross_margin'),
                    'value' => $report['totals']['margin'] === null
                        ? __('reporting.values.unavailable')
                        : $report['totals']['margin'].'%',
                    'icon' => 'heroicon-o-chart-pie',
                    'tone' => 'amber',
                ])
            </section>

            <section class="reporting-submetrics" aria-label="{{ __('reporting.sections.profitability_breakdown') }}">
                @foreach ([
                    ['returned_quantity', 'return_quantity', false],
                    ['returned_revenue', 'return_revenue', true],
                    ['credit_adjustments', 'credit_adjustment', true],
                    ['return_loss', 'return_loss', true],
                    ['return_variance', 'return_variance', true],
                ] as [$label, $field, $money])
                    <div class="reporting-submetric">
                        <span>{{ __('reporting.'.($label === 'return_loss' || $label === 'return_variance' ? 'tables' : 'kpis').'.'.$label) }}</span>
                        <strong dir="ltr">
                            @if ($money)
                                {{ \App\Models\MoneyFormatter::format($report['totals'][$field], $report['currency']) }}
                            @else
                                {{ \App\Models\NumericFormatter::format($report['totals'][$field], 'quantity') }}
                            @endif
                        </strong>
                    </div>
                @endforeach
            </section>

            @if ($report['totals']['missing'] > 0)
                <p class="reporting-filter-note" role="status">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                    <span>{{ __('reporting.kpis.missing_cost') }}: <bdi dir="ltr">{{ number_format($report['totals']['missing']) }}</bdi>. {{ __('reporting.values.unavailable') }}: {{ __('reporting.tables.cogs') }}, {{ __('reporting.tables.gross_profit') }}, {{ __('reporting.tables.margin') }}.</span>
                </p>
            @endif

            @include('reporting::components.freshness', ['state' => $freshness, 'unit' => __('reporting.freshness.branch_days')])

            @if ((float) $report['totals']['revenue'] !== 0.0 || (float) $report['totals']['return_quantity'] !== 0.0 || $report['totals']['missing'] > 0)
                @livewire(
                    \App\Modules\Reporting\Filament\Widgets\DailyProfitabilityChart::class,
                    ['from' => $from, 'until' => $until, 'locationId' => $locationId, 'currency' => $currency],
                    key('profit-chart-'.hash('sha256', json_encode([$from, $until, $locationId, $currency])))
                )
            @endif

            @foreach (['breakdown' => 'profitability_breakdown', 'top' => 'top_profitable'] as $section => $heading)
                <x-filament::section class="reporting-panel" :heading="__('reporting.sections.'.$heading)">
                    @if ($section === 'top')
                        <p class="reporting-filter-note">{{ __('reporting.values.unavailable') }}: {{ __('reporting.tables.cogs') }}. {{ __('reporting.tables.missing_cost') }}.</p>
                    @endif
                    <div class="reporting-table-wrap">
                        <table class="reporting-table">
                            <thead>
                                <tr>
                                    <th>{{ $section === 'top' || $group === 'variant' ? __('reporting.variant_fields.heading') : __('reporting.tables.product') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.revenue') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.cogs') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.gross_profit') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.margin') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.missing_cost') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($report[$section] as $row)
                                    <tr wire:key="profitability-{{ $section }}-{{ $group }}-{{ $row['id'] }}">
                                        <td>
                                            @if ($section === 'top' || $group === 'variant')
                                                @include('reporting::components.variant-attributes', [
                                                    'brand' => $row['brand_name'] ?? null,
                                                    'color' => $row['color_name'] ?? null,
                                                    'hex' => $row['color_hex'] ?? null,
                                                    'size' => $row['size_name'] ?? null,
                                                ])
                                            @else
                                                {{ $row['label'] }}
                                            @endif
                                        </td>
                                        @foreach (['revenue', 'cost', 'profit'] as $field)
                                            <td class="reporting-table__numeric" dir="ltr">
                                                {{ $row[$field] === null ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::format($row[$field], $report['currency']) }}
                                            </td>
                                        @endforeach
                                        <td class="reporting-table__numeric" dir="ltr">{{ $row['margin'] === null ? __('reporting.values.unavailable') : $row['margin'].'%' }}</td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ number_format($row['missing']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="reporting-table__empty" colspan="6">{{ __('reporting.empty.profitability') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($section === 'breakdown')
                        {{ $this->rows->links() }}
                    @endif
                </x-filament::section>
            @endforeach
        @else
            <p wire:loading wire:target="loadReport" role="status" class="reporting-empty">{{ __('reporting.loading.profitability') }}</p>
        @endif
    </div>
</x-filament-panels::page>
