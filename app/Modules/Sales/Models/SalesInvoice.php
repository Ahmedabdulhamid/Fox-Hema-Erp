<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Sales\Database\Factories\SalesInvoiceFactory;
use App\Modules\Sales\Models\Concerns\GuardsInvoiceWrites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesInvoice extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'INV';

    use GuardsInvoiceWrites;

    /** @use HasFactory<SalesInvoiceFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'invoice_number',
        'sales_order_id',
        'location_id',
        'customer_name',
        'customer_phone',
        'invoice_date',
        'due_date',
        'status',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'currency',
        'posted_at',
        'posted_by',
        'created_by',
        'notes',
    ];

    protected static function newFactory(): SalesInvoiceFactory
    {
        return SalesInvoiceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'document_snapshot' => 'array',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class, 'sales_invoice_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'sales_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(SalesCreditNote::class, 'sales_invoice_id');
    }

    public function posTransaction(): HasOne
    {
        return $this->hasOne(PosTransaction::class, 'sales_invoice_id');
    }
}
