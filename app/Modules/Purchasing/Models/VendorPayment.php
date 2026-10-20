<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Database\Factories\VendorPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorPayment extends Model
{
    /** @use HasFactory<VendorPaymentFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
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
