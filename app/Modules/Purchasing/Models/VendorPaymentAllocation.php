<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Purchasing\Database\Factories\VendorPaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPaymentAllocation extends Model
{
    /** @use HasFactory<VendorPaymentAllocationFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'supplier_id',
        'vendor_payment_id',
        'vendor_bill_id',
        'amount',
    ];

    protected static function newFactory(): VendorPaymentAllocationFactory
    {
        return VendorPaymentAllocationFactory::new();
    }

    protected function casts(): array
    {
        return [
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
