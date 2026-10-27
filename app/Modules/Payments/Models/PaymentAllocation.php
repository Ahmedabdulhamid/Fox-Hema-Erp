<?php

namespace App\Modules\Payments\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PaymentAllocation extends Model
{
    use HasPublicUuid;

    protected $table = 'payment_allocations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'allocated_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            if (! CustomerAccess::isWriting()) {
                throw ValidationException::withMessages(['document' => __('Use the authorized customer settlement service.')]);
            }
            if ($this->exists) {
                $stored = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
                $allowed = ['released_at', 'released_by', 'release_key', 'release_fingerprint', 'release_reason', 'updated_at'];
                if (array_diff(array_keys($this->getDirty()), $allowed) !== [] || $stored->released_at !== null) {
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
