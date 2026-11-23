<?php

namespace App\Modules\StockTransfer\Filament\Resources\StockTransfers\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\StockTransfer\Filament\Resources\StockTransfers\StockTransferResource;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Services\StockTransferService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStockTransfer extends EditRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('items.variant.product');
        $data['items'] = $this->record->items->map(fn ($item): array => [
            'brand_id' => $item->variant->product->brand_id ?? 'none',
            'product_id' => $item->variant->product_id,
            'variant_id' => $item->variant_id,
            'requested_quantity' => $item->requested_quantity,
            'notes' => $item->notes,
        ])->all();

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('Stock transfer draft updated');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ProductMutation::run(
            fn (): StockTransfer => app(StockTransferService::class)->updateDraft(auth()->user(), $record, $data),
            'data',
        );
    }
}
