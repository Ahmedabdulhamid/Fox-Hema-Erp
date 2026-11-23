<?php

namespace App\Modules\AccessManagement\Models;

use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class DatabaseSession extends Model
{
    protected $table = 'sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['payload'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['login_at' => 'datetime', 'location_id' => 'integer'];
    }

    public function scopeInActorLocation(Builder $query, User $actor): void
    {
        $locationId = getPermissionsTeamId();
        if (! RequestAuthorizationFacts::actorIsActive($actor) || $locationId === null
            || ! RequestAuthorizationFacts::locationIsActive((int) $locationId)
            || ! RequestAuthorizationFacts::actorHasLocation($actor, (int) $locationId)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('location_id', $locationId)
            ->whereHas('user.locations', fn (Builder $locations) => $locations->whereKey($locationId));
    }

    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        if (! RequestAuthorizationFacts::actorIsActive($actor)) {
            $query->whereRaw('1 = 0');

            return;
        }
        if ($actor->isGlobalSuperAdmin()) {
            return;
        }

        $query->where(function (Builder $sessions) use ($actor): void {
            $sessions->where('user_id', $actor->id);
            if ($actor->can('sessions.view')) {
                $sessions->orWhere(fn (Builder $others) => $others->inActorLocation($actor));
            }
        });
    }

    public function getLastActivityAttribute(int|string|null $value): ?Carbon
    {
        return $value === null ? null : Carbon::createFromTimestamp((int) $value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
