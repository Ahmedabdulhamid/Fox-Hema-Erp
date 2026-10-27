<?php

namespace App\Modules\Payments\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Payments\Database\Factories\PaymentFactory;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class Payment extends Model
{
    use HasFactory;
    use HasPublicUuid;

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'paid_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime', 'payment_date' => 'immutable_date'];
    }

    public function isSettlementReceipt(): bool
    {
        return $this->customer_id !== null || $this->treasury_account_id !== null;
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            $stored = $this->exists ? self::whereKey($this->id)->lockForUpdate()->firstOrFail() : null;
            if (($this->isSettlementReceipt() || $stored?->isSettlementReceipt())
                && (! CustomerAccess::isWriting() && ! CustomerPayments::isPosting())) {
                throw ValidationException::withMessages(['payment' => __('Use the customer payment service.')]);
            }
            if ($stored !== null && $stored->isSettlementReceipt() && (array_diff(array_keys($this->getDirty()), ['status', 'posted_at', 'posted_by', 'paid_at', 'updated_at']) !== [] || $stored->status !== 'draft')) {
                throw ValidationException::withMessages(['payment' => __('Customer receipt identities and posted records are immutable.')]);
            }

            return parent::save($options);
        });
    }

    protected static function booted(): void
    {
        static::saving(function (self $payment): void {
            $stored = $payment->exists ? self::whereKey($payment->id)->lockForUpdate()->firstOrFail() : null;
            if (($stored !== null && ($stored->status !== 'draft' || ! PaymentService::isWriting((int) $payment->id)))
                || ($stored === null && $payment->status !== 'draft')) {
                throw ValidationException::withMessages(['payment' => __('Payment history is immutable; use controlled posting or reversal.')]);
            }
        });
        static::deleting(function (self $payment): void {
            throw ValidationException::withMessages(['payment' => __('Payment history cannot be deleted.')]);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
