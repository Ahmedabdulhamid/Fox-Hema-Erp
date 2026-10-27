<div class="fox-inventory-scroll" tabindex="0" role="region" aria-label="{{ __('Color × Size') }}">
    <table class="fox-smart-table"><thead><tr><th>{{ __('Color / Size') }}</th>@foreach ($matrix['sizes'] as $size)<th>{{ $size }}</th>@endforeach<th>{{ __('Total') }}</th></tr></thead>
        <tbody>
            @forelse ($matrix['rows'] as $row)
                <tr><th><x-inventory-color :name="$row['label']" :hex="$row['hex'] ?? null" /></th>@foreach ($matrix['sizes'] as $sizeId => $size)<td>
                    @forelse ($row['cells'][$sizeId] ?? [] as $cell)
                        @if ($interactive ?? false)
                            <button type="button" wire:click="selectVariant({{ $cell->id }})" wire:key="cell-{{ $cell->id }}" title="{{ $cell->sku }} · {{ $cell->imageLabel() }}" class="fox-smart-cell {{ $cell->balance_id !== null && (float) $cell->quantity_available == 0 ? 'fox-smart-cell-zero' : ($cell->reorder_threshold !== null && (float) $cell->quantity_available > 0 && (float) $cell->quantity_available <= (float) $cell->reorder_threshold ? 'fox-smart-cell-low' : '') }}" aria-pressed="{{ $this->variant === (string) $cell->id ? 'true' : 'false' }}">
                                <bdi>{{ $cell->balance_id === null ? __('Missing balance') : \App\Models\QuantityFormatter::format((string) $cell->{$matrix['field']}) }}</bdi>
                            </button>
                        @else
                            <a class="fox-smart-cell" href="{{ \App\Modules\Inventory\Filament\Pages\ProductInventory::getUrl(['productUuid' => $cell->product->uuid, 'location' => $cell->inventory_location_id, 'variant' => $cell->id]) }}" title="{{ $cell->sku }}"><bdi>{{ $cell->balance_id === null ? __('Missing balance') : \App\Models\QuantityFormatter::format((string) $cell->{$matrix['field']}) }}</bdi><small><bdi>{{ $cell->sku }}</bdi></small></a>
                        @endif
                    @empty<span class="fox-smart-missing" title="{{ __('No variant combination') }}">—</span>@endforelse
                </td>@endforeach<td class="fox-inventory-total"><bdi>{{ \App\Models\QuantityFormatter::format($row['total']) }}</bdi></td></tr>
            @empty<tr><td colspan="{{ count($matrix['sizes']) + 2 }}" class="fox-inventory-empty">{{ __('No variants for this product.') }}</td></tr>@endforelse
        </tbody>
        <tfoot><tr><th>{{ __('Total across all colors') }}</th>@foreach ($matrix['sizes'] as $sizeId => $size)<td><bdi>{{ \App\Models\QuantityFormatter::format($matrix['columns'][$sizeId] ?? '0') }}</bdi></td>@endforeach<td><bdi>{{ \App\Models\QuantityFormatter::format($matrix['total']) }}</bdi></td></tr></tfoot>
    </table>
</div>
