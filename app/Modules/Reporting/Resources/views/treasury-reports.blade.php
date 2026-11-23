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
                    {{ __('reporting.filters.account_location') }}
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="locationId">
                            <option value="">{{ __('reporting.filters.all_locations') }}</option>
                            @foreach ($this->branches as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="reporting-filter-field">
                    {{ __('reporting.filters.treasury_account') }}
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="accountId">
                            <option value="">{{ __('reporting.filters.all_accounts') }}</option>
                            @foreach ($this->accounts as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                @include('reporting::components.filter-actions')
            </form>
            <div class="reporting-errors">
                @foreach (['from', 'until', 'locationId', 'accountId'] as $field)
                    @error($field)<p>{{ $message }}</p>@enderror
                @endforeach
            </div>
            <p class="reporting-filter-note">
                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                <span>{{ __('reporting.notes.treasury_basis') }}</span>
            </p>
        </x-filament::section>

        @if ($isLoaded)
            @php($report = $this->report)
            @php($freshness = $report['freshness'])
            <section class="reporting-kpis" aria-label="{{ __('reporting.sections.treasury_summary') }}">
                @foreach ([
                    ['external_in', 'external_in', 'heroicon-o-arrow-down-left', 'teal'],
                    ['external_out', 'external_out', 'heroicon-o-arrow-up-right', 'rose'],
                    ['internal_in', 'internal_in', 'heroicon-o-arrow-down-tray', 'blue'],
                    ['internal_out', 'internal_out', 'heroicon-o-arrow-up-tray', 'violet'],
                    ['opening_in', 'opening_in', 'heroicon-o-arrow-down-circle', 'amber'],
                    ['opening_out', 'opening_out', 'heroicon-o-arrow-up-circle', 'amber'],
                ] as [$field, $label, $icon, $tone])
                    @include('reporting::components.kpi-card', [
                        'label' => __('reporting.kpis.'.$label),
                        'value' => \App\Models\MoneyFormatter::precise($report['totals'][$field], $currency),
                        'icon' => $icon,
                        'tone' => $tone,
                    ])
                @endforeach
            </section>

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.pos_collections')">
                <p class="reporting-filter-note">{{ __('reporting.notes.pos_collections_basis') }}</p>
                <div class="reporting-submetrics">
                    @foreach ([
                        ['total', 'pos_collections'],
                        ['cash', 'pos_cash_collections'],
                        ['non_cash', 'pos_non_cash_collections'],
                    ] as [$field, $label])
                        <div class="reporting-submetric">
                            <span>{{ __('reporting.kpis.'.$label) }}</span>
                            <strong dir="ltr">{{ \App\Models\MoneyFormatter::format($report['pos_collections'][$field], $currency) }}</strong>
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
                            @forelse ($report['pos_collections']['methods'] as $method)
                                <tr wire:key="treasury-pos-payment-method-{{ $method['id'] }}">
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

            @include('reporting::components.freshness', ['state' => $freshness, 'unit' => __('reporting.freshness.account_days')])

            @if ((float) $report['totals']['external_in'] !== 0.0 || (float) $report['totals']['external_out'] !== 0.0)
                @livewire(
                    \App\Modules\Reporting\Filament\Widgets\DailyCashChart::class,
                    ['domain' => 'treasury', 'from' => $from, 'until' => $until, 'locationId' => $locationId, 'accountId' => $accountId, 'currency' => $currency],
                    key('treasury-chart-'.hash('sha256', json_encode([$from, $until, $locationId, $accountId, $currency])))
                )
            @endif

            <div class="reporting-grid-two">
                <x-filament::section class="reporting-panel" :heading="__('reporting.sections.movement_categories')">
                    <div class="reporting-table-wrap">
                        <table class="reporting-table">
                            <thead>
                                <tr>
                                    <th>{{ __('reporting.tables.category') }}</th>
                                    <th>{{ __('reporting.tables.direction') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.amount') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.entry_count') }}</th>
                                    <th class="reporting-table__numeric">{{ __('reporting.tables.reversals') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($report['categories'] as $row)
                                    <tr>
                                        <td>{{ __('reporting.values.'.$row['category']) }}</td>
                                        <td>
                                            <span @class(['reporting-status', 'reporting-status--success' => $row['direction'] === 'in', 'reporting-status--danger' => $row['direction'] === 'out'])>
                                                {{ __('reporting.values.'.$row['direction']) }}
                                            </span>
                                        </td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\MoneyFormatter::precise($row['amount'], $currency) }}</td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ number_format($row['entries']) }}</td>
                                        <td class="reporting-table__numeric" dir="ltr">{{ number_format($row['reversals']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="reporting-table__empty" colspan="5">{{ __('reporting.empty.treasury_categories') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>

                <x-filament::section class="reporting-panel" :heading="__('reporting.sections.account_balances')">
                    @if ($report['can_balance'])
                        <div class="reporting-table-wrap">
                            <table class="reporting-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('reporting.tables.account') }}</th>
                                        <th class="reporting-table__numeric">{{ __('reporting.tables.balance') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($report['balances'] as $row)
                                        <tr>
                                            <td>{{ $row['name'] }}</td>
                                            <td class="reporting-table__numeric" dir="ltr">
                                                {{ $row['balance'] === null ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::precise($row['balance'], $currency) }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="reporting-table__empty" colspan="2">{{ __('reporting.empty.treasury_balances') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        {{ $this->paginator($report['balances'], $report['balance_total'])->links() }}
                    @else
                        <p class="reporting-empty">{{ __('reporting.permissions.balance_required') }}</p>
                    @endif
                </x-filament::section>
            </div>

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.treasury_entries')">
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead>
                            <tr>
                                <th>{{ __('reporting.tables.effective_date') }}</th>
                                <th>{{ __('reporting.tables.account') }}</th>
                                <th>{{ __('reporting.tables.category') }}</th>
                                <th>{{ __('reporting.tables.direction') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.amount') }}</th>
                                <th>{{ __('reporting.tables.is_reversal') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['rows'] as $row)
                                <tr>
                                    <td dir="ltr">{{ \Carbon\CarbonImmutable::parse($row['effective_date'])->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                                    <td>{{ $row['account'] }}</td>
                                    <td>{{ __('reporting.values.'.$row['category']) }}</td>
                                    <td>
                                        <span @class(['reporting-status', 'reporting-status--success' => $row['direction'] === 'in', 'reporting-status--danger' => $row['direction'] === 'out'])>
                                            {{ __('reporting.values.'.$row['direction']) }}
                                        </span>
                                    </td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\MoneyFormatter::precise($row['amount'], $currency) }}</td>
                                    <td>
                                        <span @class(['reporting-status', 'reporting-status--warning' => $row['is_reversal'], 'reporting-status--neutral' => ! $row['is_reversal']])>
                                            {{ $row['is_reversal'] ? __('reporting.values.yes') : __('reporting.values.no') }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="reporting-table__empty" colspan="6">{{ __('reporting.empty.treasury_entries') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['rows'], $report['total'])->links() }}
            </x-filament::section>
        @else
            <p wire:loading wire:target="loadReport" role="status" class="reporting-empty">{{ __('reporting.loading.treasury') }}</p>
        @endif
    </div>
</x-filament-panels::page>
