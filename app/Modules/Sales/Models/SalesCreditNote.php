<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Sales\Database\Factories\SalesCreditNoteFactory;
use App\Modules\Sales\Models\Concerns\GuardsCreditWrites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesCreditNote extends Model
{
    use GuardsCreditWrites;

    use HasDocumentNumber;
    /** @use HasFactory<SalesCreditNoteFactory> */
    use HasFactory;
    use HasPublicUuid;

    public const DOCUMENT_TYPE = 'CN';

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected $fillable = [
        'credit_note_number',
        'sales_invoice_id',
        'sales_return_id',
        'credit_date',
        'status',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'reason',
        'created_by',
        'posted_by',
        'posted_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected static function newFactory(): SalesCreditNoteFactory
    {
        return SalesCreditNoteFactory::new();
    }

    protected function casts(): array
    {
        return [
            'credit_date' => 'date',
            'document_snapshot' => 'array',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesCreditNoteItem::class, 'sales_credit_note_id');
    }

    public function posTransaction(): HasOne
    {
        return $this->hasOne(PosTransaction::class, 'sales_credit_note_id');
    }
}
