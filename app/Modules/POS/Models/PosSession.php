<?php

namespace App\Modules\POS\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Database\Factories\PosSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSession extends Model
{
    /** @use HasFactory<PosSessionFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'session_number',
        'pos_device_id',
        'location_id',
        'opened_by',
        'closed_by',
        'status',
        'opened_at',
        'closing_started_at',
        'closed_at',
        'opening_cash',
        'expected_cash',
        'counted_cash',
        'cash_difference',
        'cash_difference_reason',
        'closing_approved_by',
        'closing_approved_at',
        'notes',
    ];

    protected static function newFactory(): PosSessionFactory
    {
        return PosSessionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closing_started_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'counted_cash' => 'decimal:4',
            'cash_difference' => 'decimal:4',
            'closing_approved_at' => 'datetime',
        ];
    }

    public function posDevice(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closingApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closing_approved_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PosTransaction::class, 'pos_session_id');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'pos_session_id');
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(PosSessionTender::class, 'pos_session_id');
    }
}
