<?php

namespace App\Modules\AccessManagement\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Database\Factories\ModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

class Module extends Model
{
    use HasFactory;
    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected $fillable = ['code', 'name', 'description', 'icon', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function newFactory(): ModuleFactory
    {
        return ModuleFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (Module $module): void {
            if ($module->isDirty('code')) {
                throw ValidationException::withMessages(['data.code' => __('Module codes cannot be changed.')]);
            }
            if ($module->code === 'access_management' && ! $module->is_active) {
                throw ValidationException::withMessages(['data.is_active' => __('AccessManagement must remain enabled.')]);
            }
        });
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'modules_permissions')->withTimestamps();
    }
}
