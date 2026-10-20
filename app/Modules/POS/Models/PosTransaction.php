<?php

namespace App\Modules\POS\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Database\Factories\PosTransactionFactory;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosTransaction extends Model
{
    /** @use HasFactory<PosTransactionFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'client_transaction_id',
        'pos_device_id',
        'pos_session_id',
        'location_id',
        'cashier_id',
        'sales_order_id',
        'sales_invoice_id',
        'sales_return_id',
        'sales_credit_note_id',
        'transaction_type',
        'sync_status',
        'client_occurred_at',
        'server_received_at',
        'processed_at',
        'payload_hash',
        'request_payload',
        'result_payload',
        'attempts',
        'last_attempt_at',
        'error_code',
        'error_message',
    ];

    protected static function newFactory(): PosTransactionFactory
    {
        return PosTransactionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'client_occurred_at' => 'datetime',
            'server_received_at' => 'datetime',
            'processed_at' => 'datetime',
            'request_payload' => 'array',
            'result_payload' => 'array',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function posDevice(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function salesCreditNote(): BelongsTo
    {
        return $this->belongsTo(SalesCreditNote::class, 'sales_credit_note_id');
    }
}
