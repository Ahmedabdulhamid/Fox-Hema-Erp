<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills\Pages;

use App\Modules\Purchasing\Filament\BillMutation;
use App\Modules\Purchasing\Filament\ReportsBillValidationErrors;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Services\VendorBillService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditVendorBill extends EditRecord
{
    use ReportsBillValidationErrors;

    protected static string $resource = VendorBillResource::class;

    #[Locked]
    public string $revision;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->revision = app(VendorBillService::class)->revision($this->record);
        $data['items'] = $this->record->items()->orderBy('id')->get()->map(fn ($item): array => $item->only([
            'variant_id', 'description', 'quantity', 'unit_price', 'discount_amount', 'tax_amount',
        ]) + ['allocations' => $item->receiptMatches->map(fn ($match): array => [
            'purchase_receipt_item_id' => $match->purchase_receipt_item_id, 'quantity' => $match->matched_quantity,
        ])->all()])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $bill = BillMutation::run(fn () => app(VendorBillService::class)->update(auth()->user(), $record, $data, $this->revision), 'data');
        $this->revision = app(VendorBillService::class)->revision($bill);

        return $bill;
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
