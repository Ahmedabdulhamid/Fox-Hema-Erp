<?php

namespace App\Modules\Treasury\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\Treasury\Database\Factories\TreasuryAccountFactory;
use App\Modules\Treasury\Services\TreasuryAccounts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class TreasuryAccount extends Model
{
    use HasFactory;
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected static function newFactory(): TreasuryAccountFactory
    {
        return TreasuryAccountFactory::new();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && ($this->isDirty('code') || ! TreasuryAccounts::isWriting((int) $this->id))) {
            throw ValidationException::withMessages(['account' => 'Use Treasury account management; ownership is immutable.']);
        }

        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['account' => 'Deactivate Treasury accounts instead of deleting them.']);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TreasuryEntry::class, 'account_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
