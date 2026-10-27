<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Sales\Database\Factories\SalesOrderFactory;
use App\Modules\Sales\Services\SalesDeliveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrder extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'SO';

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            ModuleRegistryLock::acquire();
            $this->guardWrite();

            return parent::save($options);
        });
    }

    private function guardWrite(): void
    {
        $order = $this;
        if (! $this->exists) {
            if (($order->status ?? 'draft') !== 'draft' || ($order->fulfillment_status ?? 'unfulfilled') !== 'unfulfilled'
                || $order->confirmed_at !== null || $order->confirmed_by !== null || $order->cancelled_at !== null
                || $order->cancelled_by !== null || $order->confirmation_payload_hash !== null || $order->cancellation_payload_hash !== null) {
                throw ValidationException::withMessages(['record' => __('Create draft sales orders before using lifecycle actions.')]);
            }
        } else {
            $stored = self::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($stored->status !== 'draft' || $stored->fulfillment_status !== 'unfulfilled'
                || $stored->confirmation_payload_hash !== null || $stored->cancellation_payload_hash !== null
                || $stored->confirmed_at !== null || $stored->cancelled_at !== null
                || $order->isDirty(['status', 'fulfillment_status', 'confirmed_at', 'confirmed_by', 'cancelled_at', 'cancelled_by',
                    'confirmation_payload_hash', 'cancellation_payload_hash', 'location_id', 'order_number', 'uuid', 'created_by'])) {
                throw ValidationException::withMessages(['record' => __('Sales order identity and lifecycle fields are controlled by Sales workflows. Confirmed and cancelled orders are immutable.')]);
            }
        }
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['record' => __('Cancel sales orders instead of deleting their history.')]);
    }

    /** @use HasFactory<SalesOrderFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'order_number',
        'location_id',
        'channel',
        'external_order_id',
        'customer_name',
        'customer_phone',
        'status',
        'fulfillment_status',
        'ordered_at',
        'confirmed_at',
        'confirmed_by',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'notes',
        'created_by',
    ];

    protected static function newFactory(): SalesOrderFactory
    {
        return SalesOrderFactory::new();
    }

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(SalesStockReservation::class);
    }

    public function hasDownstreamDocuments(): bool
    {
        if (array_key_exists('has_non_cancelled_sales_deliveries', $this->getAttributes()) && $this->relationLoaded('deliveries')) {
            return (bool) $this->has_non_cancelled_sales_deliveries
                || (bool) $this->has_downstream_sales_delivery_items
                || (bool) $this->has_sales_invoices
                || (bool) $this->has_sales_returns
                || (bool) $this->has_sales_pos_transaction
                || $this->deliveries->contains(
                    fn (SalesDelivery $delivery): bool => ! app(SalesDeliveryService::class)->isSafelyCancelledDraft($delivery),
                );
        }

        if (! $this->exists) {
            return false;
        }

        return RequestAuthorizationFacts::remember(
            'sales-order-downstream-documents:'.$this->getKey(),
            fn (): bool => $this->deliveries()->orderBy('id')->get()
                ->contains(fn (SalesDelivery $delivery): bool => ! app(SalesDeliveryService::class)->isSafelyCancelledDraft($delivery))
                || $this->deliveryItems()->where(function ($query): void {
                    $query->whereNotNull('stock_movement_id')->orWhereHas('invoiceMatches')->orWhereHas('returnItems');
                })->exists()
                || $this->invoices()->exists() || $this->returns()->exists() || $this->posTransaction()->exists(),
        );
    }

    public function hasConsumedReservations(): bool
    {
        if (array_key_exists('has_consumed_sales_order_reservations', $this->getAttributes())) {
            return (bool) $this->has_consumed_sales_order_reservations;
        }
        if (! $this->exists) {
            return false;
        }

        return RequestAuthorizationFacts::remember(
            'sales-order-consumed-reservations:'.$this->getKey(),
            fn (): bool => $this->reservations()->where('quantity_consumed', '>', '0')->exists(),
        );
    }

    public function scopeWithRowActionFacts(Builder $query): Builder
    {
        return $query->withExists([
            'deliveries as has_non_cancelled_sales_deliveries' => fn ($query) => $query->where('status', '!=', 'cancelled'),
            'deliveryItems as has_downstream_sales_delivery_items' => fn ($query) => $query->where(
                fn (Builder $query): Builder => $query->whereNotNull('stock_movement_id')
                    ->orWhereExists(fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')
                        ->from('sales_invoice_delivery_matches')
                        ->whereColumn('sales_invoice_delivery_matches.sales_delivery_item_id', 'sales_delivery_items.id'))
                    ->orWhereExists(fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')
                        ->from('sales_return_items')
                        ->whereColumn('sales_return_items.sales_delivery_item_id', 'sales_delivery_items.id')),
            ),
            'invoices as has_sales_invoices',
            'returns as has_sales_returns',
            'posTransaction as has_sales_pos_transaction',
            'reservations as has_consumed_sales_order_reservations' => fn ($query) => $query->where('quantity_consumed', '>', '0'),
        ])->with(['deliveries' => fn ($query) => $query->where('status', 'cancelled')
            ->with(['items.invoiceMatches', 'items.returnItems'])]);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'sales_order_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SalesDelivery::class, 'sales_order_id');
    }

    public function deliveryItems(): HasMany
    {
        return $this->hasMany(SalesDeliveryItem::class, 'sales_order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'sales_order_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'sales_order_id');
    }

    public function posTransaction(): HasOne
    {
        return $this->hasOne(PosTransaction::class, 'sales_order_id');
    }
}
