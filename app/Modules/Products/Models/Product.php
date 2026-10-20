<?php

namespace App\Modules\Products\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Models\DiscountProduct;
use App\Modules\Products\Database\Factories\ProductFactory;
use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Taxes\Models\ProductTax;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected $attributes = ['status' => 'draft', 'is_active' => false];

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected $fillable = [
        'brand_id',
        'category_id',
        'name',
        'description',
        'default_sku',
        'status',
        'is_active',
        'has_fixed_selling_price',
        'sale_price',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'is_active' => 'boolean',
            'has_fixed_selling_price' => 'boolean',
            'sale_price' => 'decimal:4',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'product_taxes', 'product_id', 'tax_id')
            ->using(ProductTax::class)
            ->withPivot('id')
            ->withTimestamps();
    }

    public function productTaxes(): HasMany
    {
        return $this->hasMany(ProductTax::class, 'product_id');
    }

    public function discounts(): BelongsToMany
    {
        return $this->belongsToMany(Discount::class, 'discount_products', 'product_id', 'discount_id')
            ->using(DiscountProduct::class)
            ->withPivot('id')
            ->withTimestamps();
    }

    public function discountProducts(): HasMany
    {
        return $this->hasMany(DiscountProduct::class, 'product_id');
    }
}
