<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Database\Factories\PurchaseOrderFactory;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PurchaseOrder extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'PO';

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            $stored = self::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->isDirty('status')) {
                $allowed = match ($stored->status) {
                    'draft', 'rfq' => ['draft', 'rfq', 'confirmed', 'cancelled'],
                    'confirmed' => ['cancelled', 'partially_received', 'received'],
                    'partially_received' => ['received'],
                    default => [],
                };
                if (! in_array($order->status, $allowed, true)) {
                    throw ValidationException::withMessages(['status' => __('This lifecycle transition is not available.')]);
                }
                if (in_array($order->status, ['partially_received', 'received'], true)) {
                    $received = app(PurchaseReceiptService::class)->received($stored, true);
                    $items = $stored->items()->orderBy('id')->lockForUpdate()->get();
                    $any = false;
                    $complete = $items->isNotEmpty();
                    foreach ($items as $item) {
                        $quantity = BigDecimal::of($received[$item->id] ?? '0');
                        $any = $any || $quantity->isPositive();
                        $complete = $complete && $quantity->isEqualTo($item->quantity);
                    }
                    $derived = $complete ? 'received' : ($any ? 'partially_received' : 'confirmed');
                    if ($derived !== $order->status) {
                        throw ValidationException::withMessages(['status' => __('Purchase order fulfillment must agree with posted receipt quantities.')]);
                    }
                }
            }
            if (! in_array($stored->status, ['draft', 'rfq'], true) && $order->isDirty([
                'supplier_id', 'location_id', 'order_number', 'order_date', 'expected_date', 'subtotal',
                'discount_amount', 'tax_amount', 'total_amount', 'notes', 'created_by', 'confirmed_by', 'confirmed_at', 'uuid',
            ])) {
                throw ValidationException::withMessages(['record' => __('Agreed purchase order details are immutable after confirmation or cancellation.')]);
            }
        });
        static::deleting(function (self $order): void {
            throw ValidationException::withMessages(['record' => __('Cancel purchase orders instead of deleting them.')]);
        });
    }

    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'supplier_id',
        'location_id',
        'order_number',
        'status',
        'order_date',
        'expected_date',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'notes',
        'created_by',
        'confirmed_by',
        'confirmed_at',
    ];

    protected static function newFactory(): PurchaseOrderFactory
    {
        return PurchaseOrderFactory::new();
    }

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:4',
            'confirmed_at' => 'datetime',
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class, 'purchase_order_id');
    }

    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class, 'purchase_order_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class, 'purchase_order_id');
    }
}
