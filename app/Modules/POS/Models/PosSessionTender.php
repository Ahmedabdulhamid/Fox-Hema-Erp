<?php

namespace App\Modules\POS\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Database\Factories\PosSessionTenderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSessionTender extends Model
{
    /** @use HasFactory<PosSessionTenderFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'pos_session_id',
        'method',
        'expected_amount',
        'verified_amount',
        'difference_amount',
        'verified_by',
        'verified_at',
        'notes',
    ];

    protected static function newFactory(): PosSessionTenderFactory
    {
        return PosSessionTenderFactory::new();
    }

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:4',
            'verified_amount' => 'decimal:4',
            'difference_amount' => 'decimal:4',
            'verified_at' => 'datetime',
        ];
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
