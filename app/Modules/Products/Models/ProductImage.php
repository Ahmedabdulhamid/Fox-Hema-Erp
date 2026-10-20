<?php

namespace App\Modules\Products\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Products\Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected static function newFactory(): ProductImageFactory
    {
        return ProductImageFactory::new();
    }

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'disk',
        'path',
        'alt_text',
        'is_primary',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
