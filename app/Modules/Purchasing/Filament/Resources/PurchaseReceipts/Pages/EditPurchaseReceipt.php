<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditPurchaseReceipt extends EditRecord
{
    protected static string $resource = PurchaseReceiptResource::class;

    #[Locked]
    public string $revision;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $service = app(PurchaseReceiptService::class);
        $this->revision = $service->revision($this->record);
        $received = $service->received($this->record->purchaseOrder);
        $data['po_number'] = $this->record->purchaseOrder->order_number;
        $data['supplier_name'] = $this->record->purchaseOrder->supplier->name;
        $data['destination'] = $this->record->location->name;
        $data['received_at'] = $this->record->received_at?->format('Y-m-d H:i:s');
        $data['items'] = $this->record->items()->with('purchaseOrderItem.variant')->orderBy('id')->get()->map(function ($item) use ($received): array {
            $line = $item->purchaseOrderItem;

            return ['id' => $item->id, 'variant_label' => $line->variant->displayName(),
                'ordered' => $line->quantity, 'expected' => $item->expected_quantity,
                'previously_received' => $received[$line->id] ?? '0.0000',
                'remaining' => (string) BigDecimal::of($line->quantity)->minus($received[$line->id] ?? '0')->toScale(4),
                'quantity' => $item->quantity, 'unit_cost' => $item->unit_cost];
        })->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = ProductMutation::run(fn () => app(PurchaseReceiptService::class)->update(auth()->user(), $record, $data, $this->revision), 'data');
        $this->revision = app(PurchaseReceiptService::class)->revision($record);

        return $record;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label(__('Save Draft'));
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
