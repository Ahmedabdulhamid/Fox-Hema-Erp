<?php

namespace App\Modules\Payments\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CustomerInvoiceAccount extends Model
{
    use HasPublicUuid;

    protected $table = 'customer_invoice_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['planned_immediate_amount' => 'decimal:4', 'due_date' => 'immutable_date'];
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            if (! CustomerAccess::isWriting()) {
                throw ValidationException::withMessages(['document' => __('Use the authorized customer settlement service.')]);
            }
            if ($this->exists) {
                $stored = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
                $allowed = [];
                if (array_diff(array_keys($this->getDirty()), $allowed) !== []) {
                    throw ValidationException::withMessages(['document' => __('Financial identities and posted evidence are immutable.')]);
                }
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['document' => __('Customer settlement evidence cannot be deleted.')]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
