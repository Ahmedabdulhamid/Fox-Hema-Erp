<?php

namespace App\Modules\Payments\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Payments\Database\Factories\CustomerFactory;
use App\Modules\Payments\Services\CustomerAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Customer extends Model
{
    use HasFactory;
    use HasPublicUuid;

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    protected $table = 'payment_customers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            if (! CustomerAccess::isWriting()) {
                throw ValidationException::withMessages(['document' => __('Use the authorized customer settlement service.')]);
            }
            if ($this->exists) {
                $stored = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
                $allowed = ['name', 'phone', 'email', 'is_active', 'updated_at'];
                if (array_diff(array_keys($this->getDirty()), $allowed) !== []) {
                    throw ValidationException::withMessages(['document' => __('Financial identities and posted evidence are immutable.')]);
                }
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['document' => __('Customer settlement evidence cannot be deleted.')]);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoiceAccounts(): HasMany
    {
        return $this->hasMany(CustomerInvoiceAccount::class);
    }
}
