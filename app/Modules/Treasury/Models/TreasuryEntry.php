<?php

namespace App\Modules\Treasury\Models;

use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class TreasuryEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists || ! TreasuryService::isWriting()) {
            throw ValidationException::withMessages(['entry' => 'Treasury ledger entries are append-only.']);
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['entry' => 'Treasury ledger entries cannot be deleted.']);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(TreasuryOperation::class, 'operation_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class, 'account_id');
    }
}
