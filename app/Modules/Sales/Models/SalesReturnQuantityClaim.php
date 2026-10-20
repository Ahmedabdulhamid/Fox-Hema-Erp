<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class SalesReturnQuantityClaim extends Model
{
    protected $guarded = ['*'];

    public function newEloquentBuilder($query): Builder
    {
        return new SalesReturnBuilder($query);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'approved_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function save(array $options = []): bool
    {
        throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
    }
}
