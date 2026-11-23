<?php

namespace App\Modules\StockTransfer\Filament\Resources\StockTransfers\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\StockTransfer\Filament\Resources\StockTransfers\StockTransferResource;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Services\StockTransferService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Stock transfer draft saved');
    }

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(
            fn (): StockTransfer => app(StockTransferService::class)->createDraft(auth()->user(), $data),
            'data',
        );
    }
}
