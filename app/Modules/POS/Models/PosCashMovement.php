<?php

namespace App\Modules\POS\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Database\Factories\PosCashMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosCashMovement extends Model
{
    /** @use HasFactory<PosCashMovementFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'reference_number',
        'pos_session_id',
        'movement_type',
        'direction',
        'amount',
        'reason',
        'corrects_movement_id',
        'created_by',
        'approved_by',
        'approved_at',
        'occurred_at',
    ];

    protected static function newFactory(): PosCashMovementFactory
    {
        return PosCashMovementFactory::new();
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'approved_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function correctsMovement(): BelongsTo
    {
        return $this->belongsTo(PosCashMovement::class, 'corrects_movement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'corrects_movement_id');
    }
}
