<?php

namespace App\Modules\Purchasing\Models\Concerns;

use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorBillItem;
use App\Modules\Purchasing\Services\VendorBillService;
use Illuminate\Validation\ValidationException;

trait ProtectsBillHistory
{
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn (): ?bool => parent::delete());
    }

    protected static function bootProtectsBillHistory(): void
    {
        static::saving(fn (self $record) => $record->guardBillHistory());
        static::deleting(fn (self $record) => $record->guardBillHistory(true));
    }

    private function guardBillHistory(bool $deleting = false): void
    {
        ModuleRegistryLock::acquire();
        if ($this instanceof VendorBill) {
            if ($deleting) {
                $this->immutableBill();
            }
            if (! $this->exists) {
                if ($this->status !== 'draft') {
                    $this->immutableBill();
                }

                return;
            }
            $orderId = self::whereKey($this->id)->value('purchase_order_id');
            if ($orderId !== null) {
                PurchaseOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            }
            $stored = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if ($stored->status !== 'draft' || $this->isDirty(['supplier_id', 'purchase_order_id', 'created_by', 'uuid'])
                || ! in_array($this->status, ['draft', 'posted', 'cancelled'], true)
                || ($this->status === 'posted' && ($this->posted_at === null || $this->posted_by === null
                    || ! VendorBillService::isPostingValidated((int) $this->id)))) {
                $this->immutableBill();
            }

            return;
        }
        $parentIds = $this instanceof VendorBillItem
            ? [$this->vendor_bill_id, $this->getOriginal('vendor_bill_id')]
            : VendorBillItem::whereIn('id', array_filter([$this->vendor_bill_item_id, $this->getOriginal('vendor_bill_item_id')]))->pluck('vendor_bill_id')->all();
        foreach (VendorBill::whereIn('id', array_filter($parentIds))->orderBy('id')->get() as $bill) {
            if ($bill->purchase_order_id !== null) {
                PurchaseOrder::whereKey($bill->purchase_order_id)->lockForUpdate()->firstOrFail();
            }
            $bill = VendorBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->status !== 'draft') {
                $this->immutableBill();
            }
        }
    }

    private function immutableBill(): never
    {
        throw ValidationException::withMessages(['bill' => __('Completed bill history is immutable. Cancel drafts instead of deleting bills.')]);
    }
}
