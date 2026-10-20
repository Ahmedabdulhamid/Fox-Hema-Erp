<?php

namespace App\Modules\Discounts\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\Discounts\Database\Factories\DiscountFactory;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory;

    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected $fillable = [
        'name',
        'code',
        'type',
        'value',
        'scope',
        'usage',
        'is_active',
        'starts_at',
        'ends_at',
        'is_stackable',
    ];

    protected static function newFactory(): DiscountFactory
    {
        return DiscountFactory::new();
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'is_active' => 'boolean',
            'is_stackable' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'discount_products', 'discount_id', 'product_id')
            ->using(DiscountProduct::class)
            ->withPivot('id')
            ->withTimestamps();
    }

    public function discountProducts(): HasMany
    {
        return $this->hasMany(DiscountProduct::class, 'discount_id');
    }
}
