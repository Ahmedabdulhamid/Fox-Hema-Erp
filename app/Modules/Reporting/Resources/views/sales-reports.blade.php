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
                    {{ __('reporting.filters.currency') }}
                    <x-filament::input.wrapper><x-filament::input wire:model="currency" maxlength="3" dir="ltr" /></x-filament::input.wrapper>
                </label>
                @include('reporting::components.filter-actions')
            </form>
            <div class="reporting-errors">
                @foreach (['from', 'until', 'locationId', 'currency'] as $field)
                    @error($field)<p>{{ $message }}</p>@enderror
                @endforeach
            </div>
            <p class="reporting-filter-note">
                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                <span>{{ __('reporting.notes.sales_basis') }}</span>
            </p>
        </x-filament::section>

        @if ($isLoaded)
            @php($report = $this->report)
            @php($freshness = $this->freshness)
            <section class="reporting-kpis" aria-label="{{ __('reporting.pages.sales.title') }}">
                @foreach ([
                    ['invoiced_total', 'invoiced', 'heroicon-o-document-text', 'teal'],
                    ['credited_total', 'credits', 'heroicon-o-arrow-uturn-left', 'rose'],
                    ['net_revenue', 'net_revenue', 'heroicon-o-banknotes', 'blue'],
                    ['net_total', 'net_sales', 'heroicon-o-calculator', 'violet'],
                ] as [$field, $label, $icon, $tone])
                    @include('reporting::components.kpi-card', [
                        'label' => __('reporting.kpis.'.$label),
                        'value' => \App\Models\MoneyFormatter::format($report['totals'][$field], $report['currency']),
                        'icon' => $icon,
                        'tone' => $tone,
                    ])
                @endforeach
                @include('reporting::components.kpi-card', [
                    'label' => __('reporting.kpis.invoice_count'),
                    'value' => number_format($report['totals']['invoice_count']),
                    'icon' => 'heroicon-o-document-check',
                    'tone' => 'amber',
                ])
                @include('reporting::components.kpi-card', [
                    'label' => __('reporting.kpis.credit_count'),
                    'value' => number_format($report['totals']['credit_count']),
                    'icon' => 'heroicon-o-document-minus',
                    'tone' => 'rose',
                ])
            </section>

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.payment_methods')">
                <p class="reporting-filter-note">{{ __('reporting.notes.sales_collection_basis') }}</p>
                <div class="reporting-submetrics">
                    @foreach ([
                        ['total', 'pos_collections'],
                        ['cash', 'pos_cash_collections'],
                        ['non_cash', 'pos_non_cash_collections'],
                    ] as [$field, $label])
                        <div class="reporting-submetric">
                            <span>{{ __('reporting.kpis.'.$label) }}</span>
                            <strong dir="ltr">{{ \App\Models\MoneyFormatter::format($report['collections'][$field], $currency) }}</strong>
                        </div>
                    @endforeach
                </div>
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead>
                            <tr>
                                <th>{{ __('reporting.tables.payment_method') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['collections']['methods'] as $method)
                                <tr wire:key="sales-payment-method-{{ $method['id'] }}">
                                    <td>{{ $method['name'] }}</td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\MoneyFormatter::format($method['amount'], $currency) }}</td>
                                </tr>
                            @empty
                                <tr><td class="reporting-table__empty" colspan="2">{{ __('reporting.empty.payment_methods') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            @include('reporting::components.freshness', ['state' => $freshness, 'unit' => __('reporting.freshness.branch_days')])

            @livewire(
                \App\Modules\Reporting\Filament\Widgets\DailySalesChart::class,
                ['from' => $from, 'until' => $until, 'locationId' => $locationId, 'currency' => $currency],
                key('daily-sales-'.hash('sha256', json_encode([$from, $until, $locationId, $currency])))
            )

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.top_variants')">
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead>
                            <tr>
                                <th>{{ __('reporting.tables.product') }}</th>
                                <th>{{ __('reporting.variant_fields.heading') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.net_quantity') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.net_revenue') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['variants'] as $variant)
                                <tr wire:key="sales-variant-{{ $variant['variant_id'] }}">
                                    <td>{{ $variant['name'] }}</td>
                                    <td>
                                        @include('reporting::components.variant-attributes', [
                                            'brand' => $variant['brand_name'] ?? null,
                                            'color' => $variant['color_name'] ?? null,
                                            'hex' => $variant['color_hex'] ?? null,
                                            'size' => $variant['size_name'] ?? null,
                                        ])
                                    </td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\NumericFormatter::format($variant['net_quantity'], 'quantity') }}</td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\MoneyFormatter::format($variant['net_revenue'], $report['currency']) }}</td>
                                </tr>
                            @empty
                                <tr><td class="reporting-table__empty" colspan="4">{{ __('reporting.empty.sales') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @else
            <p wire:loading wire:target="loadReport" role="status" class="reporting-empty">{{ __('reporting.loading.sales') }}</p>
        @endif
    </div>
</x-filament-panels::page>
