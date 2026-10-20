<?php

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Database\Factories\SizeAllocationFactory;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class SizeAllocation extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'SA';

    /** @use HasFactory<SizeAllocationFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected static function newFactory(): SizeAllocationFactory
    {
        return SizeAllocationFactory::new();
    }

    protected $table = 'inventory_size_allocations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $guard = function (self $record): void {
            if (self::whereKey($record->id)->where('status', '!=', 'draft')->exists()) {
                throw ValidationException::withMessages(['status' => __('Only draft allocations can be changed.')]);
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sourceVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'source_variant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SizeAllocationItem::class, 'allocation_id');
    }
}
