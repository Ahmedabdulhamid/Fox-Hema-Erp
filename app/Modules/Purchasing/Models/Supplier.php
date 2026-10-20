<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\Purchasing\Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected $fillable = [
        'code',
        'name',
        'contact',
        'phone',
        'email',
        'address',
        'tax_number',
        'payment_terms_days',
        'notes',
        'is_active',
    ];

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class, 'supplier_id');
    }

    public function vendorPayments(): HasMany
    {
        return $this->hasMany(VendorPayment::class, 'supplier_id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class, 'supplier_id');
    }
}
