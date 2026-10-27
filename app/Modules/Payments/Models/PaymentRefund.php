<?php

namespace App\Modules\Payments\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Sales\Models\SalesCreditNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PaymentRefund extends Model
{
    use HasPublicUuid;

    protected $table = 'payment_refunds';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'refund_date' => 'immutable_date', 'posted_at' => 'immutable_datetime', 'refunded_at' => 'immutable_datetime', 'recovered_at' => 'immutable_datetime'];
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            if (! CustomerAccess::isWriting()) {
                throw ValidationException::withMessages(['document' => __('Use the authorized customer settlement service.')]);
            }
            if ($this->exists) {
                $stored = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
                $allowed = ['status', 'posted_at', 'posted_by', 'refunded_at', 'treasury_operation_id', 'updated_at'];
                if (CustomerRefunds::isRecovering() && $stored->status === 'posted' && $stored->recovered_at === null) {
                    $allowed = ['recovered_at', 'recovered_by', 'recovery_key', 'recovery_fingerprint', 'recovery_reason', 'recovery_reference', 'recovery_operation_id', 'updated_at'];
                } elseif ($stored->status !== 'draft') {
                    throw ValidationException::withMessages(['document' => __('Posted refunds are immutable.')]);
                }
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SalesCreditNote::class, 'sales_credit_note_id');
    }
}
