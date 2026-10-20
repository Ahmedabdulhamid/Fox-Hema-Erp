<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Purchasing\Database\Factories\VendorBillReceiptMatchFactory;
use App\Modules\Purchasing\Models\Concerns\ProtectsBillHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorBillReceiptMatch extends Model
{
    /** @use HasFactory<VendorBillReceiptMatchFactory> */
    use HasFactory;

    use HasPublicUuid;
    use ProtectsBillHistory;

    protected $fillable = [
        'vendor_bill_item_id',
        'purchase_receipt_item_id',
        'matched_quantity',
    ];

    protected static function newFactory(): VendorBillReceiptMatchFactory
    {
        return VendorBillReceiptMatchFactory::new();
    }

    protected function casts(): array
    {
        return [
            'matched_quantity' => 'decimal:4',
        ];
    }

    public function vendorBillItem(): BelongsTo
    {
        return $this->belongsTo(VendorBillItem::class, 'vendor_bill_item_id');
    }

    public function purchaseReceiptItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptItem::class, 'purchase_receipt_item_id');
    }
}
