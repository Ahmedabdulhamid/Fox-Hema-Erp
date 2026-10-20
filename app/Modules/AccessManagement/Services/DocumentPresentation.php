<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\SalesDeliveryResource;
use App\Modules\Sales\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Modules\Sales\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Modules\Sales\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentPresentation
{
    public static function searchNumber(Builder $query, string $search): Builder
    {
        return app(DocumentNumberService::class)->installed()
            ? $query->whereHas('documentNumber', fn ($q) => $q->where('number', 'like', '%'.$search.'%'))
            : $query->whereRaw('1 = 0');
    }

    /** A legacy locator is explicitly labelled, never persisted or represented as a new business number. */
    public static function legacyReference(Model $record): string
    {
        $type = $record::DOCUMENT_TYPE;
        [, $column] = DocumentNumberService::SOURCES[$type];
        $original = (string) $record->getAttribute($column);
        if ($original !== '' && ! self::containsUuid($original)) {
            return $original;
        }

        return __('documents.'.$type).' — '.__('documents.legacy_record', ['id' => $record->getKey()]);
    }

    public static function containsUuid(string $value): bool
    {
        return preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value) === 1;
    }

    public function overview(Model $record, ?User $actor): array
    {
        $type = $record::DOCUMENT_TYPE;
        $location = $record instanceof VendorBill ? $record->purchaseOrder?->location : $record->location;
        $status = (string) ($record->status ?? $record->inspection_status ?? '');
        $steps = match ($type) {
            'SO' => ['draft', 'confirmed', 'partially_fulfilled', 'fulfilled'],
            'PO' => ['draft', 'rfq', 'confirmed', 'partially_received', 'received'],
            'SR' => ['draft', 'approved', 'posted'],
            'QC' => ['pending_inspection'],
            default => ['draft', 'posted'],
        };
        if ($status === 'cancelled') {
            $steps = ['cancelled'];
        } elseif (! in_array($status, $steps, true)) {
            $steps = array_filter([$status]);
        }
        [, $column] = DocumentNumberService::SOURCES[$type];
        $original = (string) $record->getAttribute($column);

        return ['reference' => $record->display_reference, 'type' => __('documents.'.$type),
            'location' => $location?->name, 'party' => $record->customer_name ?? $record->supplier?->name ?? $record->salesOrder?->customer_name,
            'status' => $status, 'steps' => $steps, 'legacy_reference' => self::containsUuid($original) ? $original : null,
            'links' => $actor === null ? [] : $this->links($record, $actor)];
    }

    /** Bounded navigation over real relationships. Every target is checked by its existing policy. */
    public function links(Model $record, User $actor): array
    {
        $links = [];
        if ($record instanceof SalesOrder) {
            foreach ([$record->deliveries(), $record->invoices(), $record->returns()] as $relation) {
                $this->collectLinks($links, $relation->getQuery(), $actor);
            }
        } elseif ($record instanceof SalesDelivery || $record instanceof SalesInvoice || $record instanceof SalesReturn) {
            $this->addLink($links, $record->salesOrder, $actor);
        } elseif ($record instanceof PurchaseOrder) {
            $this->collectLinks($links, $record->receipts()->getQuery(), $actor);
            $this->collectLinks($links, $record->vendorBills()->getQuery(), $actor);
        } elseif ($record instanceof PurchaseReceipt || $record instanceof VendorBill) {
            $this->addLink($links, $record->purchaseOrder, $actor);
            if ($record instanceof PurchaseReceipt) {
                $this->collectLinks($links, VendorBill::whereHas('items.receiptMatches.purchaseReceiptItem', fn ($q) => $q->where('purchase_receipt_id', $record->id)), $actor);
            }
        }

        return $links;
    }

    private function collectLinks(array &$links, Builder $query, User $actor): void
    {
        foreach ($query->orderByDesc('id')->limit(12)->get() as $target) {
            $this->addLink($links, $target, $actor);
        }
    }

    private function addLink(array &$links, ?Model $target, User $actor): void
    {
        if ($target === null || ! $actor->can('view', $target)) {
            return;
        }
        $resource = match ($target::class) {
            SalesOrder::class => SalesOrderResource::class,
            SalesDelivery::class => SalesDeliveryResource::class,
            SalesInvoice::class => SalesInvoiceResource::class,
            SalesReturn::class => SalesReturnResource::class,
            PurchaseOrder::class => PurchaseOrderResource::class,
            PurchaseReceipt::class => PurchaseReceiptResource::class,
            VendorBill::class => VendorBillResource::class,
        };
        $links[] = ['label' => __('documents.'.$target::DOCUMENT_TYPE).' · '.$target->display_reference,
            'url' => $resource::getUrl('view', ['record' => $target])];
    }
}
