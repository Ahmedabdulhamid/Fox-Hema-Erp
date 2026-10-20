<?php

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Inventory\Database\Factories\SizeAllocationItemFactory;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

class SizeAllocationItem extends Model
{
    /** @use HasFactory<SizeAllocationItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected static function newFactory(): SizeAllocationItemFactory
    {
        return SizeAllocationItemFactory::new();
    }

    protected $table = 'inventory_size_allocation_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        $guard = function (self $item): void {
            $allocationId = $item->getOriginal('allocation_id') ?? $item->allocation_id;
            if (! SizeAllocation::whereKey($allocationId)->where('status', 'draft')->exists()
                || ($item->exists && $item->movements()->exists())) {
                throw ValidationException::withMessages(['items' => __('Only unposted draft lines can be changed.')]);
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(SizeAllocation::class, 'allocation_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
