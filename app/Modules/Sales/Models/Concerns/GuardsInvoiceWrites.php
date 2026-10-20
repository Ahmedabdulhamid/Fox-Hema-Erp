<?php

namespace App\Modules\Sales\Models\Concerns;

use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceDeliveryMatch;
use App\Modules\Sales\Models\SalesInvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait GuardsInvoiceWrites
{
    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            ModuleRegistryLock::acquire();
            $this->guardInvoiceWrite();

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            ModuleRegistryLock::acquire();
            if ($this instanceof SalesInvoice) {
                $this->rejectInvoiceWrite();
            }
            $this->guardInvoiceWrite();

            return parent::delete();
        });
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        $this->rejectInvoiceWrite();
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        $this->rejectInvoiceWrite();
    }

    private function guardInvoiceWrite(): void
    {
        if ($this instanceof SalesInvoice) {
            $stored = $this->exists ? SalesInvoice::whereKey($this->id)->lockForUpdate()->firstOrFail() : null;
            if (($this->status ?? 'draft') !== 'draft' || $this->posted_at !== null || $this->posted_by !== null
                || $this->posting_payload_hash !== null || $this->posting_revision !== null
                || $this->cancelled_at !== null || $this->cancelled_by !== null
                || ($stored !== null && ($stored->status !== 'draft' || $stored->posted_at !== null
                    || $stored->posting_payload_hash !== null || $stored->cancelled_at !== null
                    || $this->isDirty(['uuid', 'invoice_number', 'sales_order_id', 'location_id', 'created_by', 'submission_payload_hash'])))) {
                $this->rejectInvoiceWrite();
            }

            return;
        }
        $foreignKey = $this instanceof SalesInvoiceItem ? 'sales_invoice_id' : 'sales_invoice_item_id';
        if ($this->exists && $this->isDirty($foreignKey)) {
            $this->rejectInvoiceWrite();
        }
        $parentId = $this->getOriginal($foreignKey) ?? $this->{$foreignKey};
        $invoiceId = $this instanceof SalesInvoiceDeliveryMatch
            ? SalesInvoiceItem::whereKey($parentId)->value('sales_invoice_id') : $parentId;
        $invoice = SalesInvoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        if ($invoice->status !== 'draft' || $invoice->posted_at !== null || $invoice->posted_by !== null
            || $invoice->posting_payload_hash !== null || $invoice->cancelled_at !== null) {
            $this->rejectInvoiceWrite();
        }
    }

    private function rejectInvoiceWrite(): never
    {
        throw ValidationException::withMessages(['record' => __('Invoice lifecycle and posted amounts are controlled by Sales workflows.')]);
    }
}
