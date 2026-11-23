<x-filament-panels::page class="reporting-page stock-transfer-reports" :dir="app()->getLocale() === 'ar' ? 'rtl' : 'ltr'" :lang="app()->getLocale()">
    <div class="reporting-dashboard">
        <div wire:init="loadReport"></div>
        <x-filament::section class="reporting-filter-panel">
            <form wire:submit="applyFilters" class="reporting-filter-grid">
                @foreach (['from', 'until'] as $field)
                    <label class="reporting-filter-field">{{ __('reporting.filters.'.$field) }}
                        <x-filament::input.wrapper><x-filament::input type="date" wire:model="{{ $field }}" /></x-filament::input.wrapper>
                    </label>
                @endforeach
                @foreach (['locationId' => 'source', 'destinationId' => 'destination'] as $field => $label)
                    <label class="reporting-filter-field">{{ __('stock_transfer_reporting.'.$label) }}
                        <x-filament::input.wrapper><x-filament::input.select wire:model="{{ $field }}">
                            <option value="">{{ __('reporting.filters.all_locations') }}</option>
                            @foreach ($this->branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                    </label>
                @endforeach
                <label class="reporting-filter-field">{{ __('stock_transfer_reporting.variant_filter') }}
                    <x-filament::input.wrapper><x-filament::input type="number" min="1" wire:model="variantId" /></x-filament::input.wrapper>
                </label>
                @include('reporting::components.filter-actions')
            </form>
            <div class="reporting-errors">
                @foreach (['from', 'until', 'locationId', 'destinationId', 'variantId'] as $field)
                    @error($field)<p>{{ $message }}</p>@enderror
                @endforeach
            </div>
            <p class="reporting-filter-note"><x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" /><span>{{ __('stock_transfer_reporting.basis') }}</span></p>
        </x-filament::section>
        @if ($isLoaded)
            @php($report = $this->report)
            <section class="reporting-kpis" aria-label="{{ $this->getTitle() }}">
                @foreach ([
                    ['transfer', 'document_count', 'transfers', 'heroicon-o-arrows-right-left', 'blue'],
                    ['request_line', 'quantity', 'requested', 'heroicon-o-document-text', 'violet'],
                    ['dispatch_line', 'quantity', 'dispatched', 'heroicon-o-truck', 'blue'],
                    ['receipt_line', 'quantity', 'received', 'heroicon-o-cube', 'teal'],
                ] as [$event, $field, $label, $icon, $tone])
                    @include('reporting::components.kpi-card', ['label' => __('stock_transfer_reporting.'.$label), 'value' => $this->quantity($report['totals'][$event][$field]), 'icon' => $icon, 'tone' => $tone])
                @endforeach
                @include('reporting::components.kpi-card', ['label' => __('stock_transfer_reporting.in_transit'), 'value' => $this->quantity($report['current']['remaining']), 'icon' => 'heroicon-o-truck', 'tone' => 'amber'])
                @include('reporting::components.kpi-card', ['label' => __('stock_transfer_reporting.awaiting'), 'value' => $this->quantity($report['current']['awaiting']), 'icon' => 'heroicon-o-clock', 'tone' => 'rose'])
            </section>
            <div class="reporting-submetrics" aria-label="{{ __('stock_transfer_reporting.progress') }}">
                @foreach (['complete_count' => 'completed', 'partial_count' => 'partial', 'partial_dispatch_count' => 'partial_dispatch_count', 'partial_receipt_count' => 'partial_receipt_count', 'pending_count' => 'pending'] as $field => $label)
                    <div class="reporting-submetric">
                        <span>{{ __('stock_transfer_reporting.'.$label) }}</span>
                        <strong dir="ltr">{{ $this->quantity($report['totals']['transfer'][$field]) }}</strong>
                    </div>
                @endforeach
            </div>
            @include('reporting::components.freshness', ['state' => $report['freshness'], 'unit' => __('stock_transfer_reporting.location_days')])

            <x-filament::section class="reporting-panel" :heading="__('stock_transfer_reporting.routes')">
                <p class="reporting-filter-note">{{ __('stock_transfer_reporting.route_basis') }}</p>
                <div class="reporting-table-wrap">
                    <table class="reporting-table stock-transfer-route-table">
                        <thead><tr>
                            <th scope="col">{{ __('stock_transfer_reporting.route') }}</th>
                            @foreach (['requested', 'dispatched', 'received', 'route_in_transit', 'missing'] as $field)
                                <th scope="col" class="reporting-table__numeric">{{ __('stock_transfer_reporting.'.$field) }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                            @forelse ($report['routes']['items'] as $row)
                                <tr>
                                    <td class="stock-transfer-route">
                                        <strong>{{ $report['locations'][$row['location_id']] }} <span aria-hidden="true">{{ app()->getLocale() === 'ar' ? '←' : '→' }}</span> {{ $report['locations'][$row['to_location_id']] }}</strong>
                                        <small>{{ __('stock_transfer_reporting.types.'.$report['location_types'][$row['location_id']]) }} {{ app()->getLocale() === 'ar' ? '←' : '→' }} {{ __('stock_transfer_reporting.types.'.$report['location_types'][$row['to_location_id']]) }}</small>
                                    </td>
                                    @foreach (['requested', 'dispatched', 'received', 'in_transit'] as $field)
                                        <td class="reporting-table__numeric" data-label="{{ __('stock_transfer_reporting.'.($field === 'in_transit' ? 'route_in_transit' : $field)) }}"><bdi dir="ltr">{{ $this->quantity($row[$field]) }}</bdi></td>
                                    @endforeach
                                    <td class="reporting-table__numeric stock-transfer-route-evidence" data-label="{{ __('stock_transfer_reporting.missing') }}">
                                        <span @class(['reporting-status', 'reporting-status--danger' => $row['missing'] > 0, 'reporting-status--neutral' => $row['missing'] == 0])><bdi dir="ltr">{{ $this->quantity($row['missing']) }}</bdi></span>
                                    </td>
                                </tr>
                            @empty<tr><td colspan="6" class="reporting-table__empty">{{ __('stock_transfer_reporting.empty') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['routes'])->links() }}
            </x-filament::section>

            <div class="reporting-grid-equal">
                <div class="reporting-chart">
                    @livewire(\App\Modules\Reporting\Filament\Widgets\DailyStockTransferChart::class, ['from' => $from, 'until' => $until, 'locationId' => $locationId, 'destinationId' => $destinationId],
                        key('transfer-chart-'.md5(json_encode([$from, $until, $locationId, $destinationId]))))
                </div>
                <div class="reporting-chart">
                    @livewire(\App\Modules\Reporting\Filament\Widgets\DailyStockTransferChart::class, ['from' => $from, 'until' => $until, 'locationId' => $locationId, 'destinationId' => $destinationId, 'transferCounts' => true],
                        key('transfer-count-chart-'.md5(json_encode([$from, $until, $locationId, $destinationId]))))
                </div>
            </div>

            @foreach (['rows' => 'activity', 'variants' => 'variants'] as $collection => $heading)
                <x-filament::section class="reporting-panel" :heading="__('stock_transfer_reporting.'.$heading)">
                    @if ($collection === 'variants')<p class="reporting-filter-note">{{ __('stock_transfer_reporting.variant_basis') }}</p>@endif
                    <div class="reporting-table-wrap">
                        <table class="reporting-table">
                            <thead><tr>
                                @if ($collection === 'rows')
                                    @foreach (['date', 'route', 'event', 'status', 'document_count'] as $field)<th scope="col">{{ __('stock_transfer_reporting.'.$field) }}</th>@endforeach
                                @else
                                    <th scope="col">{{ __('reporting.variant_fields.heading') }}</th>
                                    @foreach (['event', 'quantity', 'remaining'] as $field)<th scope="col">{{ __('stock_transfer_reporting.'.$field) }}</th>@endforeach
                                @endif
                                <th scope="col">{{ __('stock_transfer_reporting.progress_evidence') }}</th>
                            </tr></thead>
                            @php($groups = $collection === 'rows' ? collect($report[$collection]['items'])->groupBy('event') : (empty($report[$collection]['items']) ? [] : ['variants' => $report[$collection]['items']]))
                            @forelse ($groups as $event => $rows)
                                <tbody>
                                    @if ($collection === 'rows')
                                        <tr class="stock-transfer-group"><th colspan="6" scope="rowgroup">{{ __('stock_transfer_reporting.status_groups.'.$event) }}</th></tr>
                                    @endif
                                    @foreach ($rows as $row)
                                        <tr>
                                            @if ($collection === 'rows')
                                                <td dir="ltr">{{ \Carbon\CarbonImmutable::parse($row['report_date'])->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                                                <td>{{ $report['locations'][$row['location_id']] }} {{ app()->getLocale() === 'ar' ? '←' : '→' }} {{ $report['locations'][$row['to_location_id']] }}</td>
                                                <td>{{ __('stock_transfer_reporting.events.'.$row['event']) }}</td>
                                                <td><span @class(['reporting-status', 'reporting-status--success' => in_array($row['status'], ['posted', 'received', 'closed']), 'reporting-status--danger' => $row['status'] === 'cancelled', 'reporting-status--warning' => ! in_array($row['status'], ['posted', 'received', 'closed', 'cancelled'])])>{{ __('stock_transfer_reporting.statuses.'.$row['status']) }}</span></td>
                                                <td class="reporting-table__numeric" dir="ltr">{{ $this->quantity($row['document_count']) }}</td>
                                            @else
                                                <td>@include('reporting::components.variant-attributes', ['brand' => $row['brand_name'], 'color' => $row['color_name'], 'hex' => $row['color_hex'], 'size' => $row['size_name']])</td>
                                                <td>{{ __('stock_transfer_reporting.events.'.$row['event']) }}</td>
                                                <td class="reporting-table__numeric" dir="ltr">{{ $this->quantity($row['quantity']) }}</td>
                                                <td class="reporting-table__numeric" dir="ltr">{{ $row['event'] !== 'dispatch_line' ? __('reporting.values.not_applicable') : $this->quantity($row['remaining']) }}</td>
                                            @endif
                                            <td>
                                                @if ($row['missing'] > 0)<span class="reporting-status reporting-status--danger">{{ __('stock_transfer_reporting.missing') }}: <bdi>{{ $this->quantity($row['missing']) }}</bdi></span>@endif
                                                @if ($row['damaged'] > 0)<span class="reporting-status reporting-status--warning">{{ __('stock_transfer_reporting.damaged') }}: <bdi>{{ $this->quantity($row['damaged']) }}</bdi></span>@endif
                                                @if ($row['reversals'] > 0)<span class="reporting-status reporting-status--warning">{{ __('stock_transfer_reporting.reversals') }}: <bdi>{{ $this->quantity($row['reversals']) }}</bdi></span>@endif
                                                <details class="stock-transfer-details">
                                                    <summary>{{ __('stock_transfer_reporting.details') }}</summary>
                                                    <dl class="reporting-summary-list">
                                                        @foreach (['partial_count', 'complete_count', 'pending_count', 'damaged', 'reversals', 'missing'] as $field)
                                                            <div class="reporting-summary-list__row"><dt class="reporting-summary-list__label">{{ __('stock_transfer_reporting.'.$field) }}</dt><dd class="reporting-summary-list__value" dir="ltr">{{ $this->quantity($row[$field]) }}</dd></div>
                                                        @endforeach
                                                    </dl>
                                                </details>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            @empty
                                <tbody><tr><td colspan="{{ $collection === 'rows' ? 6 : 5 }}" class="reporting-table__empty">{{ __('stock_transfer_reporting.empty') }}</td></tr></tbody>
                            @endforelse
                        </table>
                    </div>
                    {{ $this->paginator($report[$collection])->links() }}
                </x-filament::section>
            @endforeach

            <x-filament::section class="reporting-panel" :heading="__('stock_transfer_reporting.in_transit')">
                <p class="reporting-filter-note">{{ __('stock_transfer_reporting.current_basis') }}</p>
                @if ($report['current_pending'])<p class="reporting-freshness__warning">{{ __('reporting.freshness.provisional') }}</p>@endif
                <div class="reporting-table-wrap">
                    <table class="reporting-table stock-transfer-transit-table">
                        <thead><tr>
                            @foreach (['shipment', 'route', 'variant', 'dispatched_at', 'dispatched', 'received', 'remaining', 'progress_evidence'] as $field)<th scope="col">{{ __('stock_transfer_reporting.'.$field) }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                            @forelse ($report['transit']['items'] as $row)
                                <tr>
                                    <td>{{ $row['shipment_number'] }}<small class="stock-transfer-secondary">{{ __('stock_transfer_reporting.transfer') }}: {{ $row['transfer_number'] }}</small></td>
                                    <td>{{ $report['locations'][$row['location_id']] }} {{ app()->getLocale() === 'ar' ? '←' : '→' }} {{ $report['locations'][$row['to_location_id']] }}</td>
                                    <td>@include('reporting::components.variant-attributes', ['brand' => $row['brand_name'], 'color' => $row['color_name'], 'hex' => $row['color_hex'], 'size' => $row['size_name']])</td>
                                    <td><bdi dir="ltr">{{ $row['dispatched_at'] === null ? __('reporting.values.unavailable') : \Carbon\CarbonImmutable::parse($row['dispatched_at'])->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</bdi><small class="stock-transfer-secondary">{{ __('stock_transfer_reporting.age') }}: {{ $this->age($row['dispatched_at']) }}</small></td>
                                    @foreach (['dispatched', 'received', 'remaining'] as $field)<td class="reporting-table__numeric" dir="ltr">{{ $this->quantity($row['missing'] > 0 || $report['current_pending'] ? null : $row[$field]) }}</td>@endforeach
                                    <td>
                                        @foreach (['damaged', 'reversals', 'missing'] as $field)
                                            <small class="stock-transfer-secondary"><span @class(['reporting-status', 'reporting-status--danger' => $field === 'missing' && $row[$field] > 0, 'reporting-status--warning' => $field !== 'missing' && $row[$field] > 0, 'reporting-status--neutral' => $row[$field] == 0])>{{ __('stock_transfer_reporting.'.$field) }}: <bdi>{{ $this->quantity($field === 'damaged' && ($row['missing'] > 0 || $report['current_pending']) ? null : $row[$field]) }}</bdi></span></small>
                                        @endforeach
                                    </td>
                                </tr>
                            @empty<tr><td colspan="8" class="reporting-table__empty">{{ __('stock_transfer_reporting.empty') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['transit'])->links() }}
            </x-filament::section>

            <x-filament::section class="reporting-panel" :heading="__('stock_transfer_reporting.exceptions')">
                <p class="reporting-filter-note">{{ __('stock_transfer_reporting.exception_basis') }}</p>
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead><tr>@foreach (['transfer', 'route', 'status', 'pending', 'age'] as $field)<th scope="col">{{ __('stock_transfer_reporting.'.$field) }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse ($report['exceptions']['items'] as $row)
                                <tr>
                                    <td>{{ $row['transfer_number'] }}</td><td>{{ $report['locations'][$row['location_id']] }} {{ app()->getLocale() === 'ar' ? '←' : '→' }} {{ $report['locations'][$row['to_location_id']] }}</td>
                                    <td><span class="reporting-status reporting-status--warning">{{ __('stock_transfer_reporting.statuses.'.$row['status']) }}</span></td>
                                    <td>{{ __('stock_transfer_reporting.'.($row['status'] === 'draft' && $row['requested_at'] !== null && $row['approved_at'] === null ? 'yes' : 'no')) }}</td>
                                    <td>{{ $this->age($row['requested_at'] ?? $row['created_at']) }}</td>
                                </tr>
                            @empty<tr><td colspan="5" class="reporting-table__empty">{{ __('stock_transfer_reporting.empty') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['exceptions'])->links() }}
            </x-filament::section>

            <x-filament::section class="reporting-panel" :heading="__('stock_transfer_reporting.documents')">
                <div class="reporting-table-wrap">
                    <table class="reporting-table">
                        <thead><tr>@foreach (['date', 'event', 'document', 'route', 'status'] as $field)<th scope="col">{{ __('stock_transfer_reporting.'.$field) }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse ($report['documents']['items'] as $row)
                                <tr>
                                    <td dir="ltr">{{ \Carbon\CarbonImmutable::parse($row['activity_at'])->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</td>
                                    <td>{{ __('stock_transfer_reporting.events.'.$row['event']) }}</td>
                                    <td>{{ $row['document_number'] }}@if ($row['event'] !== 'transfer')<small class="stock-transfer-secondary">{{ __('stock_transfer_reporting.transfer') }}: {{ $row['transfer_number'] }}</small>@endif</td>
                                    <td>{{ $report['locations'][$row['location_id']] }} {{ app()->getLocale() === 'ar' ? '←' : '→' }} {{ $report['locations'][$row['to_location_id']] }}</td>
                                    <td><span @class(['reporting-status', 'reporting-status--success' => in_array($row['status'], ['posted', 'received', 'closed']), 'reporting-status--danger' => $row['status'] === 'cancelled', 'reporting-status--warning' => ! in_array($row['status'], ['posted', 'received', 'closed', 'cancelled'])])>{{ __('stock_transfer_reporting.statuses.'.$row['status']) }}</span></td>
                                </tr>
                            @empty<tr><td colspan="5" class="reporting-table__empty">{{ __('stock_transfer_reporting.empty') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->paginator($report['documents'])->links() }}
            </x-filament::section>
        @else
            <p wire:loading wire:target="loadReport" role="status" class="reporting-empty">{{ __('stock_transfer_reporting.loading') }}</p>
        @endif
    </div>
</x-filament-panels::page>
