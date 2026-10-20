<?php

namespace App\Modules\Taxes\Models;

use App\Modules\Products\Models\Product;
use App\Modules\Taxes\Database\Factories\ProductTaxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductTax extends Pivot
{
    /** @use HasFactory<ProductTaxFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'product_taxes';

    protected $fillable = [
        'product_id',
        'tax_id',
    ];

    protected static function newFactory(): ProductTaxFactory
    {
        return ProductTaxFactory::new();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }
}
