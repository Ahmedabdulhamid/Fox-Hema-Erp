<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Sales\Database\Factories\SalesDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesDelivery extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'SD';

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            ModuleRegistryLock::acquire();
            $order = SalesOrder::whereKey($this->getOriginal('sales_order_id') ?? $this->sales_order_id)->lockForUpdate()->firstOrFail();
            $stored = $this->exists ? self::whereKey($this->id)->lockForUpdate()->firstOrFail() : null;
            if (! in_array($order->status, ['confirmed', 'partially_fulfilled'], true)
                || $order->confirmed_at === null || $order->confirmation_payload_hash === null || $order->cancelled_at !== null
                || (int) $this->location_id !== (int) $order->location_id
                || ! $order->location()->where('type', 'branch')->where('is_active', true)->exists()
                || ($this->status ?? 'draft') !== 'draft' || ($stored !== null && $stored->status !== 'draft')
                || $this->posted_at !== null || $this->posted_by !== null || $this->cancelled_at !== null
                || $this->cancelled_by !== null || $this->cancellation_payload_hash !== null
                || $this->posting_payload_hash !== null || $this->posting_evidence_hash !== null || $this->posting_revision !== null
                || ($stored !== null && ($stored->posted_at !== null || $stored->posted_by !== null || $stored->cancelled_at !== null
                    || $stored->cancelled_by !== null || $stored->cancellation_payload_hash !== null
                    || $stored->posting_payload_hash !== null || $stored->posting_evidence_hash !== null || $stored->posting_revision !== null
                    || $stored->items()->whereNotNull('stock_movement_id')->exists()
                    || $stored->items()->whereHas('invoiceMatches')->exists() || $stored->items()->whereHas('returnItems')->exists()
                    || $this->isDirty(['sales_order_id', 'location_id', 'delivery_number', 'uuid', 'created_by', 'submission_payload_hash'])))) {
                throw ValidationException::withMessages(['record' => __('Delivery identity and lifecycle are controlled by Sales workflows. Only drafts can be edited.')]);
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['record' => __('Cancel delivery drafts instead of deleting their history.')]);
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw ValidationException::withMessages(['record' => __('Only draft deliveries can be changed.')]);
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw ValidationException::withMessages(['record' => __('Only draft deliveries can be changed.')]);
    }

    /** @use HasFactory<SalesDeliveryFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'delivery_number',
        'sales_order_id',
        'location_id',
        'status',
        'delivered_at',
        'posted_at',
        'posted_by',
        'created_by',
        'notes',
    ];

    protected static function newFactory(): SalesDeliveryFactory
    {
        return SalesDeliveryFactory::new();
    }

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesDeliveryItem::class, 'sales_delivery_id');
    }
}
