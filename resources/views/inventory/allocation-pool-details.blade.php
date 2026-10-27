<div class="fox-allocation-details">
    <header class="fox-allocation-details-product">
        @include('components.inventory-product', ['variant' => $pool])
        <div>
            <h2>{{ $pool->product->name }}</h2>
            <p><bdi>{{ $pool->sku }}</bdi> · {{ $pool->location_name }}</p>
            @if ($receipts->isNotEmpty())
                <p>{{ __('Supplier') }}: {{ $receipts->pluck('supplier_name')->unique()->join(', ') }}</p>
                <p>{{ __('Purchase Receipts') }}: @foreach ($receipts as $receipt)<bdi>{{ $receipt->receipt_number }}</bdi>@if (! $loop->last), @endif @endforeach</p>
            @else
                <p>{{ __('No permitted posted receipt references to display.') }}</p>
            @endif
        </div>
    </header>

    <div class="fox-inventory-variant-list">
        @foreach ($colors as $color)<x-inventory-color :name="$color->name" :hex="$color->hex_code ?? null" />@endforeach
        @foreach ($sizes as $size)<span class="fox-inventory-pill">{{ $size->name }}</span>@endforeach
    </div>
    <details class="fox-inventory-variant-identities"><summary>{{ __('Variant records') }}</summary>
        <div class="fox-inventory-variant-list">@foreach ($variants as $variant)<span><x-inventory-variant :variant="$variant" /> <bdi>{{ $variant->sku }}</bdi></span>@endforeach</div>
    </details>

    <div class="fox-allocation-detail-metrics">
        @foreach ([
            'received_quantity' => ['Received quantity', $pool->received_quantity],
            'allocated_quantity' => ['Allocated quantity', $allocatedTotal],
            'remaining_quantity' => ['Unallocated received stock', $pool->remaining_quantity],
        ] as [$label, $value])
            <article><span>{{ __($label) }}</span><strong><bdi>{{ $value === null ? __('Missing balance') : \App\Models\QuantityFormatter::format((string) $value) }}</bdi></strong></article>
        @endforeach
        <article><span>{{ __('Allocation status') }}</span><strong>{{ \App\Modules\Inventory\Livewire\AllocationStockTable::statuses()[$pool->allocation_state] }}</strong></article>
    </div>

    @if ($canAllocate)
        <x-filament::button tag="a" :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('create', ['product' => $pool->product_id, 'location' => $pool->allocation_location_id])">{{ __('Allocate') }}</x-filament::button>
    @endif

    <section class="fox-inventory-panel fox-inventory-matrix">
        <header><div><h3>{{ __('Color × Size') }}</h3><p>{{ __('Quantities shown are from posted size allocation records.') }}</p></div></header>
        @if ($colors->isEmpty() || $sizes->isEmpty())
            <p class="fox-allocation-empty">{{ __('No color and size variants are configured for this product.') }}</p>
        @elseif ($matrix->isEmpty() || $allocatedTotal === '0' || $allocatedTotal === '0.0000')
            <p class="fox-allocation-empty">{{ __('No posted allocation data is available for this product and location.') }}</p>
        @else
            <div class="fox-inventory-scroll" tabindex="0" role="region" aria-label="{{ __('Color × Size') }}">
                <table class="fox-smart-table">
                    <thead><tr><th>{{ __('Color / Size') }}</th>@foreach ($sizes as $size)<th>{{ $size->name }}</th>@endforeach<th>{{ __('Total') }}</th></tr></thead>
                    <tbody>
                        @foreach ($matrix as $row)
                            <tr><th><x-inventory-color :name="$row['label']" :hex="$row['hex'] ?? null" /></th>@foreach ($sizes as $size)<td><bdi>{{ \App\Models\QuantityFormatter::format($row['cells'][$size->id]) }}</bdi></td>@endforeach<td class="fox-inventory-total"><bdi>{{ \App\Models\QuantityFormatter::format($row['total']) }}</bdi></td></tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><th>{{ __('Total across all colors') }}</th>@foreach ($sizes as $size)<td><bdi>{{ \App\Models\QuantityFormatter::format($columnTotals[$size->id]) }}</bdi></td>@endforeach<td><bdi>{{ \App\Models\QuantityFormatter::format($allocatedTotal) }}</bdi></td></tr></tfoot>
                </table>
            </div>
        @endif
    </section>

    @if ($pool->allocation_state === 'review')
        <p>{{ __('Receipt totals, completed allocation evidence or current balances are incomplete or inconsistent. Review the history before allocating.') }}</p>
    @endif
    <section class="fox-allocation-document-list">
        <h3>{{ __('Allocation history') }}</h3>
        @forelse ($documents as $document)
            <p><x-filament::link :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('view', ['record' => $document])">{{ $document->reference }}</x-filament::link> · {{ __(ucfirst($document->status)) }}</p>
        @empty
            <p>{{ __('No allocation records yet.') }}</p>
        @endforelse
    </section>
</div>
