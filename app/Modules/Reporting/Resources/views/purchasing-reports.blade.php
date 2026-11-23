<x-filament-panels::page class="reporting-page" :dir="app()->getLocale() === 'ar' ? 'rtl' : 'ltr'" :lang="app()->getLocale()">
    <div class="reporting-dashboard">
        <div wire:init="loadReport"></div>
        <x-filament::section class="reporting-filter-panel">
            <form wire:submit="applyFilters" class="reporting-filter-grid">
                @foreach (['from', 'until'] as $field)
                    <label class="reporting-filter-field">
                        {{ __('reporting.filters.'.$field) }}
                        <x-filament::input.wrapper><x-filament::input type="date" wire:model="{{ $field }}" /></x-filament::input.wrapper>
                    </label>
                @endforeach
                <label class="reporting-filter-field">
                    {{ __('reporting.filters.stock_location') }}
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="locationId">
                            <option value="">{{ __('reporting.filters.all_locations') }}</option>
                            @foreach ($this->branches as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="reporting-filter-field">
                    {{ __('purchasing_reporting.supplier') }}
                    <x-filament::input.wrapper><x-filament::input type="search" maxlength="100" wire:model.live.debounce.300ms="supplierSearch" :placeholder="__('purchasing_reporting.search_suppliers')" /></x-filament::input.wrapper>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="supplierId">
                            <option value="">{{ __('reporting.filters.all_records') }}</option>
                            @foreach ($this->suppliers as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="reporting-filter-field">
                    {{ __('purchasing_reporting.variant_filter') }}
                    <x-filament::input.wrapper><x-filament::input type="number" min="1" wire:model="variantId" /></x-filament::input.wrapper>
                </label>
                <label class="reporting-filter-field">
                    {{ __('reporting.filters.currency') }}
                    <x-filament::input.wrapper><x-filament::input.select wire:model="currency"><option value="EGP">{{ __('purchasing_reporting.egp') }}</option></x-filament::input.select></x-filament::input.wrapper>
                </label>
                @include('reporting::components.filter-actions')
            </form>
            <div class="reporting-errors">
                @foreach (['from', 'until', 'locationId', 'supplierId', 'variantId', 'currency'] as $field)
                    @error($field)<p>{{ $message }}</p>@enderror
                @endforeach
            </div>
            <p class="reporting-filter-note">
                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                <span>{{ __('purchasing_reporting.basis') }}</span>
            </p>
        </x-filament::section>
        @if ($isLoaded)
            @php($report = $this->report)
            <section class="reporting-kpis" aria-label="{{ $this->getTitle() }}">
                @foreach ([
                    ['order', 'amount', 'order_amount', 'heroicon-o-shopping-cart', 'blue'],
                    ['order_line', 'remaining', 'outstanding_quantity', 'heroicon-o-clock', 'amber'],
                    ['receipt_line', 'quantity', 'receipt_quantity', 'heroicon-o-cube', 'teal'],
                    ['receipt_line', 'amount', 'receipt_value', 'heroicon-o-truck', 'teal'],
                    ['bill', 'amount', 'bill_amount', 'heroicon-o-document-text', 'violet'],
                    ['bill_line', 'quantity', 'bill_quantity', 'heroicon-o-document-text', 'blue'],
                    ['payment', 'amount', 'payment_amount', 'heroicon-o-banknotes', 'rose'],
                    ['recovery', 'amount', 'recovery_amount', 'heroicon-o-arrow-uturn-left', 'amber'],
                ] as [$event, $field, $label, $icon, $tone])
                    @php($value = $report['totals'][$event][$field])
                    @include('reporting::components.kpi-card', [
                        'label' => __('purchasing_reporting.'.$label),
                        'value' => $value === null ? __('reporting.values.unavailable') : ($field === 'amount'
                            ? \App\Models\MoneyFormatter::precise($value, $currency)
                            : (string) \Brick\Math\BigDecimal::of((string) $value)->toScale(4)),
                        'icon' => $icon, 'tone' => $tone,
                    ])
                @endforeach
            </section>
            @include('reporting::components.freshness', ['state' => $report['freshness'], 'unit' => __('purchasing_reporting.location_days')])
            <div class="reporting-chart">
                @livewire(\App\Modules\Reporting\Filament\Widgets\DailyPurchasingChart::class, [
                    'from' => $from, 'until' => $until, 'locationId' => $locationId, 'supplierId' => $supplierId, 'currency' => $currency,
                ], key('purchasing-chart-'.md5(json_encode([$from, $until, $locationId, $supplierId, $currency]))))
            </div>
            <x-filament::section class="reporting-panel" :heading="__('purchasing_reporting.activity')">
                <p class="reporting-filter-note">{{ __('purchasing_reporting.performance_basis') }}</p>
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead><tr>
                            @foreach (['date', 'supplier', 'location', 'event', 'status', 'document_count', 'amount', 'partial_count', 'complete_count'] as $field)
                                <th>{{ __('purchasing_reporting.'.$field) }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                            @forelse ($report['rows'] as $row)
                                <tr>
                                    <td dir="ltr">{{ \Carbon\CarbonImmutable::parse($row['report_date'])->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                                    <td>{{ $row['supplier'] }}</td><td>{{ $row['location'] }}</td>
                                    <td>{{ __('purchasing_reporting.events.'.$row['event']) }}</td>
                                    <td><span @class(['reporting-status', 'reporting-status--success' => in_array($row['status'], ['posted', 'received', 'closed']), 'reporting-status--warning' => ! in_array($row['status'], ['posted', 'received', 'closed'])])>{{ __('purchasing_reporting.statuses.'.$row['status']) }}</span></td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ number_format((float) $row['document_count']) }}</td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ $row['event'] === 'receipt' ? __('reporting.values.not_applicable') : ($row['amount'] === null ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::precise($row['amount'], $currency)) }}</td>
                                    @foreach (['partial_count', 'complete_count'] as $field)
                                        <td class="reporting-table__numeric" dir="ltr">{{ $row['event'] !== 'receipt' ? __('reporting.values.not_applicable') : ($row['missing'] > 0 ? __('reporting.values.unavailable') : number_format((float) $row[$field])) }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="9" class="reporting-table__empty">{{ __('purchasing_reporting.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['rows'], $report['total'])->links() }}
            </x-filament::section>
            <x-filament::section class="reporting-panel" :heading="__('purchasing_reporting.products')">
                <p class="reporting-filter-note">{{ __('purchasing_reporting.product_basis') }}</p>
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead><tr>
                            <th>{{ __('reporting.variant_fields.heading') }}</th>
                            @foreach (['supplier', 'location', 'event', 'quantity', 'amount', 'remaining'] as $field)<th>{{ __('purchasing_reporting.'.$field) }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                            @forelse ($report['variants'] as $row)
                                <tr>
                                    <td>@include('reporting::components.variant-attributes', ['brand' => $row['brand_name'], 'color' => $row['color_name'], 'hex' => $row['color_hex'], 'size' => $row['size_name']])</td>
                                    <td>{{ $row['supplier'] }}</td><td>{{ $row['location'] }}</td><td>{{ __('purchasing_reporting.events.'.$row['event']) }}</td>
                                    @foreach (['quantity', 'amount', 'remaining'] as $field)
                                        <td class="reporting-table__numeric" dir="ltr">{{ $field === 'remaining' && $row['event'] !== 'order_line' ? __('reporting.values.not_applicable') : ($row[$field] === null ? __('reporting.values.unavailable') : ($field === 'amount' ? \App\Models\MoneyFormatter::precise($row[$field], $currency) : (string) \Brick\Math\BigDecimal::of((string) $row[$field])->toScale(4))) }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="7" class="reporting-table__empty">{{ __('purchasing_reporting.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['variants'], $report['variant_total'])->links() }}
            </x-filament::section>
            <x-filament::section class="reporting-panel" :heading="__('purchasing_reporting.balances')">
                <p class="reporting-filter-note">{{ __('purchasing_reporting.balance_basis') }}</p>
                @if ($report['balances_pending'])<p class="reporting-freshness__warning">{{ __('reporting.freshness.provisional') }}</p>@endif
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead><tr>
                            @foreach (['supplier', 'location', 'billed', 'paid', 'outstanding', 'advances', 'net_balance', 'projected_at'] as $field)<th>{{ __('purchasing_reporting.'.$field) }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                            @forelse ($report['balances'] as $row)
                                <tr>
                                    <td>{{ $row['supplier'] }}</td><td>{{ $row['location'] }}</td>
                                    @foreach (['billed', 'paid', 'outstanding', 'advances', 'net_balance'] as $field)
                                        <td class="reporting-table__numeric" dir="ltr">{{ $row['missing'] > 0 || $report['balances_pending'] ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::precise($row[$field], $currency) }}</td>
                                    @endforeach
                                    <td dir="ltr">{{ \Carbon\CarbonImmutable::parse($row['projected_at'])->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="reporting-table__empty">{{ __('purchasing_reporting.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['balances'], $report['balance_total'])->links() }}
            </x-filament::section>
        @else
            <p wire:loading wire:target="loadReport" role="status" class="reporting-empty">{{ __('purchasing_reporting.loading') }}</p>
        @endif
    </div>
</x-filament-panels::page>
