<?php

namespace App\Modules\Products\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Products\Database\Factories\ProductImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductImport extends Model
{
    /** @use HasFactory<ProductImportFactory> */
    use HasFactory, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['global_scope' => 'boolean', 'errors' => 'array', 'warnings' => 'array', 'lease_until' => 'datetime', 'accepted_at' => 'datetime', 'expires_at' => 'datetime', 'finished_at' => 'datetime', 'purged_at' => 'datetime'];
    }

    protected static function newFactory(): ProductImportFactory
    {
        return ProductImportFactory::new();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ProductImportEntry::class, 'import_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ProductImportRow::class, 'import_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ProductImportAsset::class, 'import_id');
    }
}
