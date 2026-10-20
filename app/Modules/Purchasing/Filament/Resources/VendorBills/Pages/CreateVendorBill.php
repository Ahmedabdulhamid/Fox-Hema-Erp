<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills\Pages;

use App\Modules\Purchasing\Filament\BillMutation;
use App\Modules\Purchasing\Filament\ReportsBillValidationErrors;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Services\VendorBillService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateVendorBill extends CreateRecord
{
    use ReportsBillValidationErrors;

    protected static string $resource = VendorBillResource::class;

    protected static bool $canCreateAnother = false;

    protected function afterFill(): void
    {
        $orderUuid = request()->query('order');
        $receiptUuid = request()->query('receipt');
        if ($orderUuid === null && $receiptUuid === null) {
            return;
        }
        try {
            $data = BillMutation::run(function () use ($orderUuid, $receiptUuid): array {
                $receipt = $receiptUuid === null ? null : PurchaseReceipt::where('uuid', $receiptUuid)->firstOrFail();
                $order = $receipt?->purchaseOrder ?? PurchaseOrder::where('uuid', $orderUuid)->firstOrFail();

                return app(VendorBillService::class)->prefill(auth()->user(), $order, $receipt);
            });
            $data['purchase_order_id'] = PurchaseOrder::findOrFail($data['purchase_order_id'])->uuid;
            $this->form->fill($data);
        } catch (ValidationException $exception) {
            $this->addError('data.items', collect($exception->errors())->flatten()->implode(' '));
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return BillMutation::run(fn () => app(VendorBillService::class)->create(auth()->user(), $data), 'data');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Save Draft'));
    }
}
