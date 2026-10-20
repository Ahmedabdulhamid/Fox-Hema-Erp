<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class SalesReservationConsumption extends Model
{
    use HasPublicUuid;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'consumed_at' => 'datetime'];
    }

    public function save(array $options = []): bool
    {
        throw ValidationException::withMessages(['record' => __('Reservation consumption evidence is immutable.')]);
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['record' => __('Reservation consumption evidence is immutable.')]);
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw ValidationException::withMessages(['record' => __('Reservation consumption evidence is immutable.')]);
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw ValidationException::withMessages(['record' => __('Reservation consumption evidence is immutable.')]);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(SalesStockReservation::class, 'sales_stock_reservation_id');
    }

    public function deliveryItem(): BelongsTo
    {
        return $this->belongsTo(SalesDeliveryItem::class, 'sales_delivery_item_id');
    }

    public function consumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by')->withTrashed();
    }
}
