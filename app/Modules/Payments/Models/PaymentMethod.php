<?php

namespace App\Modules\Payments\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Payments\Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PaymentMethod extends Model
{
    use HasFactory;
    use HasPublicUuid;

    protected static function newFactory(): PaymentMethodFactory
    {
        return PaymentMethodFactory::new();
    }

    public const TYPES = ['cash', 'bank_transfer', 'card', 'wallet', 'other'];

    protected $fillable = ['code', 'name', 'type', 'is_active', 'requires_reference', 'available_in_pos', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'requires_reference' => 'boolean', 'available_in_pos' => 'boolean', 'sort_order' => 'integer'];
    }

    /** Allocate the identity inside the same transaction as the new method. */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            if ($this->exists) {
                $stored = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
                foreach (['code', 'type', 'uuid'] as $field) {
                    if ($this->getAttribute($field) !== $stored->getAttribute($field)) {
                        throw ValidationException::withMessages(['code' => __('payment_methods.validation.immutable')]);
                    }
                }
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['method' => __('payment_methods.validation.delete')]);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
