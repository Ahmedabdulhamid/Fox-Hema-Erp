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
                        <x-filament::input.select wire:model.live="locationId">
                            <option value="">{{ __('reporting.filters.all_branches') }}</option>
                            @foreach ($this->branches as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                @foreach ([
                    'cashierId' => ['cashier', 'cashierId'],
                    'deviceId' => ['pos_device', 'deviceId'],
                    'sessionId' => ['pos_shift', 'sessionId'],
                ] as $field => [$label, $optionsKey])
                    <label class="reporting-filter-field">
                        {{ __('reporting.filters.'.$label) }}
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="{{ $field }}">
                                <option value="">{{ __('reporting.filters.all_records') }}</option>
                                @foreach ($this->options[$optionsKey] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                @endforeach
                @include('reporting::components.filter-actions')
            </form>
            <div class="reporting-errors">
                @foreach (['from', 'until', 'locationId', 'cashierId', 'deviceId', 'sessionId'] as $field)
                    @error($field)<p>{{ $message }}</p>@enderror
                @endforeach
            </div>
            <p class="reporting-filter-note">
                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                <span>{{ __('reporting.notes.pos_basis') }} {{ __('reporting.notes.pos_returns_basis') }}</span>
            </p>
        </x-filament::section>

        @if ($isLoaded)
            @php($report = $this->report)
            @php($freshness = $report['freshness'])
            <section class="reporting-kpis" aria-label="{{ __('reporting.pages.pos.title') }}">
                @foreach ([
                    ['sale_total', 'pos_invoice_total', 'heroicon-o-document-text', 'teal'],
                    ['sale_revenue', 'pos_revenue', 'heroicon-o-banknotes', 'blue'],
                    ['credit_total', 'posted_credits', 'heroicon-o-document-minus', 'rose'],
                    ['refund_total', 'cash_refunded', 'heroicon-o-arrow-uturn-left', 'rose'],
                    ['refund_recovery_total', 'refund_recoveries', 'heroicon-o-arrow-uturn-right', 'violet'],
                    ['opening_cash', 'shift_openings', 'heroicon-o-arrow-down-circle', 'amber'],
                    ['expected_cash', 'expected_cash', 'heroicon-o-calculator', 'blue'],
                    ['counted_cash', 'counted_cash', 'heroicon-o-banknotes', 'teal'],
                    ['variance', 'cash_variance', 'heroicon-o-scale', 'rose'],
                ] as [$field, $label, $icon, $tone])
                    @include('reporting::components.kpi-card', [
                        'label' => __('reporting.kpis.'.$label),
                        'value' => $report['totals'][$field] === null
                            ? __('reporting.values.unavailable')
                            : \App\Models\MoneyFormatter::precise($report['totals'][$field], $currency),
                        'icon' => $icon,
                        'tone' => $tone,
                    ])
                @endforeach
            </section>

            <section class="reporting-submetrics" aria-label="{{ __('reporting.sections.shift_reconciliation') }}">
                <div class="reporting-submetric">
                    <span>{{ __('reporting.kpis.posted_returns') }}</span>
                    <strong dir="ltr">{{ number_format($report['totals']['return_count']) }}</strong>
                </div>
                <div class="reporting-submetric">
                    <span>{{ __('reporting.kpis.closed_shifts') }}</span>
                    <strong dir="ltr">{{ number_format($report['totals']['closed_count']) }}</strong>
                </div>
            </section>

            @if ($report['totals']['missing_sale'] > 0 || $report['totals']['missing_close'] > 0)
                <p class="reporting-filter-note" role="status">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                    <span>{{ __('reporting.values.unavailable') }}: {{ __('reporting.kpis.pos_invoice_total') }}, {{ __('reporting.kpis.expected_cash') }}, {{ __('reporting.kpis.counted_cash') }}.</span>
                </p>
            @endif

            @include('reporting::components.freshness', ['state' => $freshness, 'unit' => __('reporting.freshness.branch_days')])

            <p class="reporting-filter-note">
                <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                <span>{{ __('reporting.notes.open_shift_basis') }}</span>
            </p>

            @if (collect($report['daily'])->contains(fn (array $day): bool => $day['net'] !== null && (float) $day['net'] !== 0.0))
                @livewire(
                    \App\Modules\Reporting\Filament\Widgets\DailyCashChart::class,
                    ['domain' => 'pos', 'from' => $from, 'until' => $until, 'locationId' => $locationId, 'cashierId' => $cashierId, 'deviceId' => $deviceId, 'sessionId' => $sessionId],
                    key('pos-chart-'.hash('sha256', json_encode([$from, $until, $locationId, $cashierId, $deviceId, $sessionId])))
                )
            @endif

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.pos_performance')">
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead>
                            <tr>
                                <th>{{ __('reporting.tables.cashier') }}</th>
                                <th>{{ __('reporting.tables.device') }}</th>
                                <th>{{ __('reporting.tables.shift') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.invoice_count') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.invoice_total') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.credit_total') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.cash_refunded') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['rows'] as $row)
                                <tr>
                                    <td>{{ $row['cashier'] }}</td>
                                    <td>{{ $row['device'] }}</td>
                                    <td dir="ltr">{{ $row['session_number'] }}</td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ number_format($row['sale_count']) }}</td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ $row['sale_total'] === null ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::precise($row['sale_total'], $currency) }}</td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\MoneyFormatter::precise($row['credit_total'], $currency) }}</td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ \App\Models\MoneyFormatter::precise($row['refund_total'], $currency) }}</td>
                                </tr>
                            @empty
                                <tr><td class="reporting-table__empty" colspan="7">{{ __('reporting.empty.pos_performance') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['rows'], $report['total'])->links() }}
            </x-filament::section>

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.shift_reconciliation')">
                <p class="reporting-filter-note">{{ __('reporting.notes.open_shift_basis') }}</p>
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead>
                            <tr>
                                <th>{{ __('reporting.tables.shift') }}</th>
                                <th>{{ __('reporting.tables.shift_status') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.opening_cash') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.expected_cash') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.counted_cash') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.variance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['shifts'] as $row)
                                <tr>
                                    <td dir="ltr">{{ $row['session_number'] }}</td>
                                    <td>
                                        <span @class([
                                            'reporting-status',
                                            'reporting-status--success' => $row['status'] === 'closed',
                                            'reporting-status--warning' => $row['status'] !== 'closed',
                                        ])>{{ __('reporting.status.'.$row['status']) }}</span>
                                    </td>
                                    @foreach (['opening_cash', 'expected_cash', 'counted_cash', 'cash_difference'] as $field)
                                        <td class="reporting-table__numeric" dir="ltr">
                                            {{ $row[$field] === null ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::precise($row[$field], $currency) }}
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td class="reporting-table__empty" colspan="6">{{ __('reporting.empty.shifts') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['shifts'], $report['shift_total'])->links() }}
                @if ($sessionId !== null)
                    <p class="reporting-filter-note">
                        {{ __('reporting.kpis.expected_cash') }}:
                        <bdi dir="ltr">{{ $report['live_expected'] === null ? __('reporting.values.unavailable') : \App\Models\MoneyFormatter::precise($report['live_expected'], $currency) }}</bdi>
                    </p>
                @endif
            </x-filament::section>

            <x-filament::section class="reporting-panel" :heading="__('reporting.sections.sync_status')">
                <p class="reporting-filter-note">{{ __('reporting.notes.sync_basis') }}</p>
                <div class="reporting-submetrics">
                    @foreach (['received', 'processing', 'completed', 'rejected', 'conflict'] as $status)
                        <div class="reporting-submetric">
                            <span>
                                <span @class([
                                    'reporting-status',
                                    'reporting-status--success' => $status === 'completed',
                                    'reporting-status--danger' => in_array($status, ['rejected', 'conflict'], true),
                                    'reporting-status--warning' => in_array($status, ['received', 'processing'], true),
                                ])>{{ __('reporting.status.'.$status) }}</span>
                            </span>
                            <strong dir="ltr">{{ number_format($report['totals'][$status]) }}</strong>
                        </div>
                    @endforeach
                </div>
                <div class="reporting-table-wrap reporting-table-wrap--spaced">
                    <table class="reporting-table">
                        <thead>
                            <tr>
                                <th>{{ __('reporting.tables.request_id') }}</th>
                                <th>{{ __('reporting.tables.shift_id') }}</th>
                                <th>{{ __('reporting.tables.request_type') }}</th>
                                <th>{{ __('reporting.tables.sync_status') }}</th>
                                <th class="reporting-table__numeric">{{ __('reporting.tables.attempts') }}</th>
                                <th>{{ __('reporting.tables.error_code') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['requests'] as $row)
                                <tr>
                                    <td dir="ltr">{{ $row['id'] }}</td>
                                    <td dir="ltr">{{ $row['pos_session_id'] }}</td>
                                    <td>
                                        <span @class([
                                            'reporting-status',
                                            'reporting-status--success' => $row['transaction_type'] === 'sale',
                                            'reporting-status--warning' => $row['transaction_type'] === 'return',
                                        ])>{{ __('reporting.values.'.$row['transaction_type']) }}</span>
                                    </td>
                                    <td>
                                        <span @class([
                                            'reporting-status',
                                            'reporting-status--success' => $row['sync_status'] === 'completed',
                                            'reporting-status--danger' => in_array($row['sync_status'], ['rejected', 'conflict'], true),
                                            'reporting-status--warning' => in_array($row['sync_status'], ['received', 'processing'], true),
                                        ])>{{ __('reporting.status.'.$row['sync_status']) }}</span>
                                    </td>
                                    <td class="reporting-table__numeric" dir="ltr">{{ number_format($row['attempts']) }}</td>
                                    <td dir="ltr">{{ $row['error_code'] ?? __('reporting.values.not_applicable') }}</td>
                                </tr>
                            @empty
                                <tr><td class="reporting-table__empty" colspan="6">{{ __('reporting.empty.requests') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['requests'], $report['request_total'])->links() }}
            </x-filament::section>
        @else
            <p wire:loading wire:target="loadReport" role="status" class="reporting-empty">{{ __('reporting.loading.pos') }}</p>
        @endif
    </div>
</x-filament-panels::page>
