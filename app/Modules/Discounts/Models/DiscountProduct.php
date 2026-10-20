<?php

namespace App\Modules\Discounts\Models;

use App\Modules\Discounts\Database\Factories\DiscountProductFactory;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DiscountProduct extends Pivot
{
    /** @use HasFactory<DiscountProductFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'discount_products';

    protected $fillable = [
        'discount_id',
        'product_id',
    ];

    protected static function newFactory(): DiscountProductFactory
    {
        return DiscountProductFactory::new();
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
