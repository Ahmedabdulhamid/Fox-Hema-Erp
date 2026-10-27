<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Purchasing\Database\Factories\VendorPaymentAllocationFactory;
use App\Modules\Purchasing\Services\VendorPaymentAllocationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class VendorPaymentAllocation extends Model
{
    /** @use HasFactory<VendorPaymentAllocationFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'operation_key', 'payload_fingerprint', 'allocated_by', 'allocated_at',
        'released_by', 'released_at', 'release_reason', 'release_key', 'release_fingerprint',
        'supplier_id',
        'vendor_payment_id',
        'vendor_bill_id',
        'amount',
    ];

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    protected static function booted(): void
    {
        static::saving(function (self $allocation): void {
            ModuleRegistryLock::acquire();
            $stored = $allocation->exists ? self::whereKey($allocation->id)->lockForUpdate()->firstOrFail() : null;
            $controlled = VendorPaymentAllocationService::isWriting();
            $payment = VendorPayment::whereKey($allocation->vendor_payment_id)->lockForUpdate()->firstOrFail();
            if (($stored !== null && (! $controlled || $stored->released_at !== null
                    || $allocation->isDirty(['supplier_id', 'vendor_payment_id', 'vendor_bill_id', 'amount', 'operation_key', 'payload_fingerprint', 'allocated_by', 'allocated_at', 'uuid'])))
                || ($stored === null && ! $controlled && ($payment->idempotency_key !== null || $payment->status !== 'draft'))) {
                throw ValidationException::withMessages(['allocation' => 'Allocation evidence is immutable; use controlled allocation or release.']);
            }
        });
        static::deleting(function (self $allocation): void {
            throw ValidationException::withMessages(['allocation' => 'Allocation history cannot be deleted.']);
        });
    }

    protected static function newFactory(): VendorPaymentAllocationFactory
    {
        return VendorPaymentAllocationFactory::new();
    }

    protected function casts(): array
    {
        return [
            'allocated_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'amount' => 'decimal:4',
        ];
    }

    public function vendorPayment(): BelongsTo
    {
        return $this->belongsTo(VendorPayment::class, 'vendor_payment_id');
    }

    public function vendorBill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
