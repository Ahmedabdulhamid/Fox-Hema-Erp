<?php

namespace App\Modules\Taxes\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\Products\Models\Product;
use App\Modules\Taxes\Database\Factories\TaxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tax extends Model
{
    /** @use HasFactory<TaxFactory> */
    use HasFactory;

    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected $fillable = [
        'name',
        'code',
        'type',
        'rate',
        'price_inclusion',
        'usage',
        'is_active',
        'effective_from',
        'effective_to',
    ];

    protected static function newFactory(): TaxFactory
    {
        return TaxFactory::new();
    }

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_taxes', 'tax_id', 'product_id')
            ->using(ProductTax::class)
            ->withPivot('id')
            ->withTimestamps();
    }

    public function productTaxes(): HasMany
    {
        return $this->hasMany(ProductTax::class, 'tax_id');
    }
}
