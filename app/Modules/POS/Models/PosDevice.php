<?php

namespace App\Modules\POS\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Database\Factories\PosDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosDevice extends Model
{
    /** @use HasFactory<PosDeviceFactory> */
    use HasFactory;

    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected $fillable = [
        'device_code',
        'name',
        'location_id',
        'is_active',
        'registered_at',
        'registered_by',
        'last_seen_at',
        'revoked_at',
    ];

    protected static function newFactory(): PosDeviceFactory
    {
        return PosDeviceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PosSession::class, 'pos_device_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PosTransaction::class, 'pos_device_id');
    }
}
