<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchasing\Database\Factories\VendorPaymentFactory;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class VendorPayment extends Model
{
    /** @use HasFactory<VendorPaymentFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'treasury_account_id', 'recovery_reference',
        'location_id', 'payment_method_id', 'currency', 'idempotency_key', 'payload_fingerprint',
        'reversal_key', 'reversal_fingerprint', 'reversal_reason',
        'supplier_id',
        'payment_number',
        'payment_date',
        'amount',
        'method',
        'external_reference',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
        'voided_by',
        'voided_at',
        'notes',
    ];

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            $this->assertWritable();

            return parent::save($options);
        });
    }

    private function assertWritable(): void
    {
        $payment = $this;
        ModuleRegistryLock::acquire();
        $stored = $payment->exists ? self::whereKey($payment->id)->lockForUpdate()->firstOrFail() : null;
        $controlled = VendorPaymentService::isWriting((int) $payment->id);
        $legacyDraftEdit = $stored !== null && $stored->status === 'draft' && $stored->idempotency_key === null
            && array_diff(array_keys($payment->getDirty()), ['payment_date', 'amount', 'method', 'external_reference', 'notes', 'updated_at']) === [];
        if (($stored === null && $payment->status !== 'draft')
            || ($stored !== null && ! $controlled && ! $legacyDraftEdit)
            || ($stored !== null && $controlled && $payment->isDirty(['treasury_account_id', 'supplier_id', 'location_id', 'payment_method_id', 'amount', 'currency', 'method', 'payment_date', 'payment_number', 'created_by', 'idempotency_key', 'payload_fingerprint', 'external_reference', 'notes', 'uuid']))) {
            throw ValidationException::withMessages(['payment' => 'Payment history is immutable; use controlled posting or reversal.']);
        }
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['payment' => 'Payment history cannot be deleted.']);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }

    public function treasuryOperation(): HasOne
    {
        return $this->hasOne(TreasuryOperation::class, 'vendor_payment_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected static function newFactory(): VendorPaymentFactory
    {
        return VendorPaymentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
            'payment_date' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class, 'vendor_payment_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
