<?php

namespace App\Modules\Products\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            if (filled($category->code)) {
                return;
            }
            do {
                $code = 'CAT-'.Str::upper((string) Str::ulid());
            } while (static::query()->where('code', $code)->exists());
            $category->code = $code;
        });
    }

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    protected $fillable = [
        'parent_id',
        'name',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function inventoryValuationSetting(): HasOne
    {
        return $this->hasOne(InventoryValuationSetting::class, 'category_id');
    }
}
