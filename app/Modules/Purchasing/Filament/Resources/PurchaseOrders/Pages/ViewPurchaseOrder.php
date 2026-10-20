<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ...PurchaseOrdersTable::lifecycleActions(),
            Action::make('createBill')->label(__('Create Bill'))
                ->visible(fn (): bool => Gate::allows('create', VendorBill::class)
                    && $this->record->receipts()->where('status', 'posted')->exists())
                ->url(fn (): string => VendorBillResource::getUrl('create', ['order' => $this->record->uuid])),
            Action::make('receipts')->label(__('Goods Receipts'))
                ->visible(fn (): bool => app(PurchasingAccess::class)->allows(auth()->user(), PurchasingPermissions::RECEIPTS_VIEW, (int) $this->record->location_id))
                ->url(fn (): string => PurchaseReceiptResource::getUrl('index', ['tableFilters' => ['purchase_order_id' => ['value' => $this->record->id]]])),
            Action::make('prepareReceipt')->label(__('Prepare Goods Receipt'))
                ->visible(fn (): bool => in_array($this->record->status, ['confirmed', 'partially_received'], true)
                    && app(PurchasingAccess::class)->allows(auth()->user(), PurchasingPermissions::RECEIPTS_VIEW, (int) $this->record->location_id)
                    && app(PurchasingAccess::class)->allows(auth()->user(), PurchasingPermissions::RECEIPTS_CREATE, (int) $this->record->location_id))
                ->action(function (): void {
                    $receipt = ProductMutation::run(fn () => app(PurchaseReceiptService::class)->prepare(auth()->user(), $this->record));
                    if ($receipt !== null) {
                        $this->redirect(PurchaseReceiptResource::getUrl('view', ['record' => $receipt]));
                    }
                }),
        ];
    }
}
