<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Sales\Database\Factories\SalesReturnFactory;
use App\Modules\Sales\Models\Concerns\GuardsReturnWrites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesReturn extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'SR';

    use GuardsReturnWrites;

    /** @use HasFactory<SalesReturnFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'return_number',
        'sales_order_id',
        'sales_invoice_id',
        'location_id',
        'status',
        'return_date',
        'reason',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'posted_by',
        'posted_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected static function newFactory(): SalesReturnFactory
    {
        return SalesReturnFactory::new();
    }

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'sales_return_id');
    }

    public function quantityClaims(): HasMany
    {
        return $this->hasMany(SalesReturnQuantityClaim::class);
    }

    public function costAllocations(): HasMany
    {
        return $this->hasMany(SalesReturnCostAllocation::class);
    }

    public function postedAllocations(): HasMany
    {
        return $this->hasMany(SalesReturnPostedAllocation::class);
    }

    public function invoiceAllocations(): HasMany
    {
        return $this->hasMany(SalesReturnInvoiceAllocation::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(SalesCreditNote::class, 'sales_return_id');
    }

    public function posTransaction(): HasOne
    {
        return $this->hasOne(PosTransaction::class, 'sales_return_id');
    }
}
