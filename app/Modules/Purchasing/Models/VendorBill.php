<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Database\Factories\VendorBillFactory;
use App\Modules\Purchasing\Models\Concerns\ProtectsBillHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorBill extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'BILL';

    /** @use HasFactory<VendorBillFactory> */
    use HasFactory;

    use HasPublicUuid;
    use ProtectsBillHistory;

    protected $fillable = [
        'supplier_id',
        'purchase_order_id',
        'bill_number',
        'bill_date',
        'due_date',
        'settlement_terms',
        'planned_immediate_amount',
        'status',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'notes',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected static function newFactory(): VendorBillFactory
    {
        return VendorBillFactory::new();
    }

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:4',
            'planned_immediate_amount' => 'decimal:4',
            'posted_at' => 'datetime',
            'bill_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorBillItem::class, 'vendor_bill_id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class, 'vendor_bill_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
