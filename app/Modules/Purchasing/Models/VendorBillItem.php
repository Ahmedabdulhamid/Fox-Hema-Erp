<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Database\Factories\VendorBillItemFactory;
use App\Modules\Purchasing\Models\Concerns\ProtectsBillHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorBillItem extends Model
{
    /** @use HasFactory<VendorBillItemFactory> */
    use HasFactory;

    use HasPublicUuid;
    use ProtectsBillHistory;

    protected $fillable = [
        'vendor_bill_id',
        'variant_id',
        'description',
        'quantity',
        'unit_price',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'line_total',
        'commercial_snapshot',
    ];

    protected static function newFactory(): VendorBillItemFactory
    {
        return VendorBillItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'commercial_snapshot' => 'array',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:6',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function vendorBill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function receiptMatches(): HasMany
    {
        return $this->hasMany(VendorBillReceiptMatch::class, 'vendor_bill_item_id');
    }
}
