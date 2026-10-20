<?php

namespace App\Modules\AccessManagement\Models;

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
        return ['login_at' => 'datetime'];
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
