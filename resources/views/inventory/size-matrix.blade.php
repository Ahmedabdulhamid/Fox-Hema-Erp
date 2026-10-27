@php
    use App\Models\QuantityFormatter;
    $metrics = ['on_hand' => __('Quantity on hand'), 'reserved' => __('Quantity reserved'), 'available' => __('Quantity available')];
@endphp
@if (! $matrix['authorized'])
    <div class="fox-inventory-panel fox-inventory-empty">@svg('heroicon-o-lock-closed')<h2>{{ __('Stock balance access required') }}</h2><p>{{ __('Allocation history remains available with your existing permissions.') }}</p></div>
@elseif ($matrix['sizes'] === [])
    <div class="fox-inventory-panel fox-inventory-empty">@svg('heroicon-o-squares-2x2')<h2>{{ __('No recorded size balances') }}</h2><p>{{ __('Choose another product or location. Missing records do not confirm zero physical stock.') }}</p></div>
@else
    <section class="fox-inventory-panel fox-inventory-matrix" aria-labelledby="inventory-size-heading">
        <header><div><h2 id="inventory-size-heading">{{ __('Current stock by size') }}</h2><p>{{ __('Stock totals use location, brand, product and color. Status and search apply to allocation history.') }}</p></div><span class="fox-inventory-pill">{{ __('Read only') }}</span></header>
        <div class="fox-inventory-scroll" tabindex="0" role="region" aria-label="{{ __('Size allocation matrix') }}">
            <table>
                <caption class="sr-only">{{ __('Current stock by size') }}</caption>
                <thead><tr><th scope="col">{{ __('Size') }}</th>@foreach ($matrix['sizes'] as $size)<th scope="col">{{ $size['label'] }}</th>@endforeach<th scope="col">{{ __('Total') }}</th></tr></thead>
                <tbody>
                    @foreach ($metrics as $key => $label)
                        <tr class="fox-inventory-matrix-{{ $key }}"><th scope="row">{{ $label }}</th>
                            @foreach ($matrix['sizes'] as $size)
                                <td @class(['fox-inventory-zero' => $key === 'available' && (float) $size[$key] <= 0])><bdi>{{ QuantityFormatter::format($size[$key]) }}</bdi></td>
                            @endforeach
                            <td class="fox-inventory-total"><bdi>{{ QuantityFormatter::format($matrix['totals'][$key]) }}</bdi></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @php
        $onHand = max(0, (float) $matrix['totals']['on_hand']);
        $available = max(0, (float) $matrix['totals']['available']);
        $percentage = $onHand > 0 ? min(100, 100 * $available / $onHand) : 0;
        $peak = max(1, ...array_map(fn ($size) => max(0, (float) $size['on_hand']), $matrix['sizes']));
    @endphp
    <div class="fox-inventory-charts">
        <section class="fox-inventory-panel fox-inventory-availability" aria-label="{{ __('Availability overview') }}">
            <header><h2>{{ __('Availability overview') }}</h2></header>
            <div class="fox-inventory-availability-body">
                <svg viewBox="0 0 120 120" role="img" aria-label="{{ __('Available share of on-hand stock') }}: {{ \App\Models\PercentageFormatter::format($percentage) }}">
                    <circle class="fox-inventory-ring-track" cx="60" cy="60" r="47" />
                    <circle class="fox-inventory-ring-value" cx="60" cy="60" r="47" pathLength="100" stroke-dasharray="{{ $percentage }} 100" />
                    <text x="60" y="64" text-anchor="middle">{{ \App\Models\PercentageFormatter::format($percentage) }}</text>
                </svg>
                <dl>@foreach ($metrics as $key => $label)<div class="fox-inventory-legend-{{ $key }}"><dt>{{ $label }}</dt><dd><bdi>{{ QuantityFormatter::format($matrix['totals'][$key]) }}</bdi></dd></div>@endforeach</dl>
            </div>
        </section>
        <section class="fox-inventory-panel fox-inventory-distribution" aria-label="{{ __('Size distribution') }}">
            <header><div><h2>{{ __('Size distribution') }}</h2><p>{{ __('On hand and available by configured size') }}</p></div><div class="fox-inventory-chart-key"><span>{{ __('On hand') }}</span><span>{{ __('Available') }}</span></div></header>
            <div class="fox-inventory-bars" tabindex="0" role="list" aria-label="{{ __('Size distribution') }}">
                @foreach ($matrix['sizes'] as $size)
                    <div class="fox-inventory-bar-group" role="listitem" aria-label="{{ $size['label'] }}: {{ __('On hand') }} {{ QuantityFormatter::format($size['on_hand']) }}, {{ __('Available') }} {{ QuantityFormatter::format($size['available']) }}">
                        <bdi>{{ QuantityFormatter::format($size['on_hand']) }}</bdi>
                        <div class="fox-inventory-bar-track"><span style="height: {{ max(0, (float) $size['on_hand']) / $peak * 100 }}%"></span><span style="height: {{ max(0, (float) $size['available']) / $peak * 100 }}%"></span></div>
                        <span>{{ $size['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
@endif
