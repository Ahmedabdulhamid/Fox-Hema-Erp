<?php

namespace App\Modules\Products\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\Products\Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected static function newFactory(): BrandFactory
    {
        return BrandFactory::new();
    }

    protected $fillable = [
        'name',
        'icon',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }
}
