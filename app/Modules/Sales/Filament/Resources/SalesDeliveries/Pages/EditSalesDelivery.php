<?php

namespace App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\SalesDeliveryResource;
use App\Modules\Sales\Filament\SalesDeliveryActions;
use App\Modules\Sales\Services\SalesDeliveryService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditSalesDelivery extends EditRecord
{
    protected static string $resource = SalesDeliveryResource::class;

    #[Locked]
    public string $revision;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->revision = app(SalesDeliveryService::class)->revision($this->record);
        $data['items'] = $this->record->items()->orderBy('id')->get(['id', 'sales_order_item_id', 'quantity'])->toArray();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = ProductMutation::run(fn () => app(SalesDeliveryService::class)->update(auth()->user(), $record, $data, $this->revision), 'data');
        $this->revision = app(SalesDeliveryService::class)->revision($record);

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('Delivery draft saved.');
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), SalesDeliveryActions::cancel()];
    }
}
