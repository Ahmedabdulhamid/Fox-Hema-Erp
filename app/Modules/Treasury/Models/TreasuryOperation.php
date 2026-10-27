<?php

namespace App\Modules\Treasury\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class TreasuryOperation extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'effective_date' => 'immutable_date', 'posted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function save(array $options = []): bool
    {
        if (! TreasuryService::isWriting() || ($this->exists && $this->isDirty('code'))) {
            throw ValidationException::withMessages(['operation' => 'Use controlled Treasury draft, posting, or reversal services.']);
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['operation' => 'Treasury history cannot be deleted.']);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TreasuryEntry::class, 'operation_id');
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function vendorPayment(): BelongsTo
    {
        return $this->belongsTo(VendorPayment::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class, 'account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class, 'destination_account_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
