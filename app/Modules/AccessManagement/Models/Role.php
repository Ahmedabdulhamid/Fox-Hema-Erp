<?php

namespace App\Modules\AccessManagement\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected $fillable = ['name', 'guard_name', 'location_id'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isSystemRole(): bool
    {
        return $this->name === 'super_admin' && $this->location_id === null && $this->guard_name === 'web';
    }
}
