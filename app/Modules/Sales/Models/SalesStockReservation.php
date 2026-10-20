<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Database\Factories\SalesStockReservationFactory;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class SalesStockReservation extends Model
{
    /** @use HasFactory<SalesStockReservationFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $guarded = ['*'];

    protected static function newFactory(): SalesStockReservationFactory
    {
        return SalesStockReservationFactory::new();
    }

    protected function casts(): array
    {
        return ['quantity_reserved' => 'decimal:4', 'quantity_consumed' => 'decimal:4', 'quantity_released' => 'decimal:4'];
    }

    public function save(array $options = []): bool
    {
        throw ValidationException::withMessages(['record' => __('Reservation records may only be changed by the Sales reservation workflow.')]);
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['record' => __('Reservation records may only be changed by the Sales reservation workflow.')]);
    }

    public function activeQuantity(): string
    {
        return (string) BigDecimal::of($this->quantity_reserved)->minus($this->quantity_consumed)->minus($this->quantity_released)->toScale(4);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(SalesReservationConsumption::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
